import XCTest
@testable import LughaKit

/// End-to-end against a running backend. Skipped unless LUGHA_E2E_URL, LUGHA_E2E_EMAIL and LUGHA_E2E_PASSWORD are set.
/// Signs in, registers this test device, syncs (download + license + install), then matches a line.
final class LiveSyncTests: XCTestCase {
    func testSyncInstallsOwnedPackagesFromTheBackend() async throws {
        let env = ProcessInfo.processInfo.environment
        guard let base = env["LUGHA_E2E_URL"].flatMap(URL.init(string:)), let email = env["LUGHA_E2E_EMAIL"], let password = env["LUGHA_E2E_PASSWORD"] else {
            throw XCTSkip("Set LUGHA_E2E_URL / LUGHA_E2E_EMAIL / LUGHA_E2E_PASSWORD to run against a backend.")
        }
        // Password login is only for this test; the app itself signs in with a device code.
        var req = URLRequest(url: base.appendingPathComponent("api/v1/auth/login"))
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        req.httpBody = try JSONSerialization.data(withJSONObject: ["email": email, "password": password, "device_name": "swift-e2e"])
        let (data, _) = try await URLSession.shared.data(for: req)
        let token = try XCTUnwrap((try JSONSerialization.jsonObject(with: data) as? [String: Any])?["token"] as? String)

        let api = APIClient(baseURL: base, token: token)
        let device = try Engine.generateDevice()
        let engine = EngineActor()
        try await engine.configure(trustJSON: try await api.trustJSON(), deviceSecretHex: device.secretHex)
        let registered = try await api.registerDevice(name: "Swift E2E", publicKey: device.public, model: "test", osVersion: "test", appVersion: "0.1.0")

        let store = PackageStore(root: FileManager.default.temporaryDirectory.appendingPathComponent("lugha-e2e-\(UUID().uuidString)"))
        let report = await SyncService(api: api, engine: engine, store: store, appVersion: "0.1.0").sync(deviceId: registered.id)

        XCTAssertTrue(report.errors.isEmpty, "\(report.errors)")
        XCTAssertTrue(report.installed.contains("sandline-sa-full"), "installed: \(report.installed)")
        XCTAssertEqual(store.installed()["sandline-sa-full"], "1.0.0")
        let hit = await engine.lookup("Water costs more than gold out here")
        XCTAssertEqual(hit?.target, "الماء هنا أغلى من الذهب.")

        // A second sync is a no-op: nothing to download.
        let again = await SyncService(api: api, engine: engine, store: store, appVersion: "0.1.0").sync(deviceId: registered.id)
        XCTAssertTrue(again.installed.isEmpty && again.updated.isEmpty)
    }
}
