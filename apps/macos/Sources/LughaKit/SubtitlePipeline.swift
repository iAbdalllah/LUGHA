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

/// OCR lines in, translated text to show out. Pure state machine (testable with a fake clock).
///
/// Each frame is matched first as one sentence (a subtitle), else line by line (a menu). Every translated piece is then
/// tracked on its own, so animated menus — where OCR reads a slightly different set of items each frame — don't blink:
/// - a piece appears once it was read on `stableFrames` frames (no half-drawn text);
/// - it stays while it keeps being read, following its English if it moves;
/// - it goes only after frames *without* it for `removeAfter` (`hideAfter` when the area is empty). A static screen sends
///   no frames at all, so a line still on screen is never dropped just because time passed (see [tick]);
/// - the same OCR text again costs no lookup.
public final class SubtitlePipeline: @unchecked Sendable {
    public var stableFrames: Int
    public var hideAfter: TimeInterval
    /// How long a piece must be missing from the frames before it goes (OCR misses a frame now and then).
    public var removeAfter: TimeInterval = 0.8
    public private(set) var current: Subtitle?
    public private(set) var history: [Subtitle] = []
    public var historyLimit = 50

    private struct Track {
        var item: SubtitleItem
        var hit: Hit
        var source: String
        var seen: Int
        var lastSeen: Date
        var missing: Bool
        var order: Int
    }

    private let lookup: (String) async -> Hit?
    private var tracks: [String: Track] = [:]
    private var lastFrameEmpty = false
    private var lastText: String?
    private var lastResult: [(SubtitleItem, Hit, String)] = []

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
        lastFrameEmpty = lines.isEmpty
        var found: [(SubtitleItem, Hit, String)] = []
        if !lines.isEmpty {
            let text = lines.map(\.text).joined(separator: "\n")
            if text == lastText {
                found = lastResult
            } else {
                found = await match(lines, text: text)
                lastText = text
                lastResult = found
            }
        }
        // Update every tracked piece: read in this frame, or missing from it.
        let foundKeys = Set(found.map(\.0.text))
        for k in tracks.keys where !foundKeys.contains(k) { tracks[k]!.missing = true }
        let nextOrder = (tracks.values.map(\.order).max() ?? -1) + 1
        for (n, (item, hit, source)) in found.enumerated() {
            if var t = tracks[item.text] {
                // Keep the order from first sight: OCR lists lines in a slightly different order on some frames.
                t.item = item; t.hit = hit; t.source = source; t.seen += 1; t.lastSeen = now; t.missing = false
                tracks[item.text] = t
            } else {
                tracks[item.text] = Track(item: item, hit: hit, source: source, seen: 1, lastSeen: now, missing: false, order: nextOrder + n)
            }
        }
        return publish(at: now)
    }

    /// Call a few times a second: screen capture sends no frame while the picture is static, so pieces whose English
    /// went away (a frame without them was seen) are removed on time even if no further frame arrives.
    @discardableResult
    public func tick(at now: Date = Date()) -> Bool { publish(at: now) }

    public func clear() {
        current = nil
        tracks = [:]
        lastText = nil
        lastResult = []
        lastFrameEmpty = false
    }

    // MARK: state → current

    private func publish(at now: Date) -> Bool {
        let hold = lastFrameEmpty ? hideAfter : removeAfter
        tracks = tracks.filter { !($0.value.missing && now.timeIntervalSince($0.value.lastSeen) >= hold) }
        let shown = tracks.values.filter { $0.seen >= stableFrames }
            .sorted { a, b in
                // Screen order: rows top to bottom (1% bands, so tiny OCR jitter can't swap them), then left to right,
                // then the order they were first seen.
                let ra = (a.item.box.minY * 100).rounded(), rb = (b.item.box.minY * 100).rounded()
                if ra != rb { return ra < rb }
                if abs(a.item.box.minX - b.item.box.minX) > 0.01 { return a.item.box.minX < b.item.box.minX }
                return a.order < b.order
            }
        let next = make(shown)
        let changed: Bool
        switch (current, next) {
        case (nil, nil): changed = false
        case let (c?, n?): changed = c.text != n.text || moved(n.items, c.items)
        default: changed = true
        }
        if changed {
            if let n = next, n.text != current?.text {
                history.insert(n, at: 0)
                if history.count > historyLimit { history.removeLast() }
            }
            current = next
        }
        return changed
    }

    private func make(_ shown: [Track]) -> Subtitle? {
        guard let first = shown.first else { return nil }
        if shown.count == 1 {
            let t = first
            return Subtitle(text: t.item.text, speaker: t.item.speaker, source: t.source, score: t.hit.score,
                            entryId: t.hit.entryId, packageId: t.hit.packageId, items: [t.item])
        }
        return Subtitle(text: shown.map(\.item.text).joined(separator: "  ·  "), speaker: nil,
                        source: shown.map(\.source).joined(separator: "\n"), score: shown.map(\.hit.score).min() ?? first.hit.score,
                        entryId: first.hit.entryId, packageId: first.hit.packageId, items: shown.map(\.item))
    }

    // MARK: matching

    /// The translated pieces in one frame: the whole text as one sentence, else each known line on its own.
    private func match(_ lines: [OCRLine], text: String) async -> [(SubtitleItem, Hit, String)] {
        if let hit = await lookup(text) {
            let box = lines.map(\.box).reduce(CGRect.null) { $0.union($1) }
            return [(SubtitleItem(text: hit.target, speaker: hit.speaker, box: box.isNull ? .zero : box, lines: lines.count), hit, text)]
        }
        guard lines.count > 1 else { return [] }
        var out: [(SubtitleItem, Hit, String)] = []
        for line in lines.prefix(16) {
            if let h = await lookup(line.text), !out.contains(where: { $0.1.entryId == h.entryId }) {
                out.append((SubtitleItem(text: h.target, speaker: h.speaker, box: line.box, lines: 1), h, line.text))
            }
        }
        return out
    }

    /// True when the English moved enough to re-place the Arabic (more than ~1.5% of the area).
    private func moved(_ a: [SubtitleItem], _ b: [SubtitleItem]) -> Bool {
        guard a.count == b.count else { return true }
        return zip(a, b).contains { abs($0.box.midX - $1.box.midX) > 0.015 || abs($0.box.midY - $1.box.midY) > 0.015 }
    }
}
