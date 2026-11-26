// LUGHA Remote Play (iPhone). AGPL-3.0 — see ../../LICENSE.
//
// Same Dart API as the Android plugin. The Remote Play session itself is LUGHA's shared core (engine/lugha-rp, on
// chiaki-ng), linked into the app through the lugha_engine pod (lugha_rpx_* in lugha.h). Video arrives as H.264
// and is decoded here with VideoToolbox; a few times a second the newest frame is read with Vision's on-device text
// recognizer and the lines inside the reading areas go to Dart. No game audio is played; the picture is shown only in the optional picture-in-picture window (LivePiP.swift).

import AVFoundation
import CoreImage
import CoreMedia
import Flutter
import UIKit
import Vision
import VideoToolbox

// ---- lugha_rpx_* (engine/lugha-ffi/src/rp.rs), resolved from the lugha_engine framework ----

@_silgen_name("lugha_rpx_discover") private func rpx_discover(_ timeoutMs: UInt32) -> UnsafeMutablePointer<CChar>?
@_silgen_name("lugha_rpx_register") private func rpx_register(_ host: UnsafePointer<CChar>, _ account: UnsafePointer<CChar>, _ pin: UInt32, _ ps5: Bool) -> UnsafeMutablePointer<CChar>?
@_silgen_name("lugha_rpx_wakeup") private func rpx_wakeup(_ host: UnsafePointer<CChar>, _ key: UnsafePointer<CChar>, _ ps5: Bool) -> Int32
@_silgen_name("lugha_rpx_start") private func rpx_start(
    _ host: UnsafePointer<CChar>, _ ps5: Bool, _ key: UnsafePointer<CChar>, _ rpKey: UnsafePointer<CChar>, _ height: UInt32,
    _ frame: @convention(c) (UnsafeMutableRawPointer?, UnsafePointer<UInt8>?, Int) -> Void,
    _ state: @convention(c) (UnsafeMutableRawPointer?, Int32, UnsafePointer<CChar>?) -> Void,
    _ ctx: UnsafeMutableRawPointer?) -> OpaquePointer?
@_silgen_name("lugha_rpx_request_keyframe") private func rpx_request_keyframe(_ s: OpaquePointer)
@_silgen_name("lugha_rpx_set_pin") private func rpx_set_pin(_ s: OpaquePointer, _ pin: UnsafePointer<CChar>)
@_silgen_name("lugha_rpx_stop") private func rpx_stop(_ s: OpaquePointer)
@_silgen_name("lugha_string_free") private func lugha_string_free(_ s: UnsafeMutablePointer<CChar>?)
@_silgen_name("lugha_rpx_log") private func rpx_log() -> UnsafeMutablePointer<CChar>?

/// The last few Remote Play warnings/errors, to show why something failed.
func remotePlayLog(lines: Int = 3) -> String {
    guard let p = rpx_log() else { return "" }
    defer { lugha_string_free(p) }
    return String(cString: p).split(separator: "\n").suffix(lines).joined(separator: "\n")
}

private func takeJSON(_ p: UnsafeMutablePointer<CChar>?) -> Any? {
    guard let p else { return nil }
    defer { lugha_string_free(p) }
    return try? JSONSerialization.jsonObject(with: Data(String(cString: p).utf8))
}

/// Keeps the app running in the background while a session is open: iOS suspends apps that leave the screen, unless
/// they play audio. A silent, looping sound that mixes with other apps (their audio is not interrupted) does that.
final class BackgroundKeeper {
    static let shared = BackgroundKeeper()
    private var player: AVAudioPlayer?

    func start() {
        guard player == nil else { return }
        do {
            try AVAudioSession.sharedInstance().setCategory(.playback, options: [.mixWithOthers])
            try AVAudioSession.sharedInstance().setActive(true)
            let p = try AVAudioPlayer(data: Self.silence(seconds: 2))
            p.numberOfLoops = -1
            p.volume = 0
            p.play()
            player = p
        } catch {
            player = nil
        }
    }

    func stop() {
        player?.stop()
        player = nil
        try? AVAudioSession.sharedInstance().setActive(false, options: [.notifyOthersOnDeactivation])
    }

