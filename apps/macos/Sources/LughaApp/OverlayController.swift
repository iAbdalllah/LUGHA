import AppKit
import LughaKit
import SwiftUI

/// Borderless, click-through panel above games (including full-screen spaces) that renders the Arabic line.
@MainActor
final class OverlayController {
    private let settings: AppSettings
    private var panel: NSPanel?
    private let model = OverlayModel()

    init(settings: AppSettings) { self.settings = settings }

    var windowIDs: [CGWindowID] { panel.map { [CGWindowID($0.windowNumber)] } ?? [] }

    /// Where the game draws its English subtitle, in global screen points (origin top-left of the main display). The
    /// Arabic is centred on it and sized to it, so it covers the English. nil = use the Position setting.
    var anchor: CGRect? {
        didSet { panel?.orderOut(nil); panel = nil; model.anchor = nil }
    }

    func show() {
        if panel == nil {
            // The screen that contains the subtitle area (window mode can be on any display).
            let mainHeight = NSScreen.screens.first?.frame.height ?? 0
            let screen = anchor.flatMap { a in
                NSScreen.screens.first { $0.frame.contains(CGPoint(x: a.midX, y: mainHeight - a.midY)) }
            } ?? NSScreen.screens.first { $0.displayID == settings.displayID } ?? NSScreen.main ?? NSScreen.screens[0]
            // Anchor in the panel's own coordinates (top-left of that screen).
            model.anchor = anchor.map { a in
                CGRect(x: a.minX - screen.frame.minX, y: a.minY - (mainHeight - screen.frame.maxY), width: a.width, height: a.height)
            }
            let p = NSPanel(contentRect: screen.frame, styleMask: [.borderless, .nonactivatingPanel], backing: .buffered, defer: false)
            p.level = .screenSaver
            p.isOpaque = false
            p.backgroundColor = .clear
            p.hasShadow = false
            p.ignoresMouseEvents = true
            p.collectionBehavior = [.canJoinAllSpaces, .fullScreenAuxiliary, .stationary, .ignoresCycle]
            p.contentView = NSHostingView(rootView: SubtitleOverlayView(model: model, settings: settings))
            panel = p
        }
        panel?.orderFrontRegardless()
    }

    /// Hides the panel and forgets lines kept up for reading.
    func hide() { panel?.orderOut(nil); hold.clear(); recent.clear(); expiry?.invalidate(); model.shown = []; model.recent = [] }

    private let hold = ReadingHold()
    private var expiry: Timer?

    private let recent = RecentDialogue()
    private var historyDim: Timer?

    func update(_ subtitle: Subtitle?) {
        hold.update(subtitle?.items ?? [])
        recent.add(subtitle?.items ?? [])
        if model.recent.map(\.text) != recent.items.map(\.text) {
            model.recent = recent.items
            // Bright for a few seconds after a new line, then it steps back so it doesn't pull the eye.
            model.recentFresh = true
            historyDim?.invalidate()
            historyDim = Timer.scheduledTimer(withTimeInterval: 6, repeats: false) { [weak self] _ in
                Task { @MainActor in withAnimation(.easeOut(duration: 0.6)) { self?.model.recentFresh = false } }
            }
        }
        withAnimation(.easeOut(duration: 0.12)) { model.subtitle = subtitle }
        refresh()
    }

    /// Shows what the hold says, then comes back when an earlier line's reading time is up.
    private func refresh() {
        let shown = settings.readHold ? hold.visible() : (model.subtitle?.items ?? []).map { (item: $0, old: false) }
        withAnimation(.easeOut(duration: 0.15)) { model.shown = shown }
        expiry?.invalidate()
        if let next = hold.nextExpiry {
            expiry = Timer.scheduledTimer(withTimeInterval: max(next.timeIntervalSinceNow, 0.05), repeats: false) { [weak self] _ in
                Task { @MainActor in self?.refresh() }
            }
        }
    }
}

