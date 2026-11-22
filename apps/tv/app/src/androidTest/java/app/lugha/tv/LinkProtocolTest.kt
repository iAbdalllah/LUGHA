package app.lugha.tv

import androidx.test.ext.junit.runners.AndroidJUnit4
import app.lugha.tv.link.LinkHub
import app.lugha.tv.link.LinkServer
import app.lugha.tv.link.NativeLink
import app.lugha.tv.link.Wire
import app.lugha.tv.link.Wire.str
import java.io.DataInputStream
import java.io.DataOutputStream
import java.io.EOFException
import java.net.Socket
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/** A real sender (Rust via JNI) against the real receiver over a loopback TCP socket, inside the app process. */
@RunWith(AndroidJUnit4::class)
class LinkProtocolTest {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private lateinit var server: LinkServer
    private var port = 0

    private class Conn(val socket: Socket) {
        val input = DataInputStream(socket.getInputStream())
        val out = DataOutputStream(socket.getOutputStream())
        fun send(type: Int, b: ByteArray) = Wire.write(out, type, b)
        fun read() = Wire.read(input)
    }

    @Before fun setUp() {
        LinkHub.store.senders.forEach { LinkHub.removeSender(it.publicKey) }
        LinkHub.onPairingLocked(0)
        server = LinkServer(scope)
        port = server.start(47911)
    }

    @After fun tearDown() {
        server.stop()
        scope.cancel()
    }

    private fun connect() = Conn(Socket("127.0.0.1", port).apply { tcpNoDelay = true; soTimeout = 10_000 })

    /** Pairs with `code`; returns the TV's public key, or null if the TV refused. */
    private fun pair(c: Conn, secret: String, code: String): String? {
        val start = Wire.result(NativeLink.senderPairStart(code, secret))
        c.send(Wire.PAIR1, Wire.unb64(start.str("msg")))
        val p2 = c.read()
        if (p2.type != Wire.PAIR2) return null
        val done = runCatching { Wire.result(NativeLink.senderPairFinish(start.str("handle").toLong(), Wire.b64(p2.payload))) }.getOrNull()
        if (done == null) {
            c.send(Wire.PAIR3, ByteArray(48)) // what a sender with the wrong code effectively sends: garbage the TV can't open
            return null
        }
        c.send(Wire.PAIR3, Wire.unb64(done.str("msg")))
        return if (c.read().type == Wire.PAIR_OK) done.str("peer") else null
    }

    private fun handshake(c: Conn, secret: String, tvPublic: String): Long {
        val init = Wire.result(NativeLink.sessionInitiate(secret, tvPublic))
        c.send(Wire.HS1, Wire.unb64(init.str("msg")))
        val hs2 = c.read()
        assertEquals(Wire.HS2, hs2.type)
        val handle = init.str("handle").toLong()
        Wire.result(NativeLink.sessionComplete(handle, Wire.b64(hs2.payload)))
        return handle
    }

    private fun sendJson(c: Conn, session: Long, json: String) = c.send(Wire.DATA, Wire.unb64(Wire.result(NativeLink.encrypt(session, json)).str("msg")))
    private fun readJson(c: Conn, session: Long) = Json.parseToJsonElement(Wire.result(NativeLink.decrypt(session, Wire.b64(c.read().payload))).str("text")).jsonObject

    @Test fun pairThenStreamSubtitlesWithLowLatency() {
        val mac = Wire.result(NativeLink.generateKeys())
        val c = connect()
        val tv = pair(c, mac.str("secret_hex"), LinkHub.state.value.pairingCode)!!
        assertEquals(LinkHub.store.publicKey, tv)
        assertEquals(1, LinkHub.store.senders.size)

        val s = handshake(c, mac.str("secret_hex"), tv)
        sendJson(c, s, """{"t":"hello","app":"test","app_version":"1","sender_name":"Test Mac"}""")
        assertEquals("welcome", readJson(c, s)["t"]!!.jsonPrimitive.content)

        val times = (1..200).map { i ->
            val t0 = System.nanoTime()
            sendJson(c, s, """{"t":"sub.show","id":$i,"sid":$i,"text":"نطلع مع الفجر رقم $i","speaker":"ريم","dir":"rtl","ack":true}""")
            val ack = readJson(c, s)
            assertEquals("ack", ack["t"]!!.jsonPrimitive.content)
            assertEquals(i.toLong(), ack["id"]!!.jsonPrimitive.content.toLong())
            (System.nanoTime() - t0) / 1_000_000.0
        }.sorted()
        val p95 = times[(times.size * 0.95).toInt()]
        android.util.Log.i("LughaLinkTest", "round-trip ms: p50=${times[times.size / 2]} p95=$p95 max=${times.last()}")
        assertTrue("p95 $p95 ms should be ≤ 50 ms", p95 <= 50.0)
        assertEquals("نطلع مع الفجر رقم 200", LinkHub.state.value.subtitle?.text)
        assertEquals("Test Mac", LinkHub.state.value.connectedTo)
        NativeLink.sessionFree(s)
        c.socket.close()

        // Reconnect later without the code: Noise IK with the stored keys.
        val c2 = connect()
        val s2 = handshake(c2, mac.str("secret_hex"), tv)
        sendJson(c2, s2, """{"t":"ping","id":9}""")
        assertEquals("pong", readJson(c2, s2)["t"]!!.jsonPrimitive.content)
        c2.socket.close()
    }

    @Test fun wrongCodesLockPairingAndStrangersCannotConnect() {
        val code = LinkHub.state.value.pairingCode
        val wrong = ((code.toInt() + 1) % 1_000_000).toString().padStart(6, '0')
        repeat(3) {
            val c = connect()
            val k = Wire.result(NativeLink.generateKeys())
            assertEquals(null, pair(c, k.str("secret_hex"), wrong))
            assertEquals(Wire.PAIR_FAIL, c.read().type)
            c.socket.close()
        }
        assertTrue(LinkHub.state.value.pairingLockedUntil > System.currentTimeMillis())
        assertTrue("code rotates after a failure", LinkHub.state.value.pairingCode != code)

        // A device that never paired can't open a session.
        val stranger = Wire.result(NativeLink.generateKeys())
        val c = connect()
        val init = Wire.result(NativeLink.sessionInitiate(stranger.str("secret_hex"), LinkHub.store.publicKey))
        c.send(Wire.HS1, Wire.unb64(init.str("msg")))
        val closed = runCatching { c.read() }.exceptionOrNull()
        assertTrue(closed is EOFException || closed is java.net.SocketException)
    }
}
