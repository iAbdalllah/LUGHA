import CoreGraphics
import Foundation
import Vision

/// One recognized line. `box` is a fraction of the captured image (0–1, origin top-left).
public struct OCRLine: Equatable, Sendable {
    public let text: String
    public let box: CGRect
    public init(text: String, box: CGRect = .zero) { self.text = text; self.box = box }
}

/// On-device text recognition (Apple Vision). This is OCR of the game's original text — not translation;
/// frames never leave the Mac.
public struct OCRService: Sendable {
    public var languages: [String]
    public var fast: Bool

    /// Wider frames are scaled down first: a Retina capture of a whole window is 4000+ px wide, and recognition time
    /// grows with pixels while game text stays readable at this size.
    public var maxWidth: Int

    public init(languages: [String] = ["en-US"], fast: Bool = false, maxWidth: Int = 2200) {
        self.languages = languages
        self.fast = fast
        self.maxWidth = maxWidth
    }

    static func downscaled(_ image: CGImage, maxWidth: Int) -> CGImage {
        guard image.width > maxWidth else { return image }
        let w = maxWidth, h = max(1, Int(Double(image.height) * Double(maxWidth) / Double(image.width)))
        guard let ctx = CGContext(data: nil, width: w, height: h, bitsPerComponent: 8, bytesPerRow: 0,
                                  space: CGColorSpace(name: CGColorSpace.sRGB)!, bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue) else { return image }
        ctx.interpolationQuality = .high
        ctx.draw(image, in: CGRect(x: 0, y: 0, width: w, height: h))
        return ctx.makeImage() ?? image
    }

    /// Recognized lines, top to bottom.
    public func recognize(_ image: CGImage) async throws -> [String] {
        try await recognizeLines(image).map(\.text)
    }

    /// Recognized lines with where they are: `box` is a fraction of the image, origin top-left.
    public func recognizeLines(_ image: CGImage) async throws -> [OCRLine] {
        let image = Self.downscaled(image, maxWidth: maxWidth)
        return try await withCheckedThrowingContinuation { cont in
            let request = VNRecognizeTextRequest { req, error in
                if let error { return cont.resume(throwing: error) }
                let obs = (req.results as? [VNRecognizedTextObservation]) ?? []
                let lines = obs
                    .sorted { $0.boundingBox.midY > $1.boundingBox.midY } // Vision's origin is bottom-left
                    .compactMap { o -> OCRLine? in
                        guard let c = o.topCandidates(1).first, c.confidence >= 0.3 else { return nil }
                        let b = o.boundingBox
                        return OCRLine(text: c.string, box: CGRect(x: b.minX, y: 1 - b.maxY, width: b.width, height: b.height))
                    }
                cont.resume(returning: lines)
            }
            request.recognitionLevel = fast ? .fast : .accurate
            request.recognitionLanguages = languages
            request.usesLanguageCorrection = false // character names and invented words must not be "corrected"
            do {
                try VNImageRequestHandler(cgImage: image).perform([request])
            } catch {
                cont.resume(throwing: error)
            }
        }
    }
}
