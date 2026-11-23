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

    // Send to TV (LUGHA Link)
    @Published var tvsFound: [TVBrowser.Found] = []
    @Published var pairedTVs: [PairedTV] = []
    @Published var tvConnected: String?
    @Published var tvStatus = ""
    private var tv: TVSender?
    private let browser = TVBrowser()

    private var ocrBusy = false

    init() {
        settings.load()
        L.language = settings.interfaceLanguage
        if let d = UserDefaults.standard.data(forKey: "pairedTVs"), let tvs = try? JSONDecoder().decode([PairedTV].self, from: d) { pairedTVs = tvs }
        browser.onChange = { [weak self] found in Task { @MainActor in self?.tvsFound = found } }
        browser.start()
        Task { await bootstrap() }
        Task { await reconnectTV() }
    }

    // MARK: Send to TV

    private var senderName: String { Host.current().localizedName ?? "Mac" }

    func pairTV(host: String, port: UInt16, name: String, code: String) async {
        tvStatus = "…"
        do {
            let id = try TVSender.identity(keychain)
            let s = try await TVSender.pair(host: host, port: port, code: code.filter(\.isNumber), name: name, senderName: senderName, secretHex: id.secretHex)
            pairedTVs = pairedTVs.filter { $0.publicKey != s.tv.publicKey } + [s.tv]
            UserDefaults.standard.set(try? JSONEncoder().encode(pairedTVs), forKey: "pairedTVs")
            tv = s
            tvConnected = s.tv.name
            tvStatus = ""
            if let sub = subtitle { try? await s.show(sub.text, speaker: sub.speaker) }
        } catch {
            tvStatus = error.localizedDescription
        }
    }

    func reconnectTV() async {
        guard tv == nil, let last = pairedTVs.last, let id = try? TVSender.identity(keychain) else { return }
        // The TV may have a new address: prefer what Bonjour currently reports under the same name.
        var target = last
        if let seen = tvsFound.first(where: { $0.name == last.name }) { target.host = seen.host; target.port = seen.port }
        if let s = try? await TVSender.connect(target, senderName: senderName, secretHex: id.secretHex) {
            tv = s
            tvConnected = s.tv.name
        }
    }

    func disconnectTV() async {
        await tv?.close()
        tv = nil
        tvConnected = nil
    }

    func forgetTV(_ t: PairedTV) async {
        if tv?.tv.publicKey == t.publicKey { await disconnectTV() }
        pairedTVs.removeAll { $0.publicKey == t.publicKey }
        UserDefaults.standard.set(try? JSONEncoder().encode(pairedTVs), forKey: "pairedTVs")
    }

    private func mirrorToTV(_ sub: Subtitle?) async {
        guard let tv else { return }
        do {
            if let sub { try await tv.show(sub.text, speaker: sub.speaker) } else { try await tv.clear() }
        } catch {
            self.tv = nil
            tvConnected = nil
            await reconnectTV() // TV restarted or Wi-Fi blipped
        }
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
        guard CaptureService.hasPermission() || CaptureService.requestPermission() else {
            status = L.t("Screen Recording permission is needed to read subtitles.")
            return
        }
        let handler: CaptureService.FrameHandler = { [weak self] image in Task { @MainActor in await self?.process(image) } }
        do {
            if let bundleID = settings.captureAppBundleID {
                // Window mode: the game can be played on the TV while its Remote Play window sits behind other windows.
                let w = try await capture.startWindow(bundleID: bundleID, area: settings.windowArea, onFrame: handler)
                captureWindow = w
            } else {
                guard let region = settings.captureRegion else { status = L.t("Choose a region first"); return }
                try await capture.start(displayID: settings.displayID, region: region, excludingWindowIDs: overlay.windowIDs, onFrame: handler)
            }
            if settings.showOnMac { overlay.show() }
            running = true
            status = ""
        } catch let e as NSError where e.localizedDescription == "window_not_found" {
            status = L.t("Open PS Remote Play and connect to your PS5 first.")
        } catch {
            status = error.localizedDescription
        }
    }

    // MARK: Window mode (PS Remote Play and the like)

    @Published var windows: [CaptureService.AppWindow] = []
    @Published var captureWindow: CaptureService.AppWindow?

    /// One entry per app, Remote Play first.
    func refreshWindows() async {
        let all = (try? await CaptureService.appWindows()) ?? []
        var seen = Set<String>()
        windows = all.filter { seen.insert($0.bundleID).inserted }
            .sorted { (CaptureService.isRemotePlay($0) ? 0 : 1, $0.appName) < (CaptureService.isRemotePlay($1) ? 0 : 1, $1.appName) }
    }

    func setSource(_ bundleID: String?) async {
        settings.captureAppBundleID = bundleID
        if bundleID != nil, settings.windowArea == .zero { settings.windowArea = AppSettings.defaultWindowArea }
        if running { await stop(); await start() }
    }

    func setShowOnMac(_ on: Bool) {
        settings.showOnMac = on
        guard running else { return }
        if on { overlay.show(); overlay.update(subtitle) } else { overlay.update(nil); overlay.hide() }
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
            if settings.showOnMac { overlay.update(subtitle) }
            await mirrorToTV(subtitle)
        }
        lastLatencyMs = Int(Date().timeIntervalSince(t0) * 1000)
    }

    /// Returns nil on success, or the server's message.
    func report(_ line: Subtitle, reason: APIClient.ReportReason, note: String) async -> String? {
        let version = loaded.first(where: { $0.packageId == line.packageId })?.version
        do {
            try await api.reportLine(package: line.packageId, version: version, entryId: line.entryId, reason: reason, note: note)
            return nil
        } catch {
            return error.localizedDescription
        }
    }

    func pickRegion() {
        RegionPicker.pick { [weak self] rect, displayID in
            guard let self, let rect else { return }
            if let bundleID = self.settings.captureAppBundleID {
                // Window mode: store the area as fractions of the window so it survives moving and resizing it.
                Task { @MainActor in
                    guard let w = (try? await CaptureService.appWindows())?.first(where: { $0.bundleID == bundleID }) else {
                        self.status = L.t("Open PS Remote Play and connect to your PS5 first.")
                        return
                    }
                    let origin = CGDisplayBounds(displayID ?? CGMainDisplayID()).origin
                    let global = rect.offsetBy(dx: origin.x, dy: origin.y)
                    let f = w.frame
                    let a = CGRect(x: (global.minX - f.minX) / f.width, y: (global.minY - f.minY) / f.height,
                                   width: global.width / f.width, height: global.height / f.height)
                        .intersection(CGRect(x: 0, y: 0, width: 1, height: 1))
                    guard !a.isNull, a.width > 0.02, a.height > 0.02 else { self.status = L.t("Select the area inside the window."); return }
                    self.settings.windowArea = a
                    if self.running { await self.stop(); await self.start() }
                }
                return
            }
            self.settings.captureRegion = rect
            self.settings.displayID = displayID
            if self.running { Task { await self.stop(); await self.start() } }
        }
    }
}
