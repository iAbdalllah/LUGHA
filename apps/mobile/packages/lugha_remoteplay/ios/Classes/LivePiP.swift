// LUGHA Remote Play (iPhone): a picture-in-picture window with the live game and the newest Arabic line under it.
// AGPL-3.0 — see ../../LICENSE.
//
// While it is open the player can use any other app and still see the translation, and iOS keeps LUGHA running (a
// PiP window is a supported way to work in the background). It opens by itself when LUGHA leaves the screen during a
// session, or from the PiP button. Frames are the decoder's own pixel buffers, shrunk and captioned with Core Image,
// then shown through an AVSampleBufferDisplayLayer — the system draws the window.

import AVFoundation
import AVKit
import CoreImage
import CoreMedia
import UIKit

final class LivePiP: NSObject, AVPictureInPictureControllerDelegate, AVPictureInPictureSampleBufferPlaybackDelegate {
    static let shared = LivePiP()

    private let layer = AVSampleBufferDisplayLayer()
    private var host: UIView?
    private var controller: AVPictureInPictureController?
    private let queue = DispatchQueue(label: "app.lugha.remoteplay.pip", qos: .userInitiated)
    private let ctx = CIContext(options: [.cacheIntermediates: false])
    private var pool: CVPixelBufferPool?
    private var lastPush = CFAbsoluteTime(0)
    private var busy = false
    private var caption = ""
    private var captionImage: CIImage?
    private var poolHeight = 0
    private(set) var on = false

    private static let width = 640, height = 360, captionHeight = 120

    static var supported: Bool { AVPictureInPictureController.isPictureInPictureSupported() }

    /// Sets the window up (called when a session starts). The layer must be in the view hierarchy for PiP to work;
    /// it sits behind everything, one point big.
    func prepare() {
        DispatchQueue.main.async { [self] in
            guard Self.supported, controller == nil, let window = UIApplication.shared.connectedScenes
                .compactMap({ ($0 as? UIWindowScene)?.windows.first(where: \.isKeyWindow) }).first else { return }
            let v = UIView(frame: CGRect(x: 0, y: 0, width: 1, height: 1))
            v.isUserInteractionEnabled = false
            v.alpha = 0.01
            layer.frame = v.bounds
            layer.videoGravity = .resizeAspect
            v.layer.addSublayer(layer)
            window.insertSubview(v, at: 0)
            host = v
            let c = AVPictureInPictureController(contentSource: .init(sampleBufferDisplayLayer: layer, playbackDelegate: self))
            c.delegate = self
            // Opens by itself when the player leaves LUGHA during a session.
            c.canStartPictureInPictureAutomaticallyFromInline = true
            c.requiresLinearPlayback = true
            controller = c
        }
    }

    /// Opens the window now (the PiP button).
    func start() { DispatchQueue.main.async { [self] in controller?.startPictureInPicture() } }

    /// Closes the window and takes the layer away (session ended).
    func teardown() {
        DispatchQueue.main.async { [self] in
            controller?.stopPictureInPicture()
            controller = nil
            layer.flushAndRemoveImage()
            host?.removeFromSuperview()
            host = nil
            on = false
        }
    }

    /// The Arabic shown under the picture (empty = picture only).
    func setCaption(_ text: String) {
        queue.async { [self] in
            guard text != caption else { return }
            caption = text
            captionImage = text.isEmpty ? nil : Self.render(text)
        }
    }

    /// A new decoded frame. At most ~12 a second are shown; the rest are skipped.
    func push(_ frame: CVPixelBuffer) {
        guard controller != nil else { return }
        let now = CFAbsoluteTimeGetCurrent()
        guard now - lastPush > 1.0 / 12 else { return }
        lastPush = now
        queue.async { [self] in
            guard !busy else { return }
            busy = true
            defer { busy = false }
            guard let out = compose(frame), let sample = Self.sample(out) else { return }
            DispatchQueue.main.async { [self] in
                if layer.status == .failed { layer.flush() }
                layer.enqueue(sample)
            }
        }
    }

    // ---- drawing ----