@MainActor
final class OverlayModel: ObservableObject {
    @Published var subtitle: Subtitle?
    /// Pieces to draw: the current ones and at most one earlier line still being read (old = true).
    @Published var shown: [(item: SubtitleItem, old: Bool)] = []
    @Published var anchor: CGRect?
    /// Recent dialogue for the side panel, newest last; fresh = a line came in the last few seconds.
    @Published var recent: [SubtitleItem] = []
    @Published var recentFresh = false
}

/// Places each piece where it wants to be, moving pieces so none covers another (OverlayLayout.arrange). The first
/// piece is the subtitle block when there is one: it keeps its place and the others make way.
private struct NoOverlapLayout: Layout {
    /// The point a piece is placed at: its centre, or (bottom anchor) the middle of its bottom edge.
    struct Spot: LayoutValueKey { static let defaultValue = CGPoint.zero }
    struct BottomAnchor: LayoutValueKey { static let defaultValue = false }
    struct Fixed: LayoutValueKey { static let defaultValue = false }
    struct Old: LayoutValueKey { static let defaultValue = false }

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        proposal.replacingUnspecifiedDimensions()
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        let sizes = subviews.map { $0.sizeThatFits(.unspecified) }
        let wanted = zip(subviews, sizes).map { v, s in
            let c = v[Spot.self]
            return CGRect(x: c.x - s.width / 2, y: v[BottomAnchor.self] ? c.y - s.height : c.y - s.height / 2, width: s.width, height: s.height)
        }
        let fixed = subviews.first.map { $0[Fixed.self] ? 1 : 0 } ?? 0
        let rects = OverlayLayout.arrange(wanted, old: subviews.map { $0[Old.self] }, in: bounds.size, gap: 4, fixed: fixed)
        for (v, r) in zip(subviews, rects) {
            v.place(at: CGPoint(x: bounds.minX + r.minX, y: bounds.minY + r.minY), proposal: ProposedViewSize(r.size))
        }
    }
}

/// Broadcast-style subtitles over the game:
/// - the dialogue as one block over the English subtitle area: lines stacked in reading order, speaker inline, an
///   earlier line kept dimmed on top until it could be read — so lines never cover each other;
/// - HUD prompts and menu items each over their own English, moved aside when they would touch the block;
/// - optionally a narrow side panel with the recent dialogue, which steps back after a few seconds.
/// Display "over" hides the English on a plate; "above" puts the Arabic just above it. Without positions it falls back
/// to the capture area's centre, or the Position setting.
struct SubtitleOverlayView: View {
    @ObservedObject var model: OverlayModel
    @ObservedObject var settings: AppSettings

    private struct Piece: Identifiable {
        let id: String
        let lines: [(item: SubtitleItem, old: Bool)]
        let spot: CGPoint
        let bottom: Bool
        let font: Double
        let maxWidth: CGFloat
        let cover: CGSize
        let fixed: Bool
        let old: Bool
    }

    var body: some View {
        GeometryReader { geo in
            ZStack(alignment: .topLeading) {
                NoOverlapLayout {
                    ForEach(pieces(in: geo.size)) { p in
                        SubtitlePlate(lines: p.lines, fontSize: p.font, settings: settings, cover: p.cover)
                            .frame(maxWidth: p.maxWidth)
                            .fixedSize(horizontal: false, vertical: true)
                            .opacity(p.old ? 0.6 : 1)
                            .layoutValue(key: NoOverlapLayout.Spot.self, value: p.spot)
                            .layoutValue(key: NoOverlapLayout.BottomAnchor.self, value: p.bottom)
                            .layoutValue(key: NoOverlapLayout.Fixed.self, value: p.fixed)
                            .layoutValue(key: NoOverlapLayout.Old.self, value: p.old)
                            .transition(.opacity)
                    }
                }
                if settings.historySide != "off", !model.recent.isEmpty {
                    historyPanel(in: geo.size)
                }
            }
            .animation(.easeOut(duration: max(settings.fadeMs, 1) / 1000), value: model.shown.map(\.item.text))
        }
    }

