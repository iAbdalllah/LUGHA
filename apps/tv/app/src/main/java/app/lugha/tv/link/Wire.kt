package app.lugha.tv.link

import android.util.Base64
import java.io.DataInputStream
import java.io.DataOutputStream
import java.io.EOFException
import java.io.IOException
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive

/** LUGHA Link framing: u32 BE length (type byte + payload) · u8 type · payload. */
object Wire {
    const val PAIR1: Int = 0x01
    const val PAIR2: Int = 0x02
    const val PAIR3: Int = 0x03
    const val PAIR_OK: Int = 0x04
    const val PAIR_FAIL: Int = 0x0F
    const val HS1: Int = 0x10
    const val HS2: Int = 0x11
    const val DATA: Int = 0x20
    const val MAX_FRAME = 64 * 1024
    const val DEFAULT_PORT = 47800
    const val SERVICE_TYPE = "_lugha._tcp."

    data class Frame(val type: Int, val payload: ByteArray)

    fun read(input: DataInputStream): Frame {
        val len = try { input.readInt() } catch (e: EOFException) { throw e }
        if (len < 1 || len > MAX_FRAME) throw IOException("bad frame length $len")
        val type = input.readUnsignedByte()
        val payload = ByteArray(len - 1)
        input.readFully(payload)
        return Frame(type, payload)
    }

    fun write(out: DataOutputStream, type: Int, payload: ByteArray) {
        synchronized(out) {
            out.writeInt(payload.size + 1)
            out.writeByte(type)
            out.write(payload)
            out.flush()
        }
    }

    fun b64(b: ByteArray): String = Base64.encodeToString(b, Base64.NO_WRAP)
    fun unb64(s: String): ByteArray = Base64.decode(s, Base64.NO_WRAP)

    private val json = Json { ignoreUnknownKeys = true }

    /** Parses a native JSON result; throws with the native error message when ok=false. */
    fun result(raw: String): JsonObject {
        val o = json.parseToJsonElement(raw).jsonObject
        if (o["ok"]?.jsonPrimitive?.content != "true") throw IOException(o["error"]?.jsonPrimitive?.content ?: "native error")
        return o
    }

    fun JsonObject.str(key: String): String = this[key]!!.jsonPrimitive.content
}
