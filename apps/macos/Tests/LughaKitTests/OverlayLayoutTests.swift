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
}
