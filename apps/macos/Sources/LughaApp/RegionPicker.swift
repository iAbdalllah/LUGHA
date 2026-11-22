import AppKit

/// Full-screen translucent overlay: drag a rectangle over the game's subtitle area.
/// Returns the rect in points with a top-left origin on that display (what ScreenCaptureKit expects).
@MainActor
enum RegionPicker {
    private static var window: NSWindow?

    static func pick(completion: @escaping (CGRect?, CGDirectDisplayID?) -> Void) {
        let screen = NSScreen.screens.first { $0.frame.contains(NSEvent.mouseLocation) } ?? NSScreen.main ?? NSScreen.screens[0]
        let w = NSWindow(contentRect: screen.frame, styleMask: .borderless, backing: .buffered, defer: false)
        w.level = .screenSaver
        w.isOpaque = false
        w.backgroundColor = NSColor.black.withAlphaComponent(0.35)
        let view = PickerView(frame: NSRect(origin: .zero, size: screen.frame.size))
        view.onDone = { rect in
            w.orderOut(nil)
            window = nil
            guard let rect, rect.width > 20, rect.height > 10 else { return completion(nil, nil) }
            // NSView is bottom-left origin; convert to top-left for the display.
            let flipped = CGRect(x: rect.minX, y: screen.frame.height - rect.maxY, width: rect.width, height: rect.height)
            completion(flipped, screen.displayID)
        }
        w.contentView = view
        w.makeKeyAndOrderFront(nil)
        NSApp.activate(ignoringOtherApps: true)
        window = w
    }

    final class PickerView: NSView {
        var onDone: ((CGRect?) -> Void)?
        private var start: NSPoint?
        private var current: NSRect?

        override var acceptsFirstResponder: Bool { true }
        override func viewDidMoveToWindow() { window?.makeFirstResponder(self) }

        override func mouseDown(with e: NSEvent) { start = convert(e.locationInWindow, from: nil) }

        override func mouseDragged(with e: NSEvent) {
            guard let s = start else { return }
            let p = convert(e.locationInWindow, from: nil)
            current = NSRect(x: min(s.x, p.x), y: min(s.y, p.y), width: abs(p.x - s.x), height: abs(p.y - s.y))
            needsDisplay = true
        }

        override func mouseUp(with e: NSEvent) { onDone?(current) }

        override func keyDown(with e: NSEvent) {
            if e.keyCode == 53 { onDone?(nil) } // Esc
        }

        override func draw(_ dirtyRect: NSRect) {
            let hint = L.t("Drag to select the subtitle area · Esc to cancel") as NSString
            hint.draw(at: NSPoint(x: 40, y: bounds.height - 60), withAttributes: [.font: NSFont.systemFont(ofSize: 20, weight: .semibold), .foregroundColor: NSColor.white])
            guard let r = current else { return }
            NSColor.clear.setFill()
            r.fill(using: .copy)
            let path = NSBezierPath(rect: r)
            path.lineWidth = 2
            NSColor(red: 0.91, green: 0.71, blue: 0.30, alpha: 1).setStroke()
            path.setLineDash([8, 5], count: 2, phase: 0)
            path.stroke()
        }
    }
}
