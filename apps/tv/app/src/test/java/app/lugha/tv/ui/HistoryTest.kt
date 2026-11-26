package app.lugha.tv.ui

import app.lugha.tv.link.AreaStyle
import app.lugha.tv.link.SubItem
import org.junit.Assert.assertEquals
import org.junit.Test

class HistoryTest {
    private fun item(text: String, area: AreaStyle? = null) = SubItem(text, null, 0.1f, 0.8f, 0.7f, 0.05f, 1, area = area)

    @Test fun sameSentenceOnceWhateverTheParagraphAroundIt() {
        val h = History()
        val line = "المفروض تكون حول المنطقة، إلا إذا أحد سبقك عليها."
        h.add(listOf(item("$line\nكال")), 1)
        h.add(listOf(item("كال\n$line")), 2)
        h.add(listOf(item(line)), 3)
        h.add(listOf(item("المفروض تكون حول المنطقة")), 4)
        assertEquals(listOf(line), h.items.map { it.text })
    }

    @Test fun instructionAreasStayOutOfTheHistory() {
        val h = History()
        val hint = AreaStyle(name = "bottom", place = "custom", look = "subtitle")
        h.add(listOf(item("انتهت المكالمة الآن يا صاحبي", hint), item("يا رجال، أنا في ذا ريفرز")), 1)
        assertEquals(listOf("يا رجال، أنا في ذا ريفرز"), h.items.map { it.text })
    }
}