    /// A WAV file of silence (16-bit mono, 8 kHz).
    private static func silence(seconds: Int) -> Data {
        let rate = 8000, samples = rate * seconds, bytes = samples * 2
        var d = Data()
        func u32(_ v: Int) { var x = UInt32(v).littleEndian; d.append(Data(bytes: &x, count: 4)) }
        func u16(_ v: Int) { var x = UInt16(v).littleEndian; d.append(Data(bytes: &x, count: 2)) }
        d.append(contentsOf: Array("RIFF".utf8)); u32(36 + bytes); d.append(contentsOf: Array("WAVEfmt ".utf8))
        u32(16); u16(1); u16(1); u32(rate); u32(rate * 2); u16(2); u16(16)
        d.append(contentsOf: Array("data".utf8)); u32(bytes)
        d.append(Data(count: bytes))
        return d
    }
}

/// Reading areas as fractions of the frame (x, y, w, h; origin top-left).
struct ReadArea { let x, y, w, h: CGFloat }

public class LughaRemotePlayPlugin: NSObject, FlutterPlugin, FlutterStreamHandler {
    private var sink: FlutterEventSink?
    private var session: RemoteSession?
    private let work = DispatchQueue(label: "app.lugha.remoteplay", qos: .userInitiated)

    public static func register(with registrar: FlutterPluginRegistrar) {
        let me = LughaRemotePlayPlugin()
        registrar.addMethodCallDelegate(me, channel: FlutterMethodChannel(name: "app.lugha/remoteplay", binaryMessenger: registrar.messenger()))
        FlutterEventChannel(name: "app.lugha/remoteplay/events", binaryMessenger: registrar.messenger()).setStreamHandler(me)
    }

    public func onListen(withArguments _: Any?, eventSink events: @escaping FlutterEventSink) -> FlutterError? { sink = events; return nil }
    public func onCancel(withArguments _: Any?) -> FlutterError? { sink = nil; return nil }

    fileprivate func emit(_ event: [String: Any]) { DispatchQueue.main.async { self.sink?(event) } }

    public func handle(_ call: FlutterMethodCall, result: @escaping FlutterResult) {
        let a = call.arguments as? [String: Any] ?? [:]
        let done: (Any?) -> Void = { v in DispatchQueue.main.async { result(v) } }
        switch call.method {
        case "discover":
            let ms = UInt32((a["timeoutMs"] as? Int) ?? 3000)
            work.async {
                let list = (takeJSON(rpx_discover(ms)) as? [[String: Any]]) ?? []
                done(list.map { c in
                    ["host": c["host"] ?? "", "name": c["name"] ?? "PlayStation", "state": (c["ready"] as? Bool ?? false) ? "ready" : "standby",
                     "ps5": c["ps5"] ?? true, "app": c["app"] ?? "", "id": c["id"] ?? ""]
                })
            }
        case "register":
            guard let host = a["host"] as? String, let acc = a["accountId"] as? String, let pin = UInt32((a["pin"] as? String ?? "").filter(\.isNumber)) else {
                return result(FlutterError(code: "args", message: "host, accountId and the 8-digit PIN are needed", details: nil))
            }
            let ps5 = a["ps5"] as? Bool ?? true
            work.async {
                let r = takeJSON(rpx_register(host, acc, pin, ps5)) as? [String: Any] ?? [:]
                if r["ok"] as? Bool == true {
                    done(["host": host, "ps5": ps5, "nickname": r["nickname"] ?? "PS5", "regist_key": r["regist_key"] ?? "", "rp_key": r["rp_key"] ?? "", "mac": r["mac"] ?? ""])
                } else {
                    let why = remotePlayLog()
                    done(FlutterError(code: "register", message: (r["error"] as? String ?? "registration failed") + (why.isEmpty ? "" : "\n" + why), details: nil))
                }
            }
        case "wakeup":
            guard let host = a["host"] as? String, let key = a["registKey"] as? String else { return result(nil) }
            let ps5 = a["ps5"] as? Bool ?? true
            work.async { _ = rpx_wakeup(host, key, ps5); done(nil) }
        case "start":
            guard let host = a["host"] as? String, let key = a["registKey"] as? String, let rpKey = a["rpKey"] as? String else {
                return result(FlutterError(code: "args", message: "registration missing", details: nil))
            }
            session?.stop()
            let s = RemoteSession(plugin: self, areas: Self.areas(a), fps: max(1, (a["fps"] as? Int) ?? 4))
            guard s.start(host: host, ps5: a["ps5"] as? Bool ?? true, key: key, rpKey: rpKey) else {
                let why = remotePlayLog()
                return result(FlutterError(code: "start", message: "could not open Remote Play (\(host))" + (why.isEmpty ? "" : "\n" + why), details: nil))
            }
            session = s
            UIApplication.shared.isIdleTimerDisabled = true // the phone must stay awake while LUGHA reads the PS5
            // Keep reading while the player uses another app or locks the phone.
            if a["background"] as? Bool ?? true { BackgroundKeeper.shared.start() }
            // The floating window (game + Arabic) — opens by itself when the player leaves LUGHA.
            if a["pip"] as? Bool ?? true { LivePiP.shared.prepare() }
            result(nil)
        case "pip":
            LivePiP.shared.start()
            result(LivePiP.supported)
        case "caption":
            LivePiP.shared.setCaption(a["text"] as? String ?? "")
            result(nil)
        case "setArea":
            session?.areas = Self.areas(a)
            result(nil)
        case "setLoginPin":
            session?.setPin(a["pin"] as? String ?? "")
            result(nil)
        case "snapshot":
            // The newest frame as PNG, for drawing reading areas on it.
            guard let s = session else { return result(nil) }
            work.async { done(s.snapshotPNG().map { FlutterStandardTypedData(bytes: $0) }) }
        case "stop":
            session?.stop()
            session = nil
            UIApplication.shared.isIdleTimerDisabled = false
            BackgroundKeeper.shared.stop()
            LivePiP.shared.teardown()
            result(nil)
        default:
            result(FlutterMethodNotImplemented)
        }
    }

