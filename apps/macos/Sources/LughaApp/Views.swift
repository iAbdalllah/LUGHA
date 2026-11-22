import LughaKit
import SwiftUI

struct MenuPanelView: View {
    @EnvironmentObject var app: AppModel
    @ObservedObject var settings: AppSettings
    @Environment(\.openWindow) private var openWindow

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

            Button(L.t("Select capture region")) { app.pickRegion() }

            VStack(alignment: .leading, spacing: 6) {
                Text(L.t("Font size")).font(.caption).foregroundColor(.secondary)
                Slider(value: $settings.fontSize, in: 18 ... 48)
                Text(L.t("Background")).font(.caption).foregroundColor(.secondary)
                Slider(value: $settings.backgroundOpacity, in: 0 ... 1)
                Text(L.t("Position")).font(.caption).foregroundColor(.secondary)
                Slider(value: $settings.verticalPosition, in: 0.05 ... 0.95)
            }

            if !app.history.isEmpty {
                Divider()
                Text(L.t("Recent lines")).font(.caption).foregroundColor(.secondary)
                ForEach(Array(app.history.prefix(4).enumerated()), id: \.offset) { _, s in
                    Text(s.text).font(.callout).frame(maxWidth: .infinity, alignment: .trailing).environment(\.layoutDirection, .rightToLeft)
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
                    Button(L.t("Sync now")) { Task { await app.sync() } }
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
