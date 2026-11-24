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
        // History only: nothing over the game, just the side panel.
        val sc = if (style.display == "history") app.lugha.tv.ui.Scene(null, emptyList()) else scene(shown)
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
            plate(v, i, px, style)
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
        if (style.historySide != "off" && history.items.isNotEmpty()) {
            val v = (views["history"] as? LinearLayout) ?: LinearLayout(context).apply { orientation = LinearLayout.VERTICAL }
            fillHistory(v, style, sh)
            val w = (sw * 0.22f).toInt()
            v.measure(View.MeasureSpec.makeMeasureSpec(w, View.MeasureSpec.EXACTLY), View.MeasureSpec.makeMeasureSpec((sh * 0.6f).toInt(), View.MeasureSpec.AT_MOST))
            val left = if (style.historySide == "left") (sw * 0.015f).toInt() else sw - w - (sw * 0.015f).toInt()
            val top = (sh * 0.45f - v.measuredHeight / 2f).toInt().coerceAtLeast((sh * 0.1f).toInt())
            // Bright for a few seconds after a new line, then it steps back so it doesn't pull the eye.
            // Bright for a few seconds after a new line, then it steps back — unless it is the only thing shown.
            val fresh = now - history.changedAt < 6000 || style.display == "history"
            put("history", v, Rect(left, top, w, v.measuredHeight), if (fresh) 0.92f else 0.38f, style.fadeMs * 2)
            if (fresh && style.display != "history") main.postDelayed(again, history.changedAt + 6000 - now + 20)
        }

        val keep = pieces.map { it.key }.toMutableSet().apply { if (style.historySide != "off" && history.items.isNotEmpty()) add("history") }
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

    /** Drawn straight on the picture (no plate to cover the English): the colour must adapt to what is behind it. */
    private fun onPicture(style: OverlayStyle) = style.mode == "outline" || style.display == "above" && style.mode == "native"

    /**
     * Native ("original"): the game's own text colour on its own plate; on the picture, the readable colour the sender
     * sampled for that spot (light on dark scenes, dark on bright ones). Box/outline: the chosen colour.
     */
    private fun textColor(style: OverlayStyle, i: SubItem?): Int = when {
        style.mode == "native" && style.display == "above" -> i?.toneFg ?: Color.WHITE
        style.mode == "native" -> i?.fg ?: Color.WHITE
        // A light box colour: dark text so it reads (box style only; outline sits on the picture).
        style.mode == "box" && lightPlate(style) -> Color.rgb(18, 18, 23)
        style.textColor == "yellow" -> Color.rgb(255, 219, 64)
        else -> Color.WHITE
    }

    private fun lightPlate(style: OverlayStyle): Boolean {
        val p = style.plateRgb
        return (0.2126 * Color.red(p) + 0.7152 * Color.green(p) + 0.0722 * Color.blue(p)) / 255 > 0.6
    }

    /** The outline/backdrop colour: the opposite of the text. */
    private fun edge(text: Int): Int {
        val l = (0.2126 * Color.red(text) + 0.7152 * Color.green(text) + 0.0722 * Color.blue(text)) / 255
        return if (l < 0.5) Color.WHITE else Color.BLACK
    }

    private fun TextView.base(px: Float, style: OverlayStyle, i: SubItem?) {
        setTextSize(TypedValue.COMPLEX_UNIT_PX, px)
        textDirection = View.TEXT_DIRECTION_RTL
        gravity = Gravity.CENTER
        setLineSpacing(0f, 1.08f)
        typeface = typeface(style.font)
        if (onPicture(style)) setShadowLayer(px * 0.16f, 0f, 0f, edge(textColor(style, i)))
        else setShadowLayer(if (style.mode == "box" && !lightPlate(style)) 4f else 0f, 0f, 2f, Color.argb(220, 0, 0, 0))
    }

    private fun background(style: OverlayStyle, i: SubItem?, px: Float) = if (onPicture(style)) {
        // A soft backdrop in the edge colour, as strong as the sampled spot needs (busy ground, mid-grey).
        val shade = if (style.mode == "native") i?.toneShade ?: 0.3f else (i?.toneShade ?: 0f)
        if (shade <= 0.02f) null else GradientDrawable().apply {
            val e = edge(textColor(style, i))
            setColor(Color.argb((shade * 255).toInt(), Color.red(e), Color.green(e), Color.blue(e)))
            cornerRadius = px * 0.3f
        }
    } else GradientDrawable().apply {
        val p = style.plateRgb
        setColor(i?.bg.takeIf { style.mode == "native" } ?: Color.argb((style.bgOpacity * 255).toInt(), Color.red(p), Color.green(p), Color.blue(p)))
        cornerRadius = if (style.mode == "native") 4f else px * 0.3f
    }

    private fun fillBlock(v: LinearLayout, lines: List<Pair<SubItem, Boolean>>, px: Float, style: OverlayStyle) {
        v.removeAllViews()
        v.gravity = Gravity.CENTER
        v.background = background(style, lines.lastOrNull { !it.second }?.first ?: lines.lastOrNull()?.first, px)
        v.setPadding((px * 0.5f).toInt(), (px * 0.18f).toInt(), (px * 0.5f).toInt(), (px * 0.18f).toInt())
        for ((i, old) in lines) {
            v.addView(TextView(context).apply {
                base(px, style, i)
                text = line(i.text, i.speaker)
                setTextColor(textColor(style, i))
                alpha = if (old) 0.55f else 1f
            }, LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { gravity = Gravity.CENTER_HORIZONTAL })
        }
    }

    private fun plate(v: TextView, i: SubItem, px: Float, style: OverlayStyle) = v.apply {
        base(px, style, i)
        this.text = line(i.text, null)
        setTextColor(textColor(style, i))
        background = background(style, i, px)
        setPadding((px * 0.3f).toInt(), (px * 0.05f).toInt(), (px * 0.3f).toInt(), (px * 0.05f).toInt())
    }

    private fun fillHistory(v: LinearLayout, style: OverlayStyle, sh: Int) {
        v.removeAllViews()
        val p = style.plateRgb
        v.background = GradientDrawable().apply { setColor(Color.argb((style.bgOpacity.coerceIn(0.35f, 0.9f) * 200).toInt(), Color.red(p), Color.green(p), Color.blue(p))); cornerRadius = sh * 0.012f }
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
                setTextColor(if (lightPlate(style)) Color.rgb(18, 18, 23) else Color.WHITE)
                // Older lines fade back; the newest is brightest.
                alpha = 0.45f + 0.55f * (n + 1) / items.size
                setPadding(0, (px * 0.25f).toInt(), 0, (px * 0.25f).toInt())
            })
        }
    }
}
