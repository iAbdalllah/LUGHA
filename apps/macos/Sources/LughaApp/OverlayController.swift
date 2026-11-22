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

    func show() {
        if panel == nil {
            let screen = NSScreen.screens.first { $0.displayID == settings.displayID } ?? NSScreen.main ?? NSScreen.screens[0]
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
}

struct SubtitleOverlayView: View {
    @ObservedObject var model: OverlayModel
    @ObservedObject var settings: AppSettings

    var body: some View {
        GeometryReader { geo in
            if let s = model.subtitle {
                (Text(s.speaker.map { "\($0): " } ?? "").foregroundColor(Color(red: 0.91, green: 0.71, blue: 0.30))
                    + Text(s.text).foregroundColor(.white))
                    .font(.system(size: settings.fontSize, weight: .semibold))
                    .multilineTextAlignment(.center)
                    .environment(\.layoutDirection, .rightToLeft)
                    .padding(.horizontal, 24).padding(.vertical, 10)
                    .background(RoundedRectangle(cornerRadius: 12).fill(Color.black.opacity(settings.backgroundOpacity)))
                    .frame(maxWidth: geo.size.width * 0.7)
                    .position(x: geo.size.width / 2, y: geo.size.height * settings.verticalPosition)
                    .transition(.opacity)
            }
        }
    }
}

extension NSScreen {
    var displayID: CGDirectDisplayID? {
        (deviceDescription[NSDeviceDescriptionKey("NSScreenNumber")] as? NSNumber)?.uint32Value
    }
}
