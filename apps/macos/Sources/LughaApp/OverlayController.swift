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

/// Broadcast-style subtitle: white semibold Arabic with a soft shadow on a dark rounded plate that hides the English
/// underneath; speaker names in amber; fades in and out.
struct SubtitleOverlayView: View {
    @ObservedObject var model: OverlayModel
    @ObservedObject var settings: AppSettings

    var body: some View {
        GeometryReader { geo in
            ZStack {
                if let s = model.subtitle {
                    let anchor = model.anchor
                    let width = anchor.map { max($0.width, min(geo.size.width * 0.5, 720)) } ?? geo.size.width * 0.7
                    SubtitlePlate(subtitle: s, fontSize: settings.fontSize, opacity: settings.backgroundOpacity)
                        .frame(maxWidth: width)
                        .position(x: anchor?.midX ?? geo.size.width / 2,
                                  y: anchor?.midY ?? geo.size.height * settings.verticalPosition)
                        .transition(.opacity)
                        .id(s.text)
                }
            }
            .animation(.easeInOut(duration: 0.15), value: model.subtitle?.text)
        }
    }
}

struct SubtitlePlate: View {
    let subtitle: Subtitle
    let fontSize: Double
    let opacity: Double

    var body: some View {
        VStack(spacing: 2) {
            if let speaker = subtitle.speaker {
                Text(speaker)
                    .font(.system(size: fontSize * 0.62, weight: .bold))
                    .foregroundColor(Color(red: 0.91, green: 0.71, blue: 0.30))
            }
            Text(subtitle.text)
                .font(.system(size: fontSize, weight: .semibold))
                .foregroundColor(.white)
                .lineSpacing(fontSize * 0.18)
        }
        .multilineTextAlignment(.center)
        .environment(\.layoutDirection, .rightToLeft)
        .shadow(color: .black.opacity(0.85), radius: 2, x: 0, y: 1)
        .padding(.horizontal, fontSize * 0.8)
        .padding(.vertical, fontSize * 0.36)
        .background(
            RoundedRectangle(cornerRadius: fontSize * 0.35, style: .continuous)
                .fill(Color.black.opacity(opacity))
        )
        .fixedSize(horizontal: false, vertical: true)
    }
}

extension NSScreen {
    var displayID: CGDirectDisplayID? {
        (deviceDescription[NSDeviceDescriptionKey("NSScreenNumber")] as? NSNumber)?.uint32Value
    }
}
