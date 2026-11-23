import AppKit
import CoreGraphics
import Foundation

/// User preferences (UserDefaults). Secrets are in the Keychain, never here.
public final class AppSettings: ObservableObject, @unchecked Sendable {
    private let d: UserDefaults

    public init(defaults: UserDefaults = .standard) { d = defaults }

    /// Release builds talk to the live server; debug builds to a local backend (`php artisan serve --port=8040`).
    #if DEBUG
    public static let defaultServer = URL(string: "http://127.0.0.1:8040")!
    #else
    public static let defaultServer = URL(string: "https://lugha.iabdallah.com")!
    #endif

    @Published public var apiBaseURL: URL = AppSettings.defaultServer { didSet { d.set(apiBaseURL.absoluteString, forKey: "apiBaseURL") } }
    @Published public var fontSize: Double = 30 { didSet { d.set(fontSize, forKey: "fontSize") } }
    @Published public var backgroundOpacity: Double = 0.78 { didSet { d.set(backgroundOpacity, forKey: "backgroundOpacity") } }
    /// 0 = top of screen, 1 = bottom
    @Published public var verticalPosition: Double = 0.86 { didSet { d.set(verticalPosition, forKey: "verticalPosition") } }
    @Published public var captureRegion: CGRect? { didSet { d.set(captureRegion.map { NSStringFromRect($0) }, forKey: "captureRegion") } }
    @Published public var displayID: UInt32? { didSet { d.set(displayID, forKey: "displayID") } }
    @Published public var interfaceLanguage: String = "ar" { didSet { d.set(interfaceLanguage, forKey: "interfaceLanguage") } }

    /// Window mode: read subtitles from one app's window (e.g. PS Remote Play) instead of a screen region. Works while
    /// the window is covered by others, so the player can play on the TV. nil = screen-region mode.
    @Published public var captureAppBundleID: String? { didSet { d.set(captureAppBundleID, forKey: "captureAppBundleID") } }
    /// Subtitle area inside that window, as fractions of its size (origin top-left), so resizing the window keeps it.
    @Published public var windowArea: CGRect = AppSettings.defaultWindowArea { didSet { d.set(NSStringFromRect(windowArea), forKey: "windowArea") } }
    /// Also draw subtitles on this Mac (off is typical in window mode: they go to the TV).
    @Published public var showOnMac: Bool = true { didSet { d.set(showOnMac, forKey: "showOnMac") } }

    /// Where most games put dialogue: the lower band, centred.
    public static let defaultWindowArea = CGRect(x: 0.08, y: 0.70, width: 0.84, height: 0.26)
    /// Everything except the title bar: menus, HUD and subtitles.
    public static let wholeWindowArea = CGRect(x: 0, y: 0.04, width: 1, height: 0.96)

    public func load() {
        if let s = d.string(forKey: "apiBaseURL"), let u = URL(string: s) { apiBaseURL = u }
        if d.object(forKey: "fontSize") != nil { fontSize = d.double(forKey: "fontSize") }
        if d.object(forKey: "backgroundOpacity") != nil { backgroundOpacity = d.double(forKey: "backgroundOpacity") }
        if d.object(forKey: "verticalPosition") != nil { verticalPosition = d.double(forKey: "verticalPosition") }
        if let r = d.string(forKey: "captureRegion") { captureRegion = NSRectFromString(r) }
        if let id = d.object(forKey: "displayID") as? UInt32 { displayID = id }
        if let l = d.string(forKey: "interfaceLanguage") { interfaceLanguage = l }
        captureAppBundleID = d.string(forKey: "captureAppBundleID")
        if let a = d.string(forKey: "windowArea") { windowArea = NSRectFromString(a) }
        if d.object(forKey: "showOnMac") != nil { showOnMac = d.bool(forKey: "showOnMac") }
    }
}