    /// "areas" (reading areas), else the single "area".
    private static func areas(_ a: [String: Any]) -> [ReadArea] {
        func one(_ m: Any?) -> ReadArea? {
            guard let m = m as? [String: Any] else { return nil }
            let v = { (k: String) in CGFloat((m[k] as? NSNumber)?.doubleValue ?? 0) }
            return ReadArea(x: v("x"), y: v("y"), w: v("w"), h: v("h"))
        }
        let list = (a["areas"] as? [Any] ?? []).compactMap(one)
        return list.isEmpty ? [one(a["area"]) ?? ReadArea(x: 0.08, y: 0.70, w: 0.84, h: 0.26)] : list
    }
}

/// One Remote Play session: H.264 → VideoToolbox → newest frame → Vision a few times a second.
final class RemoteSession {
    private weak var plugin: LughaRemotePlayPlugin?
    var areas: [ReadArea]
    private let fps: Int
    private var handle: OpaquePointer?
    private var decoder: H264Decoder?
    private let lock = NSLock()
    private var latest: CVPixelBuffer?
    private var timer: DispatchSourceTimer?
    private let ocrQueue = DispatchQueue(label: "app.lugha.remoteplay.ocr", qos: .userInitiated)
    private var lastLines: [String] = []
    private var lastRead: CVPixelBuffer?
    private var reading = false

    init(plugin: LughaRemotePlayPlugin, areas: [ReadArea], fps: Int) {
        self.plugin = plugin
        self.areas = areas
        self.fps = fps
    }

    func start(host: String, ps5: Bool, key: String, rpKey: String) -> Bool {
        decoder = H264Decoder { [weak self] buffer in
            guard let self else { return }
            self.lock.lock(); self.latest = buffer; self.lock.unlock()
            LivePiP.shared.push(buffer)
        } onError: { [weak self] in
            if let h = self?.handle { rpx_request_keyframe(h) }
        }
        let ctx = Unmanaged.passUnretained(self).toOpaque()
        handle = rpx_start(host, ps5, key, rpKey, 720, { ctx, data, size in
            guard let ctx, let data else { return }
            let me = Unmanaged<RemoteSession>.fromOpaque(ctx).takeUnretainedValue()
            me.decoder?.decode(UnsafeBufferPointer(start: data, count: size))
        }, { ctx, state, reason in
            guard let ctx else { return }
            let me = Unmanaged<RemoteSession>.fromOpaque(ctx).takeUnretainedValue()
            let why = reason.map { String(cString: $0) } ?? ""
            switch state {
            case 1: me.plugin?.emit(["type": "connected"])
            case 3: me.plugin?.emit(["type": "pin", "incorrect": why == "incorrect"])
            default:
                let error = !why.isEmpty && !why.lowercased().contains("stopped")
                let detail = error ? remotePlayLog() : ""
                me.plugin?.emit(["type": "stopped", "reason": why + (detail.isEmpty ? "" : "\n" + detail), "error": error])
            }
        }, ctx)
        guard handle != nil else { return false }
        let t = DispatchSource.makeTimerSource(queue: ocrQueue)
        t.schedule(deadline: .now() + 1, repeating: 1.0 / Double(fps))
        t.setEventHandler { [weak self] in self?.read() }
        t.resume()
        timer = t
        return true
    }

