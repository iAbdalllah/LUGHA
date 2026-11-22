import LughaKit
import SwiftUI

@main
struct LughaApp: App {
    @StateObject private var app = AppModel()

    var body: some Scene {
        MenuBarExtra {
            MenuPanelView(settings: app.settings).environmentObject(app)
        } label: {
            Image(systemName: app.running ? "captions.bubble.fill" : "captions.bubble")
        }
        .menuBarExtraStyle(.window)

        Window("LUGHA", id: "main") {
            MainWindowView(settings: app.settings).environmentObject(app)
        }
        .windowResizability(.contentSize)
    }
}
