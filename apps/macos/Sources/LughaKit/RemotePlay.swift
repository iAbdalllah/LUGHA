// LUGHA Remote Play on the Mac: PS5 as the game source, without Sony's app. The session is LUGHA's shared core
// (engine/lugha-rp on chiaki-ng, AGPL-3.0; lugha_rpx_* in lugha.h); its H.264 is decoded here with VideoToolbox and
// a few pictures a second go into the usual reading pipeline (Vision → reading areas → match → overlay/TV).

import CLugha
import CoreImage
import CoreMedia
import Foundation
import VideoToolbox

public enum PS5 {
    /// A console on this network.
    public struct Console: Identifiable, Hashable, Sendable {
        public let host: String
        public let name: String
        public let ps5: Bool
        public let ready: Bool
        public let app: String
        public var id: String { host }
    }

    /// This Mac's link with a console (keep it in the Keychain: the keys open Remote Play sessions).
    public struct Link: Codable, Equatable, Sendable {
        public var host: String
        public var name: String
        public var ps5: Bool
        public var mac: String
        public var registKey: String // base64
        public var rpKey: String // base64
    }

    private static func json(_ p: UnsafeMutablePointer<CChar>?) -> Any? {
        guard let p else { return nil }
        defer { lugha_string_free(p) }
        return try? JSONSerialization.jsonObject(with: Data(String(cString: p).utf8))
    }

    /// Recent Remote Play warnings and errors (the last few lines), to show why something failed.
    public static func log(lines: Int = 3) -> String {
        guard let p = lugha_rpx_log() else { return "" }
        defer { lugha_string_free(p) }
        return String(cString: p).split(separator: "\n").suffix(lines).joined(separator: "\n")
    }

    /// Consoles answering within `seconds`.
    public static func discover(seconds: Double = 3) async -> [Console] {
        await Task.detached {
            let list = (json(lugha_rpx_discover(UInt32(seconds * 1000))) as? [[String: Any]]) ?? []
            return list.map { c in
                Console(host: c["host"] as? String ?? "", name: c["name"] as? String ?? "PlayStation", ps5: c["ps5"] as? Bool ?? true,
                        ready: c["ready"] as? Bool ?? false, app: c["app"] as? String ?? "")
            }
        }.value
    }

    public struct Failure: LocalizedError {
        public let message: String
        public var errorDescription: String? { message }
    }

    /// Links this Mac with the console: `accountID` from the PSN sign-in, `pin` the 8 digits the PS5 shows.
    public static func link(host: String, name: String, ps5: Bool, accountID: String, pin: String) async throws -> Link {
        guard let pinValue = UInt32(pin.filter(\.isNumber)) else { throw Failure(message: "The code has 8 digits.") }
        return try await Task.detached {
            let r = json(lugha_rpx_register(host, accountID, pinValue, ps5)) as? [String: Any] ?? [:]
            guard r["ok"] as? Bool == true, let key = r["regist_key"] as? String, let rp = r["rp_key"] as? String else {
                let why = log()
                throw Failure(message: (r["error"] as? String ?? "The PS5 refused the link.") + (why.isEmpty ? "" : "\n" + why))
            }
            let nick = (r["nickname"] as? String).flatMap { $0.isEmpty ? nil : $0 } ?? name
            return Link(host: host, name: nick, ps5: ps5, mac: r["mac"] as? String ?? "", registKey: key, rpKey: rp)
        }.value
    }

    /// Wakes a linked console from rest mode (it is ready ~20 s later).
    @discardableResult
    public static func wake(_ l: Link) -> Bool { lugha_rpx_wakeup(l.host, l.registKey, l.ps5) == 0 }
}

/// A Remote Play session whose picture is read, never shown. Hands `pictures` frames a second to `onPicture`.
public final class PS5Session: @unchecked Sendable {
    public enum State: Equatable { case connecting, connected, pin, ended(String) }