    private func compose(_ frame: CVPixelBuffer) -> CVPixelBuffer? {
        let w = Self.width, h = Self.height + (captionImage == nil ? 0 : Self.captionHeight)
        if pool == nil || poolHeight != h {
            poolHeight = h
            let attrs: [CFString: Any] = [kCVPixelBufferPixelFormatTypeKey: kCVPixelFormatType_32BGRA, kCVPixelBufferWidthKey: w,
                                          kCVPixelBufferHeightKey: h, kCVPixelBufferIOSurfacePropertiesKey: [:] as CFDictionary]
            var p: CVPixelBufferPool?
            CVPixelBufferPoolCreate(nil, nil, attrs as CFDictionary, &p)
            pool = p
        }
        guard let pool else { return nil }
        var out: CVPixelBuffer?
        CVPixelBufferPoolCreatePixelBuffer(nil, pool, &out)
        guard let out else { return nil }
        let src = CIImage(cvPixelBuffer: frame)
        let sx = CGFloat(w) / src.extent.width, sy = CGFloat(Self.height) / src.extent.height
        // Core Image's origin is bottom-left: the picture on top, the caption strip under it.
        var image = src.transformed(by: CGAffineTransform(scaleX: sx, y: sy)
            .translatedBy(x: 0, y: CGFloat(h - Self.height) / sy))
        if let cap = captionImage {
            image = cap.composited(over: image)
        }
        let canvas = CIImage(color: .black).cropped(to: CGRect(x: 0, y: 0, width: w, height: h))
        ctx.render(image.composited(over: canvas), to: out)
        return out
    }

    /// The caption strip: white Arabic, right to left, up to three lines, on black.
    private static func render(_ text: String) -> CIImage? {
        let size = CGSize(width: width, height: captionHeight)
        let format = UIGraphicsImageRendererFormat()
        format.scale = 1
        let img = UIGraphicsImageRenderer(size: size, format: format).image { c in
            UIColor.black.setFill()
            c.fill(CGRect(origin: .zero, size: size))
            let style = NSMutableParagraphStyle()
            style.alignment = .center
            style.baseWritingDirection = .rightToLeft
            style.lineBreakMode = .byTruncatingTail
            var font: CGFloat = 30
            var attributed: NSAttributedString
            let box = CGRect(x: 14, y: 8, width: size.width - 28, height: size.height - 16)
            // Shrink until it fits.
            repeat {
                attributed = NSAttributedString(string: text, attributes: [.font: UIFont.systemFont(ofSize: font, weight: .semibold),
                                                                           .foregroundColor: UIColor.white, .paragraphStyle: style])
                let need = attributed.boundingRect(with: CGSize(width: box.width, height: .greatestFiniteMagnitude),
                                                   options: [.usesLineFragmentOrigin], context: nil)
                if need.height <= box.height || font <= 18 { break }
                font -= 2
            } while true
            let need = attributed.boundingRect(with: CGSize(width: box.width, height: box.height), options: [.usesLineFragmentOrigin], context: nil)
            attributed.draw(with: CGRect(x: box.minX, y: box.minY + max(0, (box.height - need.height) / 2), width: box.width, height: box.height),
                            options: [.usesLineFragmentOrigin, .truncatesLastVisibleLine], context: nil)
        }
        return img.cgImage.map { CIImage(cgImage: $0) }
    }

    private static func sample(_ buffer: CVPixelBuffer) -> CMSampleBuffer? {
        var format: CMVideoFormatDescription?
        CMVideoFormatDescriptionCreateForImageBuffer(allocator: nil, imageBuffer: buffer, formatDescriptionOut: &format)
        guard let format else { return nil }
        var timing = CMSampleTimingInfo(duration: .invalid, presentationTimeStamp: CMClockGetTime(CMClockGetHostTimeClock()), decodeTimeStamp: .invalid)
        var out: CMSampleBuffer?
        CMSampleBufferCreateReadyWithImageBuffer(allocator: nil, imageBuffer: buffer, formatDescription: format, sampleTiming: &timing, sampleBufferOut: &out)
        if let out, let attachments = CMSampleBufferGetSampleAttachmentsArray(out, createIfNecessary: true), CFArrayGetCount(attachments) > 0 {
            let dict = unsafeBitCast(CFArrayGetValueAtIndex(attachments, 0), to: CFMutableDictionary.self)
            CFDictionarySetValue(dict, Unmanaged.passUnretained(kCMSampleAttachmentKey_DisplayImmediately).toOpaque(),
                                 Unmanaged.passUnretained(kCFBooleanTrue).toOpaque())
        }
        return out
    }

    // ---- AVPictureInPictureSampleBufferPlaybackDelegate: a live stream that is always playing ----

    func pictureInPictureController(_: AVPictureInPictureController, setPlaying _: Bool) {}
    func pictureInPictureControllerTimeRangeForPlayback(_: AVPictureInPictureController) -> CMTimeRange {
        CMTimeRange(start: .negativeInfinity, duration: .positiveInfinity)
    }
    func pictureInPictureControllerIsPlaybackPaused(_: AVPictureInPictureController) -> Bool { false }
    func pictureInPictureController(_: AVPictureInPictureController, didTransitionToRenderSize _: CMVideoDimensions) {}
    func pictureInPictureController(_: AVPictureInPictureController, skipByInterval _: CMTime, completion: @escaping () -> Void) { completion() }

    func pictureInPictureControllerDidStartPictureInPicture(_: AVPictureInPictureController) { on = true }
    func pictureInPictureControllerDidStopPictureInPicture(_: AVPictureInPictureController) { on = false }
}
