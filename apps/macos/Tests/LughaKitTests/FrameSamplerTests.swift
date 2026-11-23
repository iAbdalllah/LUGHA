import XCTest
@testable import LughaKit

final class FrameSamplerTests: XCTestCase {
    /// 400×200 frame: black bars left/right (40 px), a purple picture, and a white "label" at a known box.
    private func frame() -> CGImage {
        let ctx = CGContext(data: nil, width: 400, height: 200, bitsPerComponent: 8, bytesPerRow: 0,
                            space: CGColorSpace(name: CGColorSpace.sRGB)!, bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue)!
        ctx.setFillColor(CGColor(srgbRed: 0, green: 0, blue: 0, alpha: 1)); ctx.fill(CGRect(x: 0, y: 0, width: 400, height: 200))
        ctx.setFillColor(CGColor(srgbRed: 0.30, green: 0.20, blue: 0.55, alpha: 1)); ctx.fill(CGRect(x: 40, y: 0, width: 320, height: 200))
        // "Text" strokes inside the box x 80–160, y (top-left) 40–60 → CG y = 200-60 … 200-40
        ctx.setFillColor(CGColor(srgbRed: 1, green: 1, blue: 1, alpha: 1))
        for x in stride(from: 84, to: 156, by: 8) { ctx.fill(CGRect(x: x, y: 144, width: 4, height: 12)) }
        return ctx.makeImage()!
    }

    func testReadsTheGamesColoursAroundAndInAText() throws {
        let s = try XCTUnwrap(FrameSampler(frame()))
        let c = try XCTUnwrap(s.textColors(in: CGRect(x: 80.0 / 400, y: 40.0 / 200, width: 80.0 / 400, height: 20.0 / 200)))
        XCTAssertEqual(c.background.r, 0.30, accuracy: 0.05)
        XCTAssertEqual(c.background.b, 0.55, accuracy: 0.05)
        XCTAssertGreaterThan(c.text.luma, 0.8, "text is the white strokes")
    }

    func testFindsThePictureInsideBlackBars() throws {
        let r = try XCTUnwrap(FrameSampler(frame())).contentRect()
        XCTAssertEqual(r.minX, 0.1, accuracy: 0.02)
        XCTAssertEqual(r.width, 0.8, accuracy: 0.02)
        XCTAssertEqual(r.height, 1, accuracy: 0.02)
    }

    func testFindsThe16by9PictureBelowATitleBar() throws {
        // 320×200 window: a grey 20 px title bar over a 320×180 (16:9) purple picture.
        let ctx = CGContext(data: nil, width: 320, height: 200, bitsPerComponent: 8, bytesPerRow: 0,
                            space: CGColorSpace(name: CGColorSpace.sRGB)!, bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue)!
        ctx.setFillColor(CGColor(srgbRed: 0.3, green: 0.2, blue: 0.55, alpha: 1)); ctx.fill(CGRect(x: 0, y: 0, width: 320, height: 180))
        ctx.setFillColor(CGColor(srgbRed: 0.24, green: 0.24, blue: 0.24, alpha: 1)); ctx.fill(CGRect(x: 0, y: 180, width: 320, height: 20))
        let r = try XCTUnwrap(FrameSampler(ctx.makeImage()!)).gameRect()
        XCTAssertEqual(r.minY, 0.1, accuracy: 0.01, "picture starts below the 20 px title bar")
        XCTAssertEqual(r.height, 0.9, accuracy: 0.01)
    }
}
