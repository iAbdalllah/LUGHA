// LUGHA Remote Play (Android). AGPL-3.0 — see ../../../../../../LICENSE.
//
// Uses chiaki-ng's Kotlin binding (com.metallic.chiaki.lib, compiled from the submodule) for discovery, registration
// and the Remote Play session. The session's video decoder renders into an ImageReader instead of a screen; a few
// times a second the latest frame is read with ML Kit's on-device text recognizer, and the lines inside the subtitle
// area are sent to Dart. Nothing is displayed and no game audio is played (see SessionHost.mute).
package app.lugha.remoteplay

import android.graphics.ImageFormat
import android.graphics.Rect
import android.media.ImageReader
import android.os.Handler
import android.os.HandlerThread
import android.os.Looper
import android.util.Base64
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.text.TextRecognition
import com.google.mlkit.vision.text.latin.TextRecognizerOptions
import com.metallic.chiaki.lib.Codec
import com.metallic.chiaki.lib.ConnectInfo
import com.metallic.chiaki.lib.ConnectVideoProfile
import com.metallic.chiaki.lib.ConnectedEvent
import com.metallic.chiaki.lib.ChiakiLog
import com.metallic.chiaki.lib.DiscoveryHost
import com.metallic.chiaki.lib.DiscoveryService
import com.metallic.chiaki.lib.DiscoveryServiceOptions
import com.metallic.chiaki.lib.LoginPinRequestEvent
import com.metallic.chiaki.lib.QuitEvent
import com.metallic.chiaki.lib.Regist
import com.metallic.chiaki.lib.RegistEventCanceled
import com.metallic.chiaki.lib.RegistEventFailed
import com.metallic.chiaki.lib.RegistEventSuccess
import com.metallic.chiaki.lib.RegistInfo
import com.metallic.chiaki.lib.Session
import com.metallic.chiaki.lib.Target
import com.metallic.chiaki.lib.VideoFPSPreset
import com.metallic.chiaki.lib.VideoResolutionPreset
import io.flutter.embedding.engine.plugins.FlutterPlugin
import io.flutter.plugin.common.EventChannel
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import java.net.InetSocketAddress

class LughaRemotePlayPlugin : FlutterPlugin, MethodChannel.MethodCallHandler, EventChannel.StreamHandler {
    private lateinit var channel: MethodChannel
    private lateinit var events: EventChannel
    private var sink: EventChannel.EventSink? = null
    private val main = Handler(Looper.getMainLooper())
    private var session: SessionHost? = null

    override fun onAttachedToEngine(binding: FlutterPlugin.FlutterPluginBinding) {
        channel = MethodChannel(binding.binaryMessenger, "app.lugha/remoteplay").also { it.setMethodCallHandler(this) }
        events = EventChannel(binding.binaryMessenger, "app.lugha/remoteplay/events").also { it.setStreamHandler(this) }
    }

    override fun onDetachedFromEngine(binding: FlutterPlugin.FlutterPluginBinding) {
        session?.stop()
        session = null
        channel.setMethodCallHandler(null)
        events.setStreamHandler(null)
    }

    override fun onListen(arguments: Any?, events: EventChannel.EventSink?) { sink = events }
    override fun onCancel(arguments: Any?) { sink = null }

    private fun emit(e: Map<String, Any?>) = main.post { sink?.success(e) }

