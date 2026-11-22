import CryptoKit
import Foundation

/// Installed packages live in ~/Library/Application Support/LUGHA/packages/<package_id>/ (engine layout).
public struct PackageStore: Sendable {
    public let root: URL

    public init(root: URL? = nil) {
        self.root = root ?? FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("LUGHA/packages", isDirectory: true)
        try? FileManager.default.createDirectory(at: self.root, withIntermediateDirectories: true)
    }

    public func directory(for packageId: String) -> URL {
        root.appendingPathComponent(packageId, isDirectory: true)
    }

    /// package_id → installed version, read from each manifest.
    public func installed() -> [String: String] {
        guard let dirs = try? FileManager.default.contentsOfDirectory(at: root, includingPropertiesForKeys: nil) else { return [:] }
        var out: [String: String] = [:]
        for d in dirs where d.pathExtension.isEmpty {
            guard let data = try? Data(contentsOf: d.appendingPathComponent("manifest.json")),
                  let m = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let id = m["package_id"] as? String, let v = m["version"] as? String else { continue }
            out[id] = v
        }
        return out
    }

    public func remove(_ packageId: String) {
        try? FileManager.default.removeItem(at: directory(for: packageId))
    }

    public static func sha256(of file: URL) throws -> String {
        let h = SHA256.hash(data: try Data(contentsOf: file))
        return h.map { String(format: "%02x", $0) }.joined()
    }
}

/// Keeps installed packages in step with the account: install new purchases, apply updates (delta when
/// possible), refresh licenses, drop refunded packages, then reload the matcher.
public final class SyncService: @unchecked Sendable {
    public let api: APIClient
    public let engine: EngineActor
    public let store: PackageStore
    public let appVersion: String

    public init(api: APIClient, engine: EngineActor, store: PackageStore, appVersion: String) {
        self.api = api
        self.engine = engine
        self.store = store
        self.appVersion = appVersion
    }

    public struct Report: Sendable {
        public var installed: [String] = []
        public var updated: [String] = []
        public var removed: [String] = []
        public var errors: [String] = []
        public var loaded: [LoadedPackage] = []
    }

    public func sync(deviceId: String) async -> Report {
        var r = Report()
        do {
            let library = try await api.library()
            let current = store.installed()

            for item in library {
                guard let latest = item.latestVersion, current[item.package] != latest else { continue }
                do {
                    try await install(item.package, deviceId: deviceId, from: current[item.package])
                    if current[item.package] == nil { r.installed.append(item.package) } else { r.updated.append(item.package) }
                } catch {
                    r.errors.append("\(item.package): \(error.localizedDescription)")
                }
            }

            let beat = try await api.heartbeat(device: deviceId, appVersion: appVersion, installed: store.installed().map { ($0.key, $0.value) })
            for gone in beat.revoked {
                store.remove(gone)
                r.removed.append(gone)
            }
            for (package, license) in try await api.refreshLicenses(device: deviceId) {
                try? await engine.refreshLicense(packageDir: store.directory(for: package), licenseJSON: license)
            }
        } catch {
            r.errors.append(error.localizedDescription)
        }
        if let loaded = try? await engine.load(packagesRoot: store.root) {
            r.loaded = loaded.packages
            r.errors += loaded.errors.map { "\($0.dir ?? "?"): \($0.error)" }
        }
        return r
    }

    private func install(_ package: String, deviceId: String, from: String?) async throws {
        var grant = try await api.authorizeDownload(package: package, device: deviceId, fromVersion: from)
        var file = try await api.fetch(grant.url)
        defer { try? FileManager.default.removeItem(at: file) }
        guard try PackageStore.sha256(of: file) == grant.sha256 else { throw EngineError.failed("checksum mismatch") }
        do {
            try await engine.install(packageDir: store.directory(for: package), lpk: file, licenseJSON: grant.licenseJSON)
        } catch where grant.kind == "delta" {
            // A delta that doesn't apply (e.g. local files damaged): fall back to the full package.
            grant = try await api.authorizeDownload(package: package, device: deviceId, fromVersion: nil)
            try? FileManager.default.removeItem(at: file)
            file = try await api.fetch(grant.url)
            try await engine.install(packageDir: store.directory(for: package), lpk: file, licenseJSON: grant.licenseJSON)
        }
        try? await api.completeDownload(grant.downloadId, sha256: grant.sha256)
    }
}
