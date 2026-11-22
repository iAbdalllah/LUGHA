# LUGHA mobile (Flutter — Android + iOS)

The phone app is the package manager and TV remote. It does not capture other apps' screens in v1.

- **Account:** email/password sign-in (token in the Keychain / Android Keystore via `flutter_secure_storage`), device registration with an X25519 key; at the device limit it opens *Devices* so one can be removed.
- **Library:** syncs entitlements, downloads full or delta packages, verifies checksum, signature, license and content hash with the Rust engine, refreshes licenses, removes refunded packages. *Try a line* runs the real matcher on-device.
- **Devices:** list and remove devices on the account.
- **TV:** finds LUGHA TVs over mDNS (`_lugha._tcp`), pairs with the 6-digit code (SPAKE2), then sends lines and controls the TV's look (font size, position, background) over the encrypted Noise session.
- **Account screen:** server, interface language, history of lines sent, sign-out.

The engine is `packages/lugha_engine`, a `dart:ffi` plugin around `engine/lugha-ffi` (the same Rust code as the Mac and Windows apps): `liblugha_ffi.so` per ABI on Android, a static `LughaFFI.xcframework` linked into the app on iOS.

## Build

```bash
../../engine/scripts/build-mobile.sh                 # Rust for Android ABIs + iOS xcframework (SKIP_ANDROID=1 / SKIP_IOS=1)
flutter test                                         # unit tests
(cd packages/lugha_engine && flutter test)           # engine on the host (needs: cd engine && cargo build --release -p lugha-ffi)
flutter run                                          # emulator / simulator; debug builds talk to http://10.0.2.2:8040 (Android) or 127.0.0.1:8040
flutter test integration_test/app_test.dart          # sign in → sync → install → Arabic lookup, against a running backend
flutter test integration_test/tv_link_test.dart --dart-define=TV_CODE=123456   # phone → LUGHA TV, with the TV's code
```

Needs Flutter 3.44+, Android SDK 35 + NDK 26+, Xcode 16+ (iOS 15+). Release builds need signing configs (not in the repo). Cleartext HTTP is allowed only in the debug manifest.

**Verified:** Android 14 emulator (integration test; phone → TV on the same emulator p50 16.7 ms / p95 20.3 ms incl. paint), iPhone 17 Pro simulator (integration test), Arabic RTL screens on both. Not yet: physical devices, store review.

**Not in v1:** Android MediaProjection capture (optional in the PRD; would need a consent flow and Play policy review). iOS stays a companion — iOS cannot capture other apps.
