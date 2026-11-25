import LughaKit
import SwiftUI

struct MenuPanelView: View {
    @EnvironmentObject var app: AppModel
    @ObservedObject var settings: AppSettings
    @Environment(\.openWindow) private var openWindow
    @State private var reporting: Int?

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 10) {
                Text("ل").font(.system(size: 16, weight: .bold)).frame(width: 30, height: 30)
                    .background(RoundedRectangle(cornerRadius: 8).fill(Color(red: 0.91, green: 0.71, blue: 0.30))).foregroundColor(.black)
                VStack(alignment: .leading, spacing: 1) {
                    Text(L.t("LUGHA")).font(.headline)
                    Text(!app.signedIn ? L.t("Not signed in") : app.running ? L.t("Running · offline") : L.t("Paused"))
                        .font(.caption).foregroundColor(app.running ? .green : .secondary)
                }
                Spacer()
                Button(app.running ? L.t("Pause") : L.t("Start")) { Task { await app.toggleRunning() } }
                    .disabled(app.loaded.isEmpty)
            }

            if !app.loaded.isEmpty {
                Text(app.loaded.map { "\($0.packageId) v\($0.version)" }.joined(separator: " · "))
                    .font(.caption).foregroundColor(.secondary).lineLimit(2)
            }
            if let ms = app.lastLatencyMs, app.running {
                Text("OCR + lookup: \(ms) ms").font(.caption2).foregroundColor(.secondary)
            }
            if app.running {
                // What LUGHA sees right now — the quickest way to tell a wrong capture area from a missing line.
                VStack(alignment: .leading, spacing: 2) {
                    Text(L.t("Reading:")).font(.caption2).foregroundColor(.secondary)
                    Text(app.lastRead.isEmpty ? L.t("(no text in the area)") : app.lastRead)
                        .font(.caption.monospaced()).lineLimit(3)
                        .environment(\.layoutDirection, .leftToRight)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    if let g = app.gameRect {
                        // Where LUGHA found the game picture in the window (TV positions are measured from it).
                        Text(String(format: "%@ x %.0f%%  y %.0f%%  w %.0f%%  h %.0f%%", L.t("Picture:"), g.minX * 100, g.minY * 100, g.width * 100, g.height * 100))
                            .font(.caption2.monospaced()).foregroundColor(.secondary)
                            .environment(\.layoutDirection, .leftToRight)
                    }
                }
                .padding(8).background(RoundedRectangle(cornerRadius: 8).fill(Color.primary.opacity(0.06)))
            }

            SourcePicker(settings: settings).environmentObject(app)
            Button(settings.captureAppBundleID == nil ? L.t("Select capture region") : L.t("Select subtitle area in the window")) { app.pickRegion() }

            ReadingAreasSection(settings: settings).environmentObject(app)

            TextOptions(settings: settings).environmentObject(app)

            VStack(alignment: .leading, spacing: 6) {
                Text(L.t("Font size")).font(.caption).foregroundColor(.secondary)
                Slider(value: $settings.fontSize, in: 18 ... 48)
                Text(L.t("Background")).font(.caption).foregroundColor(.secondary)
                Slider(value: $settings.backgroundOpacity, in: 0 ... 1)
                Text(L.t("Position")).font(.caption).foregroundColor(.secondary)
                Slider(value: $settings.verticalPosition, in: 0.05 ... 0.95)
            }

            DisplayOptions(settings: settings).environmentObject(app)

            TVSection().environmentObject(app)

            if !app.history.isEmpty {
                Divider()
                Text(L.t("Recent lines")).font(.caption).foregroundColor(.secondary)
                ForEach(Array(app.history.prefix(4).enumerated()), id: \.offset) { i, s in
                    HStack(alignment: .firstTextBaseline, spacing: 6) {
                        Button { reporting = reporting == i ? nil : i } label: { Image(systemName: reporting == i ? "flag.fill" : "flag") }
                            .buttonStyle(.borderless).foregroundColor(reporting == i ? .orange : .secondary).help(L.t("Report this line"))
                        Text(s.text).font(.callout).frame(maxWidth: .infinity, alignment: .trailing).environment(\.layoutDirection, .rightToLeft)
                    }
                    if reporting == i { ReportForm(line: s) { reporting = nil }.environmentObject(app) }
                }
            }
            if !app.status.isEmpty { Text(app.status).font(.caption).foregroundColor(.orange) }

            Divider()
            HStack {
                Button(L.t("Library & account…")) { openWindow(id: "main"); NSApp.activate(ignoringOtherApps: true) }
                Spacer()
                Button(L.t("Quit LUGHA")) { NSApp.terminate(nil) }
            }
        }
        .padding(16)
        .frame(width: 340)
    }
}

