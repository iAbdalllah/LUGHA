package app.lugha.tv.link

import android.os.SystemClock
import android.util.Log
import app.lugha.tv.link.Wire.str
import java.io.BufferedInputStream
import java.io.BufferedOutputStream
import java.io.DataInputStream
import java.io.DataOutputStream
import java.io.IOException
import java.net.InetSocketAddress
import java.net.ServerSocket
import java.net.Socket
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.boolean
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.float
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.long
import kotlinx.serialization.json.put

/**
 * LUGHA Link receiver (docs/05). One active sender at a time — a new connection from a paired sender takes
 * over (switching between Macs needs no extra step). Pairing is locked for 60 s after 3 wrong codes.
 */
class LinkServer(private val scope: CoroutineScope, private val hub: LinkHub = LinkHub) {
    private var server: ServerSocket? = null
    private var active: Socket? = null
    private var acceptJob: Job? = null
    private var failures = 0

    val port: Int get() = server?.localPort ?: -1

    fun start(preferredPort: Int = Wire.DEFAULT_PORT): Int {
        val s = ServerSocket().apply { reuseAddress = true }
        try {
            s.bind(InetSocketAddress(preferredPort))
        } catch (e: IOException) {
            s.bind(InetSocketAddress(0)) // port taken: fall back to any free port (advertised over mDNS)
        }
        server = s
        acceptJob = scope.launch(Dispatchers.IO) {
            while (isActive) {
                val socket = try { s.accept() } catch (e: IOException) { break }
                active?.let { runCatching { it.close() } }
                active = socket
                launch(Dispatchers.IO) { handle(socket) }
            }
        }
        return s.localPort
    }

    fun stop() {
        acceptJob?.cancel()
        runCatching { active?.close() }
        runCatching { server?.close() }
    }

    private suspend fun handle(socket: Socket) {
        var session = 0L
        try {
            socket.tcpNoDelay = true
            socket.soTimeout = 20_000
            val input = DataInputStream(BufferedInputStream(socket.getInputStream()))
            val out = DataOutputStream(BufferedOutputStream(socket.getOutputStream()))
            val store = hub.store

            while (true) {
                val f = Wire.read(input)
                when (f.type) {
                    Wire.PAIR1 -> if (!pair(f.payload, input, out)) return
                    Wire.HS1 -> {
                        val r = Wire.result(NativeLink.sessionAccept(store.secretHex, Wire.b64(f.payload), store.trustedJson()))
                        session = r.str("handle").toLong()
                        Wire.write(out, Wire.HS2, Wire.unb64(r.str("msg")))
                        val peer = r.str("peer")
                        hub.onConnected(store.senders.firstOrNull { it.publicKey == peer }?.name)
                        dataLoop(session, peer, input, out)
                        return
                    }
                    else -> return
                }
            }
        } catch (e: Exception) {
            Log.w("LughaLink", "connection closed: ${e.message}")
        } finally {
            if (session != 0L) NativeLink.sessionFree(session)
            runCatching { socket.close() }
            if (active === socket) hub.onDisconnected()
        }
    }

    /** Returns true when pairing succeeded (the sender then continues with HS1 on the same connection). */
    private fun pair(pair1: ByteArray, input: DataInputStream, out: DataOutputStream): Boolean {
        val now = System.currentTimeMillis()
        if (now < hub.state.value.pairingLockedUntil) {
            Wire.write(out, Wire.PAIR_FAIL, "locked".toByteArray())
            return false
        }
        val handle = NativeLink.pairingNew(hub.state.value.pairingCode, hub.store.secretHex)
        try {
            val p2 = Wire.result(NativeLink.pairingRespond(handle, Wire.b64(pair1)))
            Wire.write(out, Wire.PAIR2, Wire.unb64(p2.str("msg")))
            val f3 = Wire.read(input)
            if (f3.type != Wire.PAIR3) return false
            val done = try { Wire.result(NativeLink.pairingFinish(handle, Wire.b64(f3.payload))) } catch (e: IOException) { null }
            if (done == null) {
                if (++failures >= 3) {
                    failures = 0
                    hub.onPairingLocked(now + 60_000)
                }
                hub.newPairingCode() // never let a code be guessed twice
                Wire.write(out, Wire.PAIR_FAIL, "wrong_code".toByteArray())
                return false
            }
            failures = 0
            hub.onPaired(PairedSender(done.str("peer"), done.str("fingerprint"), "Mac", System.currentTimeMillis()))
            Wire.write(out, Wire.PAIR_OK, ByteArray(0))
            return true
        } finally {
            NativeLink.pairingFree(handle)
        }
    }

