import XCTest
@testable import LughaKit

final class PipelineTests: XCTestCase {
    private func hit(_ target: String) -> Hit {
        Hit(entryId: 1, packageId: "p", target: target, speaker: nil, category: "dialogues", score: 1, method: "exact", providerId: "package", machineGenerated: false)
    }

    func testShowsOnlyStableTextAndHidesAfterSilence() async {
        var lookups = 0
        let p = SubtitlePipeline(stableFrames: 2, hideAfter: 1.0) { text in
            lookups += 1
            return text.hasPrefix("Hello") ? self.hit("مرحبًا") : nil
        }
        let t0 = Date(timeIntervalSince1970: 1000)

        let first = await p.ingest(lines: ["Hello there"], at: t0)
        XCTAssertFalse(first)
        XCTAssertNil(p.current, "half-drawn text is not shown on the first frame")
        let second = await p.ingest(lines: ["Hello there"], at: t0.addingTimeInterval(0.12))
        XCTAssertTrue(second)
        XCTAssertEqual(p.current?.text, "مرحبًا")
        _ = await p.ingest(lines: ["Hello there"], at: t0.addingTimeInterval(0.25))
        XCTAssertEqual(lookups, 1, "the same line on later frames costs nothing")

        _ = await p.ingest(lines: [], at: t0.addingTimeInterval(0.6))
        XCTAssertNotNil(p.current, "short gaps keep the subtitle")
        let hidden = await p.ingest(lines: [], at: t0.addingTimeInterval(1.4))
        XCTAssertTrue(hidden)
        XCTAssertNil(p.current)
        XCTAssertEqual(p.history.count, 1)
    }

    func testUnknownTextHidesTheOldLine() async {
        let p = SubtitlePipeline(stableFrames: 1) { $0 == "Known" ? self.hit("معروف") : nil }
        _ = await p.ingest(lines: ["Known"])
        XCTAssertEqual(p.current?.text, "معروف")
        _ = await p.ingest(lines: ["Some HUD text"])
        XCTAssertNil(p.current)
    }

    func testMenusAreLookedUpLineByLine() async {
        let known: [String: (Int64, String)] = ["Map": (10, "الخريطة"), "Settings": (11, "الإعدادات")]
        let p = SubtitlePipeline(stableFrames: 1) { text in
            known[text].map { Hit(entryId: $0.0, packageId: "p", target: $0.1, speaker: nil, category: "menus", score: 1, method: "exact", providerId: "package", machineGenerated: false) }
        }
        _ = await p.ingest(lines: ["Map", "Game", "Settings"])
        XCTAssertEqual(p.current?.text, "الخريطة  ·  الإعدادات")
        _ = await p.ingest(lines: ["Nothing", "Known"])
        XCTAssertNil(p.current)
    }

    func testHidesWhenTheAreaStaysEmptyWithoutNewFrames() async {
        let p = SubtitlePipeline(stableFrames: 1, hideAfter: 1.0) { _ in self.hit("مرحبًا") }
        let t0 = Date(timeIntervalSince1970: 1000)
        _ = await p.ingest(lines: ["Hello"], at: t0)
        XCTAssertNotNil(p.current)
        // One empty frame, then the picture stops changing: no more frames arrive.
        _ = await p.ingest(lines: [], at: t0.addingTimeInterval(0.2))
        XCTAssertFalse(p.tick(at: t0.addingTimeInterval(0.8)), "not empty long enough yet")
        XCTAssertTrue(p.tick(at: t0.addingTimeInterval(1.3)))
        XCTAssertNil(p.current)
    }

    func testTickKeepsALineThatIsStillOnScreen() async {
        let p = SubtitlePipeline(stableFrames: 1, hideAfter: 1.0) { _ in self.hit("مرحبًا") }
        let t0 = Date(timeIntervalSince1970: 1000)
        _ = await p.ingest(lines: ["Hello"], at: t0)
        // The English stays and the picture is static: no frames, but nothing says it went away.
        XCTAssertFalse(p.tick(at: t0.addingTimeInterval(5)))
        XCTAssertNotNil(p.current)
    }
}
