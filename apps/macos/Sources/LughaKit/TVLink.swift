import CLugha
import Foundation
import Network

/// LUGHA Link sender (Mac → TV), docs/05. Crypto is the shared Rust crate (via lugha-ffi); this file is
/// framing + TCP (Network.framework) + Bonjour discovery.
public enum LinkFrame {
    public static let pair1: UInt8 = 0x01, pair2: UInt8 = 0x02, pair3: UInt8 = 0x03, pairOK: UInt8 = 0x04, pairFail: UInt8 = 0x0F
    public static let hs1: UInt8 = 0x10, hs2: UInt8 = 0x11, data: UInt8 = 0x20
    public static let serviceType = "_lugha._tcp"
    public static let defaultPort: UInt16 = 47800
}

public struct PairedTV: Codable, Equatable, Sendable, Identifiable {
    public let publicKey: String
    public var name: String
    public var host: String
    public var port: UInt16
    public var id: String { publicKey }
}

public enum LinkError: Error, LocalizedError, Equatable {
    case wrongCode, locked, closed, protocolError(String)
    public var errorDescription: String? {
        switch self {
        case .wrongCode: return "Wrong code"
        case .locked: return "Too many wrong codes — try again in a minute"
        case .closed: return "Connection closed"
        case let .protocolError(m): return m
        }
    }
}

private func ffiJSON(_ f: () -> UnsafeMutablePointer<CChar>?) throws -> [String: Any] {
    guard let p = f() else { throw LinkError.protocolError("native call failed") }
    defer { lugha_string_free(p) }
    let o = (try JSONSerialization.jsonObject(with: Data(String(cString: p).utf8)) as? [String: Any]) ?? [:]
    guard o["ok"] as? Bool == true else { throw LinkError.protocolError(o["error"] as? String ?? "native error") }
    return o
}

/// Framed TCP connection.
final class FramedConnection: @unchecked Sendable {
    private let conn: NWConnection
    private let queue = DispatchQueue(label: "app.lugha.link")

    init(host: String, port: UInt16) {
        let tcp = NWProtocolTCP.Options()
        tcp.noDelay = true
        conn = NWConnection(host: NWEndpoint.Host(host), port: NWEndpoint.Port(rawValue: port)!, using: NWParameters(tls: nil, tcp: tcp))
    }

    func open(timeout: TimeInterval = 8) async throws {
        try await withCheckedThrowingContinuation { (cont: CheckedContinuation<Void, Error>) in
            var done = false
            let finish: (Result<Void, Error>) -> Void = { r in if !done { done = true; cont.resume(with: r) } }
            conn.stateUpdateHandler = { state in
                switch state {
                case .ready: finish(.success(()))
                case let .failed(e), let .waiting(e): finish(.failure(e))
                case .cancelled: finish(.failure(LinkError.closed))
                default: break
                }
            }
            conn.start(queue: queue)
            queue.asyncAfter(deadline: .now() + timeout) { finish(.failure(LinkError.closed)) }
        }
    }

    func send(_ type: UInt8, _ payload: Data) async throws {
        var frame = Data()
        var len = UInt32(payload.count + 1).bigEndian
        frame.append(Data(bytes: &len, count: 4))
        frame.append(type)
        frame.append(payload)
        try await withCheckedThrowingContinuation { (cont: CheckedContinuation<Void, Error>) in
            conn.send(content: frame, completion: .contentProcessed { e in e.map { cont.resume(throwing: $0) } ?? cont.resume() })
        }
    }

    private func receive(_ n: Int) async throws -> Data {
        try await withCheckedThrowingContinuation { cont in
            conn.receive(minimumIncompleteLength: n, maximumLength: n) { data, _, complete, error in
                if let error { return cont.resume(throwing: error) }
                guard let data, data.count == n else { return cont.resume(throwing: complete ? LinkError.closed : LinkError.protocolError("short read")) }
                cont.resume(returning: data)
            }
        }
    }

