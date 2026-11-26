Pod::Spec.new do |s|
  s.name             = 'lugha_remoteplay'
  s.version          = '0.1.0'
  s.summary          = 'LUGHA Remote Play for iPhone (chiaki-ng, AGPL-3.0)'
  s.homepage         = 'https://lugha.app'
  s.license          = { :type => 'AGPL-3.0', :file => '../LICENSE' }
  s.author           = 'LUGHA'
  s.source           = { :path => '.' }
  s.platform         = :ios, '15.0'
  s.swift_version    = '5.9'
  s.source_files     = 'Classes/**/*'
  s.dependency 'Flutter'
  # The Remote Play core (lugha_rpx_*) is inside lugha_engine's Rust library.
  s.dependency 'lugha_engine'
  s.frameworks       = 'VideoToolbox', 'CoreMedia', 'CoreVideo', 'AVKit', 'CoreImage', 'Vision'
  s.pod_target_xcconfig = { 'DEFINES_MODULE' => 'YES' }
end