    override fun onMethodCall(call: MethodCall, result: MethodChannel.Result) {
        try {
            when (call.method) {
                "discover" -> discover(call.argument<Int>("timeoutMs") ?: 3000, result)
                "register" -> register(call, result)
                "wakeup" -> {
                    // The wake-up credential is the registration key, which the console sends as hex text
                    // (chiaki-ng's DiscoveryManager does the same).
                    val registKey = Base64.decode(call.argument<String>("registKey"), Base64.DEFAULT)
                    val text = String(registKey.takeWhile { it != 0.toByte() }.toByteArray(), Charsets.US_ASCII)
                    val credential = text.toULongOrNull(16) ?: run { result.error("bad_key", "Unreadable registration key", null); return }
                    // Fire-and-forget UDP packet; the PS5 shows up as ready in discovery a few seconds later.
                    DiscoveryService.wakeup(null, call.argument<String>("host")!!, credential, call.argument<Boolean>("ps5") ?: true)
                    result.success(null)
                }
                "start" -> {
                    session?.stop()
                    val area = areaOf(call.argument<Map<String, Double>>("area"))
                    session = SessionHost(
                        host = call.argument<String>("host")!!,
                        ps5 = call.argument<Boolean>("ps5") ?: true,
                        registKey = Base64.decode(call.argument<String>("registKey"), Base64.DEFAULT),
                        morning = Base64.decode(call.argument<String>("rpKey"), Base64.DEFAULT),
                        area = area,
                        fps = call.argument<Int>("fps") ?: 4,
                        emit = ::emit,
                    ).also { it.start() }
                    result.success(null)
                }
                "setArea" -> { session?.area = areaOf(call.argument<Map<String, Double>>("area")); result.success(null) }
                "setLoginPin" -> { session?.setLoginPin(call.argument<String>("pin") ?: ""); result.success(null) }
                "stop" -> { session?.stop(); session = null; result.success(null) }
                else -> result.notImplemented()
            }
        } catch (e: Exception) {
            result.error("remoteplay_error", e.message ?: e.toString(), null)
        }
    }

    private fun areaOf(m: Map<String, Double>?) =
        if (m == null) floatArrayOf(0.08f, 0.70f, 0.84f, 0.26f)
        else floatArrayOf(m["x"]!!.toFloat(), m["y"]!!.toFloat(), m["w"]!!.toFloat(), m["h"]!!.toFloat())

    // ---- discovery ----

    private fun discover(timeoutMs: Int, result: MethodChannel.Result) {
        val found = linkedMapOf<String, DiscoveryHost>()
        val service = DiscoveryService(
            DiscoveryServiceOptions(16UL, 3UL, 500UL, InetSocketAddress("255.255.255.255", 987)),
        ) { hosts -> synchronized(found) { hosts.forEach { h -> h.hostAddr?.let { found[it] = h } } } }
        main.postDelayed({
            service.dispose()
            val list = synchronized(found) { found.values.toList() }.map { h ->
                mapOf(
                    "host" to h.hostAddr, "name" to h.hostName, "id" to h.hostId, "ps5" to h.isPS5, "app" to h.runningAppName,
                    "state" to when (h.state) { DiscoveryHost.State.READY -> "ready"; DiscoveryHost.State.STANDBY -> "standby"; else -> "unknown" },
                )
            }
            result.success(list)
        }, timeoutMs.toLong())
    }

    // ---- registration ----

    private fun register(call: MethodCall, result: MethodChannel.Result) {
        val accountId = Base64.decode(call.argument<String>("accountId"), Base64.DEFAULT)
        if (accountId.size != RegistInfo.ACCOUNT_ID_SIZE) {
            result.error("bad_account_id", "PSN account id must be 8 bytes", null); return
        }
        val pin = call.argument<String>("pin")!!.filter(Char::isDigit)
        val ps5 = call.argument<Boolean>("ps5") ?: true
        val host = call.argument<String>("host")!!
        val info = RegistInfo(if (ps5) Target.PS5_1 else Target.PS4_10, host, false, null, accountId, pin.toInt())
        val log = ChiakiLog(ChiakiLog.Level.INFO.value or ChiakiLog.Level.WARNING.value or ChiakiLog.Level.ERROR.value) { _, _ -> }
        var regist: Regist? = null
        var done = false
        regist = Regist(info, log) { ev ->
            main.post {
                if (done) return@post
                done = true
                when (ev) {
                    is RegistEventSuccess -> {
                        val h = ev.host
                        result.success(
                            mapOf(
                                "host" to host, "ps5" to h.target.isPS5, "nickname" to h.serverNickname,
                                "regist_key" to Base64.encodeToString(h.rpRegistKey, Base64.NO_WRAP),
                                "rp_key" to Base64.encodeToString(h.rpKey, Base64.NO_WRAP),
                                "mac" to h.serverMac.joinToString(":") { "%02x".format(it) },
                            ),
                        )
                    }
                    is RegistEventFailed -> result.error("regist_failed", "Registration failed — check the PIN and that this phone is on the same network as the PS5.", null)
                    is RegistEventCanceled -> result.error("regist_canceled", "Registration canceled", null)
                }
                regist?.dispose()
            }
        }
    }
}