struct MainWindowView: View {
    @EnvironmentObject var app: AppModel
    @ObservedObject var settings: AppSettings

    var body: some View {
        VStack(alignment: .leading, spacing: 18) {
            if !app.signedIn {
                signIn
            } else {
                HStack {
                    Text(L.t("Signed in as %@", app.userName ?? "…")).font(.headline)
                    Spacer()
                    Button(L.t("Sync now")) { Task { await app.sync() } }.disabled(app.syncing)
                    Button(L.t("Sign out")) { app.signOut() }
                }
                if let d = app.lastSync { Text("\(L.t("Last sync")): \(d.formatted(date: .omitted, time: .shortened))").font(.caption).foregroundColor(.secondary) }
                Text(L.t("Your packages")).font(.title3.bold())
                if app.library.isEmpty {
                    Text(L.t("No packages yet. Buy one in the LUGHA store.")).foregroundColor(.secondary)
                }
                ForEach(app.library) { item in
                    HStack {
                        VStack(alignment: .leading) {
                            Text("\(item.game.title) — \(item.name)").font(.body.weight(.semibold))
                            if let v = app.loaded.first(where: { $0.packageId == item.package })?.version {
                                Text(L.t("Installed %@", v)).font(.caption).foregroundColor(.green)
                            }
                        }
                        Spacer()
                        Text(item.latestVersion.map { "v\($0)" } ?? "—").font(.caption.monospaced()).foregroundColor(.secondary)
                    }
                    .padding(10).background(RoundedRectangle(cornerRadius: 10).fill(Color.primary.opacity(0.05)))
                }
                if !CaptureService.hasPermission() {
                    HStack {
                        Text(L.t("Screen Recording permission is needed to read subtitles.")).font(.callout)
                        Button(L.t("Open System Settings")) {
                            NSWorkspace.shared.open(URL(string: "x-apple.systempreferences:com.apple.preference.security?Privacy_ScreenCapture")!)
                        }
                    }
                }
            }
            if !app.status.isEmpty { Text(app.status).font(.caption).foregroundColor(.orange) }
            Spacer()
        }
        .padding(24)
        .frame(minWidth: 460, minHeight: 380)
        .environment(\.layoutDirection, settings.interfaceLanguage == "ar" ? .rightToLeft : .leftToRight)
    }

    private var signIn: some View {
        VStack(alignment: .leading, spacing: 14) {
            Text(L.t("Sign in with a code")).font(.title2.bold())
            TextField(L.t("Server"), text: Binding(get: { settings.apiBaseURL.absoluteString },
                                                  set: { if let u = URL(string: $0) { settings.apiBaseURL = u } }))
                .textFieldStyle(.roundedBorder).environment(\.layoutDirection, .leftToRight)
            if let code = app.deviceCode {
                Text(L.t("Open lugha.app/activate in your browser and enter this code:"))
                Text(code.userCode).font(.system(size: 34, weight: .bold, design: .monospaced)).environment(\.layoutDirection, .leftToRight)
                HStack {
                    Button(L.t("Open activation page")) { NSWorkspace.shared.open(code.verificationUrlComplete) }
                    ProgressView().controlSize(.small)
                    Text(L.t("Waiting for approval…")).foregroundColor(.secondary)
                }
            } else {
                Button(L.t("Sign in")) { Task { await app.beginSignIn() } }.keyboardShortcut(.defaultAction)
            }
            Picker(L.t("Language"), selection: $settings.interfaceLanguage) {
                Text("العربية").tag("ar")
                Text("English").tag("en")
            }
            .onChange(of: settings.interfaceLanguage) { _, v in L.language = v }
            .frame(width: 220)
        }
    }
}


