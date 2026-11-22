#!/bin/sh
# Builds lugha-ffi (engine + LUGHA Link sender) for the Flutter app:
#   Android: liblugha_ffi.so for arm64-v8a / armeabi-v7a / x86_64  → packages/lugha_engine/android/src/main/jniLibs
#   iOS:     static libs for device + simulator → LughaFFI.xcframework  → packages/lugha_engine/ios
set -eu
HERE="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN="$HERE/../apps/mobile/packages/lugha_engine"
NDK="${ANDROID_NDK_HOME:-$(ls -d "$HOME"/Library/Android/sdk/ndk/* 2>/dev/null | sort -V | tail -1)}"
BIN="$NDK/toolchains/llvm/prebuilt/$(uname -s | tr '[:upper:]' '[:lower:]')-x86_64/bin"
export CARGO_TARGET_AARCH64_LINUX_ANDROID_LINKER="$BIN/aarch64-linux-android26-clang"
export CARGO_TARGET_ARMV7_LINUX_ANDROIDEABI_LINKER="$BIN/armv7a-linux-androideabi26-clang"
export CARGO_TARGET_X86_64_LINUX_ANDROID_LINKER="$BIN/x86_64-linux-android26-clang"
# C code inside rusqlite/zstd needs the NDK compilers too.
export CC_aarch64_linux_android="$BIN/aarch64-linux-android26-clang"
export CC_armv7_linux_androideabi="$BIN/armv7a-linux-androideabi26-clang"
export CC_x86_64_linux_android="$BIN/x86_64-linux-android26-clang"
export AR_aarch64_linux_android="$BIN/llvm-ar" AR_armv7_linux_androideabi="$BIN/llvm-ar" AR_x86_64_linux_android="$BIN/llvm-ar"

if [ "${SKIP_ANDROID:-0}" != 1 ]; then
  for pair in aarch64-linux-android:arm64-v8a armv7-linux-androideabi:armeabi-v7a x86_64-linux-android:x86_64; do
    t="${pair%%:*}"; abi="${pair##*:}"
    (cd "$HERE" && cargo build --release -p lugha-ffi --target "$t")
    mkdir -p "$PLUGIN/android/src/main/jniLibs/$abi"
    cp "$HERE/target/$t/release/liblugha_ffi.so" "$PLUGIN/android/src/main/jniLibs/$abi/"
  done
fi

if [ "${SKIP_IOS:-0}" != 1 ]; then
  export IPHONEOS_DEPLOYMENT_TARGET=15.0
  for t in aarch64-apple-ios aarch64-apple-ios-sim x86_64-apple-ios; do (cd "$HERE" && cargo build --release -p lugha-ffi --target "$t"); done
  # One simulator slice for Apple Silicon and Intel Macs.
  mkdir -p "$HERE/target/ios-sim-universal"
  lipo -create "$HERE/target/aarch64-apple-ios-sim/release/liblugha_ffi.a" "$HERE/target/x86_64-apple-ios/release/liblugha_ffi.a" \
    -output "$HERE/target/ios-sim-universal/liblugha_ffi.a"
  rm -rf "$PLUGIN/ios/LughaFFI.xcframework"
  mkdir -p "$PLUGIN/ios/headers" && cp "$HERE/lugha-ffi/include/lugha.h" "$PLUGIN/ios/headers/"
  xcodebuild -create-xcframework \
    -library "$HERE/target/aarch64-apple-ios/release/liblugha_ffi.a" -headers "$PLUGIN/ios/headers" \
    -library "$HERE/target/ios-sim-universal/liblugha_ffi.a" -headers "$PLUGIN/ios/headers" \
    -output "$PLUGIN/ios/LughaFFI.xcframework" >/dev/null
fi
echo "engine built into $PLUGIN"