    func read() async throws -> (UInt8, Data) {
        let head = try await receive(4)
        let len = Int(head.withUnsafeBytes { $0.load(as: UInt32.self).bigEndian })
        guard len >= 1, len <= 65536 else { throw LinkError.protocolError("bad frame length") }
        let body = try await receive(len)
        return (body[body.startIndex], body.dropFirst())
    }

    func close() { conn.cancel() }
}

/// One live session with a TV.
public final class TVSender: @unchecked Sendable {
    public let tv: PairedTV
    private let conn: FramedConnection
    private var session: OpaquePointer?
    private var nextID: UInt64 = 1
    private let lock = NSLock()
    public private(set) var lastLatencyMs: Double?

    private init(tv: PairedTV, conn: FramedConnection) {
        self.tv = tv
        self.conn = conn
    }

    deinit {
        conn.close()
        if let session { lugha_link_session_free(session) }
    }

    /// The Mac's link identity, kept in the Keychain.
    public static func identity(_ keychain: Keychain = Keychain()) throws -> (secretHex: String, publicKey: String) {
        if let s = keychain.get("link_secret"), let p = keychain.get("link_public") { return (s, p) }
        let k = try ffiJSON { lugha_link_keys_generate() }
        let s = k["secret_hex"] as! String, p = k["public"] as! String
        try keychain.set(s, for: "link_secret")
        try keychain.set(p, for: "link_public")
        return (s, p)
    }

    /// First-time pairing with the code shown on the TV; on success the connection continues as a session.
    public static func pair(host: String, port: UInt16, code: String, name: String, senderName: String, secretHex: String) async throws -> TVSender {
        let conn = FramedConnection(host: host, port: port)
        try await conn.open()
        var pairing: OpaquePointer?
        let start = try ffiJSON { lugha_link_pair_start(code, secretHex, &pairing) }
        try await conn.send(LinkFrame.pair1, Data(base64Encoded: start["msg"] as! String)!)
        let (t2, p2) = try await conn.read()
        guard t2 == LinkFrame.pair2 else { lugha_link_pairing_free(pairing); conn.close(); throw t2 == LinkFrame.pairFail ? LinkError.locked : LinkError.protocolError("unexpected frame") }
        let fin: [String: Any]
        do {
            fin = try ffiJSON { lugha_link_pair_finish(pairing, p2.base64EncodedString()) }
        } catch {
            try? await conn.send(LinkFrame.pair3, Data(count: 48)) // let the TV count the failed attempt
            conn.close()
            throw LinkError.wrongCode
        }
        try await conn.send(LinkFrame.pair3, Data(base64Encoded: fin["msg"] as! String)!)
        let (t4, _) = try await conn.read()
        guard t4 == LinkFrame.pairOK else { conn.close(); throw LinkError.wrongCode }
        let tv = PairedTV(publicKey: fin["peer"] as! String, name: name, host: host, port: port)
        let sender = TVSender(tv: tv, conn: conn)
        try await sender.handshake(secretHex: secretHex, senderName: senderName)
        return sender
    }

    /// Reconnect to a TV paired earlier (no code).
    public static func connect(_ tv: PairedTV, senderName: String, secretHex: String) async throws -> TVSender {
        let conn = FramedConnection(host: tv.host, port: tv.port)
        try await conn.open()
        let sender = TVSender(tv: tv, conn: conn)
        try await sender.handshake(secretHex: secretHex, senderName: senderName)
        return sender
    }

    private func handshake(secretHex: String, senderName: String) async throws {
        var s: OpaquePointer?
        let start = try ffiJSON { lugha_link_session_start(secretHex, tv.publicKey, &s) }
        session = s
        try await conn.send(LinkFrame.hs1, Data(base64Encoded: start["msg"] as! String)!)
        let (t, hs2) = try await conn.read()
        guard t == LinkFrame.hs2 else { throw LinkError.protocolError("TV refused the session (not paired?)") }
        _ = try ffiJSON { lugha_link_session_complete(session, hs2.base64EncodedString()) }
        try await send(["t": "hello", "app": "lugha-mac", "app_version": "0.1.0", "sender_name": senderName])
        _ = try await receive() // welcome
    }

