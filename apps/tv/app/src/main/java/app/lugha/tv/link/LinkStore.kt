package app.lugha.tv.link

import android.content.Context
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import app.lugha.tv.link.Wire.str

@Serializable
data class PairedSender(val publicKey: String, val fingerprint: String, val name: String, val pairedAt: Long)

@Serializable
/**
 * How subtitles look. [positionY] is the vertical centre as a fraction of the screen (the sender sets it to where the
 * game draws its English subtitle, so the Arabic covers it); [maxWidth] is the fraction of the width a line may use.
 */
data class OverlayStyle(
    val fontScale: Float = 1.0f,
    val positionY: Float = 0.86f,
    val bgOpacity: Float = 0.8f,
    val maxWidth: Float = 0.8f,
    /** "native" (the game's own colours), "box" (dark plate) or "outline" (no plate). */
    val mode: String = "native",
    /** Text colour for box/outline: "white" or "yellow". */
    val textColor: String = "white",
    /** Arabic font id, same as the Mac: "" (system), naskh, kufi, cairo, tajawal, amiri — bundled in res/font. */
    val font: String = "",
    /**
     * "over": the Arabic hides the English on a plate; "above": just above the English, which stays; "history": no
     * line over the game at all — only the history panel.
     */
    val display: String = "over",
    /** Side panel with the recent dialogue: "off", "left" or "right". */
    val history: String = "off",
    /** Fade-in of a new line, ms (0 = appear at once). */
    val fadeMs: Int = 150,
    /** Keep a replaced dialogue line up (dimmed, above the new one) until it could be read. */
    val readHold: Boolean = true,
    /** Colour of the text boxes and the history panel (#RRGGBB; its opacity is [bgOpacity]). */
    val plateColor: String = "#000000",
) {
    /** The history panel's side: in history-only display it is always shown (right unless left was chosen). */
    val historySide get() = if (display == "history" && history == "off") "right" else history
    val plateRgb: Int get() = runCatching { android.graphics.Color.parseColor(plateColor) }.getOrDefault(android.graphics.Color.BLACK)
    val positionBottom get() = positionY >= 0.5f
}

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
