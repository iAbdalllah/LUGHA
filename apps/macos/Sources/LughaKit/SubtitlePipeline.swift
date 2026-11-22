import Foundation

public struct Subtitle: Equatable, Sendable {
    public let text: String
    public let speaker: String?
    public let source: String
    public let score: Double
}

/// OCR text in, subtitle to show out. Pure state machine (testable with a fake clock):
/// - a line must be seen on `stableFrames` consecutive frames before it shows (avoids half-drawn text);
/// - the subtitle hides after `hideAfter` seconds with no recognized text;
/// - identical consecutive OCR results cost nothing.
public final class SubtitlePipeline: @unchecked Sendable {
    public var stableFrames: Int
    public var hideAfter: TimeInterval
    public private(set) var current: Subtitle?
    public private(set) var history: [Subtitle] = []
    public var historyLimit = 50

    private let lookup: (String) async -> Hit?
    private var candidate: String?
    private var candidateCount = 0
    private var lastTextAt: Date?
    private var shownSource: String?

    public init(stableFrames: Int = 2, hideAfter: TimeInterval = 1.2, lookup: @escaping (String) async -> Hit?) {
        self.stableFrames = stableFrames
        self.hideAfter = hideAfter
        self.lookup = lookup
    }

    /// Feed one frame's OCR lines. Returns true when `current` changed.
    @discardableResult
    public func ingest(lines: [String], at now: Date = Date()) async -> Bool {
        let text = lines.map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }.joined(separator: "\n")
        guard !text.isEmpty else {
            candidate = nil
            candidateCount = 0
            if current != nil, let last = lastTextAt, now.timeIntervalSince(last) >= hideAfter {
                current = nil
                shownSource = nil
                return true
            }
            return false
        }
        lastTextAt = now
        if text == shownSource { return false }
        if text == candidate { candidateCount += 1 } else { candidate = text; candidateCount = 1 }
        guard candidateCount >= stableFrames else { return false }

        candidateCount = 0
        shownSource = text
        guard let hit = await lookup(text) else {
            // Text we have no translation for: hide rather than show a stale line.
            let changed = current != nil
            current = nil
            return changed
        }
        let sub = Subtitle(text: hit.target, speaker: hit.speaker, source: text, score: hit.score)
        if sub != current {
            current = sub
            history.insert(sub, at: 0)
            if history.count > historyLimit { history.removeLast() }
            return true
        }
        return false
    }

    public func clear() {
        current = nil
        shownSource = nil
        candidate = nil
        candidateCount = 0
    }
}