    private func pieces(in size: CGSize) -> [Piece] {
        let scale = settings.fontSize / 30
        guard let a = model.anchor else {
            return model.shown.prefix(1).map { s in
                Piece(id: "line", lines: [s], spot: CGPoint(x: size.width / 2, y: size.height * settings.verticalPosition), bottom: false,
                      font: settings.fontSize, maxWidth: size.width * 0.7, cover: .zero, fixed: false, old: false)
            }
        }
        func rect(_ b: CGRect) -> CGRect { CGRect(x: a.minX + b.minX * a.width, y: a.minY + b.minY * a.height, width: b.width * a.width, height: b.height * a.height) }
        func font(_ lineHeight: CGFloat) -> Double { min(max(Double(lineHeight) * 0.9 * scale, 13), 64) }
        let above = settings.displayMode == "above"
        let covers = !above && settings.textStyle != "outline"
        let (block, hud) = OverlayLayout.scene(model.shown)
        var out: [Piece] = []
        if let block {
            let r = block.area.width > 0 ? rect(block.area) : CGRect(x: a.minX, y: a.minY, width: a.width, height: a.height)
            let f = font(block.lineHeight * a.height)
            out.append(Piece(id: "block", lines: block.lines,
                             // Over: anchored to the bottom English line, growing upward. Above: just above the English.
                             spot: CGPoint(x: r.midX, y: above ? r.minY - 4 : r.maxY + f * 0.15), bottom: true, font: f,
                             maxWidth: min(max(r.width * 1.35, a.width * 0.4), a.width * 0.95),
                             cover: covers ? CGSize(width: r.width + f * 0.6, height: r.height + f * 0.3) : .zero, fixed: true, old: false))
        }
        for h in hud {
            let i = h.item
            guard i.box.width > 0 else { continue }
            let r = rect(i.box)
            let f = font(r.height / CGFloat(max(i.lines, 1)))
            out.append(Piece(id: "hud:" + i.text, lines: [h], spot: CGPoint(x: r.midX, y: above ? r.minY - 4 : r.midY), bottom: above, font: f,
                             maxWidth: max(r.width * 1.6, min(a.width, 900)),
                             cover: covers && settings.textStyle == "native" ? CGSize(width: r.width + 8, height: r.height + 4) : .zero, fixed: false, old: h.old))
        }
        return out
    }

    /// Narrow panel at the side of the game picture with the recent dialogue, newest at the bottom and brightest.
    private func historyPanel(in size: CGSize) -> some View {
        let a = model.anchor ?? CGRect(origin: .zero, size: size)
        let w = max(a.width * 0.22, 220)
        let f = max(min(a.height * 0.021 * settings.fontSize / 30, 22), 12)
        let items = model.recent
        return VStack(alignment: .trailing, spacing: f * 0.5) {
            ForEach(Array(items.enumerated()), id: \.element.text) { n, i in
                (Text(i.speaker.map { "\($0): " } ?? "").foregroundColor(SubtitlePlate.amber).font(.system(size: f * 0.82, weight: .bold))
                    + Text(i.text).foregroundColor(.white).font(ArabicFonts.family(settings.fontName).map { .custom($0, size: f) } ?? .system(size: f, weight: .medium)))
                    .multilineTextAlignment(.trailing)
                    .frame(maxWidth: .infinity, alignment: .trailing)
                    .opacity(0.45 + 0.55 * Double(n + 1) / Double(items.count))
            }
        }
        .environment(\.layoutDirection, .rightToLeft)
        .padding(f * 0.8)
        .frame(width: w)
        .background(RoundedRectangle(cornerRadius: f * 0.6, style: .continuous).fill(Color(white: 0.04).opacity(0.6)))
        .opacity(model.recentFresh ? 0.92 : 0.38)
        .position(x: settings.historySide == "left" ? a.minX + a.width * 0.015 + w / 2 : a.maxX - a.width * 0.015 - w / 2, y: a.minY + a.height * 0.45)
        .animation(.easeOut(duration: 0.4), value: model.recentFresh)
    }
}

