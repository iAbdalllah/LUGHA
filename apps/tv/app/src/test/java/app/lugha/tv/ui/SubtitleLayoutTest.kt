package app.lugha.tv.ui

import app.lugha.tv.link.SubItem
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class SubtitleLayoutTest {
    private fun item(text: String, y: Float, speaker: String? = "Cop") = SubItem(text, speaker, 0.35f, y, 0.3f, 0.03f, 1)

    @Test
    fun twoStackedSubtitlesDoNotCoverEachOther() {
        // Recorded case: "Cop: …" and "Jason: …" English lines 50 px apart, Arabic bubbles 90 px tall.
        val a = Rect(700, 820, 500, 90)
        val b = Rect(720, 870, 460, 90)
        val out = arrange(listOf(a, b), listOf(false, false), 2000, 1030, 6)
        assertEquals(a, out[0])
        assertFalse(out[0].overlaps(out[1], 0))
        assertTrue("the second line moves just below the first", out[1].top >= out[0].bottom)
    }

    @Test
    fun aLineThatWouldLeaveTheScreenMovesUpInstead() {
        val a = Rect(700, 930, 500, 90)
        val b = Rect(700, 935, 500, 90)
        val out = arrange(listOf(a, b), listOf(false, false), 2000, 1030, 6)
        assertFalse(out[0].overlaps(out[1], 0))
        assertTrue(out.all { it.top >= 0 && it.bottom <= 1030 })
    }

    @Test
    fun anEarlierLineGoesAboveTheCurrentOne() {
        val current = Rect(700, 850, 500, 90)
        val earlier = Rect(700, 850, 500, 90)
        val out = arrange(listOf(earlier, current), listOf(true, false), 2000, 1030, 6)
        assertEquals(current, out[1])
        assertTrue(out[0].bottom <= out[1].top)
    }

    @Test
    fun aReplacedLineStaysUntilReadThenGoes() {
        val hold = ReadingHold()
        val first = item("أوه! المشتبه فيه ترك مركبته!", 0.82f)
        val second = item("ازحف أسرع يا خوي.", 0.82f, "Jason")
        hold.update(listOf(first), 0)
        hold.update(listOf(second), 300) // the game replaced it after 0.3 s
        assertEquals(listOf(second to false, first to true), hold.visible(400))
        assertEquals(listOf(second to false), hold.visible(readMillis(first.text) + 1))
    }

    @Test
    fun onlyOneEarlierLineIsKeptAndHudWordsAreNot() {
        val hold = ReadingHold()
        val a = item("السطر الأول من الحوار هنا", 0.8f)
        val b = item("السطر الثاني من الحوار هنا", 0.8f)
        val c = item("السطر الثالث من الحوار هنا", 0.8f)
        val hud = item("هدد", 0.7f, null)
        hold.update(listOf(a, hud), 0)
        hold.update(listOf(b), 200)
        hold.update(listOf(c), 400)
        assertEquals(listOf(c to false, b to true), hold.visible(500))
    }

    @Test
    fun aLineBackOnScreenIsCurrentAgain() {
        val hold = ReadingHold()
        val a = item("السطر الأول من الحوار هنا", 0.8f)
        hold.update(listOf(a), 0)
        hold.update(emptyList(), 100)
        hold.update(listOf(a), 200)
        assertEquals(listOf(a to false), hold.visible(10_000))
    }

    @Test
    fun stackedDialogueBecomesOneBlockAndHudStaysApart() {
        // Recorded prison scene: Prisoner line over Lucia's, a HUD word to the right.
        val prisoner = SubItem("قلت لك ذاك الحقير الثاني، أنا ما سويت شيء.", "Prisoner", 0.308f, 0.702f, 0.384f, 0.013f, 1)
        val lucia = SubItem("إيه", "Lucia", 0.464f, 0.725f, 0.071f, 0.012f, 1)
        val hud = SubItem("تشغيل", null, 0.685f, 0.712f, 0.101f, 0.039f, 1)
        val s = scene(listOf(lucia to false, prisoner to false, hud to false))
        assertEquals(listOf(prisoner, lucia), s.block!!.lines.map { it.first })
        assertEquals(0.308f, s.block!!.x, 0.001f)
        assertEquals(0.737f, s.block!!.y + s.block!!.h, 0.001f)
        assertEquals(listOf(hud), s.hud.map { it.first })
    }

    @Test
    fun anEarlierLineSitsAtTheTopOfTheBlockAndTopTextIsNotDialogue() {
        val old = item("السطر الأول من الحوار هنا", 0.8f)
        val now = item("السطر الثاني من الحوار هنا", 0.8f)
        val tip = SubItem("اضغط مطولاً لتخفيف السرعة أثناء الهبوط", null, 0.01f, 0.2f, 0.2f, 0.06f, 2)
        val s = scene(listOf(now to false, old to true, tip to false))
        assertEquals(listOf(old to true, now to false), s.block!!.lines)
        assertEquals(listOf(tip), s.hud.map { it.first })
    }

    @Test
    fun historyKeepsRecentDialogueOnceNewestLast() {
        val h = History(max = 2)
        val a = item("السطر الأول من الحوار هنا", 0.8f)
        val b = item("السطر الثاني من الحوار هنا", 0.8f)
        val c = item("السطر الثالث من الحوار هنا", 0.8f)
        h.add(listOf(a, SubItem("هدد", null, 0.9f, 0.7f, 0.05f, 0.03f, 1)), 1)
        h.add(listOf(a, b), 2)
        h.add(listOf(c), 3)
        assertEquals(listOf(b, c), h.items)
        assertEquals(3L, h.changedAt)
    }
}
