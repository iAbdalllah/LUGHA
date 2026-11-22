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
        config.width = max(Int(region.width * 2), 64)
        config.height = max(Int(region.height * 2), 32)
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
