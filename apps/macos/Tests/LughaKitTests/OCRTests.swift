import AppKit
import XCTest
@testable import LughaKit

/// Renders a game-style subtitle (white text on a dark scene), runs real Vision OCR on it, and matches the
/// OCR output against an installed package — the desktop pipeline minus the screen capture.
final class OCRTests: XCTestCase {
    static func render(_ text: String, size: CGSize = CGSize(width: 1400, height: 90)) -> CGImage {
        let ctx = CGContext(data: nil, width: Int(size.width), height: Int(size.height), bitsPerComponent: 8, bytesPerRow: 0,
                            space: CGColorSpaceCreateDeviceRGB(), bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue)!
        ctx.setFillColor(CGColor(red: 0.18, green: 0.16, blue: 0.14, alpha: 1))
        ctx.fill(CGRect(origin: .zero, size: size))
        let attr = NSAttributedString(string: text, attributes: [.font: NSFont.systemFont(ofSize: 40, weight: .medium), .foregroundColor: NSColor.white])
        let line = CTLineCreateWithAttributedString(attr)
        ctx.textPosition = CGPoint(x: 30, y: 28)
        CTLineDraw(line, ctx)
        return ctx.makeImage()!
    }

    func testOCRThenEngineLookup() async throws {
        let f = try Fixtures.build()
        let engine = try Engine(trustJSON: f.trustJSON, deviceSecretHex: f.device.secretHex)
        let root = f.dir.appendingPathComponent("packages")
        try engine.install(packageDir: root.appendingPathComponent("sandline-sa-full"), lpk: f.lpk, licenseJSON: f.licenseJSON)
        _ = try engine.load(packagesRoot: root)

        let lines = try await OCRService().recognize(Self.render("RIM: We leave at dawn. Nobody talks about tonight."))
        XCTAssertFalse(lines.isEmpty)
        let text = lines.joined(separator: " ").replacingOccurrences(of: "RIM: ", with: "")
        let hit = try XCTUnwrap(engine.lookup(text), "OCR read: \(lines)")
        XCTAssertEqual(hit.entryId, 1)
    }

    func testAverageHashIgnoresIdenticalFrames() {
        let a = Self.render("Water costs more than gold out here.")
        let b = Self.render("Water costs more than gold out here.")
        let c = Self.render("Something entirely different appears now")
        XCTAssertEqual(CaptureService.averageHash(a), CaptureService.averageHash(b))
        XCTAssertNotEqual(CaptureService.averageHash(a), CaptureService.averageHash(c))
    }
}
