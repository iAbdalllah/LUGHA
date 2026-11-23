package app.lugha.tv.link

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.graphics.Color
import android.graphics.PixelFormat
import android.graphics.drawable.GradientDrawable
import android.os.Build
import android.os.IBinder
import android.provider.Settings
import android.text.SpannableStringBuilder
import android.text.Spanned
import android.text.style.ForegroundColorSpan
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.view.WindowManager
import android.widget.TextView
import app.lugha.tv.R
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch

/**
 * Keeps the receiver alive while the TV shows another input or app, and — when the user enabled it and the
 * TV grants "display over other apps" — draws subtitles above everything.
 */
class LinkService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private lateinit var server: LinkServer
    private lateinit var discovery: Discovery
    private var overlay: android.widget.FrameLayout? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        startForegroundCompat()
        server = LinkServer(scope)
        val port = server.start()
        LinkHub.setPort(port)
        discovery = Discovery(this).also { it.register(LinkHub.tvName, port, LinkHub.store.fingerprint) }
        scope.launch { LinkHub.state.collectLatest { renderOverlay(it) } }
    }

    override fun onDestroy() {
        discovery.unregister()
        server.stop()
        overlay?.let { getSystemService(WindowManager::class.java).removeView(it) }
        scope.cancel()
        super.onDestroy()
    }

    private fun startForegroundCompat() {
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel("link", getString(R.string.app_name), NotificationManager.IMPORTANCE_MIN))
        val n = Notification.Builder(this, "link").setContentTitle(getString(R.string.service_running)).setSmallIcon(android.R.drawable.ic_menu_view).build()
        if (Build.VERSION.SDK_INT >= 29) startForeground(1, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_CONNECTED_DEVICE) else startForeground(1, n)
    }

    /** Plates on screen, keyed by their text, so a label that stays is updated in place instead of redrawn. */
    private val plates = mutableMapOf<String, TextView>()

    private fun renderOverlay(s: HubState) {
        val wanted = LinkHub.store.overlayMode && Settings.canDrawOverlays(this) && !AppVisibility.foreground
        val layer = if (wanted) overlay ?: createOverlay().also { overlay = it } else overlay
        if (layer == null) return
        val sub = s.subtitle
        if (!wanted || sub == null) {
            layer.removeAllViews()
            plates.clear()
            layer.visibility = View.GONE
            return
        }
        val dm = resources.displayMetrics
        val (sw, sh) = dm.widthPixels to dm.heightPixels
        val style = s.style
        // What to show: each placed piece, or (older senders) one line at the style's position.
        data class Want(val text: String, val speaker: String?, val sp: Float, val bg: Int?, val fg: Int?, val maxW: Int, val minW: Int, val minH: Int, val cx: Float, val cy: Float)
        val wants = if (sub.items.isEmpty()) {
            listOf(Want(sub.text, sub.speaker, 34f * style.fontScale, null, null, (sw * style.maxWidth).toInt(), 0, 0, sw / 2f, sh * style.positionY))
        } else sub.items.map { i ->
            val lineH = i.h * sh / i.lines.coerceAtLeast(1)
            val px = (lineH * 0.9f * style.fontScale).coerceIn(18f, 84f)
            val maxW = (i.w * sw * 1.6f).coerceAtLeast(sw * 0.25f).coerceAtMost(sw * 0.9f).toInt()
            val native = style.mode == "native"
            Want(i.text, i.speaker, px / dm.scaledDensity, i.bg, i.fg, maxW,
                (i.w * sw).toInt().coerceAtMost(maxW) + (if (native) 8 else 0), if (native) (i.h * sh).toInt() + 4 else 0,
                (i.x + i.w / 2) * sw, (i.y + i.h / 2) * sh)
        }
        val keep = wants.map { it.text }.toSet()
        plates.keys.filter { it !in keep }.forEach { layer.removeView(plates.remove(it)) }
        for (w in wants) {
            val v = plates[w.text] ?: TextView(this).also { plates[w.text] = it }
            styleplate(v, w.text, w.speaker, w.sp, style, w.bg, w.fg, w.maxW)
            v.minWidth = w.minW
            v.minHeight = w.minH
            v.measure(View.MeasureSpec.makeMeasureSpec(w.maxW, View.MeasureSpec.AT_MOST), View.MeasureSpec.UNSPECIFIED)
            place(layer, v, w.cx, w.cy, sw, sh)
        }
        layer.visibility = View.VISIBLE
    }

    private fun typeface(font: String): android.graphics.Typeface {
        val res = when (font) {
            "naskh" -> R.font.noto_naskh_arabic
            "kufi" -> R.font.noto_kufi_arabic
            "cairo" -> R.font.cairo
            "tajawal" -> R.font.tajawal_bold
            "amiri" -> R.font.amiri_bold
            else -> 0
        }
        val base = if (res != 0) androidx.core.content.res.ResourcesCompat.getFont(this, res) ?: android.graphics.Typeface.DEFAULT else android.graphics.Typeface.DEFAULT
        return if (Build.VERSION.SDK_INT >= 28) android.graphics.Typeface.create(base, 600, false) else android.graphics.Typeface.create(base, android.graphics.Typeface.BOLD)
    }

    /**
     * One translated piece in the chosen style: native (the game's own background and text colours on a plate that
     * covers the English — reads as if the game drew Arabic), box (white/yellow on a dark rounded plate) or outline.
     */
    private fun styleplate(view: TextView, text: String, speaker: String?, sp: Float, style: OverlayStyle, bg: Int?, fg: Int?, maxW: Int) = view.apply {
        val chosen = if (style.textColor == "yellow") Color.rgb(255, 219, 64) else Color.WHITE
        this.text = SpannableStringBuilder().apply {
            speaker?.let {
                append(it).append("\n")
                setSpan(ForegroundColorSpan(Color.rgb(0xE9, 0xB4, 0x4C)), 0, length, Spanned.SPAN_EXCLUSIVE_EXCLUSIVE)
            }
            append(text)
        }
        setTextSize(TypedValue.COMPLEX_UNIT_SP, sp)
        textDirection = View.TEXT_DIRECTION_RTL
        gravity = Gravity.CENTER
        setLineSpacing(0f, 1.1f)
        typeface = typeface(style.font)
        val em = sp * resources.displayMetrics.scaledDensity
        when (style.mode) {
            "native" -> {
                setShadowLayer(0f, 0f, 0f, 0)
                setTextColor(fg ?: Color.WHITE)
                setPadding((em * 0.25f).toInt(), 0, (em * 0.25f).toInt(), 0)
                background = GradientDrawable().apply { setColor(bg ?: Color.argb((style.bgOpacity * 255).toInt(), 0, 0, 0)); cornerRadius = 4f }
            }
            "outline" -> {
                background = null
                setTextColor(chosen)
                setShadowLayer(em * 0.16f, 0f, 0f, Color.BLACK)
                setPadding((em * 0.2f).toInt(), 0, (em * 0.2f).toInt(), 0)
            }
            else -> {
                setTextColor(chosen)
                setShadowLayer(4f, 0f, 2f, Color.argb(220, 0, 0, 0))
                setPadding((em * 0.7f).toInt(), (em * 0.28f).toInt(), (em * 0.7f).toInt(), (em * 0.28f).toInt())
                background = GradientDrawable().apply { setColor(Color.argb((style.bgOpacity * 255).toInt(), 0, 0, 0)); cornerRadius = em * 0.35f }
            }
        }
        maxWidth = maxW
    }

    /** Centres [v] (already measured) on (cx, cy), kept fully on screen. */
    private fun place(layer: android.widget.FrameLayout, v: TextView, cx: Float, cy: Float, sw: Int, sh: Int) {
        val w = v.measuredWidth
        val h = v.measuredHeight
        // Absolute LEFT/TOP: with the TV in Arabic the layout is right-to-left, and START would mirror every position.
        val lp = android.widget.FrameLayout.LayoutParams(android.widget.FrameLayout.LayoutParams.WRAP_CONTENT, android.widget.FrameLayout.LayoutParams.WRAP_CONTENT,
            Gravity.LEFT or Gravity.TOP)
        lp.leftMargin = (cx - w / 2f).toInt().coerceIn(0, (sw - w).coerceAtLeast(0))
        lp.topMargin = (cy - h / 2f).toInt().coerceIn(0, (sh - h).coerceAtLeast(0))
        if (v.parent == null) layer.addView(v, lp) else v.layoutParams = lp
    }

    /** A full-screen, click-through, transparent layer above other apps and inputs. */
    private fun createOverlay(): android.widget.FrameLayout {
        val layer = android.widget.FrameLayout(this).apply { layoutDirection = View.LAYOUT_DIRECTION_LTR }
        val lp = WindowManager.LayoutParams(
            WindowManager.LayoutParams.MATCH_PARENT, WindowManager.LayoutParams.MATCH_PARENT,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE or
                WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN or WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS,
            PixelFormat.TRANSLUCENT,
        ).apply { gravity = Gravity.TOP or Gravity.LEFT }
        getSystemService(WindowManager::class.java).addView(layer, lp)
        return layer
    }

    companion object {
        fun start(context: Context) {
            context.startForegroundService(Intent(context, LinkService::class.java))
        }
    }
}

/** Set by the activity so the overlay hides while LUGHA TV's own screen is showing. */
object AppVisibility {
    @Volatile var foreground = false
}
