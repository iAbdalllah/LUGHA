#!/bin/sh
# Builds lugha-link-jni for Android (arm64, armv7 for 32-bit TVs, x86_64) and copies the .so files into the
# TV app. Needs the Android NDK: set ANDROID_NDK_HOME or have it under ~/Library/Android/sdk/ndk/<version>.
set -eu
HERE="$(cd "$(dirname "$0")/.." && pwd)"
NDK="${ANDROID_NDK_HOME:-$(ls -d "$HOME"/Library/Android/sdk/ndk/* 2>/dev/null | sort -V | tail -1)}"
HOST_TAG="$(uname -s | tr '[:upper:]' '[:lower:]')-x86_64"   # the NDK ships x86_64 host tools (run under Rosetta on Apple Silicon)
BIN="$NDK/toolchains/llvm/prebuilt/$HOST_TAG/bin"
[ -d "$BIN" ] || { echo "NDK not found ($BIN)"; exit 1; }
OUT="$HERE/../apps/tv/app/src/main/jniLibs"

export CARGO_TARGET_AARCH64_LINUX_ANDROID_LINKER="$BIN/aarch64-linux-android26-clang"
export CARGO_TARGET_ARMV7_LINUX_ANDROIDEABI_LINKER="$BIN/armv7a-linux-androideabi26-clang"
export CARGO_TARGET_X86_64_LINUX_ANDROID_LINKER="$BIN/x86_64-linux-android26-clang"

for pair in aarch64-linux-android:arm64-v8a armv7-linux-androideabi:armeabi-v7a x86_64-linux-android:x86_64; do
  target="${pair%%:*}"; abi="${pair##*:}"
  (cd "$HERE" && cargo build --release -p lugha-link-jni --target "$target")
  mkdir -p "$OUT/$abi"
  cp "$HERE/target/$target/release/liblughalink.so" "$OUT/$abi/"
done
echo "copied to $OUT"
