package app.lugha.tv.ui

import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import android.provider.Settings
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.sizeIn
import androidx.compose.foundation.layout.absoluteOffset
import androidx.compose.foundation.layout.wrapContentSize
import androidx.compose.foundation.layout.offset
import androidx.compose.ui.BiasAlignment
import androidx.compose.ui.graphics.Shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.Density
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.tv.material3.Button
import androidx.tv.material3.ExperimentalTvMaterial3Api
import androidx.tv.material3.MaterialTheme
import androidx.tv.material3.Text
import androidx.tv.material3.darkColorScheme
import app.lugha.tv.R
import app.lugha.tv.link.HubState
import app.lugha.tv.link.LinkHub
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter

val Amber = Color(0xFFE9B44C)
val Ground = Color(0xFF0B0C0F)
val Surface2 = Color(0xFF16181D)

private enum class Screen { Live, Pair, Settings, History }

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
fun LughaTv() {
    val state by LinkHub.state.collectAsStateWithLifecycle()
    var screen by remember { mutableStateOf(if (LinkHub.store.senders.isEmpty()) Screen.Pair else Screen.Live) }
    LaunchedEffect(state.justPaired) {
        if (state.justPaired != null) { screen = Screen.Live; LinkHub.consumeJustPaired() }
    }
    BackHandler(enabled = screen != Screen.Live && LinkHub.store.senders.isNotEmpty()) { screen = Screen.Live }

    // TVs report very different dp sizes for the same 1080p panel (960 dp at xhdpi, 1440 dp at tvdpi). The screens
    // are laid out for a 1440 dp-wide canvas, so scale density to make every TV present exactly that width;
    // the user's font-size setting (fontScale) is kept.
    val base = LocalDensity.current
    val widthPx = LocalConfiguration.current.screenWidthDp * base.density
    CompositionLocalProvider(LocalDensity provides Density(widthPx / DESIGN_WIDTH_DP, base.fontScale)) {
    MaterialTheme(colorScheme = darkColorScheme(primary = Amber, background = Ground, surface = Surface2)) {
        // Layout follows the TV's language (RTL for Arabic); subtitle text is always RTL (SubtitleBubble).
        Box(Modifier.fillMaxSize().background(Ground)) {
            when (screen) {
                Screen.Pair -> PairScreen(state) { if (LinkHub.store.senders.isNotEmpty()) screen = Screen.Live }
                Screen.Live -> LiveScreen(state, onSettings = { screen = Screen.Settings })
                Screen.Settings -> SettingsScreen(state, onPair = { LinkHub.newPairingCode(); screen = Screen.Pair }, onHistory = { screen = Screen.History })
                Screen.History -> HistoryScreen(state)
            }
        }
    }
    }
}

/** Width of the canvas the TV screens are designed on (a 1080p panel at tvdpi). */
private const val DESIGN_WIDTH_DP = 1440f

private fun qr(content: String, size: Int = 512): Bitmap {
    val m = QRCodeWriter().encode(content, BarcodeFormat.QR_CODE, size, size)
    return Bitmap.createBitmap(size, size, Bitmap.Config.RGB_565).apply {
        for (x in 0 until size) for (y in 0 until size) setPixel(x, y, if (m[x, y]) android.graphics.Color.BLACK else android.graphics.Color.WHITE)
    }
}

