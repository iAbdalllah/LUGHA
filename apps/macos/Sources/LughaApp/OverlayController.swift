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

    func hide() { panel?.orderOut(nil) }

    func update(_ subtitle: Subtitle?) {
        withAnimation(.easeOut(duration: 0.12)) { model.subtitle = subtitle }
    }
}

@MainActor
final class OverlayModel: ObservableObject {
    @Published var subtitle: Subtitle?
    @Published var anchor: CGRect?
}

/// Broadcast-style subtitles drawn exactly over the English: each translated piece is centred on the box where OCR
/// found its English, at least as wide, with the text sized to the English line's height (scaled by Font size).
/// Without positions it falls back to the capture area's centre, or the Position setting.
struct SubtitleOverlayView: View {
    @ObservedObject var model: OverlayModel
    @ObservedObject var settings: AppSettings

    var body: some View {
        GeometryReader { geo in
            ZStack {
                if let s = model.subtitle {
                    ForEach(Array(placed(s, in: geo.size).enumerated()), id: \.offset) { _, p in
                        SubtitlePlate(item: p.item, fontSize: p.font, settings: settings, cover: p.cover)
                            .frame(maxWidth: p.maxWidth)
                            .position(p.center)
                    }
                    .transition(.opacity)
                    .id(s.text)
                }
            }
            .animation(.easeInOut(duration: 0.15), value: model.subtitle?.text)
        }
    }

    private struct Placed { let item: SubtitleItem; let center: CGPoint; let font: Double; let minWidth: CGFloat; let maxWidth: CGFloat; var cover: CGSize = .zero }

    private func placed(_ s: Subtitle, in size: CGSize) -> [Placed] {
        let scale = settings.fontSize / 30
        guard let a = model.anchor else {
            return s.items.prefix(1).map { Placed(item: $0, center: CGPoint(x: size.width / 2, y: size.height * settings.verticalPosition),
                                                  font: settings.fontSize, minWidth: 0, maxWidth: size.width * 0.7) }
        }
        return s.items.map { i in
            guard i.box.width > 0 else {
                return Placed(item: i, center: CGPoint(x: a.midX, y: a.midY), font: settings.fontSize, minWidth: 0, maxWidth: max(a.width, 480))
            }
            let box = CGRect(x: a.minX + i.box.minX * a.width, y: a.minY + i.box.minY * a.height,
                             width: i.box.width * a.width, height: i.box.height * a.height)
            let lineHeight = box.height / CGFloat(max(i.lines, 1))
            let font = min(max(Double(lineHeight) * 0.9 * scale, 13), 64)
            // Wide enough to hide the English; long Arabic may grow up to the whole area's width.
            return Placed(item: i, center: CGPoint(x: box.midX, y: box.midY), font: font,
                          minWidth: 0, maxWidth: max(box.width * 1.6, min(a.width, 900)),
                          cover: CGSize(width: box.width + 8, height: box.height + 4))
        }
    }
}

/// One translated piece, in the chosen text style:
/// - Native: the game's own background and text colours on a plate that exactly covers the English — it reads as if
///   the game drew Arabic itself;
/// - Box: white (or yellow) text with a soft shadow on a dark rounded plate;
/// - Outline: no plate, outlined text like film subtitles.
struct SubtitlePlate: View {
    let item: SubtitleItem
    let fontSize: Double
    @ObservedObject var settings: AppSettings
    /// Native style: the size of the English to cover.
    var cover: CGSize = .zero

    private var font: Font {
        settings.fontName.isEmpty ? .system(size: fontSize, weight: .semibold) : .custom(settings.fontName, size: fontSize).weight(.semibold)
    }
    private var chosenColor: Color { settings.textColor == "yellow" ? Color(red: 1, green: 0.86, blue: 0.25) : .white }
    private func color(_ c: RGB?) -> Color? { c.map { Color(.sRGB, red: $0.r, green: $0.g, blue: $0.b) } }

    var body: some View {
        switch settings.textStyle {
        case "native": native
        case "outline": outline
        default: box
        }
    }

    private var lines: some View {
        VStack(spacing: 2) {
            if let speaker = item.speaker {
                Text(speaker).font(.system(size: fontSize * 0.62, weight: .bold)).foregroundColor(Color(red: 0.91, green: 0.71, blue: 0.30))
            }
            Text(item.text).font(font).lineSpacing(fontSize * 0.15)
        }
        .multilineTextAlignment(.center)
        .environment(\.layoutDirection, .rightToLeft)
        .fixedSize(horizontal: false, vertical: true)
    }

    private var native: some View {
        let bg = color(item.background) ?? Color.black.opacity(settings.backgroundOpacity)
        let fg = color(item.foreground) ?? .white
        return lines
            .foregroundColor(fg)
            .padding(.horizontal, fontSize * 0.25)
            .frame(minWidth: cover.width, minHeight: cover.height)
            .background(RoundedRectangle(cornerRadius: 3, style: .continuous).fill(bg))
    }

    private var box: some View {
        lines
            .foregroundColor(chosenColor)
            .shadow(color: .black.opacity(0.85), radius: 2, x: 0, y: 1)
            .padding(.horizontal, fontSize * 0.8)
            .padding(.vertical, fontSize * 0.36)
            .background(RoundedRectangle(cornerRadius: fontSize * 0.35, style: .continuous).fill(Color.black.opacity(settings.backgroundOpacity)))
    }

    private var outline: some View {
        // A crisp outline from four offset copies plus a soft shadow.
        ZStack {
            ForEach(0 ..< 4, id: \.self) { k in
                lines.foregroundColor(.black).offset(x: [1.5, -1.5, 0, 0][k], y: [0, 0, 1.5, -1.5][k])
            }
            lines.foregroundColor(chosenColor)
        }
        .shadow(color: .black.opacity(0.7), radius: 3, x: 0, y: 2)
    }
}

extension NSScreen {
    var displayID: CGDirectDisplayID? {
        (deviceDescription[NSDeviceDescriptionKey("NSScreenNumber")] as? NSNumber)?.uint32Value
    }
}
