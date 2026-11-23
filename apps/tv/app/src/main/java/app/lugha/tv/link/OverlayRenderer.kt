package app.lugha.tv.link

import android.content.Context
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.text.SpannableStringBuilder
import android.text.Spanned
import android.text.style.ForegroundColorSpan
import android.text.style.RelativeSizeSpan
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import app.lugha.tv.R
import app.lugha.tv.ui.History
import app.lugha.tv.ui.ReadingHold
import app.lugha.tv.ui.Rect
import app.lugha.tv.ui.arrange
import app.lugha.tv.ui.scene

/**
 * Draws subtitles on the full-screen layer above the game:
 * - the dialogue as one block over the English subtitle area (lines stacked in reading order, speaker inline, an
 *   earlier line kept dimmed on top until it could be read) — so lines never cover each other;
 * - HUD prompts and menu items each over their own English, moved aside when they would touch the block;
 * - optionally a narrow, fading side panel with the recent dialogue.
 * "over" hides the English on plates; "above" puts the Arabic just above it.
 */
class OverlayRenderer(private val context: Context, private val layer: FrameLayout) {
    private val main = Handler(Looper.getMainLooper())
    private val hold = ReadingHold()
    private val history = History()
    private var lastSid: Long? = null
    private var lastState: HubState? = null
    private val again = Runnable { lastState?.let { render(it, force = true) } }

    private val amber = Color.rgb(0xE9, 0xB4, 0x4C)
    private val dm get() = context.resources.displayMetrics

    /** Pieces on screen keyed by their text, so a piece that stays is updated in place (no flicker, no re-fade). */
    private val views = mutableMapOf<String, View>()

    fun clear() {
        main.removeCallbacks(again)
        layer.removeAllViews()
        views.clear()
        hold.clear()
        history.clear()
        lastSid = null
    }

