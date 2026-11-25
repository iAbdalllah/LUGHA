#!/bin/sh
# Builds the engine and the app, then assembles build/LUGHA.app (ad-hoc signed for local use).
# Distribution needs a Developer ID certificate + notarization: set SIGN_IDENTITY and run notarytool afterwards.
set -eu
HERE="$(cd "$(dirname "$0")/.." && pwd)"
ENGINE="$HERE/../../engine"
VERSION="${VERSION:-0.1.0}"

# With PS5 Remote Play (chiaki-ng, built with CMake; needs Python with protobuf).
(cd "$ENGINE" && cargo build --release -p lugha-ffi --features remoteplay)
(cd "$HERE" && swift build -c release --product LughaApp)

APP="$HERE/build/LUGHA.app"
rm -rf "$APP"
mkdir -p "$APP/Contents/MacOS" "$APP/Contents/Resources"
cp "$HERE/.build/release/LughaApp" "$APP/Contents/MacOS/LUGHA"
# Arabic fonts shared with LUGHA TV (SIL OFL; licences alongside).
cp -R "$HERE/Resources/Fonts" "$APP/Contents/Resources/Fonts"
cat > "$APP/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CFBundleIdentifier</key><string>app.lugha.mac</string>
  <key>CFBundleName</key><string>LUGHA</string>
  <key>CFBundleDisplayName</key><string>LUGHA · لغة</string>
  <key>CFBundleExecutable</key><string>LUGHA</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleShortVersionString</key><string>$VERSION</string>
  <key>CFBundleVersion</key><string>$VERSION</string>
  <key>LSMinimumSystemVersion</key><string>14.0</string>
  <key>LSUIElement</key><true/>
  <key>NSHighResolutionCapable</key><true/>
  <key>CFBundleDevelopmentRegion</key><string>ar</string>
  <key>CFBundleLocalizations</key><array><string>ar</string><string>en</string></array>
  <key>NSAppTransportSecurity</key><dict><key>NSAllowsLocalNetworking</key><true/></dict>
  <key>NSLocalNetworkUsageDescription</key><string>LUGHA connects to your PS5 and your LUGHA TV on this network.</string>
</dict>
</plist>
PLIST
codesign --force --options runtime --sign "${SIGN_IDENTITY:--}" "$APP"
echo "$APP"

# Guard: the app must not depend on anything outside itself and the system (a dylib by absolute path crashes at launch).
if otool -L "$APP/Contents/MacOS/LUGHA" | tail -n +2 | grep -vqE '^\s*(/System/|/usr/lib/)'; then
  echo "error: LUGHA links a library outside the system:" >&2; otool -L "$APP/Contents/MacOS/LUGHA" >&2; exit 1
fi