@Composable
private fun Logo(size: Int = 64) {
    Box(Modifier.size(size.dp).background(Amber, RoundedCornerShape((size / 3.5).dp)), contentAlignment = Alignment.Center) {
        Text("ل", color = Color(0xFF1A1405), fontSize = (size / 1.9).sp, fontWeight = FontWeight.Bold)
    }
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun PairScreen(state: HubState, onBack: () -> Unit) {
    val address = "${state.address ?: "?"}:${state.port}"
    val bitmap = remember(state.pairingCode, address) { qr("lugha://pair?h=$address&c=${state.pairingCode}&k=${LinkHub.store.fingerprint}").asImageBitmap() }
    val locked = state.pairingLockedUntil > System.currentTimeMillis()
    Row(
        Modifier.fillMaxSize().background(Brush.radialGradient(listOf(Amber.copy(alpha = 0.12f), Color.Transparent), radius = 1400f)).padding(72.dp),
        horizontalArrangement = Arrangement.spacedBy(96.dp), verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(24.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) { Logo(); Spacer(Modifier.width(16.dp)); Text(stringResource(R.string.app_name), fontSize = 34.sp, fontWeight = FontWeight.Bold, color = Color.White) }
            Text(stringResource(R.string.pair_title), fontSize = 48.sp, fontWeight = FontWeight.Bold, color = Color.White, lineHeight = 58.sp)
            Text(stringResource(R.string.pair_body), fontSize = 24.sp, color = Color(0xFFB9BEC8), lineHeight = 34.sp)
            if (locked) {
                Text(stringResource(R.string.pairing_locked), fontSize = 24.sp, color = Color(0xFFF0A3A3))
            } else {
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                        state.pairingCode.forEach { d ->
                            Box(Modifier.size(84.dp, 108.dp).background(Surface2, RoundedCornerShape(16.dp)), contentAlignment = Alignment.Center) {
                                Text(d.toString(), fontSize = 56.sp, fontWeight = FontWeight.Bold, color = Color.White)
                            }
                        }
                    }
                }
            }
            Text(stringResource(R.string.tv_address, address), fontSize = 20.sp, color = Color(0xFF8E94A0))
            if (LinkHub.store.senders.isNotEmpty()) Button(onClick = onBack) { Text("←") }
        }
        Box(Modifier.size(340.dp).background(Color.White, RoundedCornerShape(24.dp)).padding(18.dp)) {
            Image(bitmap, contentDescription = "QR", modifier = Modifier.fillMaxSize())
        }
    }
}

/** The Arabic font chosen on the Mac (same ids), bundled in res/font. */
fun arabicFont(id: String): androidx.compose.ui.text.font.FontFamily? = when (id) {
    "naskh" -> androidx.compose.ui.text.font.FontFamily(androidx.compose.ui.text.font.Font(R.font.noto_naskh_arabic))
    "kufi" -> androidx.compose.ui.text.font.FontFamily(androidx.compose.ui.text.font.Font(R.font.noto_kufi_arabic))
    "cairo" -> androidx.compose.ui.text.font.FontFamily(androidx.compose.ui.text.font.Font(R.font.cairo))
    "tajawal" -> androidx.compose.ui.text.font.FontFamily(androidx.compose.ui.text.font.Font(R.font.tajawal_bold))
    "amiri" -> androidx.compose.ui.text.font.FontFamily(androidx.compose.ui.text.font.Font(R.font.amiri_bold))
    else -> null
}

