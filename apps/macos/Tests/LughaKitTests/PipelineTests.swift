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
        let t0 = Date(timeIntervalSince1970: 1000)
        _ = await p.ingest(lines: ["Known"], at: t0)
        XCTAssertEqual(p.current?.text, "معروف")
        _ = await p.ingest(lines: ["Some HUD text"], at: t0.addingTimeInterval(0.1))
        XCTAssertNotNil(p.current, "one frame without the English is not enough")
        _ = await p.ingest(lines: ["Some HUD text!"], at: t0.addingTimeInterval(1.0))
        XCTAssertNil(p.current)
    }

    func testMenusAreLookedUpLineByLine() async {
        let known: [String: (Int64, String)] = ["Map": (10, "الخريطة"), "Settings": (11, "الإعدادات")]
        let p = SubtitlePipeline(stableFrames: 1) { text in
            known[text].map { Hit(entryId: $0.0, packageId: "p", target: $0.1, speaker: nil, category: "menus", score: 1, method: "exact", providerId: "package", machineGenerated: false) }
        }
        _ = await p.ingest(lines: ["Map", "Game", "Settings"])
        XCTAssertEqual(p.current?.text, "الخريطة  ·  الإعدادات")
        _ = await p.ingest(lines: ["Nothing", "Known"], at: Date().addingTimeInterval(5))
        _ = await p.ingest(lines: ["Nothing", "Known!"], at: Date().addingTimeInterval(6))
        XCTAssertNil(p.current, "gone once nothing known has held past removeAfter")
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

    func testNoisyUnknownTextStillReplacesTheOldLine() async {
        // Whole-window capture during play: HUD text changes every frame, none of it known.
        let p = SubtitlePipeline(stableFrames: 2) { $0 == "Map" ? self.hit("الخريطة") : nil }
        let t0 = Date(timeIntervalSince1970: 1000)
        _ = await p.ingest(lines: ["Map"], at: t0); _ = await p.ingest(lines: ["Map"], at: t0.addingTimeInterval(0.1))
        XCTAssertEqual(p.current?.text, "الخريطة")
        _ = await p.ingest(lines: ["TURN ON", "$104"], at: t0.addingTimeInterval(0.3))
        _ = await p.ingest(lines: ["TURN 0N", "$1O4"], at: t0.addingTimeInterval(1.2))
        XCTAssertNil(p.current, "the menu closed: the Arabic must go even though the noise never repeats")
    }

    func testItemsKeepWhereTheEnglishWas() async {
        let known: [String: (Int64, String)] = ["Map": (10, "الخريطة"), "Help": (11, "المساعدة")]
        let p = SubtitlePipeline(stableFrames: 1) { t in
            known[t].map { Hit(entryId: $0.0, packageId: "p", target: $0.1, speaker: nil, category: "menus", score: 1, method: "exact", providerId: "package", machineGenerated: false) }
        }
        let map = CGRect(x: 0.05, y: 0.30, width: 0.10, height: 0.03), help = CGRect(x: 0.05, y: 0.45, width: 0.10, height: 0.03)
        _ = await p.ingest(ocr: [OCRLine(text: "Map", box: map), OCRLine(text: "Game", box: .zero), OCRLine(text: "Help", box: help)])
        XCTAssertEqual(p.current?.items.map(\.box), [map, help])
        XCTAssertEqual(p.current?.items.map(\.text), ["الخريطة", "المساعدة"])
    }

    func testAMenuDoesNotBlinkWhenOneFrameMissesItems() async {
        let known: [String: (Int64, String)] = ["Map": (10, "الخريطة"), "Help": (11, "المساعدة")]
        let p = SubtitlePipeline(stableFrames: 1) { t in
            known[t].map { Hit(entryId: $0.0, packageId: "p", target: $0.1, speaker: nil, category: "menus", score: 1, method: "exact", providerId: "package", machineGenerated: false) }
        }
        let t0 = Date(timeIntervalSince1970: 1000)
        _ = await p.ingest(lines: ["Map", "Help"], at: t0)
        XCTAssertEqual(p.current?.items.count, 2)
        // One animated frame: OCR reads only "Map".
        _ = await p.ingest(lines: ["Map", "Hel"], at: t0.addingTimeInterval(0.15))
        XCTAssertEqual(p.current?.items.count, 2, "a one-frame miss must not drop المساعدة")
        _ = await p.ingest(lines: ["Map", "Help"], at: t0.addingTimeInterval(0.3))
        XCTAssertEqual(p.current?.items.count, 2)
    }
}