struct TVSection: View {
    @EnvironmentObject var app: AppModel
    @State private var code = ""
    @State private var target: TVBrowser.Found?
    @State private var manual = ""

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Divider()
            HStack {
                Text(L.t("Send to TV")).font(.caption).foregroundColor(.secondary)
                Spacer()
                if let name = app.tvConnected {
                    Text("● \(name)").font(.caption).foregroundColor(.green)
                    Button(L.t("Disconnect")) { Task { await app.disconnectTV() } }.controlSize(.small)
                } else if !app.pairedTVs.isEmpty {
                    Button(L.t("Reconnect")) { Task { await app.reconnectTV() } }.controlSize(.small)
                }
            }
            if app.tvConnected == nil {
                Picker(L.t("TV"), selection: $target) {
                    Text(L.t("Choose a TV…")).tag(TVBrowser.Found?.none)
                    ForEach(app.tvsFound) { Text($0.name).tag(Optional($0)) }
                }
                .labelsHidden()
                HStack {
                    TextField(L.t("or address (IP:port)"), text: $manual).textFieldStyle(.roundedBorder).environment(\.layoutDirection, .leftToRight)
                    TextField(L.t("Code"), text: $code).textFieldStyle(.roundedBorder).frame(width: 90).environment(\.layoutDirection, .leftToRight)
                    Button(L.t("Pair")) {
                        let (host, port, name) = resolveTarget()
                        Task { await app.pairTV(host: host, port: port, name: name, code: code) }
                    }
                    .disabled(code.filter(\.isNumber).count != 6 || (target == nil && manual.isEmpty))
                }
            }
            if !app.tvStatus.isEmpty { Text(app.tvStatus).font(.caption).foregroundColor(.orange) }
        }
    }

    private func resolveTarget() -> (String, UInt16, String) {
        if let t = target { return (t.host, t.port, t.name) }
        let parts = manual.split(separator: ":")
        return (String(parts.first ?? ""), parts.count > 1 ? UInt16(parts[1]) ?? LinkFrame.defaultPort : LinkFrame.defaultPort, String(parts.first ?? "TV"))
    }
}

/// Inline "report this line" form under a recent line. Reports go to the reviewers; accepted fixes ship in the next package update.
struct ReportForm: View {
    @EnvironmentObject var app: AppModel
    let line: Subtitle
    let done: () -> Void
    @State private var reason: APIClient.ReportReason = .wrongMeaning
    @State private var note = ""
    @State private var sending = false
    @State private var error: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(line.source).font(.caption).foregroundColor(.secondary).environment(\.layoutDirection, .leftToRight)
                .frame(maxWidth: .infinity, alignment: .leading).lineLimit(2)
            Picker(L.t("Reason"), selection: $reason) {
                ForEach(APIClient.ReportReason.allCases, id: \.self) { Text(L.t("reason." + $0.rawValue)).tag($0) }
            }
            TextField(L.t("Note (optional)"), text: $note, axis: .vertical).lineLimit(1 ... 3).textFieldStyle(.roundedBorder)
            if let error { Text(error).font(.caption).foregroundColor(.orange) }
            HStack {
                Spacer()
                Button(L.t("Cancel"), action: done).controlSize(.small)
                Button(L.t("Send report")) {
                    sending = true
                    Task {
                        error = await app.report(line, reason: reason, note: note)
                        sending = false
                        if error == nil { app.status = L.t("Thanks — your report was sent to the reviewers."); done() }
                    }
                }
                .controlSize(.small).keyboardShortcut(.defaultAction).disabled(sending || line.packageId.isEmpty)
            }
        }
        .padding(10)
        .background(RoundedRectangle(cornerRadius: 8).fill(Color.primary.opacity(0.06)))
    }
}

/// Where subtitles are read from: a screen area, or an app's window (PS Remote Play), which keeps working while the
/// window is covered — so the player can play on the TV.
struct SourcePicker: View {
    @EnvironmentObject var app: AppModel
    @ObservedObject var settings: AppSettings

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Picker(L.t("Read from"), selection: Binding(get: { settings.captureAppBundleID ?? "" },
                                                       set: { v in Task { await app.setSource(v.isEmpty ? nil : v) } })) {
                Text(L.t("An area of the screen")).tag("")
                ForEach(app.windows) { w in
                    Text(CaptureService.isRemotePlay(w) ? "\(w.appName) ★" : w.appName).tag(w.bundleID)
                }
                // Keep the saved choice visible while that app is closed.
                if let id = settings.captureAppBundleID, !app.windows.contains(where: { $0.bundleID == id }) {
                    Text(L.t("%@ (not open)", id)).tag(id)
                }
            }
            if settings.captureAppBundleID != nil {
                Picker(L.t("Area"), selection: Binding(
                    get: { settings.windowArea == AppSettings.wholeWindowArea ? 1 : settings.windowArea == AppSettings.defaultWindowArea ? 0 : 2 },
                    set: { v in Task { await app.setWindowArea(v == 1 ? AppSettings.wholeWindowArea : AppSettings.defaultWindowArea) } })) {
                    Text(L.t("Subtitles (bottom)")).tag(0)
                    Text(L.t("Whole window (menus)")).tag(1)
                    if settings.windowArea != AppSettings.wholeWindowArea && settings.windowArea != AppSettings.defaultWindowArea {
                        Text(L.t("Custom area")).tag(2)
                    }
                }
                .pickerStyle(.segmented)
                Text(L.t("Keeps reading while the window is behind others. Don't minimise it.")).font(.caption2).foregroundColor(.secondary)
                Toggle(L.t("Show subtitles on this Mac too"), isOn: Binding(get: { settings.showOnMac }, set: { app.setShowOnMac($0) }))
                    .font(.caption)
            }
        }
        .task { await app.refreshWindows() }
    }
}

