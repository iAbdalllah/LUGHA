import AppKit
import Combine
import LughaKit
import SwiftUI

/// Wires the services together: account + device, sync, capture → OCR → engine → overlay.
@MainActor
final class AppModel: ObservableObject {
    static let appVersion = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "0.1.0"

    let settings = AppSettings()
    let keychain = Keychain()
    let engine = EngineActor()
    let store = PackageStore()
    let capture = CaptureService()
    let ocr = OCRService()
    lazy var api = APIClient(baseURL: settings.apiBaseURL, token: keychain.get("api_token"), language: settings.interfaceLanguage)
    lazy var pipeline = SubtitlePipeline { [engine] text in await engine.lookup(text) }
    lazy var overlay = OverlayController(settings: settings)

    @Published var userName: String?
    @Published var running = false
    @Published var subtitle: Subtitle?
    @Published var history: [Subtitle] = []
    @Published var library: [APIClient.LibraryItem] = []
    @Published var loaded: [LoadedPackage] = []
    @Published var status: String = ""
    @Published var deviceCode: APIClient.DeviceCodeStart?
    @Published var lastSync: Date?
    @Published var lastLatencyMs: Int?

    private var ocrBusy = false

    init() {
        settings.load()
        L.language = settings.interfaceLanguage
        Task { await bootstrap() }
    }

    var signedIn: Bool { api.token != nil }

    func bootstrap() async {
        guard signedIn else { return }
        do {
            userName = try await api.me().name
            try await configureEngine()
            await sync()
        } catch let e as APIError where e.status == 401 {
            signOut() // device removed from the web, or token revoked
        } catch {
            status = error.localizedDescription
            // Offline: still try to open what is installed (licenses work offline until they expire).
            try? await configureEngineOffline()
        }
    }

    // MARK: Account & device

    func beginSignIn() async {
        do {
            api.baseURL = settings.apiBaseURL
            let start = try await api.startDeviceCode(clientName: "LUGHA for Mac — \(Host.current().localizedName ?? "Mac")")
            deviceCode = start
            NSWorkspace.shared.open(start.verificationUrlComplete)
            let (token, user) = try await api.waitForApproval(start)
            try keychain.set(token, for: "api_token")
            api.token = token
            userName = user.name
            deviceCode = nil
            try await configureEngine()
            await sync()
        } catch {
            deviceCode = nil
            status = error.localizedDescription
        }
    }

    func signOut() {
        keychain.delete("api_token")
        keychain.delete("device_id")
        api.token = nil
        userName = nil
        library = []
    }

    private func deviceIdentity() throws -> Engine.DeviceIdentity {
        if let secret = keychain.get("device_secret"), let pub = keychain.get("device_public"), let fp = keychain.get("device_fp") {
            return .init(secretHex: secret, public: pub, fingerprint: fp)
        }
        let d = try Engine.generateDevice()
        try keychain.set(d.secretHex, for: "device_secret")
        try keychain.set(d.public, for: "device_public")
        try keychain.set(d.fingerprint, for: "device_fp")
        return d
    }

    private func configureEngine() async throws {
        let identity = try deviceIdentity()
        let trust = try await api.trustJSON()
        try keychain.set(trust, for: "trust")
        try await engine.configure(trustJSON: trust, deviceSecretHex: identity.secretHex)
        if keychain.get("device_id") == nil {
            let device = try await api.registerDevice(name: Host.current().localizedName ?? "Mac", publicKey: identity.public,
                                                      model: Self.model(), osVersion: ProcessInfo.processInfo.operatingSystemVersionString, appVersion: Self.appVersion)
            try keychain.set(device.id, for: "device_id")
        }
    }

    private func configureEngineOffline() async throws {
        guard let trust = keychain.get("trust"), let secret = keychain.get("device_secret") else { return }
        try await engine.configure(trustJSON: trust, deviceSecretHex: secret)
        loaded = (try? await engine.load(packagesRoot: store.root))?.packages ?? []
    }

    func sync() async {
        guard let deviceId = keychain.get("device_id") else { return }
        status = "…"
        let sync = SyncService(api: api, engine: engine, store: store, appVersion: Self.appVersion)
        let report = await sync.sync(deviceId: deviceId)
        library = (try? await api.library()) ?? library
        loaded = report.loaded
        lastSync = Date()
        status = report.errors.first ?? ""
    }

    private static func model() -> String {
        var size = 0
        sysctlbyname("hw.model", nil, &size, nil, 0)
        var buf = [CChar](repeating: 0, count: size)
        sysctlbyname("hw.model", &buf, &size, nil, 0)
        return String(cString: buf)
    }

    // MARK: Capture → OCR → engine → overlay

    func toggleRunning() async {
        running ? await stop() : await start()
    }

    func start() async {
        guard let region = settings.captureRegion else { status = L.t("Choose a region first"); return }
        guard CaptureService.hasPermission() || CaptureService.requestPermission() else {
            status = L.t("Screen Recording permission is needed to read subtitles.")
            return
        }
        overlay.show()
        do {
            try await capture.start(displayID: settings.displayID, region: region, excludingWindowIDs: overlay.windowIDs) { [weak self] image in
                Task { @MainActor in await self?.process(image) }
            }
            running = true
            status = ""
        } catch {
            status = error.localizedDescription
        }
    }

    func stop() async {
        await capture.stop()
        pipeline.clear()
        subtitle = nil
        overlay.update(nil)
        overlay.hide()
        running = false
    }

    private func process(_ image: CGImage) async {
        guard !ocrBusy else { return } // drop frames while OCR is busy instead of queueing them
        ocrBusy = true
        defer { ocrBusy = false }
        let t0 = Date()
        let lines = (try? await ocr.recognize(image)) ?? []
        if await pipeline.ingest(lines: lines) {
            subtitle = pipeline.current
            history = pipeline.history
            overlay.update(subtitle)
        }
        lastLatencyMs = Int(Date().timeIntervalSince(t0) * 1000)
    }

    func pickRegion() {
        RegionPicker.pick { [weak self] rect, displayID in
            guard let self, let rect else { return }
            self.settings.captureRegion = rect
            self.settings.displayID = displayID
            if self.running { Task { await self.stop(); await self.start() } }
        }
    }
}
