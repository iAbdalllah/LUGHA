import CoreGraphics
import Foundation

/// Where the Arabic pieces go so none covers another (same rules as the TV app's SubtitleLayout.kt).
public enum OverlayLayout {
    /// Current lines go first, each over its English, top to bottom; one that would land on a placed piece moves just
    /// below it (an older line, just above), and the other way when that runs off the screen.
    public static func arrange(_ wanted: [CGRect], old: [Bool], in bounds: CGSize, gap: CGFloat) -> [CGRect] {
        func clamp(_ r: CGRect) -> CGRect {
            var r = r
            r.origin.x = min(max(r.minX, 0), max(bounds.width - r.width, 0))
            r.origin.y = min(max(r.minY, 0), max(bounds.height - r.height, 0))
            return r
        }
        let order = wanted.indices.sorted { (old[$0] ? 1 : 0, wanted[$0].minY) < (old[$1] ? 1 : 0, wanted[$1].minY) }
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
            let r = slide(down: !old[k]) ?? slide(down: old[k]) ?? start
            out[k] = r
            placed.append(r)
        }
        return out
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