/// Boxes to read from: only text inside them is translated (subtitles, a tutorial box…), so signs, the PS5's own
/// menus and the HUD elsewhere are skipped.
struct ReadingAreasSection: View {
    @EnvironmentObject var app: AppModel
    @ObservedObject var settings: AppSettings

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text(L.t("Reading areas")).font(.caption).foregroundColor(.secondary)
                Spacer()
                Menu(L.t("Presets")) {
                    Button(L.t("Subtitles only")) { applyPreset("subtitles") }
                    Button(L.t("Full screen")) { applyPreset("full") }
                    Button(L.t("Subtitles + instructions")) { applyPreset("game") }
                }
                .controlSize(.small).fixedSize()
                if !settings.readingAreas.isEmpty { Button(L.t("Show")) { Task { await app.showReadingAreas() } }.controlSize(.small) }
                Button(L.t("Add area")) { app.addReadingArea() }.controlSize(.small)
            }
            ForEach($settings.readingAreas) { $area in
                VStack(alignment: .leading, spacing: 4) {
                    HStack(spacing: 6) {
                        Toggle("", isOn: $area.enabled).labelsHidden().toggleStyle(.checkbox)
                        TextField("", text: $area.name).textFieldStyle(.roundedBorder).font(.caption)
                        Button(role: .destructive) { settings.readingAreas.removeAll { $0.id == area.id } } label: { Image(systemName: "trash") }
                            .buttonStyle(.borderless)
                    }
                    DisclosureGroup(L.t("Design")) { AreaDesign(area: $area, changed: changed) }.font(.caption)
                }
                .padding(6)
                .background(RoundedRectangle(cornerRadius: 8).fill(Color.secondary.opacity(0.08)))
            }
            Text(L.t("Only text inside these boxes is translated. With none, everything in the capture is read.")).font(.caption2).foregroundColor(.secondary)
        }
    }

    private func applyPreset(_ kind: String) {
        settings.readingAreas = ReadingArea.preset(kind).map { a in var a = a; a.name = L.t(a.name); return a }
        changed()
    }

    private func changed() { Task { await app.textOptionsChanged() } }
}

/// One area's design: where its Arabic goes, its look, and the size of its box.
struct AreaDesign: View {
    @Binding var area: ReadingArea
    let changed: () -> Void

