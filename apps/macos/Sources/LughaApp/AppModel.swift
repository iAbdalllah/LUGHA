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
    // Shown on the first frame a line matches (matching already rejects noise: short text needs an exact hit), gone
    // 0.4 s after its English leaves the screen.
    lazy var pipeline = SubtitlePipeline(stableFrames: 1, hideAfter: 0.6) { [engine] text in
        let t = Date()
        let hit = await engine.lookup(text)
        DiagnosticsLog.shared.write("lookup", ["q": text, "ms": Int(Date().timeIntervalSince(t) * 1000),
                                              "hit": hit.map { ["id": $0.entryId, "target": $0.target, "score": $0.score, "method": $0.method] } as Any])
        return hit
    }
    lazy var overlay = OverlayController(settings: settings)

    @Published var userName: String?
    @Published var running = false {
        didSet {
            // Translating is latency-critical while the game is in front: without this, App Nap throttles LUGHA as a
            // background app (timers, capture callbacks, the TV link).
            if running, liveActivity == nil {
                liveActivity = ProcessInfo.processInfo.beginActivity(options: [.userInitiated, .latencyCritical], reason: "Translating game text")
            } else if !running, let a = liveActivity {
                ProcessInfo.processInfo.endActivity(a)
                liveActivity = nil
            }
        }
    }
    private var liveActivity: NSObjectProtocol?
    @Published var subtitle: Subtitle?
    @Published var history: [Subtitle] = []
    @Published var library: [APIClient.LibraryItem] = []
    @Published var loaded: [LoadedPackage] = []
    @Published var status: String = ""
    @Published var deviceCode: APIClient.DeviceCodeStart?
    @Published var lastSync: Date?
    /// One sync at a time: each would download the same (possibly 100+ MB) package again.
    @Published var syncing = false
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
        ArabicFonts.registerBundled()
        L.language = settings.interfaceLanguage
        if let d = UserDefaults.standard.data(forKey: "pairedTVs"), let tvs = try? JSONDecoder().decode([PairedTV].self, from: d) { pairedTVs = tvs }
        browser.onChange = { [weak self] found in Task { @MainActor in self?.tvsFound = found } }
        browser.start()
        Task { await bootstrap() }
        Task { await reconnectTV() }
        startTVKeepAlive()
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
            await pushStyleToTV()
            startTVKeepAlive()
            if let sub = subtitle { try? await s.show(sub.text, speaker: sub.speaker) }
        } catch {
            tvStatus = error.localizedDescription
        }
    }

    /// LUGHA TV closes a link that is silent for 20 s, and subtitles can be minutes apart: ping every 8 s, and
    /// reconnect (re-sending the style and the current line) as soon as a ping goes unanswered.
    private var tvKeepAlive: Timer?
    private func startTVKeepAlive() {
        tvKeepAlive?.invalidate()
        tvKeepAlive = Timer.scheduledTimer(withTimeInterval: 8, repeats: true) { [weak self] _ in
            Task { @MainActor in
                guard let self else { return }
                // Not connected (TV off, restarting, or the first attempt failed): keep trying while one is paired.
                guard let tv = self.tv else { await self.reconnectTV(); return }
                if await tv.isAlive() { return }
                self.tv = nil
                self.tvConnected = nil
                await self.reconnectTV()
            }
        }
    }

    func reconnectTV() async {
        guard tv == nil, let last = pairedTVs.last, let id = try? TVSender.identity(keychain) else { return }
        // The TV may have a new address: prefer what Bonjour currently reports under the same name.
        var target = last
        // Right after launch Bonjour may not have answered yet; give it a moment (saved addresses can be stale).
        for _ in 0 ..< 10 where !tvsFound.contains(where: { $0.name == last.name }) {
            try? await Task.sleep(nanoseconds: 300_000_000)
        }
        if let seen = tvsFound.first(where: { $0.name == last.name }) { target.host = seen.host; target.port = seen.port }
        if let s = try? await TVSender.connect(target, senderName: senderName, secretHex: id.secretHex) {
            tv = s
            tvConnected = s.tv.name
            await pushStyleToTV()
            if let sub = subtitle { try? await s.show(sub.text, speaker: sub.speaker) }
            startTVKeepAlive()
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
            let t = Date()
            if let sub { try await tv.show(sub.text, speaker: sub.speaker, items: tvItems(sub)) } else { try await tv.clear() }
            DiagnosticsLog.shared.write("tv", ["ms": Int(Date().timeIntervalSince(t) * 1000), "items": sub?.items.count ?? 0])
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
        guard !syncing, let deviceId = keychain.get("device_id") else { return }
        syncing = true
        // A package download runs while the game is in front; App Nap would throttle it to a crawl (~80 KB/s).
        let activity = ProcessInfo.processInfo.beginActivity(options: [.userInitiated, .idleSystemSleepDisabled], reason: "Downloading packages")
        defer { syncing = false; ProcessInfo.processInfo.endActivity(activity) }
        status = L.t("Syncing… large packages can take a few minutes.")
        // Show the account's packages right away, not only after downloads finish.
        library = (try? await api.library()) ?? library
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
                // The whole window is captured so the game picture (inside the title bar and black bars) is found and
                // positions match the TV picture exactly; a smaller subtitle area chosen in the window only filters
                // which text is read (see `readFilter`).
                let w = try await capture.startWindow(bundleID: bundleID, area: AppSettings.wholeWindowArea, onFrame: handler)
                captureWindow = w
                let a = AppSettings.wholeWindowArea, f = w.frame
                // The Arabic sits exactly over the English: the subtitle area, in global screen points.
                overlay.anchor = CGRect(x: f.minX + a.minX * f.width, y: f.minY + a.minY * f.height, width: a.width * f.width, height: a.height * f.height)
                pictureArea = a
            } else {
                guard let region = settings.captureRegion else { status = L.t("Choose a region first"); return }
                try await capture.start(displayID: settings.displayID, region: region, excludingWindowIDs: overlay.windowIDs, onFrame: handler)
                let origin = CGDisplayBounds(settings.displayID ?? CGMainDisplayID())
                overlay.anchor = region.offsetBy(dx: origin.minX, dy: origin.minY)
                pictureArea = CGRect(x: region.minX / origin.width, y: region.minY / origin.height, width: region.width / origin.width, height: region.height / origin.height)
            }
            if settings.showOnMac { overlay.show() }
            pipeline.stableFor = settings.stableMs / 1000
            pipeline.removeAfter = settings.goneAfterMs / 1000
            running = true
            status = ""
            await pushStyleToTV()
            startHeartbeat()
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
        gameRect = nil
        heartbeat?.invalidate()
        heartbeat = nil
        await capture.stop()
        pipeline.clear()
        subtitle = nil
        overlay.update(nil)
        overlay.hide()
        running = false
    }

    /// What OCR read in the capture area on the last frame (shown in the panel so the player can see what LUGHA sees).
    @Published var lastRead = ""

    /// Window mode: bottom band (subtitles) or the whole window (menus too).
    func setWindowArea(_ area: CGRect) async {
        settings.windowArea = area
        if running { await stop(); await start() }
    }

    /// The subtitle area as fractions of the game picture — sent to the TV so it draws the Arabic in the same place.
    private var pictureArea: CGRect?
    private var heartbeat: Timer?

    /// Screen capture sends no frame while the picture is unchanged, so check a few times a second whether the
    /// English line is gone and the Arabic should go too.
    private func startHeartbeat() {
        heartbeat?.invalidate()
        heartbeat = Timer.scheduledTimer(withTimeInterval: 0.25, repeats: true) { [weak self] _ in
            Task { @MainActor in
                guard let self, self.running, self.pipeline.tick() else { return }
                // Only the pieces whose English went away are dropped; the rest stay where they are (keeping the
                // colours they were drawn with).
                let keep = self.pipeline.current.map { cur -> Subtitle in
                    var s = cur
                    let colors = Dictionary((self.subtitle?.items ?? []).map { ($0.text, $0) }, uniquingKeysWith: { a, _ in a })
                    s.items = cur.items.map { i in
                        var i = i
                        if let old = colors[i.text] { i.background = old.background; i.foreground = old.foreground; i.toneOver = old.toneOver; i.toneAbove = old.toneAbove }
                        return i
                    }
                    return s
                }
                DiagnosticsLog.shared.write("tick", ["left": keep?.items.count ?? 0])
                self.subtitle = keep
                self.overlay.update(keep)
                await self.mirrorToTV(keep)
            }
        }
    }

    /// Where the game picture is within the captured frame (whole-window mode), as fractions; nil = fills it.
    @Published private(set) var gameRect: CGRect?
    private var contentCheckedAt = Date.distantPast

    /// Each translated piece placed where its English is on the TV, as fractions of the full game picture.
    /// Whole-window mode maps through the detected picture rect (so black bars and the title bar don't shift it);
    /// other modes through the capture area's place in the picture.
    private func tvItems(_ sub: Subtitle) -> [TVSender.Item] {
        let boxes: [CGRect]
        if let g = gameRect, g.width > 0, g.height > 0 {
            boxes = sub.items.map { CGRect(x: ($0.box.minX - g.minX) / g.width, y: ($0.box.minY - g.minY) / g.height,
                                           width: $0.box.width / g.width, height: $0.box.height / g.height) }
        } else if let a = pictureArea {
            boxes = sub.items.map { CGRect(x: a.minX + $0.box.minX * a.width, y: a.minY + $0.box.minY * a.height,
                                           width: $0.box.width * a.width, height: $0.box.height * a.height) }
        } else { return [] }
        return zip(sub.items, boxes).filter { $0.0.box.width > 0 }.map { i, b in
            TVSender.Item(text: i.text, speaker: i.speaker, x: b.minX, y: b.minY, w: b.width, h: b.height, lines: i.lines,
                          background: i.background?.hex, foreground: i.foreground?.hex,
                          toneText: (settings.displayMode == "above" ? i.toneAbove : i.toneOver)?.text.hex,
                          toneShade: (settings.displayMode == "above" ? i.toneAbove : i.toneOver)?.shade)
        }
    }

    /// Re-send the look to the TV after a text option changes, and redraw the current line.
    func textOptionsChanged() async {
        pipeline.stableFor = settings.stableMs / 1000
        pipeline.removeAfter = settings.goneAfterMs / 1000
        await pushStyleToTV()
        if let s = subtitle { await mirrorToTV(s) }
    }

    func pushStyleToTV() async {
        guard let tv else { return }
        let a = pictureArea ?? CGRect(x: 0.08, y: 0.78, width: 0.84, height: 0.16)
        try? await tv.setStyle(fontScale: settings.fontSize / 30, positionY: Double(a.midY), bgOpacity: settings.backgroundOpacity,
                               maxWidth: Double(min(max(a.width, 0.5), 0.9)), mode: settings.textStyle, textColor: settings.textColor,
                               font: settings.fontName, display: settings.displayMode, history: settings.effectiveHistorySide, plateColor: settings.plateColor,
                               fadeMs: Int(settings.fadeMs), readHold: settings.readHold)
    }

    private func process(_ image: CGImage) async {
        guard !ocrBusy else { return } // drop frames while OCR is busy instead of queueing them
        ocrBusy = true
        defer { ocrBusy = false }
        let t0 = Date()
        let read = (try? await ocr.recognizeLines(image)) ?? []
        // Reading areas: only text inside the boxes the user drew (the PS5's own menus, signs elsewhere are skipped).
        let lines = ReadingArea.keep(read, in: readFilter)
        let ocrMs = Int(Date().timeIntervalSince(t0) * 1000)
        lastRead = lines.map(\.text).joined(separator: " · ")
        // Where the game picture is inside the window (black bars, title bar), re-checked every few seconds.
        if settings.captureAppBundleID != nil, Date().timeIntervalSince(contentCheckedAt) > 3 {
            contentCheckedAt = Date()
            gameRect = FrameSampler(image, maxWidth: 640)?.gameRect()
        }
        let t1 = Date()
        let changed = await pipeline.ingest(ocr: lines)
        DiagnosticsLog.shared.write("frame", [
            "ocr_ms": ocrMs, "match_ms": Int(Date().timeIntervalSince(t1) * 1000), "size": [image.width, image.height],
            "ocr": lines.map { ["text": $0.text, "box": DiagnosticsLog.box($0.box)] }, "skipped": read.count - lines.count,
            "shown": (pipeline.current?.items ?? []).map { ["text": $0.text, "box": DiagnosticsLog.box($0.box)] }, "changed": changed,
        ])
        if changed {
            // Native style: each piece in the game's own colours, read from this frame.
            subtitle = pipeline.current.map { s in FrameSampler(image).map { s.colored(by: $0) } ?? s }
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

    // MARK: Reading areas

    /// The boxes text is read from: the user's reading areas, or — window mode with a smaller subtitle area chosen in
    /// the window and no reading areas — that area.
    private var readFilter: [ReadingArea] {
        if settings.readingAreas.contains(where: \.enabled) { return settings.readingAreas }
        if settings.captureAppBundleID != nil, settings.windowArea != AppSettings.wholeWindowArea {
            return [ReadingArea(name: "", rect: settings.windowArea)]
        }
        return []
    }

    /// The captured picture in global screen points (what OCR box fractions refer to), running or not.
    func captureAreaGlobal() async -> CGRect? {
        if let bundleID = settings.captureAppBundleID {
            // Window mode captures the whole window (reading areas are fractions of it).
            return (try? await CaptureService.appWindows())?.first(where: { $0.bundleID == bundleID })?.frame
        }
        guard let region = settings.captureRegion else { return nil }
        let origin = CGDisplayBounds(settings.displayID ?? CGMainDisplayID())
        return region.offsetBy(dx: origin.minX, dy: origin.minY)
    }

    /// Draw a new reading area on the screen (inside the game picture).
    func addReadingArea() {
        RegionPicker.pick { [weak self] rect, displayID in
            guard let self, let rect else { return }
            Task { @MainActor in
                guard let cap = await self.captureAreaGlobal() else {
                    self.status = self.settings.captureAppBundleID == nil ? L.t("Choose a region first") : L.t("Open PS Remote Play and connect to your PS5 first.")
                    return
                }
                let origin = CGDisplayBounds(displayID ?? CGMainDisplayID()).origin
                let g = rect.offsetBy(dx: origin.x, dy: origin.y)
                let f = CGRect(x: (g.minX - cap.minX) / cap.width, y: (g.minY - cap.minY) / cap.height, width: g.width / cap.width, height: g.height / cap.height)
                    .intersection(CGRect(x: 0, y: 0, width: 1, height: 1))
                guard !f.isNull, f.width > 0.01, f.height > 0.01 else { self.status = L.t("Select the area inside the window."); return }
                self.settings.readingAreas.append(ReadingArea(name: L.t("Area %d", self.settings.readingAreas.count + 1), rect: f))
                await self.showReadingAreas()
            }
        }
    }

    /// Outlines the reading areas over the game for a few seconds.
    func showReadingAreas() async {
        guard let cap = await captureAreaGlobal() else { return }
        if !running { overlay.anchor = cap }
        overlay.show()
        overlay.flashAreas(settings.readingAreas.filter(\.enabled)) { [weak self] in
            guard let self, !self.running else { return }
            self.overlay.hide()
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
