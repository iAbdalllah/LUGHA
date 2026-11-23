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
    public var removeAfter: TimeInterval = 0.4
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
        cache = [:]
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

    /// Lookups already made (hits and misses): the same text is on screen for many frames.
    private var cache: [String: Hit?] = [:]

    private func cachedLookup(_ q: String) async -> Hit? {
        if let c = cache[q] { return c }
        let h = await lookup(q)
        if cache.count > 4000 { cache.removeAll(keepingCapacity: true) }
        cache[q] = h
        return h
    }

    /// "Clerk: Rent's just…" → ("Clerk", "Rent's just…"). Games put the speaker's name before the line; the package
    /// has the line alone.
    public static func splitSpeaker(_ text: String) -> (speaker: String?, text: String) {
        guard let colon = text.firstIndex(of: ":") else { return (nil, text) }
        let name = text[..<colon].trimmingCharacters(in: .whitespaces)
        let rest = text[text.index(after: colon)...].trimmingCharacters(in: .whitespaces)
        let words = name.split(separator: " ")
        guard !rest.isEmpty, (1...3).contains(words.count), name.count <= 24,
              words.allSatisfy({ w in w.first.map { $0.isUppercase || $0.isNumber } ?? false }),
              name.allSatisfy({ $0.isLetter || $0.isNumber || " .'-#".contains($0) }) else { return (nil, text) }
        return (name, rest)
    }

    /// Worth a lookup: mostly Latin text with at least one word of 2+ letters (not OCR noise from the picture, not a
    /// lone compass letter).
    public static func looksLikeText(_ text: String) -> Bool {
        let chars = text.filter { !$0.isWhitespace }
        guard !chars.isEmpty else { return false }
        let plain = chars.filter { $0.isASCII }.count
        guard Double(plain) / Double(chars.count) >= 0.9 else { return false }
        return text.split(whereSeparator: { !$0.isLetter }).contains { $0.count >= 2 }
    }

    /// Lines that read as one piece of text: each next line starts right under the previous one and is centred or
    /// left-aligned with it (a subtitle wrapped onto two lines, a paragraph). HUD prompts elsewhere stay separate.
    static func blocks(_ lines: [OCRLine]) -> [[OCRLine]] {
        var blocks: [[OCRLine]] = []
        for line in rows(lines).sorted(by: { $0.box.minY < $1.box.minY }) {
            let h = max(line.box.height, 0.001)
            if !line.box.isEmpty, splitSpeaker(line.text).speaker == nil,
               let i = blocks.lastIndex(where: { b in
                   guard let last = b.last, !last.box.isEmpty else { return false }
                   let gap = line.box.minY - last.box.maxY
                   let lh = max(last.box.height, h)
                   let centred = abs(line.box.midX - last.box.midX) < max(last.box.width, line.box.width) * 0.25
                   let leftAligned = abs(line.box.minX - last.box.minX) < lh
                   return gap > -lh * 0.5 && gap < lh * 0.9 && abs(line.box.height - last.box.height) < lh * 0.45 && (centred || leftAligned)
               }) {
                blocks[i].append(line)
            } else {
                blocks.append([line])
            }
        }
        return blocks
    }

    /// OCR sometimes reads one line as pieces side by side ("Clerk: Pfft! I'll" + "turn you upside down!"): pieces on
    /// the same row with a small gap become one line.
    static func rows(_ lines: [OCRLine]) -> [OCRLine] {
        var out: [OCRLine] = []
        for line in lines.sorted(by: { $0.box.minX < $1.box.minX }) {
            if !line.box.isEmpty, let i = out.lastIndex(where: { a in
                guard !a.box.isEmpty else { return false }
                let h = min(a.box.height, line.box.height)
                let overlap = min(a.box.maxY, line.box.maxY) - max(a.box.minY, line.box.minY)
                let gap = line.box.minX - a.box.maxX
                return overlap > h * 0.5 && gap > -h && gap < h * 1.2
            }) {
                out[i] = OCRLine(text: out[i].text + " " + line.text, box: out[i].box.union(line.box))
            } else {
                out.append(line)
            }
        }
        return out
    }

    /// The translated pieces in one frame: each block as one sentence, else its lines one by one.
    private func match(_ lines: [OCRLine], text: String) async -> [(SubtitleItem, Hit, String)] {
        var out: [(SubtitleItem, Hit, String)] = []
        func add(_ hit: Hit, _ box: CGRect, _ count: Int, _ source: String, _ speaker: String?) {
            guard !out.contains(where: { $0.1.entryId == hit.entryId }) else { return }
            out.append((SubtitleItem(text: hit.target, speaker: hit.speaker ?? speaker, box: box.isNull ? .zero : box, lines: count), hit, source))
        }
        /// One piece of text: with its speaker name taken off; very short text (a HUD word, a sign) only on an exact match.
        func find(_ raw: String) async -> (Hit, String?)? {
            let (speaker, body) = Self.splitSpeaker(raw)
            // Without the name first; then as read (a label like "Mission: …" can be part of the line itself).
            for (text, name) in speaker == nil ? [(raw, nil)] : [(body, speaker), (raw, nil)] {
                guard Self.looksLikeText(text), let hit = await cachedLookup(text) else { continue }
                let words = text.split(whereSeparator: { $0.isWhitespace }).count
                if words <= 2 && hit.method != "exact" { continue }
                return (hit, name)
            }
            return nil
        }
        for block in Self.blocks(lines).prefix(12) {
            let box = block.map(\.box).reduce(CGRect.null) { $0.union($1) }
            let joined = block.map(\.text).joined(separator: " ")
            if let (hit, speaker) = await find(joined) {
                add(hit, box, block.count, joined, speaker)
                continue
            }
            guard block.count > 1 else { continue }
            for line in block.prefix(16) {
                if let (hit, speaker) = await find(line.text) { add(hit, line.box, 1, line.text, speaker) }
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