    private func pct(_ label: String, _ v: Binding<CGFloat>) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            Text(label).font(.caption2).foregroundColor(.secondary)
            TextField("", value: Binding(get: { Double(v.wrappedValue * 100) }, set: { v.wrappedValue = CGFloat(min(max($0, 0), 100) / 100); changed() }),
                      format: .number.precision(.fractionLength(1)))
                .textFieldStyle(.roundedBorder).frame(width: 58)
        }
    }

    private func slider(_ label: String, _ v: Binding<Double>, _ range: ClosedRange<Double>) -> some View {
        HStack {
            Text(label).font(.caption2).foregroundColor(.secondary).frame(width: 90, alignment: .leading)
            Slider(value: Binding(get: { v.wrappedValue }, set: { v.wrappedValue = $0; changed() }), in: range)
            Text("\(Int(v.wrappedValue * 100))%").font(.caption2.monospaced()).frame(width: 40)
        }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(L.t("Reading area (% of the picture)")).font(.caption2).foregroundColor(.secondary)
            HStack(spacing: 6) {
                pct(L.t("Left"), $area.rect.origin.x); pct(L.t("Top"), $area.rect.origin.y)
                pct(L.t("Width"), $area.rect.size.width); pct(L.t("Height"), $area.rect.size.height)
            }
            .environment(\.layoutDirection, .leftToRight)
            Picker(L.t("Translation"), selection: Binding(get: { area.place }, set: { area.place = $0; changed() })) {
                Text(L.t("Automatic")).tag("auto")
                Text(L.t("Over the English")).tag("over")
                Text(L.t("Below the English box")).tag("below")
                Text(L.t("Above the English box")).tag("above")
                Text(L.t("Custom place")).tag("custom")
            }
            if area.place == "custom" {
                Text(L.t("Translation area (% of the picture)")).font(.caption2).foregroundColor(.secondary)
                HStack(spacing: 6) {
                    pct(L.t("Left"), $area.translation.origin.x); pct(L.t("Top"), $area.translation.origin.y)
                    pct(L.t("Width"), $area.translation.size.width); pct(L.t("Height"), $area.translation.size.height)
                }
                .environment(\.layoutDirection, .leftToRight)
                Picker(L.t("Text in the area"), selection: Binding(get: { area.valign }, set: { area.valign = $0; changed() })) {
                    Text(L.t("Top")).tag("top"); Text(L.t("Middle")).tag("center"); Text(L.t("Bottom")).tag("bottom")
                }
            }
            Picker(L.t("Look"), selection: Binding(get: { area.look }, set: { area.look = $0; changed() })) {
                Text(L.t("Automatic")).tag("auto")
                Text(L.t("Text on the picture")).tag("subtitle")
                Text(L.t("Box")).tag("box")
                Text(L.t("Game-style card")).tag("card")
            }
            Picker(L.t("Alignment"), selection: Binding(get: { area.align }, set: { area.align = $0; changed() })) {
                Text(L.t("Right")).tag("right"); Text(L.t("Centre")).tag("center"); Text(L.t("Left")).tag("left")
            }
            .pickerStyle(.segmented)
            slider(L.t("Text size"), $area.font, 0.5...2)
            slider(L.t("Box width"), $area.boxW, 0.3...2)
            slider(L.t("Box height"), $area.boxH, 0...2.5)
            HStack(spacing: 6) {
                Text(L.t("Accent edge")).font(.caption2).foregroundColor(.secondary)
                ForEach([nil, "#E9B44C", "#E85FA0", "#4FC3F7", "#7BD88F"], id: \.self) { (hex: String?) in
                    let c = hex.flatMap { RGB(hex: $0) }
                    Circle().fill(c.map { Color(.sRGB, red: $0.r, green: $0.g, blue: $0.b) } ?? Color.clear).frame(width: 16, height: 16)
                        .overlay(Circle().stroke(area.accent == hex ? Color.accentColor : Color.secondary.opacity(0.5), lineWidth: area.accent == hex ? 2.5 : 1))
                        .overlay(hex == nil ? Image(systemName: "nosign").font(.system(size: 9)).foregroundColor(.secondary) : nil)
                        .onTapGesture { area.accent = hex; changed() }
                }
            }
        }
        .padding(.top, 4)
    }
}

/// Where the Arabic goes, the history panel and timing.
struct DisplayOptions: View {
    @EnvironmentObject var app: AppModel
    @ObservedObject var settings: AppSettings

