# LUGHA TV (Android TV / Google TV)

Receives subtitles from the LUGHA Mac app over the local network and renders them in Arabic (RTL). It never captures HDMI input.

- **Pairing:** a 6-digit code + QR on the TV; SPAKE2 so the code can't be brute-forced offline; 3 wrong codes lock pairing for a minute.
- **Sessions:** Noise IK, only paired senders; crypto is the shared Rust crate `engine/lugha-link` via JNI (`liblughalink.so`).
- **Screens:** pairing, live subtitles (status + latency), D-pad settings (font size, position, background, overlay mode, paired devices), history.
- **Overlay mode:** with "Display over other apps" allowed, a foreground service draws subtitles above other inputs/apps.

## Build

```bash
../../engine/scripts/build-android.sh      # Rust JNI libs (arm64, armv7, x86_64) → app/src/main/jniLibs
./gradlew assembleDebug                     # app/build/outputs/apk/debug/app-debug.apk
./gradlew connectedDebugAndroidTest         # on a running emulator/device: pairing, lockout, 200 subtitles with paint acks
```

Mac ↔ TV end to end (emulator running): `../macos/scripts/tv-e2e.sh` installs the app, forwards the port, reads the debug pairing code from logcat, runs `LiveTVTests` and saves a screenshot.

Needs JDK 17+, Android SDK 35, NDK 26+. Release builds need a signing config (not in the repo).
