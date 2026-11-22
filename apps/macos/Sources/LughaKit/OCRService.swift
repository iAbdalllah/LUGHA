import CoreGraphics
import Foundation
import Vision

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
        try await withCheckedThrowingContinuation { cont in
            let request = VNRecognizeTextRequest { req, error in
                if let error { return cont.resume(throwing: error) }
                let obs = (req.results as? [VNRecognizedTextObservation]) ?? []
                let lines = obs
                    .sorted { $0.boundingBox.midY > $1.boundingBox.midY } // Vision's origin is bottom-left
                    .compactMap { $0.topCandidates(1).first }
                    .filter { $0.confidence >= 0.3 }
                    .map(\.string)
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
