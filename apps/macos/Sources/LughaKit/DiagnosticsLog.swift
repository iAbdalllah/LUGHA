import CoreGraphics
import Foundation

/// What LUGHA read and decided, one JSON line per event, in ~/Library/Logs/LUGHA/pipeline.log — so a line that was on
/// screen but not translated can be traced (what OCR read, which lookups ran, what matched, how long each step took).
/// Rotates at 20 MB (one previous file kept). Turn off with `defaults write app.lugha.mac Diagnostics -bool NO`.
public final class DiagnosticsLog: @unchecked Sendable {
    public static let shared = DiagnosticsLog()

    public let url: URL
    private let queue = DispatchQueue(label: "lugha.diagnostics")
    private var handle: FileHandle?
    private let maxBytes: UInt64 = 20 * 1024 * 1024
    public var enabled: Bool { UserDefaults.standard.object(forKey: "Diagnostics") as? Bool ?? true }

    private init() {
        let dir = FileManager.default.urls(for: .libraryDirectory, in: .userDomainMask)[0].appendingPathComponent("Logs/LUGHA", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        url = dir.appendingPathComponent("pipeline.log")
    }

    public func write(_ event: String, _ fields: [String: Any]) {
        guard enabled else { return }
        var obj = fields
        obj["t"] = String(format: "%.3f", Date().timeIntervalSince1970)
        obj["ev"] = event
        guard var data = try? JSONSerialization.data(withJSONObject: obj, options: [.sortedKeys, .withoutEscapingSlashes]) else { return }
        data.append(0x0A)
        queue.async { [self] in
            if handle == nil {
                if !FileManager.default.fileExists(atPath: url.path) { FileManager.default.createFile(atPath: url.path, contents: nil) }
                handle = try? FileHandle(forWritingTo: url)
                _ = try? handle?.seekToEnd()
            }
            if let h = handle, let size = try? h.offset(), size > maxBytes {
                try? h.close()
                handle = nil
                let old = url.deletingPathExtension().appendingPathExtension("1.log")
                try? FileManager.default.removeItem(at: old)
                try? FileManager.default.moveItem(at: url, to: old)
                FileManager.default.createFile(atPath: url.path, contents: nil)
                handle = try? FileHandle(forWritingTo: url)
            }
            try? handle?.write(contentsOf: data)
        }
    }

    /// Box as [x, y, w, h] rounded to 3 decimals (fractions of the capture area).
    public static func box(_ r: CGRect) -> [Double] {
        [r.minX, r.minY, r.width, r.height].map { (Double($0) * 1000).rounded() / 1000 }
    }
}
