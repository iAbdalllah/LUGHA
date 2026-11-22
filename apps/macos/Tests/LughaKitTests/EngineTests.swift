import XCTest
@testable import LughaKit

final class EngineTests: XCTestCase {
    func testInstallLoadAndLookupThroughSwift() throws {
        let f = try Fixtures.build()
        let engine = try Engine(trustJSON: f.trustJSON, deviceSecretHex: f.device.secretHex)
        let store = PackageStore(root: f.dir.appendingPathComponent("packages"))

        try engine.install(packageDir: store.directory(for: "sandline-sa-full"), lpk: f.lpk, licenseJSON: f.licenseJSON)
        XCTAssertEqual(store.installed(), ["sandline-sa-full": "1.0.0"])

        let loaded = try engine.load(packagesRoot: store.root)
        XCTAssertEqual(loaded.packages.map(\.packageId), ["sandline-sa-full"])
        XCTAssertTrue(loaded.errors.isEmpty)

        let hit = try XCTUnwrap(engine.lookup("WE LEAVE AT DAWN. Nobody talks about tonight"))
        XCTAssertEqual(hit.target, "نطلع مع الفجر. وأحد ما يتكلم عن الليلة.")
        XCTAssertEqual(hit.speaker, "Rim")
        XCTAssertFalse(hit.machineGenerated)
        XCTAssertNil(engine.lookup("Press any key"))
    }

    func testWrongDeviceAndBadTrustAreRejected() throws {
        let f = try Fixtures.build()
        let stranger = try Engine.generateDevice()
        let engine = try Engine(trustJSON: f.trustJSON, deviceSecretHex: stranger.secretHex)
        XCTAssertThrowsError(try engine.install(packageDir: f.dir.appendingPathComponent("x"), lpk: f.lpk, licenseJSON: f.licenseJSON))
        XCTAssertThrowsError(try Engine(trustJSON: "{}", deviceSecretHex: f.device.secretHex))
    }
}
