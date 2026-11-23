import CoreGraphics
import CoreImage
import CoreMedia
import Foundation
import ScreenCaptureKit

/// Captures one region of one display at a low frame rate with ScreenCaptureKit. Requires the user's
/// Screen Recording permission (System Settings › Privacy & Security). Only the chosen region is captured.
public final class CaptureService: NSObject, SCStreamOutput, SCStreamDelegate, @unchecked Sendable {
    public typealias FrameHandler = @Sendable (CGImage) -> Void

    private var stream: SCStream?
    private let queue = DispatchQueue(label: "app.lugha.capture")
    private let ciContext = CIContext()
    private var onFrame: FrameHandler?
    private var lastHash: UInt64 = 0
    public private(set) var isRunning = false

    public static func hasPermission() -> Bool { CGPreflightScreenCaptureAccess() }

    @discardableResult
    public static func requestPermission() -> Bool { CGRequestScreenCaptureAccess() }

    /// - Parameters:
    ///   - displayID: the display to capture (nil = main)
    ///   - region: in points, origin top-left of that display
    ///   - fps: frames per second (6–10 is plenty for subtitles)
    public func start(displayID: CGDirectDisplayID?, region: CGRect, fps: Int = 8, excludingWindowIDs: [CGWindowID] = [], onFrame: @escaping FrameHandler) async throws {
        await stop()
        let content = try await SCShareableContent.excludingDesktopWindows(false, onScreenWindowsOnly: true)
        guard let display = content.displays.first(where: { $0.displayID == (displayID ?? CGMainDisplayID()) }) ?? content.displays.first else {
            throw NSError(domain: "LUGHA", code: 1, userInfo: [NSLocalizedDescriptionKey: "No display available"])
        }
        // Never capture our own overlay, or we would read our Arabic subtitle back.
        let excluded = content.windows.filter { excludingWindowIDs.contains($0.windowID) }
        let filter = SCContentFilter(display: display, excludingWindows: excluded)

        let config = SCStreamConfiguration()
        config.sourceRect = region
        (config.width, config.height) = Self.outputSize(region.size)
        config.minimumFrameInterval = CMTime(value: 1, timescale: CMTimeScale(max(fps, 1)))
        config.showsCursor = false
        config.queueDepth = 3

        self.onFrame = onFrame
        let stream = SCStream(filter: filter, configuration: config, delegate: self)
        try stream.addStreamOutput(self, type: .screen, sampleHandlerQueue: queue)
        try await stream.startCapture()
        self.stream = stream
        isRunning = true
    }

    /// An app window LUGHA can read from.
    public struct AppWindow: Identifiable, Hashable, Sendable {
        public let id: CGWindowID
        public let bundleID: String
        public let appName: String
        public let title: String
        /// In screen points, origin top-left.
        public let frame: CGRect
    }

    /// Normal-level windows of other apps, largest first (one per app is what the picker shows).
    public static func appWindows() async throws -> [AppWindow] {
        let content = try await SCShareableContent.excludingDesktopWindows(true, onScreenWindowsOnly: false)
        let mine = Bundle.main.bundleIdentifier
        return content.windows
            .filter { $0.windowLayer == 0 && $0.frame.width >= 320 && $0.frame.height >= 180 }
            .compactMap { w -> AppWindow? in
                guard let app = w.owningApplication, app.bundleIdentifier != mine, !app.bundleIdentifier.isEmpty else { return nil }
                return AppWindow(id: w.windowID, bundleID: app.bundleIdentifier, appName: app.applicationName, title: w.title ?? "", frame: w.frame)
            }
            .sorted { $0.frame.width * $0.frame.height > $1.frame.width * $1.frame.height }
    }

    /// PS Remote Play for Mac, matched by bundle id or name so a renamed build still matches.
    public static func isRemotePlay(_ w: AppWindow) -> Bool {
        w.bundleID.lowercased().contains("remoteplay") || w.appName.lowercased().contains("remote play")
    }

