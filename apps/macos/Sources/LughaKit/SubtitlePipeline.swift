import Foundation

public struct Subtitle: Equatable, Sendable {
    public let text: String
    public let speaker: String?
    public let source: String
    public let score: Double
    /// The line's id in its package (= the server's translation id) — what "report this line" sends.
    public let entryId: Int64
    public let packageId: String

    public init(text: String, speaker: String?, source: String, score: Double, entryId: Int64 = 0, packageId: String = "") {
        self.text = text
        self.speaker = speaker
        self.source = source
        self.score = score
        self.entryId = entryId
        self.packageId = packageId
    }
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
    /// When the capture area became empty (nil while text is there). Drives [tick].
    private var emptySince: Date?

    public init(stableFrames: Int = 2, hideAfter: TimeInterval = 1.2, lookup: @escaping (String) async -> Hit?) {
        self.stableFrames = stableFrames
        self.hideAfter = hideAfter
        self.lookup = lookup
    }

    /// Feed one frame's OCR lines. Returns true when `current` changed.
    @discardableResult
    public func ingest(lines: [String], at now: Date = Date()) async -> Bool {
        let cleaned = lines.map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }
        let text = cleaned.joined(separator: "\n")
        guard !text.isEmpty else {
            candidate = nil
            candidateCount = 0
            if emptySince == nil { emptySince = now }
            if current != nil, let last = lastTextAt, now.timeIntervalSince(last) >= hideAfter {
                current = nil
                shownSource = nil
                return true
            }
            return false
        }
        lastTextAt = now
        emptySince = nil
        if text == shownSource { return false }
        if text == candidate { candidateCount += 1 } else { candidate = text; candidateCount = 1 }
        guard candidateCount >= stableFrames else { return false }

        candidateCount = 0
        shownSource = text
        let sub: Subtitle
        if let hit = await lookup(text) {
            // A subtitle: the lines together are one sentence.
            sub = Subtitle(text: hit.target, speaker: hit.speaker, source: text, score: hit.score, entryId: hit.entryId, packageId: hit.packageId)
        } else if cleaned.count > 1, let menu = await lookupEachLine(cleaned) {
            // A menu or list: each line is its own entry. Show the ones we know, in screen order.
            sub = menu
        } else {
            // Text we have no translation for: hide rather than show a stale line.
            let changed = current != nil
            current = nil
            return changed
        }
        if sub != current {
            current = sub
            history.insert(sub, at: 0)
            if history.count > historyLimit { history.removeLast() }
            return true
        }
        return false
    }

    /// Looks up each OCR line on its own (menus, option lists). nil when none is known.
    private func lookupEachLine(_ lines: [String]) async -> Subtitle? {
        var hits: [(String, Hit)] = []
        for line in lines.prefix(12) {
            if let h = await lookup(line), !hits.contains(where: { $0.1.entryId == h.entryId }) { hits.append((line, h)) }
        }
        guard let first = hits.first else { return nil }
        return Subtitle(text: hits.map(\.1.target).joined(separator: "  ·  "), speaker: nil,
                        source: hits.map(\.0).joined(separator: "\n"), score: hits.map(\.1.score).min() ?? first.1.score,
                        entryId: first.1.entryId, packageId: first.1.packageId)
    }

    /// Call a few times a second. Screen capture only delivers frames when the picture changes, so after the English
    /// line disappears there may be no further frame; this hides the Arabic once the area has been empty long enough.
    /// Returns true when `current` changed.
    @discardableResult
    public func tick(at now: Date = Date()) -> Bool {
        guard current != nil, let since = emptySince, now.timeIntervalSince(since) >= hideAfter else { return false }
        current = nil
        shownSource = nil
        return true
    }

    public func clear() {
        current = nil
        shownSource = nil
        candidate = nil
        candidateCount = 0
        emptySince = nil
    }
}