    private var handle: OpaquePointer?
    private var decoder: H264Decoder?
    private let lock = NSLock()
    private var latest: CVPixelBuffer?
    private var timer: DispatchSourceTimer?
    private let queue = DispatchQueue(label: "app.lugha.ps5.frames", qos: .userInitiated)
    private let context = CIContext()
    private let onPicture: (CGImage) -> Void
    private let onState: (State) -> Void

    public init(onPicture: @escaping (CGImage) -> Void, onState: @escaping (State) -> Void) {
        self.onPicture = onPicture
        self.onState = onState
    }

    public func start(_ link: PS5.Link, pictures perSecond: Double = 6) throws {
        decoder = H264Decoder { [weak self] buffer in
            guard let self else { return }
            self.lock.lock(); self.latest = buffer; self.lock.unlock()
        } onError: { [weak self] in
            if let h = self?.handle { lugha_rpx_request_keyframe(h) }
        }
        let ctx = Unmanaged.passUnretained(self).toOpaque()
        onState(.connecting)
        handle = lugha_rpx_start(link.host, link.ps5, link.registKey, link.rpKey, 720, { ctx, data, size in
            guard let ctx, let data else { return }
            let me = Unmanaged<PS5Session>.fromOpaque(ctx).takeUnretainedValue()
            me.decoder?.decode(UnsafeBufferPointer(start: data, count: size))
        }, { ctx, state, reason in
            guard let ctx else { return }
            let me = Unmanaged<PS5Session>.fromOpaque(ctx).takeUnretainedValue()
            let why = reason.map { String(cString: $0) } ?? ""
            switch state {
            case 1: me.onState(.connected)
            case 3: me.onState(.pin)
            default: me.onState(.ended(why))
            }
        }, ctx)
        guard handle != nil else {
            let why = PS5.log()
            throw PS5.Failure(message: "Could not open Remote Play (\(link.host))" + (why.isEmpty ? "" : "\n" + why))
        }
        let t = DispatchSource.makeTimerSource(queue: queue)
        t.schedule(deadline: .now() + 1, repeating: 1 / max(perSecond, 0.5))
        t.setEventHandler { [weak self] in
            guard let self, let img = self.picture() else { return }
            self.onPicture(img)
        }
        t.resume()
        timer = t
    }

    public func setPin(_ pin: String) {
        if let h = handle { lugha_rpx_set_pin(h, pin) }
    }

    /// The newest frame as a CGImage (also for the area editor), or nil before the first one.
    public func picture() -> CGImage? {
        lock.lock()
        let b = latest
        lock.unlock()
        guard let b else { return nil }
        let image = CIImage(cvPixelBuffer: b)
        return context.createCGImage(image, from: image.extent)
    }

    public func stop() {
        timer?.cancel()
        timer = nil
        if let h = handle { lugha_rpx_stop(h) } // joins chiaki's threads: no callback after this
        handle = nil
        decoder = nil
    }

    deinit { stop() }
}

/// Annex B H.264 → VideoToolbox → BGRA pixel buffers.
final class H264Decoder {
    private var format: CMVideoFormatDescription?
    private var session: VTDecompressionSession?
    private var sps: Data?
    private var pps: Data?
    private let onFrame: (CVPixelBuffer) -> Void
    private let onError: () -> Void

    init(onFrame: @escaping (CVPixelBuffer) -> Void, onError: @escaping () -> Void) {
        self.onFrame = onFrame
        self.onError = onError
    }

    deinit {
        if let s = session { VTDecompressionSessionInvalidate(s) }
    }

    /// NAL units of one access unit, start codes removed.
    static func nalUnits(_ b: UnsafeBufferPointer<UInt8>) -> [Data] {
        var units: [Data] = []
        var i = 0, start = -1
        let n = b.count
        while i + 2 < n {
            if b[i] == 0, b[i + 1] == 0, b[i + 2] == 1 {
                if start >= 0 {
                    var end = i
                    if end > start, b[end - 1] == 0 { end -= 1 } // 4-byte start code
                    units.append(Data(b[start ..< end]))
                }
                i += 3
                start = i
            } else {
                i += 1
            }
        }
        if start >= 0, start < n { units.append(Data(b[start ..< n])) }
        return units
    }

