Pod::Spec.new do |s|
  s.name             = 'lugha_engine'
  s.version          = '0.1.0'
  s.summary          = 'LUGHA engine (Rust) for Flutter'
  s.homepage         = 'https://lugha.app'
  s.license          = { :type => 'Proprietary' }
  s.author           = 'LUGHA'
  s.source           = { :path => '.' }
  s.platform         = :ios, '15.0'
  # The Rust static library (engine/scripts/build-mobile.sh) is force-loaded into this pod's framework so every
  # exported symbol is present; Dart finds them with DynamicLibrary.process(). Classes/keep.c references them too.
  s.preserve_paths = 'LughaFFI.xcframework/**/*'
  s.source_files     = 'Classes/**/*'
  s.public_header_files = 'Classes/**/*.h'
  s.dependency 'Flutter'
  # The plugin pod links as a framework: force-load the right slice into it.
  s.pod_target_xcconfig = {
    'DEFINES_MODULE' => 'YES',
    'OTHER_LDFLAGS[sdk=iphonesimulator*]' => '$(inherited) -force_load "${PODS_TARGET_SRCROOT}/LughaFFI.xcframework/ios-arm64_x86_64-simulator/liblugha_ffi.a"',
    'OTHER_LDFLAGS[sdk=iphoneos*]' => '$(inherited) -force_load "${PODS_TARGET_SRCROOT}/LughaFFI.xcframework/ios-arm64/liblugha_ffi.a"',
  }
end
