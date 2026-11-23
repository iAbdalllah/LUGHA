import Foundation

public struct APIError: Error, LocalizedError, Sendable {
    public let status: Int
    public let code: String
    public let detail: String?
    public var errorDescription: String? { detail ?? code }
}

/// LUGHA REST API v1 (docs/03-api.md) — only what the desktop app needs.
public final class APIClient: @unchecked Sendable {
    public var baseURL: URL
    public var token: String?
    public var language: String
    private let session: URLSession

    public init(baseURL: URL, token: String? = nil, language: String = "ar", session: URLSession = .shared) {
        self.baseURL = baseURL
        self.token = token
        self.language = language
        self.session = session
    }

    static let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .convertFromSnakeCase
        return d
    }()

    private func request(_ method: String, _ path: String, body: [String: Any]? = nil) async throws -> Data {
        var req = URLRequest(url: baseURL.appendingPathComponent("api/v1").appendingPathComponent(path))
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        req.setValue(language, forHTTPHeaderField: "Accept-Language")
        if let token { req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let body {
            req.setValue("application/json", forHTTPHeaderField: "Content-Type")
            req.httpBody = try JSONSerialization.data(withJSONObject: body)
        }
        let (data, response) = try await session.data(for: req)
        let status = (response as? HTTPURLResponse)?.statusCode ?? 0
        guard (200 ..< 300).contains(status) else {
            let obj = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any]
            throw APIError(status: status, code: obj?["code"] as? String ?? "http_\(status)", detail: Self.message(obj))
        }
        return data
    }

    /// Problem detail, or the first validation message (`{"errors": {"field": ["…"]}}`).
    static func message(_ obj: [String: Any]?) -> String? {
        if let errors = obj?["errors"] as? [String: Any], let first = errors.values.compactMap({ ($0 as? [String])?.first }).first { return first }
        return obj?["detail"] as? String ?? obj?["message"] as? String
    }

    // MARK: Trust & sign-in

    /// Raw trust document, handed to the engine as-is.
    public func trustJSON() async throws -> String {
        String(decoding: try await request("GET", "trust"), as: UTF8.self)
    }

    public struct DeviceCodeStart: Decodable, Sendable {
        public let deviceCode: String
        public let userCode: String
        public let verificationUrl: URL
        public let verificationUrlComplete: URL
        public let expiresIn: Int
        public let interval: Int
    }

    public func startDeviceCode(clientName: String) async throws -> DeviceCodeStart {
        try Self.decoder.decode(DeviceCodeStart.self, from: try await request("POST", "auth/device-code", body: ["client_name": clientName]))
    }

    public struct User: Decodable, Sendable { public let id: String; public let name: String; public let email: String }
    struct TokenResponse: Decodable { let token: String; let user: User }

    /// Polls until the user approves in the browser. Throws on denial/expiry.
    public func waitForApproval(_ start: DeviceCodeStart) async throws -> (token: String, user: User) {
        var interval = UInt64(max(start.interval, 1))
        let deadline = Date().addingTimeInterval(TimeInterval(start.expiresIn))
        while Date() < deadline {
            try await Task.sleep(nanoseconds: interval * 1_000_000_000)
            do {
                let r = try Self.decoder.decode(TokenResponse.self, from: try await request("POST", "auth/device-token", body: ["device_code": start.deviceCode]))
                return (r.token, r.user)
            } catch let e as APIError where e.code == "authorization_pending" {
                continue
            } catch let e as APIError where e.code == "slow_down" {
                interval += 5
            }
        }
        throw APIError(status: 400, code: "expired_token", detail: nil)
    }

    public func me() async throws -> User {
        struct R: Decodable { let user: User }
        return try Self.decoder.decode(R.self, from: try await request("GET", "me")).user
    }

    // MARK: Devices & library

    public struct Device: Decodable, Sendable { public let id: String; public let name: String; public let kind: String }

    public func registerDevice(name: String, publicKey: String, model: String, osVersion: String, appVersion: String) async throws -> Device {
        struct R: Decodable { let data: Device }
        let body: [String: Any] = ["kind": "macos", "name": name, "public_key": publicKey, "model": model, "os_version": osVersion, "app_version": appVersion]
        return try Self.decoder.decode(R.self, from: try await request("POST", "devices", body: body)).data
    }

    public struct LibraryItem: Decodable, Sendable, Identifiable, Equatable {
        public struct Game: Decodable, Sendable, Equatable { public let slug: String; public let title: String }
        public let package: String
        public let name: String
        public let game: Game
        public let latestVersion: String?
        public var id: String { package }
    }

    public func library() async throws -> [LibraryItem] {
        struct R: Decodable { let data: [LibraryItem] }
        return try Self.decoder.decode(R.self, from: try await request("GET", "library")).data
    }

    public struct DownloadGrant: Sendable {
        public let downloadId: String
        public let url: URL
        public let kind: String
        public let version: String
        public let sha256: String
        public let size: Int
        public let licenseJSON: String
    }

    public func authorizeDownload(package: String, device: String, fromVersion: String?) async throws -> DownloadGrant {
        var body: [String: Any] = ["package": package, "device": device]
        if let fromVersion { body["from_version"] = fromVersion }
        let data = try await request("POST", "downloads", body: body)
        guard let o = try JSONSerialization.jsonObject(with: data) as? [String: Any],
              let id = o["download_id"] as? String, let url = (o["url"] as? String).flatMap(URL.init(string:)),
              let license = o["license"], let licenseData = try? JSONSerialization.data(withJSONObject: license)
        else { throw APIError(status: 0, code: "bad_response", detail: nil) }
        return DownloadGrant(downloadId: id, url: url, kind: o["kind"] as? String ?? "full", version: o["version"] as? String ?? "",
                             sha256: o["sha256"] as? String ?? "", size: o["size"] as? Int ?? 0, licenseJSON: String(decoding: licenseData, as: UTF8.self))
    }

    /// Downloads to a temp file (signed URL, no bearer token) and returns its location.
    /// Package downloads: fail after 60 s without data (instead of hanging forever); a large package on a slow line may
    /// take long, so the whole download may run up to 3 h.
    ///
    /// A fresh ephemeral session per download: the host advertises HTTP/3 (alt-svc h3), and a session that has seen
    /// that switches to QUIC, which Hostinger delivers at ~150 KB/s against 10+ MB/s over HTTP/2. A new ephemeral
    /// session has no alt-svc memory, so its single request stays on HTTP/2.
    private static func downloadSession() -> URLSession {
        let c = URLSessionConfiguration.ephemeral
        c.timeoutIntervalForRequest = 60
        c.timeoutIntervalForResource = 3 * 60 * 60
        c.waitsForConnectivity = false
        return URLSession(configuration: c)
    }

    public func fetch(_ url: URL) async throws -> URL {
        let session = Self.downloadSession()
        defer { session.finishTasksAndInvalidate() }
        var request = URLRequest(url: url)
        request.assumesHTTP3Capable = false
        let (tmp, response) = try await session.download(for: request)
        guard (response as? HTTPURLResponse)?.statusCode == 200 else { throw APIError(status: (response as? HTTPURLResponse)?.statusCode ?? 0, code: "download_failed", detail: nil) }
        let dest = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString + ".lpk")
        try FileManager.default.moveItem(at: tmp, to: dest)
        return dest
    }

    public func completeDownload(_ id: String, sha256: String) async throws {
        _ = try await request("POST", "downloads/\(id)/complete", body: ["sha256": sha256])
    }

    public struct Heartbeat: Decodable, Sendable {
        public struct Update: Decodable, Sendable { public let package: String; public let from: String; public let to: String; public let kind: String }
        public let updates: [Update]
        public let revoked: [String]
    }

    public func heartbeat(device: String, appVersion: String, installed: [(package: String, version: String)]) async throws -> Heartbeat {
        let body: [String: Any] = ["app_version": appVersion, "installations": installed.map { ["package": $0.package, "version": $0.version] }]
        return try Self.decoder.decode(Heartbeat.self, from: try await request("POST", "devices/\(device)/heartbeat", body: body))
    }

    // MARK: Community

    public enum ReportReason: String, CaseIterable, Sendable {
        case wrongMeaning = "wrong_meaning", typo, awkward, wrongSpeaker = "wrong_speaker", offensive, other
    }

    /// Report a wrong line to the reviewers. `entryId` is the line id from the package (`Hit.entryId`).
    public func reportLine(package: String, version: String?, entryId: Int64, reason: ReportReason, note: String?) async throws {
        var body: [String: Any] = ["package": package, "entry_id": entryId, "reason": reason.rawValue, "source": "macos"]
        if let version { body["version"] = version }
        if let note = note?.trimmingCharacters(in: .whitespacesAndNewlines), !note.isEmpty { body["note"] = note }
        _ = try await request("POST", "translation-reports", body: body)
    }

    /// package slug → license JSON
    public func refreshLicenses(device: String) async throws -> [String: String] {
        let o = try JSONSerialization.jsonObject(with: try await request("POST", "devices/\(device)/licenses")) as? [String: Any]
        var out: [String: String] = [:]
        for (k, v) in (o?["licenses"] as? [String: Any]) ?? [:] {
            if let d = try? JSONSerialization.data(withJSONObject: v) { out[k] = String(decoding: d, as: UTF8.self) }
        }
        return out
    }
}