    private func bind<T>(_ k: ReferenceWritableKeyPath<AppSettings, T>) -> Binding<T> {
        Binding(get: { settings[keyPath: k] }, set: { settings[keyPath: k] = $0; Task { await app.textOptionsChanged() } })
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(L.t("Display")).font(.caption).foregroundColor(.secondary)
            Picker("", selection: bind(\.displayMode)) {
                Text(L.t("Over the English")).tag("over")
                Text(L.t("Above the English")).tag("above")
                Text(L.t("History only")).tag("history")
            }
            .pickerStyle(.segmented).labelsHidden()
            Text(settings.displayMode == "over" ? L.t("The Arabic hides the English.")
                 : settings.displayMode == "above" ? L.t("The Arabic sits just above the English, which stays visible.")
                 : L.t("Nothing over the game; the lines appear only in the history panel."))
                .font(.caption2).foregroundColor(.secondary)

            HStack(spacing: 6) {
                Text(L.t("Box colour")).font(.caption).foregroundColor(.secondary)
                Spacer()
                // Presets, then any colour.
                ForEach(["#000000", "#1B2A4A", "#3A1F4D", "#1F3D2B", "#5A1E1E", "#FFFFFF"], id: \.self) { hex in
                    let c = RGB(hex: hex)!
                    Circle().fill(Color(.sRGB, red: c.r, green: c.g, blue: c.b)).frame(width: 18, height: 18)
                        .overlay(Circle().stroke(settings.plateColor == hex ? Color.accentColor : Color.secondary.opacity(0.5), lineWidth: settings.plateColor == hex ? 2.5 : 1))
                        .onTapGesture { settings.plateColor = hex; Task { await app.textOptionsChanged() } }
                }
                ColorPicker("", selection: Binding(
                    get: { SubtitlePlate.plate(settings) },
                    set: { c in
                        guard let rgb = NSColor(c).usingColorSpace(.sRGB) else { return }
                        settings.plateColor = String(format: "#%02X%02X%02X", Int(rgb.redComponent * 255), Int(rgb.greenComponent * 255), Int(rgb.blueComponent * 255))
                        Task { await app.textOptionsChanged() }
                    }), supportsOpacity: false).labelsHidden()
            }

            Text(L.t("History panel")).font(.caption).foregroundColor(.secondary)
            Picker("", selection: bind(\.historySide)) {
                Text(L.t("Off")).tag("off")
                Text(L.t("Left")).tag("left")
                Text(L.t("Right")).tag("right")
            }
            .pickerStyle(.segmented).labelsHidden()
            Text(L.t("Recent dialogue at the side of the game; it dims after a few seconds.")).font(.caption2).foregroundColor(.secondary)
            Toggle(L.t("Keep a line until it can be read"), isOn: bind(\.readHold)).font(.caption)

            DisclosureGroup(L.t("Timing")) {
                VStack(alignment: .leading, spacing: 4) {
                    slider(L.t("Wait for stable text (ms)"), \.stableMs, 0 ... 500, L.t("Avoids half-drawn lines; adds that much delay."))
                    slider(L.t("English gone after (ms)"), \.goneAfterMs, 100 ... 1500, L.t("Ignores short OCR blinks; the Arabic leaves this long after the English."))
                    slider(L.t("Fade in (ms)"), \.fadeMs, 0 ... 500, nil)
                }
            }
            .font(.caption)
        }
    }

    private func slider(_ title: String, _ k: ReferenceWritableKeyPath<AppSettings, Double>, _ range: ClosedRange<Double>, _ note: String?) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            HStack {
                Text(title)
                Spacer()
                Text("\(Int(settings[keyPath: k]))").monospacedDigit().foregroundColor(.secondary)
            }
            Slider(value: bind(k), in: range, step: 10)
            if let note { Text(note).font(.caption2).foregroundColor(.secondary) }
        }
    }
}

/// How the Arabic looks: style, text colour and font.
struct TextOptions: View {
    @EnvironmentObject var app: AppModel
    @ObservedObject var settings: AppSettings

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(L.t("Text style")).font(.caption).foregroundColor(.secondary)
            Picker("", selection: Binding(get: { settings.textStyle }, set: { settings.textStyle = $0; Task { await app.textOptionsChanged() } })) {
                Text(L.t("Native")).tag("native")
                Text(L.t("Box")).tag("box")
                Text(L.t("Outline")).tag("outline")
            }
            .pickerStyle(.segmented).labelsHidden()
            Text(settings.textStyle == "native" ? L.t("In the game's own colours, as if the game drew it.") : " ")
                .font(.caption2).foregroundColor(.secondary)
            HStack {
                if settings.textStyle != "native" {
                    Picker(L.t("Colour"), selection: Binding(get: { settings.textColor }, set: { settings.textColor = $0; Task { await app.textOptionsChanged() } })) {
                        Text(L.t("White")).tag("white")
                        Text(L.t("Yellow")).tag("yellow")
                    }
                    .frame(maxWidth: 150)
                }
                Picker(L.t("Font"), selection: Binding(get: { settings.fontName }, set: { settings.fontName = $0; Task { await app.textOptionsChanged() } })) {
                    ForEach(ArabicFonts.all, id: \.id) { f in
                        Text(f.label).font(f.family.map { .custom($0, size: 13) } ?? .system(size: 13)).tag(f.id)
                    }
                }
            }
            .font(.caption)
        }
    }
}
