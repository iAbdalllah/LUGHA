import CoreGraphics
import Foundation

/// An sRGB colour, 0–1 per channel.
public struct RGB: Equatable, Sendable {
    public let r, g, b: Double
    public init(r: Double, g: Double, b: Double) { self.r = r; self.g = g; self.b = b }
    public var hex: String { String(format: "#%02X%02X%02X", Int(r * 255), Int(g * 255), Int(b * 255)) }
    var luma: Double { 0.2126 * r + 0.7152 * g + 0.0722 * b }
    func distance(_ o: RGB) -> Double { abs(r - o.r) + abs(g - o.g) + abs(b - o.b) }
}

/// Reads colours out of a captured frame (downscaled once) so the Arabic can be drawn in the game's own colours, and
/// finds where the game picture sits inside a window that adds black bars or a title bar.
public struct FrameSampler {
    public let width: Int
    public let height: Int
    private var px: [UInt8]

    public init?(_ image: CGImage, maxWidth: Int = 1280) {
        let scale = min(1, Double(maxWidth) / Double(image.width))
        width = max(1, Int(Double(image.width) * scale))
        height = max(1, Int(Double(image.height) * scale))
        px = [UInt8](repeating: 0, count: width * height * 4)
        let ok = px.withUnsafeMutableBytes { buf -> Bool in
            guard let ctx = CGContext(data: buf.baseAddress, width: width, height: height, bitsPerComponent: 8, bytesPerRow: width * 4,
                                      space: CGColorSpace(name: CGColorSpace.sRGB)!, bitmapInfo: CGImageAlphaInfo.noneSkipLast.rawValue) else { return false }
            ctx.interpolationQuality = .medium
            ctx.draw(image, in: CGRect(x: 0, y: 0, width: width, height: height))
            return true
        }
        if !ok { return nil }
    }

    private func at(_ x: Int, _ y: Int) -> RGB {
        let i = (min(max(y, 0), height - 1) * width + min(max(x, 0), width - 1)) * 4
        return RGB(r: Double(px[i]) / 255, g: Double(px[i + 1]) / 255, b: Double(px[i + 2]) / 255)
    }

    /// Background and text colour of a text box (fractions, origin top-left): the background is the median of a thin
    /// ring just outside the box; the text colour is the average of the box pixels that differ most from it.
    public func textColors(in box: CGRect) -> (background: RGB, text: RGB)? {
        let x0 = Int(box.minX * Double(width)), x1 = Int(box.maxX * Double(width))
        let y0 = Int(box.minY * Double(height)), y1 = Int(box.maxY * Double(height))
        guard x1 > x0 + 2, y1 > y0 + 1 else { return nil }
        let pad = max(2, (y1 - y0) / 4)
        var ring: [RGB] = []
        for x in stride(from: x0 - pad, through: x1 + pad, by: 2) { ring.append(at(x, y0 - pad)); ring.append(at(x, y1 + pad)) }
        for y in stride(from: y0, through: y1, by: 2) { ring.append(at(x0 - pad, y)); ring.append(at(x1 + pad, y)) }
        func median(_ v: [Double]) -> Double { let s = v.sorted(); return s[s.count / 2] }
        let bg = RGB(r: median(ring.map(\.r)), g: median(ring.map(\.g)), b: median(ring.map(\.b)))
        var inside: [RGB] = []
        for y in stride(from: y0, to: y1, by: 1) { for x in stride(from: x0, to: x1, by: 2) { inside.append(at(x, y)) } }
        let far = inside.sorted { $0.distance(bg) > $1.distance(bg) }.prefix(max(1, inside.count / 8))
        let n = Double(far.count)
        var fg = RGB(r: far.map(\.r).reduce(0, +) / n, g: far.map(\.g).reduce(0, +) / n, b: far.map(\.b).reduce(0, +) / n)
        // Too little contrast (anti-aliasing, blur): fall back to white or black, whichever reads on the background.
        if fg.distance(bg) < 0.35 { fg = bg.luma > 0.55 ? RGB(r: 0.08, g: 0.08, b: 0.1) : RGB(r: 1, g: 1, b: 1) }
        return (bg, fg)
    }

    /// The console's picture inside a window: trims black bars, then — the picture being `aspect` (16:9) — trims what
    /// is left over at the top (the window's title bar). Fractions of the frame, origin top-left.
    public func gameRect(aspect: Double = 16.0 / 9.0) -> CGRect {
        var r = contentRect()
        let w = r.width * Double(width), h = r.height * Double(height)
        let wanted = w / aspect
        if wanted < h - 1 {
            // Taller than 16:9: the extra rows are the title bar above the picture.
            let extra = (h - wanted) / Double(height)
            r = CGRect(x: r.minX, y: r.minY + extra, width: r.width, height: r.height - extra)
        } else if wanted > h + 1 {
            // Wider than 16:9 without black bars: the picture is cropped left/right; centre it.
            let keepW = h * aspect / Double(width)
            r = CGRect(x: r.midX - keepW / 2, y: r.minY, width: keepW, height: r.height)
        }
        return r
    }

    /// The game picture inside the frame: trims near-black bars (letterboxing) on all sides.
    public func contentRect(threshold: Double = 0.045) -> CGRect {
        // "Dark" = at least 90% of the row/column is near-black: Remote Play draws small icons (mic, speaker,
        // controller) inside its black bars, which must not stop the trim.
        func mostlyDark(_ samples: [RGB]) -> Bool { Double(samples.filter { $0.luma < threshold }.count) >= Double(samples.count) * 0.9 }
        func rowDark(_ y: Int) -> Bool { mostlyDark(stride(from: 0, to: width, by: 4).map { at($0, y) }) }
        func colDark(_ x: Int) -> Bool { mostlyDark(stride(from: 0, to: height, by: 4).map { at(x, $0) }) }
        var top = 0, bottom = height - 1, left = 0, right = width - 1
        while top < bottom, rowDark(top) { top += 1 }
        while bottom > top, rowDark(bottom) { bottom -= 1 }
        while left < right, colDark(left) { left += 1 }
        while right > left, colDark(right) { right -= 1 }
        guard right - left > width / 3, bottom - top > height / 3 else { return CGRect(x: 0, y: 0, width: 1, height: 1) }
        return CGRect(x: Double(left) / Double(width), y: Double(top) / Double(height),
                      width: Double(right - left + 1) / Double(width), height: Double(bottom - top + 1) / Double(height))
    }
}
