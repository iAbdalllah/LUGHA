import Foundation
@testable import LughaKit

/// Builds a real signed package and license with lugha-pkg so tests exercise the same path as production.
enum Fixtures {
    static let engineDir = URL(fileURLWithPath: #filePath).deletingLastPathComponent().appendingPathComponent("../../../../engine").standardized
    static let pkg = engineDir.appendingPathComponent("target/release/lugha-pkg")

    struct Built {
        let dir: URL
        let lpk: URL
        let trustJSON: String
        let device: Engine.DeviceIdentity
        let licenseJSON: String
    }

    @discardableResult
    static func run(_ args: [String], env: [String: String] = [:]) throws -> [String: Any] {
        let p = Process()
        p.executableURL = pkg
        p.arguments = args
        p.environment = ProcessInfo.processInfo.environment.merging(env) { $1 }
        let out = Pipe()
        p.standardOutput = out
        try p.run()
        let data = out.fileHandleForReading.readDataToEndOfFile()
        p.waitUntilExit()
        guard p.terminationStatus == 0 else { throw NSError(domain: "lugha-pkg", code: Int(p.terminationStatus)) }
        return (try JSONSerialization.jsonObject(with: data) as? [String: Any]) ?? [:]
    }

    static func build() throws -> Built {
        let dir = FileManager.default.temporaryDirectory.appendingPathComponent("lugha-swift-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        let key = dir.appendingPathComponent("key.json")
        try run(["keygen", "--key-id", "pk-swift", "--out", key.path])
        let meta: [String: Any] = ["game_id": "sandline", "package_id": "sandline-sa-full", "version": "1.0.0", "localization_style": "saudi_white_dialect",
                                   "package_type": "full", "categories": ["cutscenes", "side_dialogues"]]
        try JSONSerialization.data(withJSONObject: meta).write(to: dir.appendingPathComponent("meta.json"))
        let entries = [
            ["id": 1, "source": "We leave at dawn. Nobody talks about tonight.", "target": "نطلع مع الفجر. وأحد ما يتكلم عن الليلة.", "category": "cutscenes", "speaker": "Rim"],
            ["id": 2, "source": "Water costs more than gold out here.", "target": "الماء هنا أغلى من الذهب.", "category": "side_dialogues"],
        ]
        try entries.map { String(decoding: try JSONSerialization.data(withJSONObject: $0), as: UTF8.self) }.joined(separator: "\n")
            .write(to: dir.appendingPathComponent("entries.jsonl"), atomically: true, encoding: .utf8)

        let contentKey = (0 ..< 32).map { _ in String(format: "%02x", UInt8.random(in: 0 ... 255)) }.joined()
        let lpk = dir.appendingPathComponent("p.lpk")
        try run(["build", "--meta", dir.appendingPathComponent("meta.json").path, "--entries", dir.appendingPathComponent("entries.jsonl").path,
                 "--key", key.path, "--out", lpk.path], env: ["LUGHA_CONTENT_KEY": contentKey])

        let device = try Engine.generateDevice()
        let license = try run(["issue-license", "--key", key.path, "--package-id", "sandline-sa-full", "--version", "1.0.0", "--device-public", device.public],
                              env: ["LUGHA_CONTENT_KEY": contentKey])
        let kf = try JSONSerialization.jsonObject(with: Data(contentsOf: key)) as! [String: Any]
        let trust = try JSONSerialization.data(withJSONObject: ["keys": [["key_id": kf["key_id"]!, "public": kf["public"]!]]])
        return Built(dir: dir, lpk: lpk, trustJSON: String(decoding: trust, as: UTF8.self), device: device,
                     licenseJSON: String(decoding: try JSONSerialization.data(withJSONObject: license), as: UTF8.self))
    }
}