    private val json = Json { ignoreUnknownKeys = true }

    private suspend fun dataLoop(session: Long, peer: String, input: DataInputStream, out: DataOutputStream) {
        fun send(obj: JsonObject) = Wire.write(out, Wire.DATA, Wire.unb64(Wire.result(NativeLink.encrypt(session, obj.toString())).str("msg")))

        while (true) {
            val f = Wire.read(input)
            if (f.type != Wire.DATA) return
            val t0 = SystemClock.elapsedRealtime()
            val m = json.parseToJsonElement(Wire.result(NativeLink.decrypt(session, Wire.b64(f.payload))).str("text")).jsonObject
            when (m["t"]?.jsonPrimitive?.content) {
                "hello" -> {
                    val name = m["sender_name"]?.jsonPrimitive?.content ?: "Mac"
                    hub.renameSender(peer, name)
                    hub.onConnected(name)
                    send(buildJsonObject { put("t", "welcome"); put("tv_name", hub.tvName); put("screen_w", 1920); put("screen_h", 1080) })
                }
                "sub.show" -> {
                    val items = (m["items"] as? kotlinx.serialization.json.JsonArray).orEmpty().mapNotNull { e ->
                        val o = e as? JsonObject ?: return@mapNotNull null
                        fun f(k: String) = o[k]?.jsonPrimitive?.content?.toFloatOrNull()
                        fun color(k: String) = o[k]?.jsonPrimitive?.content?.let { runCatching { android.graphics.Color.parseColor(it) }.getOrNull() }
                        SubItem(o["text"]?.jsonPrimitive?.content ?: return@mapNotNull null, o["speaker"]?.jsonPrimitive?.content?.takeIf { it != "null" },
                            f("x") ?: return@mapNotNull null, f("y") ?: return@mapNotNull null, f("w") ?: 0f, f("h") ?: 0f,
                            o["lines"]?.jsonPrimitive?.content?.toIntOrNull() ?: 1, color("bg"), color("fg"))
                    }.filter { it.w > 0f && it.h > 0f }
                    hub.show(Subtitle(m["sid"]?.jsonPrimitive?.long ?: 0, m["text"]?.jsonPrimitive?.content ?: "",
                        m["speaker"]?.jsonPrimitive?.content?.takeIf { it != "null" }, System.currentTimeMillis(), items))
                    if (m["ack"]?.jsonPrimitive?.boolean == true) {
                        hub.awaitFrame()
                        val painted = SystemClock.elapsedRealtime() - t0
                        hub.latency(painted)
                        send(buildJsonObject { put("t", "ack"); put("id", m["id"]!!.jsonPrimitive.long); put("painted_ms", painted) })
                    }
                }
                "sub.clear" -> hub.clear()
                "style.set" -> hub.setStyle(OverlayStyle(
                    fontScale = m["font_scale"]?.jsonPrimitive?.float ?: 1f,
                    positionY = (m["position_y"]?.jsonPrimitive?.float ?: 0.86f).coerceIn(0.05f, 0.95f),
                    bgOpacity = m["bg_opacity"]?.jsonPrimitive?.float ?: 0.8f,
                    maxWidth = (m["max_width"]?.jsonPrimitive?.float ?: 0.8f).coerceIn(0.3f, 0.95f),
                    mode = m["mode"]?.jsonPrimitive?.content ?: "native",
                    textColor = m["text_color"]?.jsonPrimitive?.content ?: "white",
                    font = m["font"]?.jsonPrimitive?.content ?: ""))
                "ping" -> send(buildJsonObject { put("t", "pong"); put("id", m["id"]!!.jsonPrimitive.long) })
                "bye" -> return
            }
        }
    }
}
