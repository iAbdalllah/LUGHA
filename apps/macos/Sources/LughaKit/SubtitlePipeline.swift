import CoreGraphics
import Foundation

/// One translated piece of text and where its English is, so the Arabic can be drawn exactly over it.
public struct SubtitleItem: Equatable, Sendable {
    public let text: String
    public let speaker: String?
    /// Where the English is, as a fraction of the capture area (0–1, origin top-left).
    public let box: CGRect
    /// How many OCR lines the English took (to size the Arabic to one line's height).
    public let lines: Int
    /// The game's own colours around and in the English (Native style); nil until sampled from the frame.
    public var background: RGB?
    public var foreground: RGB?

    public init(text: String, speaker: String? = nil, box: CGRect = .zero, lines: Int = 1, background: RGB? = nil, foreground: RGB? = nil) {
        self.text = text
        self.speaker = speaker
        self.box = box
        self.lines = lines
        self.background = background
        self.foreground = foreground
    }
}

public struct Subtitle: Equatable, Sendable {
    /// A copy whose items carry colours sampled from the frame they were read in.
    public func colored(by sampler: FrameSampler) -> Subtitle {
        var s = self
        s.items = items.map { i in
            var i = i
            if i.box.width > 0, let c = sampler.textColors(in: i.box) { i.background = c.background; i.foreground = c.text }
            return i
        }
        return s
    }

    /// All items as one line (history, reports, the TV's plain-text fallback).
    public let text: String
    public let speaker: String?
    public let source: String
    public let score: Double
    /// The line's id in its package (= the server's translation id) — what "report this line" sends.
    public let entryId: Int64
    public let packageId: String
    /// Each translated piece with its position: one for a subtitle, one per known label for a menu.
    public var items: [SubtitleItem]

    public init(text: String, speaker: String?, source: String, score: Double, entryId: Int64 = 0, packageId: String = "", items: [SubtitleItem]? = nil) {
        self.text = text
        self.speaker = speaker
        self.source = source
        self.score = score
        self.entryId = entryId
        self.packageId = packageId
        self.items = items ?? [SubtitleItem(text: text, speaker: speaker)]
    }
}

/// OCR lines in, translated text to show out. Pure state machine (testable with a fake clock):
/// - each frame is matched first as one sentence (a subtitle), else line by line (a menu);
/// - a change (a new match, or "nothing we know") must hold for `stableFrames` frames before it shows, so half-drawn
///   text doesn't flash — stability is judged on the match, not the raw text, so noisy HUD text doesn't block it;
/// - when the area is empty the Arabic stays through short gaps and hides after `hideAfter` (see [tick]);
/// - the same OCR text again costs no lookup.
public final class SubtitlePipeline: @unchecked Sendable {
    public var stableFrames: Int
    public var hideAfter: TimeInterval
    public private(set) var current: Subtitle?
    public private(set) var history: [Subtitle] = []
    public var historyLimit = 50

    private let lookup: (String) async -> Hit?
    private var candidateKey: String?
    private var candidateCount = 0
    /// When the capture area became empty (nil while text is there). Drives [tick].
    private var emptySince: Date?
    private var lastText: String?
    private var lastResult: Subtitle?
    /// Last frame that had any text: short gaps are measured from here.
    private var lastTextAt: Date?

    public init(stableFrames: Int = 2, hideAfter: TimeInterval = 1.2, lookup: @escaping (String) async -> Hit?) {
        self.stableFrames = stableFrames
        self.hideAfter = hideAfter
        self.lookup = lookup
    }

    /// Feed one frame's OCR lines (text only). Returns true when `current` changed.
    @discardableResult
    public func ingest(lines: [String], at now: Date = Date()) async -> Bool {
        await ingest(ocr: lines.map { OCRLine(text: $0) }, at: now)
    }

