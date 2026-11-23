// swift-tools-version:6.0
import Foundation
import PackageDescription

// The Rust engine is linked as a static library built by:  cargo build --release -p lugha-ffi  (in lugha/engine)
let engineLib = URL(fileURLWithPath: #filePath).deletingLastPathComponent()
    .appendingPathComponent("../../engine/target/release").standardized.path

let package = Package(
    name: "LUGHA",
    platforms: [.macOS(.v14)],
    products: [
        .executable(name: "LughaApp", targets: ["LughaApp"]),
        .library(name: "LughaKit", targets: ["LughaKit"]),
    ],
    targets: [
        .target(name: "CLugha", path: "Sources/CLugha"),
        .target(
            name: "LughaKit",
            dependencies: ["CLugha"],
            path: "Sources/LughaKit",
            linkerSettings: [
                // The static archive by path: the same folder also holds liblugha_ffi.dylib (built for Android's
                // cdylib), and `-l` would pick the dylib — an absolute path outside the app that macOS refuses to load.
                .unsafeFlags(["\(engineLib)/liblugha_ffi.a"]),
                .linkedFramework("Security"),
                .linkedFramework("Vision"),
                .linkedFramework("ScreenCaptureKit"),
            ]
        ),
        .executableTarget(name: "LughaApp", dependencies: ["LughaKit"], path: "Sources/LughaApp"),
        .testTarget(name: "LughaKitTests", dependencies: ["LughaKit"], path: "Tests/LughaKitTests"),
    ],
    swiftLanguageModes: [.v5]
)