    func setPin(_ pin: String) {
        if let h = handle { rpx_set_pin(h, pin) }
    }

    func stop() {
        timer?.cancel()
        timer = nil
        if let h = handle { rpx_stop(h) } // joins chiaki's threads: no callback after this
        handle = nil
        decoder = nil
    }

    private func newest() -> CVPixelBuffer? {
        lock.lock(); defer { lock.unlock() }
        return latest
    }

    /// Text inside the reading areas, top to bottom; sent only when it changes.
    private func read() {
        guard !reading, let frame = newest() else { return }
        reading = true
        defer { reading = false }
        // The same picture as last time: nothing new to read.
        if frame === lastRead { return }
        lastRead = frame
        let boxes = areas
        // Each reading area is read on its own (together far smaller than the whole picture, so much faster), with a
        // small margin so a line at the edge is not cut; a line belongs to the area that holds its centre.
        let unit = CGRect(x: 0, y: 0, width: 1, height: 1)
        let jobs: [(area: Int, roi: CGRect, req: VNRecognizeTextRequest)] = boxes.enumerated().compactMap { n, a in
            let padded = CGRect(x: a.x - 0.02, y: a.y - 0.025, width: a.w + 0.04, height: a.h + 0.05).intersection(unit)
            guard !padded.isNull, padded.width > 0.01, padded.height > 0.01 else { return nil }
            let req = VNRecognizeTextRequest()
            req.recognitionLevel = .accurate
            req.usesLanguageCorrection = false
            req.recognitionLanguages = ["en-US"]
            // Vision's origin is bottom-left; the areas' is top-left.
            let roi = CGRect(x: padded.minX, y: 1 - padded.maxY, width: padded.width, height: padded.height)
            req.regionOfInterest = roi
            return (n, roi, req)
        }
        try? VNImageRequestHandler(cvPixelBuffer: frame, options: [:]).perform(jobs.map(\.req))
        var found: [(text: String, area: Int, box: CGRect)] = []
        var seen = Set<String>()
        for job in jobs {
            for o in job.req.results ?? [] {
                guard let text = o.topCandidates(1).first?.string, !text.trimmingCharacters(in: .whitespaces).isEmpty else { continue }
                // Boxes come relative to the region read; back to the whole frame, origin top-left like the areas.
                let r = o.boundingBox, roi = job.roi
                let fx = roi.minX + r.minX * roi.width, fy = roi.minY + r.minY * roi.height
                let fw = r.width * roi.width, fh = r.height * roi.height
                let bx = CGRect(x: fx, y: 1 - (fy + fh), width: fw, height: fh)
                guard let n = boxes.firstIndex(where: { bx.midX >= $0.x && bx.midX <= $0.x + $0.w && bx.midY >= $0.y && bx.midY <= $0.y + $0.h }) else { continue }
                // Padded regions overlap a little: the same line read twice is kept once.
                let key = "\(Int(bx.midX * 200)):\(Int(bx.midY * 200)):\(text)"
                if seen.insert(key).inserted { found.append((text, n, bx)) }
            }
        }
        found.sort { $0.box.minY < $1.box.minY }
        let lines = found.map(\.text)
        if lines != lastLines {
            lastLines = lines
            plugin?.emit(["type": "text", "lines": lines, "areas": found.map(\.area),
                          "boxes": found.map { [Double($0.box.minX), Double($0.box.minY), Double($0.box.width), Double($0.box.height)] }])
        }
    }

    func snapshotPNG() -> Data? {
        guard let frame = newest() else { return nil }
        let image = CIImage(cvPixelBuffer: frame)
        let scale = min(1, 960 / image.extent.width)
        let small = image.transformed(by: CGAffineTransform(scaleX: scale, y: scale))
        let ctx = CIContext()
        guard let cg = ctx.createCGImage(small, from: small.extent) else { return nil }
        return UIImage(cgImage: cg).pngData()
    }
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