    /// Feed one frame's OCR lines with positions. Returns true when `current` changed.
    @discardableResult
    public func ingest(ocr: [OCRLine], at now: Date = Date()) async -> Bool {
        let lines = ocr.map { OCRLine(text: $0.text.trimmingCharacters(in: .whitespaces), box: $0.box) }.filter { !$0.text.isEmpty }
        guard !lines.isEmpty else {
            candidateKey = nil
            candidateCount = 0
            if emptySince == nil { emptySince = lastTextAt ?? now }
            return tick(at: now)
        }
        emptySince = nil
        lastTextAt = now

        let text = lines.map(\.text).joined(separator: "\n")
        let result: Subtitle?
        if text == lastText {
            result = lastResult
        } else {
            result = await match(lines, text: text)
            lastText = text
            lastResult = result
        }

        // Same translation still on screen: keep it, follow the English if it moved.
        if let r = result, let c = current, r.text == c.text {
            candidateKey = nil
            candidateCount = 0
            if moved(r.items, c.items) { current = r; return true }
            return false
        }
        let key = result?.text ?? "\u{2205}" // ∅ = text we have no translation for
        if key == candidateKey { candidateCount += 1 } else { candidateKey = key; candidateCount = 1 }
        guard candidateCount >= stableFrames else { return false }
        candidateKey = nil
        candidateCount = 0

        guard let r = result else {
            // Only unknown text left: the English we translated is gone.
            let changed = current != nil
            current = nil
            return changed
        }
        current = r
        history.insert(r, at: 0)
        if history.count > historyLimit { history.removeLast() }
        return true
    }

    /// Call a few times a second. Screen capture only delivers frames when the picture changes, so after the English
    /// line disappears there may be no further frame; this hides the Arabic once the area has been empty long enough.
    /// Returns true when `current` changed.
    @discardableResult
    public func tick(at now: Date = Date()) -> Bool {
        guard current != nil, let since = emptySince, now.timeIntervalSince(since) >= hideAfter else { return false }
        current = nil
        return true
    }

    public func clear() {
        current = nil
        candidateKey = nil
        candidateCount = 0
        emptySince = nil
        lastText = nil
        lastResult = nil
    }

    // MARK: matching

    private func match(_ lines: [OCRLine], text: String) async -> Subtitle? {
        if let hit = await lookup(text) {
            // A subtitle: the lines together are one sentence.
            let box = lines.map(\.box).reduce(CGRect.null) { $0.union($1) }
            let item = SubtitleItem(text: hit.target, speaker: hit.speaker, box: box.isNull ? .zero : box, lines: lines.count)
            return Subtitle(text: hit.target, speaker: hit.speaker, source: text, score: hit.score, entryId: hit.entryId, packageId: hit.packageId, items: [item])
        }
        guard lines.count > 1 else { return nil }
        // A menu or list: each line is its own entry. Keep the known ones, in screen order, each where it is.
        var hits: [(OCRLine, Hit)] = []
        for line in lines.prefix(16) {
            if let h = await lookup(line.text), !hits.contains(where: { $0.1.entryId == h.entryId }) { hits.append((line, h)) }
        }
        guard let first = hits.first else { return nil }
        return Subtitle(text: hits.map(\.1.target).joined(separator: "  ·  "), speaker: nil,
                        source: hits.map(\.0.text).joined(separator: "\n"), score: hits.map(\.1.score).min() ?? first.1.score,
                        entryId: first.1.entryId, packageId: first.1.packageId,
                        items: hits.map { SubtitleItem(text: $0.1.target, speaker: $0.1.speaker, box: $0.0.box, lines: 1) })
    }

    /// True when the English moved enough to re-place the Arabic (more than ~1.5% of the area).
    private func moved(_ a: [SubtitleItem], _ b: [SubtitleItem]) -> Bool {
        guard a.count == b.count else { return true }
        return zip(a, b).contains { abs($0.box.midX - $1.box.midX) > 0.015 || abs($0.box.midY - $1.box.midY) > 0.015 }
    }
}
