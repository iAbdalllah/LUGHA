import CoreGraphics
import Foundation

/// Where the Arabic pieces go so none covers another (same rules as the TV app's SubtitleLayout.kt).
public enum OverlayLayout {
    /// Current lines go first, each over its English, top to bottom; one that would land on a placed piece moves just
    /// below it (an older line, just above), and the other way when that runs off the screen.
    public static func arrange(_ wanted: [CGRect], old: [Bool], in bounds: CGSize, gap: CGFloat, fixed: Int = 0) -> [CGRect] {
        func clamp(_ r: CGRect) -> CGRect {
            var r = r
            r.origin.x = min(max(r.minX, 0), max(bounds.width - r.width, 0))
            r.origin.y = min(max(r.minY, 0), max(bounds.height - r.height, 0))
            return r
        }
        // The first `fixed` rects (the subtitle block) stay where they are; the rest make way for them.
        let order = Array(0 ..< min(fixed, wanted.count)) + wanted.indices.dropFirst(fixed).sorted { (old[$0] ? 1 : 0, wanted[$0].minY) < (old[$1] ? 1 : 0, wanted[$1].minY) }
        var placed: [CGRect] = []
        var out = wanted
        for k in order {
            let start = clamp(wanted[k])
            func slide(down: Bool) -> CGRect? {
                var r = start
                for _ in 0 ... placed.count {
                    let hits = placed.filter { $0.insetBy(dx: -gap, dy: -gap).intersects(r) }
                    guard let hit = down ? hits.max(by: { $0.maxY < $1.maxY }) : hits.min(by: { $0.minY < $1.minY }) else { return r }
                    r.origin.y = down ? hit.maxY + gap : hit.minY - gap - r.height
                    if r.minY < 0 || r.maxY > bounds.height { return nil }
                }
                return nil
            }
            let r = k < fixed ? start : (slide(down: !old[k]) ?? slide(down: old[k]) ?? start)
            out[k] = r
            placed.append(r)
        }
        return out
    }

    /// A dialogue line: spoken text (a speaker, or a sentence) in the lower half, where games draw subtitles.
    public static func isDialogue(_ i: SubtitleItem) -> Bool {
        (i.speaker != nil || i.text.count >= 14) && (i.box.width == 0 || i.box.midY >= 0.5)
    }

    /// The subtitle block: every dialogue line on one plate over the English subtitle area, earlier lines first, then
    /// screen order. `area` is the union of the current lines' English boxes.
    public struct Block {
        public let lines: [(item: SubtitleItem, old: Bool)]
        public let area: CGRect
        /// One English line's height (fraction of the area), to size the Arabic like the game's text.
        public var lineHeight: CGFloat {
            let hs = lines.filter { !$0.old }.map { $0.item.box.height / CGFloat(max($0.item.lines, 1)) }.sorted()
            return hs.isEmpty ? area.height : hs[hs.count / 2]
        }
    }

    /// Splits what is shown into the subtitle block (or nil) and the rest (HUD prompts, menus), each over its English.
    public static func scene(_ shown: [(item: SubtitleItem, old: Bool)]) -> (block: Block?, hud: [(item: SubtitleItem, old: Bool)]) {
        let talk = shown.filter { isDialogue($0.item) }
        let hud = shown.filter { !isDialogue($0.item) }
        guard !talk.isEmpty else { return (nil, hud) }
        let current = talk.filter { !$0.old }.isEmpty ? talk : talk.filter { !$0.old }
        let area = current.map(\.item.box).reduce(CGRect.null) { $0.union($1) }
        let ordered = talk.filter(\.old) + talk.filter { !$0.old }.sorted { $0.item.box.minY < $1.item.box.minY }
        return (Block(lines: ordered, area: area), hud)
    }

    /// How long a line stays up at least, so it can be read: longer text, longer time.
    public static func readTime(_ text: String) -> TimeInterval { min(max(0.9 + 0.055 * Double(text.count), 1.5), 5) }
}

/// Keeps a dialogue line on screen until it could be read, even when the game already replaced it, without filling the
/// screen: at most `maxOld` earlier lines stay, dimmed, next to the current ones. HUD words (short, no speaker) are not
/// kept: they come and go with the game.
public final class ReadingHold {
    private struct Entry { var item: SubtitleItem; let since: Date; var goneAt: Date? }
    private var entries: [String: Entry] = [:]
    private var order: [String] = []
    private let maxOld: Int

    public init(maxOld: Int = 1) { self.maxOld = maxOld }

    private static func isDialogue(_ i: SubtitleItem) -> Bool { i.speaker != nil || i.text.count >= 14 }

    public func update(_ items: [SubtitleItem], at now: Date = Date()) {
        let keys = Set(items.map(\.text))
        for k in order where !keys.contains(k) && entries[k]?.goneAt == nil { entries[k]?.goneAt = now }
        for i in items {
            if entries[i.text] == nil { entries[i.text] = Entry(item: i, since: now, goneAt: nil); order.append(i.text) }
            else { entries[i.text]?.item = i; entries[i.text]?.goneAt = nil }
        }
        prune(now)
    }

    private func prune(_ now: Date) {
        entries = entries.filter { _, e in
            e.goneAt == nil || (Self.isDialogue(e.item) && now < e.since.addingTimeInterval(OverlayLayout.readTime(e.item.text)))
        }
        let old = entries.filter { $0.value.goneAt != nil }.sorted { $0.value.goneAt! > $1.value.goneAt! }
        for (k, _) in old.dropFirst(maxOld) { entries[k] = nil }
        order.removeAll { entries[$0] == nil }
    }

    /// What to show now: (item, isOld), current lines first in the order they came.
    public func visible(at now: Date = Date()) -> [(item: SubtitleItem, old: Bool)] {
        prune(now)
        let current = order.compactMap { entries[$0] }.filter { $0.goneAt == nil }.map { ($0.item, false) }
        let old = entries.values.filter { $0.goneAt != nil }.sorted { $0.goneAt! > $1.goneAt! }.map { ($0.item, true) }
        return (current + old).map { (item: $0.0, old: $0.1) }
    }

    /// When the next earlier line runs out of time, or nil when none is waiting.
    public var nextExpiry: Date? {
        entries.values.filter { $0.goneAt != nil }.map { $0.since.addingTimeInterval(OverlayLayout.readTime($0.item.text)) }.min()
    }

    public func clear() { entries = [:]; order = [] }
}

/// Recent dialogue for the side panel: newest last, each line once.
public final class RecentDialogue {
    public private(set) var items: [SubtitleItem] = []
    public private(set) var changedAt = Date.distantPast
    private let max: Int

    public init(max: Int = 6) { self.max = max }

    public func add(_ new: [SubtitleItem], at now: Date = Date()) {
        for i in new where OverlayLayout.isDialogue(i) && !items.contains(where: { $0.text == i.text }) {
            items.append(i)
            if items.count > max { items.removeFirst(items.count - max) }
            changedAt = now
        }
    }

    public func clear() { items = [] }
}