    fun render(s: HubState, force: Boolean = false) {
        lastState = s
        main.removeCallbacks(again)
        val now = System.currentTimeMillis()
        val style = s.style
        val sub = s.subtitle
        val items = when {
            sub == null -> emptyList()
            sub.items.isNotEmpty() -> sub.items
            // Older senders: one line at the style's position.
            else -> listOf(SubItem(sub.text, sub.speaker, (1f - style.maxWidth) / 2f, style.positionY - 0.025f, style.maxWidth, 0.05f, 1))
        }
        if (sub?.sid != lastSid || force) {
            if (sub?.sid != lastSid) {
                hold.update(items, now)
                history.add(items, now)
            }
            lastSid = sub?.sid
        }
        val shown = if (style.readHold) hold.visible(now) else items.map { it to false }
        val sc = scene(shown)
        val (sw, sh) = dm.widthPixels to dm.heightPixels
        val gap = (sh * 0.006f).toInt()
        val above = style.display == "above"

        data class Piece(val key: String, val view: View, val want: Rect, val alpha: Float = 1f)
        val pieces = mutableListOf<Piece>()

        sc.block?.let { b ->
            val px = (b.lineH * sh * 0.9f * style.fontScale).coerceIn(18f, 84f)
            val key = "block"
            val v = (views[key] as? LinearLayout) ?: LinearLayout(context).apply { orientation = LinearLayout.VERTICAL }
            fillBlock(v, b.lines, px, style)
            val maxW = (b.w * sw * 1.35f).coerceAtLeast(sw * 0.4f).coerceAtMost(sw * 0.92f).toInt()
            v.minimumWidth = if (above || style.mode == "outline") 0 else (b.w * sw).toInt().coerceAtMost(maxW) + (px * 0.6f).toInt()
            v.minimumHeight = if (above || style.mode == "outline") 0 else (b.h * sh).toInt() + (px * 0.3f).toInt()
            v.measure(View.MeasureSpec.makeMeasureSpec(maxW, View.MeasureSpec.AT_MOST), View.MeasureSpec.UNSPECIFIED)
            val w = v.measuredWidth
            val h = v.measuredHeight
            val cx = (b.x + b.w / 2f) * sw
            // Over: anchored to the bottom English line, growing upward. Above: just above the English.
            val top = if (above) (b.y * sh - gap - h) else ((b.y + b.h) * sh + px * 0.15f - h)
            pieces += Piece(key, v, Rect((cx - w / 2f).toInt(), top.toInt(), w, h))
        }
        for ((i, old) in sc.hud) {
            val lineH = i.h * sh / i.lines.coerceAtLeast(1)
            val px = (lineH * 0.9f * style.fontScale).coerceIn(18f, 84f)
            val maxW = (i.w * sw * 1.6f).coerceAtLeast(sw * 0.25f).coerceAtMost(sw * 0.9f).toInt()
            val key = "hud:" + i.text
            val v = (views[key] as? TextView) ?: TextView(context)
            plate(v, i.text, null, px, style, if (style.mode == "native") i.bg else null, i.fg)
            v.maxWidth = maxW
            val cover = !above && style.mode == "native"
            v.minWidth = if (cover) (i.w * sw).toInt().coerceAtMost(maxW) + 8 else 0
            v.minHeight = if (cover) (i.h * sh).toInt() + 4 else 0
            v.measure(View.MeasureSpec.makeMeasureSpec(maxW, View.MeasureSpec.AT_MOST), View.MeasureSpec.UNSPECIFIED)
            val w = v.measuredWidth
            val h = v.measuredHeight
            val cx = (i.x + i.w / 2f) * sw
            val top = if (above) (i.y * sh - gap - h) else ((i.y + i.h / 2f) * sh - h / 2f)
            pieces += Piece(key, v, Rect((cx - w / 2f).toInt(), top.toInt(), w, h), if (old) 0.6f else 1f)
        }
        val rects = arrange(pieces.map { it.want }, pieces.map { false }, sw, sh, gap, fixed = if (sc.block != null) 1 else 0)

        // History panel.
        if (style.history != "off" && history.items.isNotEmpty()) {
            val v = (views["history"] as? LinearLayout) ?: LinearLayout(context).apply { orientation = LinearLayout.VERTICAL }
            fillHistory(v, style, sh)
            val w = (sw * 0.22f).toInt()
            v.measure(View.MeasureSpec.makeMeasureSpec(w, View.MeasureSpec.EXACTLY), View.MeasureSpec.makeMeasureSpec((sh * 0.6f).toInt(), View.MeasureSpec.AT_MOST))
            val left = if (style.history == "left") (sw * 0.015f).toInt() else sw - w - (sw * 0.015f).toInt()
            val top = (sh * 0.45f - v.measuredHeight / 2f).toInt().coerceAtLeast((sh * 0.1f).toInt())
            // Bright for a few seconds after a new line, then it steps back so it doesn't pull the eye.
            val fresh = now - history.changedAt < 6000
            put("history", v, Rect(left, top, w, v.measuredHeight), if (fresh) 0.92f else 0.38f, style.fadeMs * 2)
            if (fresh) main.postDelayed(again, history.changedAt + 6000 - now + 20)
        }

        val keep = pieces.map { it.key }.toMutableSet().apply { if (style.history != "off" && history.items.isNotEmpty()) add("history") }
        views.keys.filter { it !in keep }.forEach { layer.removeView(views.remove(it)) }
        pieces.forEachIndexed { n, p -> put(p.key, p.view, rects[n], p.alpha, style.fadeMs) }

        if (style.readHold) hold.nextExpiry()?.let { main.postDelayed(again, (it - now).coerceIn(50, 5000)) }
    }

    /** Adds or moves a view to [r]; a new one fades in over [fadeMs]. */
    private fun put(key: String, v: View, r: Rect, alpha: Float, fadeMs: Int) {
        val lp = FrameLayout.LayoutParams(r.width, r.height, Gravity.LEFT or Gravity.TOP).apply { leftMargin = r.left; topMargin = r.top }
        if (v.parent == null) {
            views[key] = v
            layer.addView(v, lp)
            if (fadeMs > 0) { v.alpha = 0f; v.animate().alpha(alpha).setDuration(fadeMs.toLong()).start() } else v.alpha = alpha
        } else {
            v.layoutParams = lp
            v.animate().alpha(alpha).setDuration(250).start()
        }
    }

    private fun typeface(font: String): Typeface {
        val res = when (font) {
            "naskh" -> R.font.noto_naskh_arabic
            "kufi" -> R.font.noto_kufi_arabic
            "cairo" -> R.font.cairo
            "tajawal" -> R.font.tajawal_bold
            "amiri" -> R.font.amiri_bold
            else -> 0
        }
        val base = if (res != 0) androidx.core.content.res.ResourcesCompat.getFont(context, res) ?: Typeface.DEFAULT else Typeface.DEFAULT
        return if (Build.VERSION.SDK_INT >= 28) Typeface.create(base, 600, false) else Typeface.create(base, Typeface.BOLD)
    }

