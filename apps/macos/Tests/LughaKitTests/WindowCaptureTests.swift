import XCTest
@testable import LughaKit

/// Window mode against a real app window that is covered by another window (how PS Remote Play is used while the
/// player plays on the TV). Opt-in: put an app on screen showing LUGHA_WINDOW_E2E_TEXT, cover it, then run with
/// LUGHA_WINDOW_E2E_BUNDLE=<its bundle id>. Needs Screen Recording permission for the test runner.
final class WindowCaptureTests: XCTestCase {
    func testReadsTextFromACoveredWindow() async throws {
        let env = ProcessInfo.processInfo.environment
        guard let bundle = env["LUGHA_WINDOW_E2E_BUNDLE"], let expected = env["LUGHA_WINDOW_E2E_TEXT"] else {
            throw XCTSkip("Set LUGHA_WINDOW_E2E_BUNDLE and LUGHA_WINDOW_E2E_TEXT to run against a real window.")
        }
        let windows = try await CaptureService.appWindows()
        XCTAssertTrue(windows.contains { $0.bundleID == bundle }, "window list: \(windows.map(\.bundleID))")

        let capture = CaptureService()
        let got = expectation(description: "frame")
        nonisolated(unsafe) var frame: CGImage?
        try await capture.startWindow(bundleID: bundle, area: CGRect(x: 0, y: 0, width: 1, height: 1)) { image in
            if frame == nil { frame = image; got.fulfill() }
        }
        await fulfillment(of: [got], timeout: 10)
        await capture.stop()

        let lines = try await OCRService().recognize(try XCTUnwrap(frame))
        let text = lines.joined(separator: " ")
        XCTAssertTrue(text.localizedCaseInsensitiveContains(expected), "OCR read: \(lines)")
    }
}