    /// Window mode: captures `area` (fractions of the window, origin top-left) of the largest window of `bundleID`.
    /// The window may be covered by other windows (not minimised); only its pixels are captured, never our overlay.
    @discardableResult
    public func startWindow(bundleID: String, area: CGRect, fps: Int = 8, onFrame: @escaping FrameHandler) async throws -> AppWindow {
        await stop()
        let content = try await SCShareableContent.excludingDesktopWindows(true, onScreenWindowsOnly: false)
        let candidates = content.windows.filter { $0.owningApplication?.bundleIdentifier == bundleID && $0.windowLayer == 0 && $0.frame.width >= 320 }
        // Apps like PS Remote Play keep hidden helper windows: prefer the visible one (on screen, maybe covered),
        // then the largest. Off-screen windows give no picture.
        let visible = candidates.filter(\.isOnScreen)
        guard let window = (visible.isEmpty ? candidates : visible).max(by: { $0.frame.width * $0.frame.height < $1.frame.width * $1.frame.height }) else {
            throw NSError(domain: "LUGHA", code: 2, userInfo: [NSLocalizedDescriptionKey: "window_not_found"])
        }
        let size = window.frame.size
        let a = area.intersection(CGRect(x: 0, y: 0, width: 1, height: 1))
        let rect = CGRect(x: a.minX * size.width, y: a.minY * size.height, width: a.width * size.width, height: a.height * size.height).integral

        let config = SCStreamConfiguration()
        config.sourceRect = rect
        (config.width, config.height) = Self.outputSize(rect.size)
        config.minimumFrameInterval = CMTime(value: 1, timescale: CMTimeScale(max(fps, 1)))
        config.showsCursor = false
        config.queueDepth = 3

        self.onFrame = onFrame
        let stream = SCStream(filter: SCContentFilter(desktopIndependentWindow: window), configuration: config, delegate: self)
        try stream.addStreamOutput(self, type: .screen, sampleHandlerQueue: queue)
        try await stream.startCapture()
        self.stream = stream
        isRunning = true
        return AppWindow(id: window.windowID, bundleID: bundleID, appName: window.owningApplication?.applicationName ?? bundleID, title: window.title ?? "", frame: window.frame)
    }

    /// Frames at Retina resolution, but at most 2200 px wide: a whole 5K window is far more than OCR needs, and every
    /// extra pixel costs capture, conversion and recognition time on each frame.
    static func outputSize(_ points: CGSize, maxWidth: Double = 2200) -> (Int, Int) {
        let scale = min(2, maxWidth / max(points.width, 1))
        return (max(Int(points.width * scale), 64), max(Int(points.height * scale), 32))
    }

    public func stop() async {
        if let stream { try? await stream.stopCapture() }
        stream = nil
        isRunning = false
        lastHash = 0
    }

    public func stream(_ stream: SCStream, didOutputSampleBuffer sampleBuffer: CMSampleBuffer, of type: SCStreamOutputType) {
        guard type == .screen, sampleBuffer.isValid, let pixels = sampleBuffer.imageBuffer else { return }
        let image = CIImage(cvPixelBuffer: pixels)
        guard let cg = ciContext.createCGImage(image, from: image.extent) else { return }
        // Skip OCR when the region hasn't visibly changed.
        let h = Self.averageHash(cg)
        if h == lastHash { return }
        lastHash = h
        onFrame?(cg)
    }

    public func stream(_ stream: SCStream, didStopWithError error: Error) {
        isRunning = false
    }

    /// 64-bit average hash of a 16×4 grayscale thumbnail (subtitles are wide).
    public static func averageHash(_ image: CGImage) -> UInt64 {
        let w = 16, h = 4
        var pixels = [UInt8](repeating: 0, count: w * h)
        guard let ctx = CGContext(data: &pixels, width: w, height: h, bitsPerComponent: 8, bytesPerRow: w,
                                  space: CGColorSpaceCreateDeviceGray(), bitmapInfo: CGImageAlphaInfo.none.rawValue) else { return 0 }
        ctx.interpolationQuality = .low
        ctx.draw(image, in: CGRect(x: 0, y: 0, width: w, height: h))
        let avg = pixels.reduce(0) { $0 + Int($1) } / pixels.count
        return pixels.enumerated().reduce(UInt64(0)) { acc, p in p.element > avg ? acc | (1 << UInt64(p.offset)) : acc }
    }
}