    func decode(_ frame: UnsafeBufferPointer<UInt8>) {
        var slices: [Data] = []
        for nal in Self.nalUnits(frame) where !nal.isEmpty {
            switch nal[nal.startIndex] & 0x1F {
            case 7: if nal != sps { sps = nal; format = nil }
            case 8: if nal != pps { pps = nal; format = nil }
            case 1, 5: slices.append(nal)
            default: break // SEI, AUD, ...
            }
        }
        if format == nil, !makeSession() { return }
        guard let session, let format, !slices.isEmpty else { return }

        // AVCC: each NAL with a 4-byte big-endian length.
        var avcc = Data()
        for s in slices {
            var len = UInt32(s.count).bigEndian
            avcc.append(Data(bytes: &len, count: 4))
            avcc.append(s)
        }
        var block: CMBlockBuffer?
        let size = avcc.count
        guard CMBlockBufferCreateWithMemoryBlock(allocator: kCFAllocatorDefault, memoryBlock: nil, blockLength: size, blockAllocator: kCFAllocatorDefault,
                                                 customBlockSource: nil, offsetToData: 0, dataLength: size, flags: 0, blockBufferOut: &block) == noErr,
              let block else { return }
        avcc.withUnsafeBytes { _ = CMBlockBufferReplaceDataBytes(with: $0.baseAddress!, blockBuffer: block, offsetIntoDestination: 0, dataLength: size) }
        var sample: CMSampleBuffer?
        var sizes = [size]
        guard CMSampleBufferCreateReady(allocator: kCFAllocatorDefault, dataBuffer: block, formatDescription: format, sampleCount: 1,
                                        sampleTimingEntryCount: 0, sampleTimingArray: nil, sampleSizeEntryCount: 1, sampleSizeArray: &sizes,
                                        sampleBufferOut: &sample) == noErr, let sample else { return }
        let status = VTDecompressionSessionDecodeFrame(session, sampleBuffer: sample, flags: [], infoFlagsOut: nil) { [weak self] status, _, image, _, _ in
            if status == noErr, let image { self?.onFrame(image) } else { self?.onError() }
        }
        if status != noErr {
            if status == kVTInvalidSessionErr { self.format = nil }
            onError()
        }
    }

    private func makeSession() -> Bool {
        guard let sps, let pps else { return false }
        var fmt: CMVideoFormatDescription?
        let ok = sps.withUnsafeBytes { s in
            pps.withUnsafeBytes { p in
                let ptrs = [s.bindMemory(to: UInt8.self).baseAddress!, p.bindMemory(to: UInt8.self).baseAddress!]
                let sizes = [sps.count, pps.count]
                return CMVideoFormatDescriptionCreateFromH264ParameterSets(allocator: kCFAllocatorDefault, parameterSetCount: 2, parameterSetPointers: ptrs,
                                                                          parameterSetSizes: sizes, nalUnitHeaderLength: 4, formatDescriptionOut: &fmt)
            }
        } == noErr
        guard ok, let fmt else { return false }
        if let old = session { VTDecompressionSessionInvalidate(old) }
        let attrs: [CFString: Any] = [kCVPixelBufferPixelFormatTypeKey: kCVPixelFormatType_32BGRA, kCVPixelBufferIOSurfacePropertiesKey: [:] as CFDictionary]
        var s: VTDecompressionSession?
        guard VTDecompressionSessionCreate(allocator: kCFAllocatorDefault, formatDescription: fmt, decoderSpecification: nil,
                                           imageBufferAttributes: attrs as CFDictionary, outputCallback: nil, decompressionSessionOut: &s) == noErr,
              let s else { return false }
        session = s
        format = fmt
        return true
    }
}
