package app.lugha.tv.link

import android.content.Context
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import app.lugha.tv.link.Wire.str

@Serializable
data class PairedSender(val publicKey: String, val fingerprint: String, val name: String, val pairedAt: Long)

@Serializable
data class OverlayStyle(val fontScale: Float = 1.0f, val positionBottom: Boolean = true, val bgOpacity: Float = 0.75f)

/** This TV's link identity, its paired senders and display preferences (app-private storage). */
class LinkStore(context: Context) {
    private val prefs = context.getSharedPreferences("lugha_link", Context.MODE_PRIVATE)
    private val json = Json { ignoreUnknownKeys = true }

    val secretHex: String
    val publicKey: String
    val fingerprint: String

    init {
        if (prefs.getString("secret_hex", null) == null) {
            val k = Wire.result(NativeLink.generateKeys())
            prefs.edit().putString("secret_hex", k.str("secret_hex")).putString("public", k.str("public"))
                .putString("fingerprint", k.str("fingerprint")).apply()
        }
        secretHex = prefs.getString("secret_hex", "")!!
        publicKey = prefs.getString("public", "")!!
        fingerprint = prefs.getString("fingerprint", "")!!
    }

    var senders: List<PairedSender>
        get() = prefs.getString("senders", null)?.let { json.decodeFromString<List<PairedSender>>(it) } ?: emptyList()
        set(v) { prefs.edit().putString("senders", json.encodeToString(v)).apply() }

    fun addSender(s: PairedSender) { senders = senders.filter { it.publicKey != s.publicKey } + s }
    fun removeSender(publicKey: String) { senders = senders.filter { it.publicKey != publicKey } }
    fun trustedJson(): String = json.encodeToString(senders.map { it.publicKey })

    var style: OverlayStyle
        get() = prefs.getString("style", null)?.let { json.decodeFromString<OverlayStyle>(it) } ?: OverlayStyle()
        set(v) { prefs.edit().putString("style", json.encodeToString(v)).apply() }

    var overlayMode: Boolean
        get() = prefs.getBoolean("overlay_mode", false)
        set(v) { prefs.edit().putBoolean("overlay_mode", v).apply() }
}
