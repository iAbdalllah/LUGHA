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

    private var renderer: OverlayRenderer? = null

    private fun renderOverlay(s: HubState) {
        val wanted = LinkHub.store.overlayMode && Settings.canDrawOverlays(this) && !AppVisibility.foreground
        val layer = if (wanted) overlay ?: createOverlay().also { overlay = it } else overlay
        if (layer == null) return
        val r = renderer ?: OverlayRenderer(this, layer).also { renderer = it }
        if (!wanted) {
            r.clear()
            layer.visibility = View.GONE
            return
        }
        r.render(s)
        layer.visibility = View.VISIBLE
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