    private func send(_ msg: [String: Any]) async throws {
        let json = String(decoding: try JSONSerialization.data(withJSONObject: msg), as: UTF8.self)
        let enc = try lock.withLock { try ffiJSON { lugha_link_encrypt(session, json) } }
        try await conn.send(LinkFrame.data, Data(base64Encoded: enc["msg"] as! String)!)
    }

    private func receive() async throws -> [String: Any] {
        let (t, body) = try await conn.read()
        guard t == LinkFrame.data else { throw LinkError.protocolError("unexpected frame") }
        let dec = try lock.withLock { try ffiJSON { lugha_link_decrypt(session, body.base64EncodedString()) } }
        return (try JSONSerialization.jsonObject(with: Data((dec["text"] as! String).utf8)) as? [String: Any]) ?? [:]
    }

    private func takeID() -> UInt64 { lock.withLock { defer { nextID += 1 }; return nextID } }

    /// Show a line on the TV. With `waitForAck`, returns the round trip until the TV painted it (ms).
    @discardableResult
    public func show(_ text: String, speaker: String?, waitForAck: Bool = false) async throws -> Double? {
        let id = takeID()
        let t0 = DispatchTime.now()
        var m: [String: Any] = ["t": "sub.show", "id": id, "sid": id, "text": text, "dir": "rtl", "ack": waitForAck]
        if let speaker { m["speaker"] = speaker }
        try await send(m)
        guard waitForAck else { return nil }
        while true {
            let r = try await receive()
            if r["t"] as? String == "ack", (r["id"] as? NSNumber)?.uint64Value == id {
                let ms = Double(DispatchTime.now().uptimeNanoseconds - t0.uptimeNanoseconds) / 1_000_000
                lastLatencyMs = ms
                return ms
            }
        }
    }

    public func clear() async throws { try await send(["t": "sub.clear", "id": takeID()]) }

    public func ping() async throws -> Bool {
        let id = takeID()
        try await send(["t": "ping", "id": id])
        return try await receive()["t"] as? String == "pong"
    }

    public func setStyle(fontScale: Double, positionY: Double, bgOpacity: Double) async throws {
        try await send(["t": "style.set", "font_scale": fontScale, "position_y": positionY, "bg_opacity": bgOpacity])
    }

    public func close() async {
        try? await send(["t": "bye", "reason": "closed"])
        conn.close()
    }
}

/// Finds LUGHA TVs on the local network (Bonjour `_lugha._tcp`).
public final class TVBrowser: @unchecked Sendable {
    public struct Found: Hashable, Sendable, Identifiable {
        public let name: String
        public let host: String
        public let port: UInt16
        public var id: String { "\(host):\(port)" }
    }

    private let browser = NWBrowser(for: .bonjour(type: LinkFrame.serviceType, domain: nil), using: .tcp)
    private let queue = DispatchQueue(label: "app.lugha.browse")
    public var onChange: (([Found]) -> Void)?

    public init() {}

    public func start() {
        browser.browseResultsChangedHandler = { [weak self] results, _ in
            self?.resolve(results)
        }
        browser.start(queue: queue)
    }

    public func stop() { browser.cancel() }

    private func resolve(_ results: Set<NWBrowser.Result>) {
        var found: [Found] = []
        let group = DispatchGroup()
        for r in results {
            guard case let .service(name, _, _, _) = r.endpoint else { continue }
            group.enter()
            let c = NWConnection(to: r.endpoint, using: .tcp)
            c.stateUpdateHandler = { state in
                if case .ready = state, case let .hostPort(host, port)? = c.currentPath?.remoteEndpoint {
                    var h = "\(host)"
                    if let pct = h.firstIndex(of: "%") { h = String(h[..<pct]) } // strip interface scope
                    found.append(Found(name: name, host: h, port: port.rawValue))
                    c.cancel()
                    group.leave()
                } else if case .failed = state {
                    c.cancel()
                    group.leave()
                }
            }
            c.start(queue: queue)
        }
        group.notify(queue: .main) { [weak self] in self?.onChange?(found.sorted { $0.name < $1.name }) }
    }
}
