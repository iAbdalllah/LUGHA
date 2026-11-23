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

    public init(languages: [String] = ["en-US"], fast: Bool = false) {
        self.languages = languages
        self.fast = fast
    }

    /// Recognized lines, top to bottom.
    public func recognize(_ image: CGImage) async throws -> [String] {
        try await recognizeLines(image).map(\.text)
    }

    /// Recognized lines with where they are: `box` is a fraction of the image, origin top-left.
    public func recognizeLines(_ image: CGImage) async throws -> [OCRLine] {
        try await withCheckedThrowingContinuation { cont in
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