    /** "Lucia: إيه" with the name in amber, on one line, right to left. */
    private fun line(text: String, speaker: String?) = SpannableStringBuilder().apply {
        speaker?.let {
            append("‏").append(it).append(":‏ ")
            setSpan(ForegroundColorSpan(amber), 0, length, Spanned.SPAN_EXCLUSIVE_EXCLUSIVE)
            setSpan(RelativeSizeSpan(0.8f), 0, length, Spanned.SPAN_EXCLUSIVE_EXCLUSIVE)
        }
        append(text)
    }

    private fun textColor(style: OverlayStyle, fg: Int?) =
        if (style.mode == "native") fg ?: Color.WHITE else if (style.textColor == "yellow") Color.rgb(255, 219, 64) else Color.WHITE

    private fun TextView.base(px: Float, style: OverlayStyle) {
        setTextSize(TypedValue.COMPLEX_UNIT_PX, px)
        textDirection = View.TEXT_DIRECTION_RTL
        gravity = Gravity.CENTER
        setLineSpacing(0f, 1.08f)
        typeface = typeface(style.font)
        if (style.mode == "outline" || style.display == "above" && style.mode != "box") setShadowLayer(px * 0.16f, 0f, 0f, Color.BLACK)
        else setShadowLayer(if (style.mode == "box") 4f else 0f, 0f, 2f, Color.argb(220, 0, 0, 0))
    }

    private fun background(style: OverlayStyle, bg: Int?, px: Float) = when {
        style.mode == "outline" -> null
        style.display == "above" && style.mode == "native" -> null
        else -> GradientDrawable().apply {
            setColor(bg ?: Color.argb((style.bgOpacity * 255).toInt(), 0, 0, 0))
            cornerRadius = if (style.mode == "native") 4f else px * 0.3f
        }
    }

    private fun fillBlock(v: LinearLayout, lines: List<Pair<SubItem, Boolean>>, px: Float, style: OverlayStyle) {
        v.removeAllViews()
        v.gravity = Gravity.CENTER
        val bg = lines.lastOrNull { !it.second }?.first?.bg.takeIf { style.mode == "native" }
        v.background = background(style, bg, px)
        v.setPadding((px * 0.5f).toInt(), (px * 0.18f).toInt(), (px * 0.5f).toInt(), (px * 0.18f).toInt())
        for ((i, old) in lines) {
            v.addView(TextView(context).apply {
                base(px, style)
                text = line(i.text, i.speaker)
                setTextColor(textColor(style, i.fg))
                alpha = if (old) 0.55f else 1f
            }, LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { gravity = Gravity.CENTER_HORIZONTAL })
        }
    }

    private fun plate(v: TextView, text: String, speaker: String?, px: Float, style: OverlayStyle, bg: Int?, fg: Int?) = v.apply {
        base(px, style)
        this.text = line(text, speaker)
        setTextColor(textColor(style, fg))
        background = background(style, bg, px)
        setPadding((px * 0.3f).toInt(), (px * 0.05f).toInt(), (px * 0.3f).toInt(), (px * 0.05f).toInt())
    }

    private fun fillHistory(v: LinearLayout, style: OverlayStyle, sh: Int) {
        v.removeAllViews()
        v.background = GradientDrawable().apply { setColor(Color.argb(150, 10, 10, 14)); cornerRadius = sh * 0.012f }
        val px = sh * 0.021f * style.fontScale.coerceIn(0.8f, 1.3f)
        v.setPadding((px * 0.8f).toInt(), (px * 0.6f).toInt(), (px * 0.8f).toInt(), (px * 0.6f).toInt())
        val items = history.items
        items.forEachIndexed { n, i ->
            v.addView(TextView(context).apply {
                setTextSize(TypedValue.COMPLEX_UNIT_PX, px)
                textDirection = View.TEXT_DIRECTION_RTL
                gravity = Gravity.START
                typeface = typeface(style.font)
                setLineSpacing(0f, 1.05f)
                text = line(i.text, i.speaker)
                setTextColor(Color.WHITE)
                // Older lines fade back; the newest is brightest.
                alpha = 0.45f + 0.55f * (n + 1) / items.size
                setPadding(0, (px * 0.25f).toInt(), 0, (px * 0.25f).toInt())
            })
        }
    }
}
