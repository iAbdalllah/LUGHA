import CLugha
import Foundation

public struct Hit: Codable, Equatable, Sendable {
    public let entryId: Int64
    public let packageId: String
    public let target: String
    public let speaker: String?
    public let category: String
    public let score: Double
    public let method: String
    public let providerId: String
    public let machineGenerated: Bool
}

public struct LoadedPackage: Codable, Equatable, Sendable {
    public let packageId: String
    public let version: String
    public let gameId: String
    public let entries: Int
}

public enum EngineError: Error, LocalizedError, Equatable {
    case invalidTrustOrKey
    case failed(String)

    public var errorDescription: String? {
        switch self {
        case .invalidTrustOrKey: return "Invalid trust document or device key."
        case let .failed(m): return m
        }
    }
}

/// Swift face of the Rust engine (lugha-ffi). Not thread-safe: use it from one serial queue (EngineActor).
public final class Engine: @unchecked Sendable {
    private let handle: OpaquePointer

    private static let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .convertFromSnakeCase
        return d
    }()

    public struct DeviceIdentity: Codable, Sendable {
        public let secretHex: String
        public let `public`: String
        public let fingerprint: String

        public init(secretHex: String, public: String, fingerprint: String) {
            self.secretHex = secretHex
            self.public = `public`
            self.fingerprint = fingerprint
        }
    }

    public static var version: String { String(cString: lugha_engine_version()) }

    public static func generateDevice() throws -> DeviceIdentity {
        try decode(call { lugha_device_generate() })
    }

    public init(trustJSON: String, deviceSecretHex: String) throws {
        guard let h = lugha_engine_new(trustJSON, deviceSecretHex) else { throw EngineError.invalidTrustOrKey }
        handle = h
    }

    deinit { lugha_engine_free(handle) }

    @discardableResult
    public func install(packageDir: URL, lpk: URL, licenseJSON: String) throws -> [String: Any] {
        try object(Self.call { lugha_install(handle, packageDir.path, lpk.path, licenseJSON) })
    }

    public func refreshLicense(packageDir: URL, licenseJSON: String) throws {
        _ = try object(Self.call { lugha_refresh_license(handle, packageDir.path, licenseJSON) })
    }

    public struct LoadResult: Codable, Sendable {
        public struct LoadError: Codable, Sendable { public let dir: String?; public let error: String }
        public let packages: [LoadedPackage]
        public let errors: [LoadError]
    }

    public func load(packagesRoot: URL) throws -> LoadResult {
        try Self.decode(Self.call { lugha_load(handle, packagesRoot.path) })
    }

    public func lookup(_ text: String) -> Hit? {
        struct R: Codable { let hit: Hit? }
        return (try? Self.decode(Self.call { lugha_lookup(handle, text) }) as R)?.hit
    }

    public func resetContext() { lugha_reset_context(handle) }

    // MARK: - JSON plumbing

    private static func call(_ f: () -> UnsafeMutablePointer<CChar>?) -> Data {
        guard let p = f() else { return Data(#"{"ok":false,"error":"engine returned nothing"}"#.utf8) }
        defer { lugha_string_free(p) }
        return Data(String(cString: p).utf8)
    }

    private static func check(_ data: Data) throws {
        struct Status: Codable { let ok: Bool; let error: String? }
        let s = try decoder.decode(Status.self, from: data)
        if !s.ok { throw EngineError.failed(s.error ?? "unknown engine error") }
    }

    private static func decode<T: Decodable>(_ data: Data) throws -> T {
        try check(data)
        return try decoder.decode(T.self, from: data)
    }

    private func object(_ data: Data) throws -> [String: Any] {
        try Self.check(data)
        return (try JSONSerialization.jsonObject(with: data) as? [String: Any]) ?? [:]
    }
}

/// Serializes all engine access.
public actor EngineActor {
    private var engine: Engine?

    public init() {}

    public func configure(trustJSON: String, deviceSecretHex: String) throws {
        engine = try Engine(trustJSON: trustJSON, deviceSecretHex: deviceSecretHex)
    }

    public var isReady: Bool { engine != nil }

    public func install(packageDir: URL, lpk: URL, licenseJSON: String) throws {
        guard let engine else { throw EngineError.failed("engine not configured") }
        try engine.install(packageDir: packageDir, lpk: lpk, licenseJSON: licenseJSON)
    }

    public func refreshLicense(packageDir: URL, licenseJSON: String) throws {
        guard let engine else { throw EngineError.failed("engine not configured") }
        try engine.refreshLicense(packageDir: packageDir, licenseJSON: licenseJSON)
    }

    public func load(packagesRoot: URL) throws -> Engine.LoadResult {
        guard let engine else { throw EngineError.failed("engine not configured") }
        return try engine.load(packagesRoot: packagesRoot)
    }

    public func lookup(_ text: String) -> Hit? { engine?.lookup(text) }

    public func resetContext() { engine?.resetContext() }
}
