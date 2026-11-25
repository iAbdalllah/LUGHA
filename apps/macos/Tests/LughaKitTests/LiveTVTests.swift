import XCTest
@testable import LughaKit

/// Mac → TV over a real network connection to the LUGHA TV app. Skipped unless LUGHA_TV_HOST, LUGHA_TV_PORT and
/// LUGHA_TV_CODE are set (scripts/tv-e2e.sh starts the app in an emulator, forwards the port and reads the code).
/// Wrong-code lockout is covered by the TV app's instrumented tests.
final class LiveTVTests: XCTestCase {
    func testPairStreamAndReconnect() async throws {
        let env = ProcessInfo.processInfo.environment
        guard let host = env["LUGHA_TV_HOST"], let port = env["LUGHA_TV_PORT"].flatMap(UInt16.init), let code = env["LUGHA_TV_CODE"] else {
            throw XCTSkip("Set LUGHA_TV_HOST / LUGHA_TV_PORT / LUGHA_TV_CODE to run against a TV.")
        }
        let keychain = Keychain(service: "app.lugha.mac.tests")
        keychain.delete("link_secret"); keychain.delete("link_public")
        let id = try TVSender.identity(keychain)

        let tv = try await TVSender.pair(host: host, port: port, code: code, name: "Emulator TV", senderName: "Test Mac", secretHex: id.secretHex)
        // Transport baseline: encrypted ping/pong only (no drawing), to separate network path from TV rendering.
        var pings: [Double] = []
        for _ in 1 ... 50 {
            let t0 = DispatchTime.now()
            _ = try await tv.ping()
            pings.append(Double(DispatchTime.now().uptimeNanoseconds - t0.uptimeNanoseconds) / 1_000_000)
        }
        pings.sort()
        print("ping round trip (transport only): p50=\(pings[pings.count / 2]) ms p95=\(pings[Int(Double(pings.count) * 0.95)]) ms")
        var times: [Double] = []
        for i in 1 ... 150 {
            times.append(try await tv.show("نطلع مع الفجر. وأحد ما يتكلم عن الليلة. (\(i))", speaker: "ريم", waitForAck: true) ?? 999)
        }
        times.sort()
        let p95 = times[Int(Double(times.count) * 0.95)]
        print("Mac→TV round trip incl. paint: p50=\(times[times.count / 2]) ms p95=\(p95) ms max=\(times.last!) ms")
        // Budget from docs/06: p95 ≤ 50 ms on a home LAN. Emulator port tunnels add their own delay, so the harness
        // can raise the ceiling (LUGHA_TV_MAX_P95_MS) when it isn't a real LAN.
        XCTAssertLessThanOrEqual(p95, Double(env["LUGHA_TV_MAX_P95_MS"] ?? "50") ?? 50)
        try await tv.show("الماء هنا أغلى من الذهب.", speaker: "تاجر", waitForAck: true)
        await tv.close()

        // Reconnect without a code (Noise IK with the stored keys) and leave a line on screen for the screenshot.
        let again = try await TVSender.connect(tv.tv, senderName: "Test Mac", secretHex: id.secretHex)
        let pong = try await again.ping()
        XCTAssertTrue(pong)
        try await again.show("نطلع مع الفجر. وأحد ما يتكلم عن الليلة.", speaker: "ريم", waitForAck: true)

        // What the phone sends with designed reading areas: placed items with an `area`, keep-alive pings, clear-all.
        let style: [String: any Sendable] = ["name": "Instructions", "place": "custom", "tx": 0.1, "ty": 0.76, "tw": 0.8, "th": 0.05,
                                             "valign": "bottom", "look": "card", "align": "right", "font": 0.9, "box_w": 1.0, "box_h": 0.8,
                                             "accent": "#E85FA0"]
        let item = TVSender.Item(text: "اضغط مطولاً لتخفيف السرعة.", speaker: nil, x: 0.2, y: 0.93, w: 0.6, h: 0.06, lines: 1, area: style)
        let ack = try await again.show("اضغط مطولاً لتخفيف السرعة.", speaker: nil, items: [item], waitForAck: true)
        XCTAssertNotNil(ack)
        for _ in 1 ... 4 {
            try await Task.sleep(nanoseconds: 8_000_000_000)
            let alive = try await again.ping()
            XCTAssertTrue(alive, "the TV dropped the link while idle")
        }
        try await again.clearAll()
        let after = try await again.show("الماء هنا أغلى من الذهب.", speaker: "تاجر", items: [item], waitForAck: true)
        XCTAssertNotNil(after)
    }
}
