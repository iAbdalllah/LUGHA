package app.lugha.tv.ui

import app.lugha.tv.link.SubItem

/** A bubble's rectangle on the TV, in pixels. */
data class Rect(val left: Int, val top: Int, val width: Int, val height: Int) {
    val right get() = left + width
    val bottom get() = top + height
    fun overlaps(o: Rect, gap: Int) = left < o.right + gap && o.left < right + gap && top < o.bottom + gap && o.top < bottom + gap
    fun movedTo(top: Int) = copy(top = top)
}

/**
 * Places the bubbles so none covers another. Current lines go first, each over its English, top to bottom; a bubble
 * that would land on one already placed moves just below it (an older line, just above), and the other way when that
 * runs off the screen.
 *
 * @param wanted each bubble centred where its English is
 * @param old true for a line kept up for reading after its English left the screen
 */
fun arrange(wanted: List<Rect>, old: List<Boolean>, screenW: Int, screenH: Int, gap: Int, fixed: Int = 0): List<Rect> {
    val clamp = { r: Rect ->
        r.copy(left = r.left.coerceIn(0, (screenW - r.width).coerceAtLeast(0)), top = r.top.coerceIn(0, (screenH - r.height).coerceAtLeast(0)))
    }
    // The first [fixed] rects (the subtitle block) stay where they are; the rest make way for them.
    val order = (0 until fixed).toList() + (fixed until wanted.size).sortedWith(compareBy({ old[it] }, { wanted[it].top }))
    val placed = mutableListOf<Rect>()
    val out = arrayOfNulls<Rect>(wanted.size)
    for (k in order) {
        val start = clamp(wanted[k])
        fun slide(down: Boolean): Rect? {
            var r = start
            repeat(placed.size + 1) {
                val hit = placed.filter { it.overlaps(r, gap) }.let { hits ->
                    if (down) hits.maxByOrNull { it.bottom } else hits.minByOrNull { it.top }
                } ?: return r
                r = r.movedTo(if (down) hit.bottom + gap else hit.top - gap - r.height)
                if (r.top < 0 || r.bottom > screenH) return null
            }
            return null
        }
        val r = if (k < fixed) start else slide(down = !old[k]) ?: slide(down = old[k]) ?: start
        out[k] = r
        placed += r
    }
    return out.map { it!! }
}

/** How long a line stays up at least, so it can be read: longer text, longer time. */
fun readMillis(text: String): Long = (900L + 55L * text.length).coerceIn(1500L, 5000L)

/**
 * Keeps a dialogue line on screen until it could be read, even when the game already replaced it, without filling the
 * screen: at most [maxOld] earlier lines stay, dimmed, next to the current ones. HUD words (short, no speaker) are not
 * kept: they come and go with the game.
 */
class ReadingHold(private val maxOld: Int = 1) {
    private class Entry(var item: SubItem, val since: Long, var goneAt: Long?)

    private val entries = LinkedHashMap<String, Entry>()

    private fun SubItem.isDialogue() = speaker != null || text.length >= 14

    fun update(items: List<SubItem>, now: Long) {
        val keys = items.map { it.text }.toSet()
        for ((k, e) in entries) if (k !in keys && e.goneAt == null) e.goneAt = now
        for (i in items) {
            val e = entries[i.text]
            if (e == null) entries[i.text] = Entry(i, now, null) else { e.item = i; e.goneAt = null }
        }
        prune(now)
    }

    private fun keep(e: Entry, now: Long) = e.goneAt == null || (e.item.isDialogue() && now < e.since + readMillis(e.item.text))

    private fun prune(now: Long) {
        entries.entries.removeAll { !keep(it.value, now) }
        // Only the newest earlier lines stay.
        val old = entries.values.filter { it.goneAt != null }.sortedByDescending { it.goneAt }
        old.drop(maxOld).forEach { e -> entries.values.remove(e) }
    }