@Composable
fun SubtitleBubble(text: String, speaker: String?, fontScale: Float, bgOpacity: Float, modifier: Modifier = Modifier, maxWidth: androidx.compose.ui.unit.Dp = 1150.dp) {
    // Broadcast style: white semibold Arabic with a soft shadow on a dark plate that hides the English underneath;
    // the speaker's name sits above in amber.
    val shadow = Shadow(Color.Black.copy(alpha = 0.85f), Offset(0f, 2f), 4f)
    Column(
        modifier.widthIn(max = maxWidth).background(Color.Black.copy(alpha = bgOpacity), RoundedCornerShape((18 * fontScale).dp))
            .padding(horizontal = (40 * fontScale).dp, vertical = (16 * fontScale).dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        speaker?.let {
            Text(it, color = Amber, fontSize = (30 * fontScale).sp, fontWeight = FontWeight.Bold,
                style = MaterialTheme.typography.bodyLarge.copy(textDirection = TextDirection.Rtl, shadow = shadow))
        }
        Text(
            text, color = Color.White, fontSize = (48 * fontScale).sp, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center,
            fontFamily = arabicFont(LinkHub.state.value.style.font),
            lineHeight = (66 * fontScale).sp, style = MaterialTheme.typography.bodyLarge.copy(textDirection = TextDirection.Rtl, shadow = shadow),
        )
    }
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun LiveScreen(state: HubState, onSettings: () -> Unit) {
    val focus = remember { FocusRequester() }
    LaunchedEffect(Unit) { focus.requestFocusSafely() }
    BoxWithConstraints(Modifier.fillMaxSize().background(Brush.verticalGradient(listOf(Color(0xFF1E2630), Color(0xFF2A2018), Color(0xFF0B0906))))) {
        Row(Modifier.align(Alignment.TopEnd).padding(36.dp).background(Color.Black.copy(alpha = 0.55f), RoundedCornerShape(50)).padding(horizontal = 18.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(12.dp).background(if (state.connectedTo != null) Color(0xFF7FD1A8) else Color(0xFF8E94A0), CircleShape))
            Spacer(Modifier.width(10.dp))
            Text(state.connectedTo?.let { stringResource(R.string.connected, it) } ?: stringResource(R.string.waiting), color = Color.White, fontSize = 20.sp)
            state.lastLatencyMs?.takeIf { state.connectedTo != null }?.let {
                Spacer(Modifier.width(10.dp))
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) { Text(stringResource(R.string.latency, it.toInt()), color = Color(0xFFB9BEC8), fontSize = 18.sp) }
            }
        }
        Button(onClick = onSettings, modifier = Modifier.align(Alignment.TopStart).padding(36.dp).focusRequester(focus)) { Text(stringResource(R.string.settings)) }

        state.subtitle?.let { s ->
            if (s.items.isEmpty()) {
                // No positions: centred where the game draws its English subtitle (positionY).
                SubtitleBubble(s.text, s.speaker, state.style.fontScale, state.style.bgOpacity,
                    Modifier.align(BiasAlignment(0f, state.style.positionY * 2f - 1f)).padding(vertical = 24.dp),
                    maxWidth = maxWidth * state.style.maxWidth)
            } else {
                // Each piece exactly over its English, text sized to one English line.
                val sw = maxWidth
                val sh = maxHeight
                s.items.forEach { i ->
                    val lineH = sh * (i.h / i.lines.coerceAtLeast(1))
                    val scale = (lineH.value * 0.9f / 48f * state.style.fontScale).coerceIn(0.35f, 1.6f)
                    // absoluteOffset: the TV may run right-to-left, and offset() would mirror the position.
                    androidx.compose.foundation.layout.Box(
                        Modifier.absoluteOffset(x = sw * i.x, y = sh * i.y).size(sw * i.w, sh * i.h),
                        contentAlignment = Alignment.Center,
                    ) {
                        if (state.style.mode == "native") {
                            // The game's own colours on a plate that covers the English.
                            Text(i.text, color = i.fg?.let { Color(it) } ?: Color.White, fontSize = (48 * scale).sp, fontWeight = FontWeight.SemiBold,
                                fontFamily = arabicFont(state.style.font),
                                textAlign = TextAlign.Center, maxLines = 2,
                                style = MaterialTheme.typography.bodyLarge.copy(textDirection = TextDirection.Rtl),
                                modifier = Modifier.wrapContentSize(unbounded = true)
                                    .background(i.bg?.let { Color(it) } ?: Color.Black.copy(alpha = state.style.bgOpacity), RoundedCornerShape(4.dp))
                                    .sizeIn(minWidth = sw * i.w + 4.dp, minHeight = sh * i.h + 2.dp)
                                    .padding(horizontal = (12 * scale).dp))
                        } else {
                            SubtitleBubble(i.text, i.speaker, scale, if (state.style.mode == "outline") 0f else state.style.bgOpacity,
                                Modifier.wrapContentSize(unbounded = true), maxWidth = (sw * i.w * 1.6f).coerceAtLeast(sw * 0.25f))
                        }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun SettingRow(label: String, value: String, onClick: () -> Unit, focus: FocusRequester? = null) {
    Button(onClick = onClick, modifier = Modifier.fillMaxWidth().height(84.dp).let { if (focus != null) it.focusRequester(focus) else it }) {
        Row(Modifier.fillMaxSize(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
            Text(label, fontSize = 26.sp)
            Text(value, fontSize = 24.sp, color = Amber)
        }
    }
}

@OptIn(ExperimentalTvMaterial3Api::class)
@Composable
private fun SettingsScreen(state: HubState, onPair: () -> Unit, onHistory: () -> Unit) {
    val ctx = LocalContext.current
    val focus = remember { FocusRequester() }
    LaunchedEffect(Unit) { focus.requestFocusSafely() }
    val st = state.style
    var overlay by remember { mutableStateOf(LinkHub.store.overlayMode) }
    Row(Modifier.fillMaxSize()) {
        LazyColumn(Modifier.width(680.dp).fillMaxSize().background(Color(0xF00E0F12)).padding(48.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
            item { Text(stringResource(R.string.settings), fontSize = 40.sp, fontWeight = FontWeight.Bold, color = Color.White, modifier = Modifier.padding(bottom = 12.dp)) }
            item {
                val sizes = listOf(0.8f, 1.0f, 1.25f, 1.5f)
                SettingRow(stringResource(R.string.font_size), "${(st.fontScale * 100).toInt()}%", {
                    LinkHub.setStyle(st.copy(fontScale = sizes[(sizes.indexOfFirst { it >= st.fontScale } + 1) % sizes.size]))
                }, focus)
            }
            item { SettingRow(stringResource(R.string.position), stringResource(if (st.positionBottom) R.string.bottom else R.string.top), { LinkHub.setStyle(st.copy(positionY = if (st.positionBottom) 0.14f else 0.86f)) }) }
            item {
                val ops = listOf(0.5f, 0.75f, 0.9f)
                SettingRow(stringResource(R.string.background), "${(st.bgOpacity * 100).toInt()}%", { LinkHub.setStyle(st.copy(bgOpacity = ops[(ops.indexOfFirst { it >= st.bgOpacity } + 1) % ops.size])) })
            }
            item {
                SettingRow(stringResource(R.string.overlay_mode), if (overlay) "✓" else "—", {
                    overlay = !overlay
                    LinkHub.store.overlayMode = overlay
                    if (overlay && !Settings.canDrawOverlays(ctx)) {
                        runCatching { ctx.startActivity(Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:${ctx.packageName}"))) }
                    }
                })
            }
            if (overlay && !Settings.canDrawOverlays(ctx)) item { Text(stringResource(R.string.overlay_needs_permission), color = Color(0xFFF0A3A3), fontSize = 20.sp) }
            item { SettingRow(stringResource(R.string.history), "${state.history.size}", onHistory) }
            item { SettingRow(stringResource(R.string.pair_new), "", onPair) }
            item { Text(stringResource(R.string.paired_devices), color = Color(0xFF8E94A0), fontSize = 22.sp, modifier = Modifier.padding(top = 16.dp)) }
            if (state.senders.isEmpty()) item { Text(stringResource(R.string.none), color = Color(0xFFB9BEC8), fontSize = 22.sp) }
            items(state.senders, key = { it.publicKey }) { s ->
                SettingRow("${s.name} · ${s.fingerprint.take(8)}", stringResource(R.string.remove), { LinkHub.removeSender(s.publicKey) })
            }
            item { Text(stringResource(R.string.back_hint), color = Color(0xFF8E94A0), fontSize = 20.sp, modifier = Modifier.padding(top = 20.dp)) }
        }
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.BottomCenter) {
            SubtitleBubble(stringResource(R.string.preview), null, st.fontScale, st.bgOpacity, Modifier.padding(bottom = 110.dp, start = 48.dp, end = 48.dp))
        }
    }
}

@Composable
private fun HistoryScreen(state: HubState) {
    LazyColumn(Modifier.fillMaxSize().padding(64.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        item { Text(stringResource(R.string.history), fontSize = 40.sp, fontWeight = FontWeight.Bold, color = Color.White) }
        if (state.history.isEmpty()) item { Text(stringResource(R.string.none), color = Color(0xFFB9BEC8), fontSize = 24.sp) }
        items(state.history, key = { it.sid }) { s ->
            Text(buildAnnotatedString { s.speaker?.let { withStyle(SpanStyle(color = Amber)) { append("$it: ") } }; append(s.text) },
                color = Color.White, fontSize = 30.sp, style = MaterialTheme.typography.bodyLarge.copy(textDirection = TextDirection.Rtl),
                modifier = Modifier.fillMaxWidth().background(Surface2, RoundedCornerShape(16.dp)).padding(20.dp))
        }
    }
}

/**
 * Initial D-pad focus. The target may not be attached yet (e.g. the screen changed while LUGHA TV was in the
 * background after a pairing), so retry briefly instead of crashing.
 */
suspend fun FocusRequester.requestFocusSafely() {
    repeat(20) {
        if (runCatching { requestFocus() }.isSuccess) return
        kotlinx.coroutines.delay(50)
    }
}