/**
 * One Remote Play session whose frames are read, not shown.
 * [area] is (x, y, w, h) as fractions of the frame; it can change while running.
 */
private class SessionHost(
    host: String,
    ps5: Boolean,
    registKey: ByteArray,
    morning: ByteArray,
    @Volatile var area: FloatArray,
    fps: Int,
    private val emit: (Map<String, Any?>) -> Unit,
) {
    // 720p is plenty for subtitle text and keeps decoding and recognition light.
    private val profile = ConnectVideoProfile.preset(VideoResolutionPreset.RES_720P, VideoFPSPreset.FPS_30, Codec.CODEC_H264)
    private val session = Session(ConnectInfo(ps5, host, registKey, morning, profile), null, false)
    private val thread = HandlerThread("lugha-rp-frames").also { it.start() }
    private val handler = Handler(thread.looper)
    private val reader = ImageReader.newInstance(profile.width, profile.height, ImageFormat.YUV_420_888, 3)
    private val recognizer = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)
    private val intervalMs = 1000L / fps.coerceIn(1, 10)
    @Volatile private var busy = false
    @Volatile private var lastRun = 0L
    private var lastLines: List<String> = emptyList()

    fun start() {
        session.eventCallback = { e ->
            when (e) {
                is ConnectedEvent -> emit(mapOf("type" to "connected"))
                is LoginPinRequestEvent -> emit(mapOf("type" to "pin", "incorrect" to e.pinIncorrect))
                is QuitEvent -> emit(mapOf("type" to "stopped", "reason" to (e.reasonString ?: e.reason.toString()), "error" to e.reason.isError))
                else -> {}
            }
        }
        reader.setOnImageAvailableListener({ r -> onFrame(r) }, handler)
        session.setSurface(reader.surface)
        val err = session.start()
        if (!err.isSuccess) {
            emit(mapOf("type" to "stopped", "reason" to err.toString(), "error" to true))
        }
    }

    private fun onFrame(r: ImageReader) {
        val image = r.acquireLatestImage() ?: return
        val now = System.currentTimeMillis()
        if (busy || now - lastRun < intervalMs) { image.close(); return }
        busy = true
        lastRun = now
        val (w, h) = image.width to image.height
        val a = area
        val box = Rect((a[0] * w).toInt(), (a[1] * h).toInt(), ((a[0] + a[2]) * w).toInt(), ((a[1] + a[3]) * h).toInt())
        recognizer.process(InputImage.fromMediaImage(image, 0))
            .addOnSuccessListener { text ->
                // Lines whose centre falls inside the subtitle area, top to bottom.
                val lines = text.textBlocks.flatMap { it.lines }
                    .filter { l -> l.boundingBox?.let { box.contains(it.centerX(), it.centerY()) } == true }
                    .sortedBy { it.boundingBox?.top ?: 0 }
                    .map { it.text.trim() }
                    .filter { it.isNotEmpty() }
                if (lines != lastLines) {
                    lastLines = lines
                    emit(mapOf("type" to "text", "lines" to lines))
                }
            }
            .addOnCompleteListener {
                image.close()
                busy = false
            }
    }

    fun setLoginPin(pin: String) = session.setLoginPin(pin)

    fun stop() {
        session.eventCallback = null
        session.stop()
        session.setSurface(null)
        session.dispose()
        reader.close()
        recognizer.close()
        thread.quitSafely()
    }
}