    /** What to show now: (item, isOld), current lines first. */
    fun visible(now: Long): List<Pair<SubItem, Boolean>> {
        prune(now)
        val current = entries.values.filter { it.goneAt == null }.map { it.item to false }
        val old = entries.values.filter { it.goneAt != null }.sortedByDescending { it.goneAt }.map { it.item to true }
        return current + old
    }

    /** When the next earlier line runs out of time (to redraw then), or null when none is waiting. */
    fun nextExpiry(): Long? = entries.values.filter { it.goneAt != null }.minOfOrNull { it.since + readMillis(it.item.text) }

    fun clear() = entries.clear()
}

/** A dialogue line: spoken text (a speaker, or a sentence) in the lower half, where games draw subtitles. */
fun isDialogue(i: SubItem) = (i.speaker != null || i.text.length >= 14) && i.y + i.h / 2f >= 0.5f

/** The subtitle block: every dialogue line on one plate over the English subtitle area. */
data class Block(val lines: List<Pair<SubItem, Boolean>>, val x: Float, val y: Float, val w: Float, val h: Float) {
    /** One English line's height (fraction of the screen), to size the Arabic like the game's text. */
    val lineH: Float get() = lines.filter { !it.second }.map { it.first.h / it.first.lines.coerceAtLeast(1) }.sorted().let { if (it.isEmpty()) h else it[it.size / 2] }
}

/** What to draw: the subtitle block (or null) and the other pieces (HUD prompts, menus, signs), each over its English. */
data class Scene(val block: Block?, val hud: List<Pair<SubItem, Boolean>>)

/** Splits what is shown into the subtitle block and the rest. Lines in the block keep screen order; earlier lines first. */
fun scene(shown: List<Pair<SubItem, Boolean>>): Scene {
    val (talk, hud) = shown.partition { isDialogue(it.first) }
    if (talk.isEmpty()) return Scene(null, hud)
    val current = talk.filter { !it.second }.ifEmpty { talk }
    val left = current.minOf { it.first.x }
    val top = current.minOf { it.first.y }
    val right = current.maxOf { it.first.x + it.first.w }
    val bottom = current.maxOf { it.first.y + it.first.h }
    val ordered = talk.filter { it.second } + talk.filter { !it.second }.sortedBy { it.first.y }
    return Scene(Block(ordered, left, top, right - left, bottom - top), hud)
}

/**
 * Recent dialogue for the side panel: newest last, each sentence once, at most [max] lines. Items are split into their
 * lines first, because the sender merges a speaker's lines into one paragraph that changes as it grows ("A", then
 * "A\nB", or a speaker name read on its own line) — comparing whole items let the same sentence in again and again.
 */
class History(private val max: Int = 8) {
    private val lines = ArrayDeque<SubItem>()
    var changedAt = 0L
        private set

    fun add(items: List<SubItem>, now: Long) {
        for (i in items.filter { isDialogue(it) && it.area?.instructions != true }) {
            for (raw in i.text.split('\n')) {
                val text = raw.trim()
                val key = norm(text)
                // A lone word is a speaker name or a HUD scrap ("Cal", "Right"), not a sentence.
                if (key.length < 6 || !text.contains(' ')) continue
                val same = lines.indexOfFirst { val k = norm(it.text); k == key || k.contains(key) || key.contains(k) }
                if (same >= 0) {
                    // The longer reading wins (the line finished typing in, or the OCR got more of it), in place.
                    if (key.length > norm(lines[same].text).length) { lines[same] = i.copy(text = text); changedAt = now }
                    continue
                }
                lines.addLast(i.copy(text = text))
                while (lines.size > max) lines.removeFirst()
                changedAt = now
            }
        }
    }

    /** Letters and digits only, so punctuation and spacing differences between two readings don't count. */
    private fun norm(s: String) = s.filter { it.isLetterOrDigit() }

    val items: List<SubItem> get() = lines.toList()
    fun clear() = lines.clear()
}
