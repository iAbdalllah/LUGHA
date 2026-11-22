# 05 · Device Communication Protocol (LUGHA Link v1)

Sender (desktop or mobile) → Receiver (TV). Local network only; no cloud in the path. Target latency sender-send → TV-paint ≤ 50 ms on the same Wi-Fi.

## 1. Discovery

TV advertises mDNS/DNS-SD service `_lugha._tcp.local.`, port chosen at runtime, TXT records:

| key | value |
|---|---|
| `v` | `1` |
| `id` | TV device public id (ULID) |
| `name` | user-visible name, e.g. "Living-room TV" |
| `fp` | first 16 hex of the TV's X25519 key fingerprint |
| `paired` | `0` / `1` (whether any sender is paired) |

Fallback when mDNS is blocked: the TV screen shows `IP:port`; sender can enter it manually or scan the QR.

## 2. Pairing (first time)

1. User opens **Pair** on TV → TV shows a 6-digit code and a QR `lugha://pair?h=<ip>:<port>&id=<tv_id>&k=<tv_pubkey_b64>&c=<code>`.
2. Sender connects `wss://<ip>:<port>/link` (self-signed TLS; the cert is not trusted — authentication is the step below).
3. **PAKE**: SPAKE2 (Ed25519 group) using the 6-digit code as password → shared secret `S`. QR path skips manual entry and also pins the TV key.
4. Both sides exchange long-term X25519 public keys encrypted under `S`, plus the TLS certificate fingerprint. Each stores the other as a trusted peer.
5. Later sessions: Noise `IK` handshake over the WebSocket using the stored keys (no code needed). Peers are also listed in the user's account (`devices` of kind `android_tv`) so they can be removed from the web.

Three wrong codes → pairing locked 60 s. Codes rotate every pairing attempt.

## 3. Transport

WebSocket, binary frames, each = Noise transport message containing one **CBOR** map (JSON shown below for readability). Messages carry `t` (type), `id` (uint, per-sender monotonic), `ts` (sender ms clock).

## 4. Messages

| `t` | Direction | Body |
|---|---|---|
| `hello` | S→R | `{app, app_version, sender_name, caps:["rtl","style","history"]}` |
| `welcome` | R→S | `{tv_name, screen:{w,h}, caps, style}` |
| `sub.show` | S→R | `{sid, text, speaker?, lang:"ar", dir:"rtl", category, dur_ms?, slot:"bottom"\|"top"\|"custom", replace:true}` |
| `sub.clear` | S→R | `{sid?}` (no sid = clear all) |
| `style.set` | S→R or R→S | `{font_scale, position:{x,y} (0–1), bg_opacity, bg_color, text_color, max_lines, speaker_color}` |
| `history.get` | S→R | `{limit}` → `history` `{items:[{sid,text,speaker,ts}]}` |
| `ping` / `pong` | both | `{}` every 5 s; 3 missed → reconnect |
| `ack` | R→S | `{id, painted_at}` (only when sender asked `ack:true`; used for latency stats) |
| `bye` | both | `{reason}` |
| `error` | both | `{code, message}` — `unsupported_version`, `not_paired`, `rate_limited` |

Rules:
- Receiver renders `sub.show` immediately; if `dur_ms` absent, it stays until next `sub.show`/`sub.clear` or 8 s idle.
- Sender rate limit: ≤ 20 msgs/s; the receiver drops older `sub.show` if a newer one arrives before paint (latest-wins).
- Text is UTF-8 NFC; receiver applies Unicode bidi with paragraph direction from `dir`, Arabic shaping by the platform text stack (Compose/HarfBuzz), and isolates Latin runs (names, numbers) with FSI/PDI on the sender side.
- One active sender at a time; a second sender gets `error{code:"busy"}` unless the TV user switches.

## 5. Versioning

`hello.v` mismatch on major → `error unsupported_version`; minors are additive (unknown keys ignored).

## 6. Optional cloud relay (later, not v1)

For TVs on a different network: both ends join `private-device.{id}` on Reverb with the same message bodies, end-to-end encrypted with the paired keys so the server sees only ciphertext.
