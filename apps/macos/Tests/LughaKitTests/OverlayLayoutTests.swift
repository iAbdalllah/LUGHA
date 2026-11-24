import CoreGraphics
import Foundation
@testable import LughaKit
import XCTest

final class OverlayLayoutTests: XCTestCase {
    func testStackedSubtitlesDoNotCoverEachOther() {
        let a = CGRect(x: 700, y: 820, width: 500, height: 90), b = CGRect(x: 720, y: 870, width: 460, height: 90)
        let out = OverlayLayout.arrange([a, b], old: [false, false], in: CGSize(width: 2000, height: 1030), gap: 6)
        XCTAssertEqual(out[0], a)
        XCTAssertGreaterThanOrEqual(out[1].minY, out[0].maxY)
    }

    func testOffTheBottomMovesUpAndOldLinesGoAbove() {
        let size = CGSize(width: 2000, height: 1030)
        let low = OverlayLayout.arrange([CGRect(x: 0, y: 930, width: 500, height: 90), CGRect(x: 0, y: 935, width: 500, height: 90)], old: [false, false], in: size, gap: 6)
        XCTAssertFalse(low[0].intersects(low[1]))
        XCTAssertTrue(low.allSatisfy { $0.minY >= 0 && $0.maxY <= 1030 })
        let r = CGRect(x: 700, y: 850, width: 500, height: 90)
        let out = OverlayLayout.arrange([r, r], old: [true, false], in: size, gap: 6)
        XCTAssertEqual(out[1], r)
        XCTAssertLessThanOrEqual(out[0].maxY, out[1].minY)
    }

    func testAReplacedLineStaysUntilReadAndOnlyOneIsKept() {
        let hold = ReadingHold()
        let t0 = Date(timeIntervalSince1970: 0)
        let a = SubtitleItem(text: "أوه! المشتبه فيه ترك مركبته!", speaker: "Cop")
        let b = SubtitleItem(text: "ازحف أسرع يا خوي.", speaker: "Jason")
        let c = SubtitleItem(text: "انقلع يلعن شكلك!", speaker: "Local")
        let hud = SubtitleItem(text: "هدد")
        hold.update([a, hud], at: t0)
        hold.update([b], at: t0.addingTimeInterval(0.3))
        XCTAssertEqual(hold.visible(at: t0.addingTimeInterval(0.4)).map { "\($0.item.text)|\($0.old)" }, ["\(b.text)|false", "\(a.text)|true"])
        hold.update([c], at: t0.addingTimeInterval(0.5))
        XCTAssertEqual(hold.visible(at: t0.addingTimeInterval(0.6)).map(\.item.text), [c.text, b.text], "only the newest earlier line stays")
        XCTAssertEqual(hold.visible(at: t0.addingTimeInterval(10)).map(\.item.text), [c.text])
    }

    func testStackedDialogueIsOneBlockAndHudStaysApart() {
        let prisoner = SubtitleItem(text: "قلت لك ذاك الحقير الثاني، أنا ما سويت شيء.", speaker: "Prisoner", box: CGRect(x: 0.308, y: 0.702, width: 0.384, height: 0.013))
        let lucia = SubtitleItem(text: "إيه", speaker: "Lucia", box: CGRect(x: 0.464, y: 0.725, width: 0.071, height: 0.012))
        let hud = SubtitleItem(text: "تشغيل", box: CGRect(x: 0.685, y: 0.712, width: 0.101, height: 0.039))
        let s = OverlayLayout.scene([(lucia, false), (prisoner, false), (hud, false)])
        XCTAssertEqual(s.block?.lines.map(\.item.text), [prisoner.text, lucia.text])
        XCTAssertEqual(s.block!.area.maxY, 0.737, accuracy: 0.001)
        XCTAssertEqual(s.hud.map(\.item.text), [hud.text])
        let r = RecentDialogue(max: 2)
        r.add([prisoner, hud]); r.add([lucia]); r.add([prisoner])
        XCTAssertEqual(r.items.map(\.text), [prisoner.text, lucia.text])
    }

    func testTextToneAdaptsToThePictureBehindIt() {
        let gravel = Tone.forPatch(luma: 0.45, spread: 0.15)  // busy mid-grey (the tourists screenshot)
        XCTAssertFalse(gravel.darkText)
        XCTAssertGreaterThan(gravel.shade, 0.25, "busy ground gets a backdrop")
        let sky = Tone.forPatch(luma: 0.85, spread: 0.04)
        XCTAssertTrue(sky.darkText, "bright, flat: dark text")
        XCTAssertEqual(sky.shade, 0)
        let night = Tone.forPatch(luma: 0.08, spread: 0.03)
        XCTAssertFalse(night.darkText)
        XCTAssertEqual(night.shade, 0, "dark and flat: plain light text reads")
    }

    func testReadingAreasKeepOnlyTextInsideEnabledBoxes() {
        let sub = OCRLine(text: "Staff Member: Hi there!", box: CGRect(x: 0.4, y: 0.72, width: 0.2, height: 0.013))
        let menu = OCRLine(text: "Jaio sai uc ual", box: CGRect(x: 0.44, y: 0.26, width: 0.11, height: 0.016))
        XCTAssertEqual(ReadingArea.keep([sub, menu], in: []).count, 2, "no areas: read everything")
        let subs = ReadingArea(name: "Subtitles", rect: CGRect(x: 0.1, y: 0.65, width: 0.8, height: 0.15))
        XCTAssertEqual(ReadingArea.keep([sub, menu], in: [subs]).map(\.text), [sub.text])
        var off = subs; off.enabled = false
        XCTAssertEqual(ReadingArea.keep([sub, menu], in: [off]).count, 2, "disabled areas don't filter")
    }
}
