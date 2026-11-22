#!/bin/sh
# Mac → TV end-to-end: installs LUGHA TV on the running emulator/device, forwards its port, reads the
# pairing code from the debug log, runs LiveTVTests, and saves a screenshot of the TV to build/tv-screenshot.png.
set -eu
HERE="$(cd "$(dirname "$0")/.." && pwd)"
ADB="${ADB:-$HOME/Library/Android/sdk/platform-tools/adb}"
APK="$HERE/../tv/app/build/outputs/apk/debug/app-debug.apk"

"$ADB" install -r "$APK" >/dev/null
"$ADB" shell pm clear app.lugha.tv >/dev/null       # fresh identity + no paired senders
"$ADB" logcat -c
"$ADB" shell am start -n app.lugha.tv/.ui.MainActivity >/dev/null
"$ADB" forward tcp:47800 tcp:47800 >/dev/null
CODE=""
for i in $(seq 1 30); do
  CODE=$("$ADB" logcat -d -s LughaPair:I | sed -n 's/.*code=\([0-9]\{6\}\).*/\1/p' | tail -1)
  [ -n "$CODE" ] && break
  sleep 1
done
[ -n "$CODE" ] || { echo "no pairing code in logcat"; exit 1; }
cd "$HERE"
LUGHA_TV_HOST=127.0.0.1 LUGHA_TV_PORT=47800 LUGHA_TV_CODE="$CODE" swift test --filter LiveTVTests
mkdir -p build
"$ADB" exec-out screencap -p > build/tv-screenshot.png
echo "screenshot: $HERE/build/tv-screenshot.png"
