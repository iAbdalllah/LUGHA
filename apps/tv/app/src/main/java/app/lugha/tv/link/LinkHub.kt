package app.lugha.tv.link

import android.content.Context
import android.net.ConnectivityManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.util.Log
import android.view.Choreographer
import app.lugha.tv.BuildConfig
import java.net.Inet4Address
import kotlin.coroutines.resume
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.suspendCancellableCoroutine

/** A translated piece and where its English is on screen (fractions, origin top-left); [lines] = English line count. */
data class SubItem(val text: String, val speaker: String?, val x: Float, val y: Float, val w: Float, val h: Float, val lines: Int)

/** [items] place each piece over its English; empty = show [text] at the style's position. */
data class Subtitle(val sid: Long, val text: String, val speaker: String?, val receivedAt: Long, val items: List<SubItem> = emptyList())

data class HubState(
    val subtitle: Subtitle? = null,
    val history: List<Subtitle> = emptyList(),
    val connectedTo: String? = null,
    val pairingCode: String = "",
    val address: String? = null,
    val port: Int = Wire.DEFAULT_PORT,
    val lastLatencyMs: Long? = null,
    val pairingLockedUntil: Long = 0,
    val style: OverlayStyle = OverlayStyle(),
    val senders: List<PairedSender> = emptyList(),
    val justPaired: String? = null,
)

/** Process-wide receiver state shared by the server, the overlay service and the UI. */
object LinkHub {
    lateinit var store: LinkStore
        private set
    private val _state = MutableStateFlow(HubState())
    val state: StateFlow<HubState> = _state
    private val main = Handler(Looper.getMainLooper())
    private const val HISTORY = 50

    val tvName: String
        get() = runCatching { Settings.Global.getString(appContext.contentResolver, Settings.Global.DEVICE_NAME) }.getOrNull()
            ?: "${Build.MANUFACTURER} ${Build.MODEL}"

    private lateinit var appContext: Context

    fun init(context: Context) {
        appContext = context.applicationContext
        store = LinkStore(appContext)
        _state.update { it.copy(style = store.style, senders = store.senders, address = localAddress()) }
        newPairingCode()
    }

    fun newPairingCode() {
        val code = NativeLink.newPairingCode().trim('"')
        _state.update { it.copy(pairingCode = code) }
        // Debug builds log the code so automated tests can pair; release builds never do.
        if (BuildConfig.DEBUG) Log.i("LughaPair", "code=$code")
    }

    fun setPort(port: Int) = _state.update { it.copy(port = port, address = localAddress()) }

    fun localAddress(): String? = runCatching {
        val cm = appContext.getSystemService(ConnectivityManager::class.java)
        cm.getLinkProperties(cm.activeNetwork)?.linkAddresses?.map { it.address }?.firstOrNull { it is Inet4Address && !it.isLoopbackAddress }?.hostAddress
    }.getOrNull()

    // ── called by the server ──
    fun onConnected(name: String?) = _state.update { it.copy(connectedTo = name ?: "…") }
    fun onDisconnected() = _state.update { it.copy(connectedTo = null) }
    fun onPairingLocked(until: Long) = _state.update { it.copy(pairingLockedUntil = until) }

    fun onPaired(sender: PairedSender) {
        store.addSender(sender)
        _state.update { it.copy(senders = store.senders, justPaired = sender.name) }
        newPairingCode()
    }

    fun renameSender(publicKey: String, name: String) {
        store.senders.firstOrNull { it.publicKey == publicKey && it.name != name }?.let { store.addSender(it.copy(name = name)) }
        _state.update { it.copy(senders = store.senders) }
    }

    fun show(sub: Subtitle) = _state.update { s ->
        s.copy(subtitle = sub, history = (listOf(sub) + s.history.filter { it.sid != sub.sid }).take(HISTORY))
    }

    fun clear() = _state.update { it.copy(subtitle = null) }
    fun latency(ms: Long) = _state.update { it.copy(lastLatencyMs = ms) }

    fun setStyle(style: OverlayStyle) {
        store.style = style
        _state.update { it.copy(style = style) }
    }

    fun removeSender(publicKey: String) {
        store.removeSender(publicKey)
        _state.update { it.copy(senders = store.senders) }
    }

    fun consumeJustPaired() = _state.update { it.copy(justPaired = null) }

    /** Resumes after the next frame has been drawn (used for "painted" acks). */
    suspend fun awaitFrame() = suspendCancellableCoroutine { cont ->
        main.post { Choreographer.getInstance().postFrameCallback { if (cont.isActive) cont.resume(Unit) } }
    }
}