/// Translated text in the chosen style, one line per piece of dialogue ("Lucia: إيه", name in amber):
/// - Native: the game's own background and text colours on a plate that covers the English — it reads as if the game
///   drew Arabic itself;
/// - Box: white (or yellow) text with a soft shadow on a dark rounded plate;
/// - Outline: no plate, outlined text like film subtitles. Display "above" with Native uses this too (nothing to cover).
struct SubtitlePlate: View {
    static let amber = Color(red: 0.91, green: 0.71, blue: 0.30)
    let lines: [(item: SubtitleItem, old: Bool)]
    let fontSize: Double
    @ObservedObject var settings: AppSettings
    /// The size of the English to cover (zero: no minimum).
    var cover: CGSize = .zero

    private var font: Font {
        ArabicFonts.family(settings.fontName).map { Font.custom($0, size: fontSize).weight(.semibold) } ?? .system(size: fontSize, weight: .semibold)
    }
    private var chosenColor: Color { settings.textColor == "yellow" ? Color(red: 1, green: 0.86, blue: 0.25) : .white }
    private func color(_ c: RGB?) -> Color? { c.map { Color(.sRGB, red: $0.r, green: $0.g, blue: $0.b) } }
    private var main: SubtitleItem? { lines.last(where: { !$0.old })?.item ?? lines.last?.item }

    var body: some View {
        switch (settings.textStyle, settings.displayMode) {
        case ("native", "above"), ("outline", _): outline
        case ("native", _): native
        default: box
        }
    }

    private func text(_ c: Color?) -> some View {
        VStack(spacing: fontSize * 0.12) {
            ForEach(Array(lines.enumerated()), id: \.offset) { _, l in
                (Text(l.item.speaker.map { "\($0): " } ?? "").foregroundColor(Self.amber).font(.system(size: fontSize * 0.72, weight: .bold))
                    + Text(l.item.text).font(font).foregroundColor(c ?? color(l.item.foreground) ?? .white))
                    .lineSpacing(fontSize * 0.12)
                    .opacity(l.old ? 0.55 : 1)
            }
        }
        .multilineTextAlignment(.center)
        .environment(\.layoutDirection, .rightToLeft)
        .fixedSize(horizontal: false, vertical: true)
    }

    private var native: some View {
        let bg = color(main?.background) ?? Color.black.opacity(settings.backgroundOpacity)
        return text(nil)
            .padding(.horizontal, fontSize * 0.3)
            .padding(.vertical, lines.count > 1 ? fontSize * 0.15 : 0)
            .frame(minWidth: cover.width, minHeight: cover.height)
            .background(RoundedRectangle(cornerRadius: 3, style: .continuous).fill(bg))
    }

    private var box: some View {
        text(chosenColor)
            .shadow(color: .black.opacity(0.85), radius: 2, x: 0, y: 1)
            .padding(.horizontal, fontSize * 0.7)
            .padding(.vertical, fontSize * 0.3)
            .frame(minWidth: cover.width, minHeight: cover.height)
            .background(RoundedRectangle(cornerRadius: fontSize * 0.35, style: .continuous).fill(Color.black.opacity(settings.backgroundOpacity)))
    }

    private var outline: some View {
        // A crisp outline from four offset copies plus a soft shadow.
        ZStack {
            ForEach(0 ..< 4, id: \.self) { k in
                text(.black).offset(x: [1.5, -1.5, 0, 0][k], y: [0, 0, 1.5, -1.5][k])
            }
            text(settings.textColor == "yellow" ? chosenColor : (settings.textStyle == "native" ? nil : chosenColor))
        }
        .shadow(color: .black.opacity(0.7), radius: 3, x: 0, y: 2)
    }
}

extension NSScreen {
    var displayID: CGDirectDisplayID? {
        (deviceDescription[NSDeviceDescriptionKey("NSScreenNumber")] as? NSNumber)?.uint32Value
    }
}
