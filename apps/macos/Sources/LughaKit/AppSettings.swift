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

    /// How the Arabic looks: "native" (the game's own colours, as if the game drew it), "box" (dark plate),
    /// "outline" (no plate, outlined text like film subtitles).
    @Published public var textStyle: String = "native" { didSet { d.set(textStyle, forKey: "textStyle") } }
    /// Text colour for the Box and Outline styles: "white" or "yellow".
    @Published public var textColor: String = "white" { didSet { d.set(textColor, forKey: "textColor") } }
    /// Arabic font id shared with the TV: "", naskh, kufi, cairo, tajawal, amiri ("" = system).
    @Published public var fontName: String = "" { didSet { d.set(fontName, forKey: "fontName") } }

    /// "over": the Arabic hides the English on a plate; "above": just above the English, which stays; "history":
    /// nothing over the game — only the history panel (right unless left is chosen).
    @Published public var displayMode: String = "over" { didSet { d.set(displayMode, forKey: "displayMode") } }
    /// Colour of the text boxes and the history panel, #RRGGBB (its opacity is the Background slider).
    @Published public var plateColor: String = "#000000" { didSet { d.set(plateColor, forKey: "plateColor") } }
    /// The history panel's side, taking history-only display into account.
    public var effectiveHistorySide: String { displayMode == "history" && historySide == "off" ? "right" : historySide }
    /// Side panel with the recent dialogue: "off", "left" or "right".
    @Published public var historySide: String = "off" { didSet { d.set(historySide, forKey: "historySide") } }
    /// Fade-in of a new line, ms (0 = at once).
    @Published public var fadeMs: Double = 150 { didSet { d.set(fadeMs, forKey: "fadeMs") } }
    /// Keep a replaced dialogue line up (dimmed, above the new one) until it could be read.
    @Published public var readHold: Bool = true { didSet { d.set(readHold, forKey: "readHold") } }
    /// Show text only once it has been read this long, ms (avoids half-drawn lines; adds that much delay).
    @Published public var stableMs: Double = 0 { didSet { d.set(stableMs, forKey: "stableMs") } }
    /// Treat the English as gone after it is missing this long, ms (ignores OCR missing a frame).
    @Published public var goneAfterMs: Double = 400 { didSet { d.set(goneAfterMs, forKey: "goneAfterMs") } }

    /// Boxes to read text from (empty = everything in the capture). Only text inside an enabled box is translated.
    @Published public var readingAreas: [ReadingArea] = [] { didSet { d.set(try? JSONEncoder().encode(readingAreas), forKey: "readingAreas") } }

    /// Where most games put dialogue: the lower band, centred.
    public static let defaultWindowArea = CGRect(x: 0.08, y: 0.70, width: 0.84, height: 0.26)
    /// The whole window: menus, HUD and subtitles. The title bar and black bars are trimmed by finding the 16:9 game
    /// picture inside the frame (FrameSampler.gameRect), so positions line up with the full TV picture.
    public static let wholeWindowArea = CGRect(x: 0, y: 0, width: 1, height: 1)
    private static let oldWholeWindowArea = CGRect(x: 0, y: 0.04, width: 1, height: 0.96)

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
        if windowArea == Self.oldWholeWindowArea { windowArea = Self.wholeWindowArea }
        if d.object(forKey: "showOnMac") != nil { showOnMac = d.bool(forKey: "showOnMac") }
        if let s = d.string(forKey: "textStyle") { textStyle = s }
        if let s = d.string(forKey: "textColor") { textColor = s }
        if let data = d.data(forKey: "readingAreas"), let a = try? JSONDecoder().decode([ReadingArea].self, from: data) { readingAreas = a }
        if let s = d.string(forKey: "displayMode"), ["over", "above", "history"].contains(s) { displayMode = s }
        if let s = d.string(forKey: "plateColor"), RGB(hex: s) != nil { plateColor = s }
        if let s = d.string(forKey: "historySide"), ["off", "left", "right"].contains(s) { historySide = s }
        if d.object(forKey: "fadeMs") != nil { fadeMs = d.double(forKey: "fadeMs") }
        if d.object(forKey: "readHold") != nil { readHold = d.bool(forKey: "readHold") }
        if d.object(forKey: "stableMs") != nil { stableMs = d.double(forKey: "stableMs") }
        if d.object(forKey: "goneAfterMs") != nil { goneAfterMs = d.double(forKey: "goneAfterMs") }
        if let s = d.string(forKey: "fontName"), ["", "naskh", "kufi", "cairo", "tajawal", "amiri"].contains(s) { fontName = s }
    }
}

/// A box the user drew: text is read only inside the enabled boxes. `rect` is a fraction of the captured picture (the
/// Remote Play window's subtitle area or the screen region), origin top-left, so it survives moving the window.
public struct ReadingArea: Codable, Equatable, Identifiable, Sendable {
    public var id = UUID()
    public var name: String
    public var rect: CGRect
    public var enabled = true

    public init(name: String, rect: CGRect, enabled: Bool = true) { self.name = name; self.rect = rect; self.enabled = enabled }

    /// The OCR lines to use: those whose centre is inside an enabled area (all of them when none is enabled).
    public static func keep(_ lines: [OCRLine], in areas: [ReadingArea]) -> [OCRLine] {
        let on = areas.filter(\.enabled).map(\.rect)
        guard !on.isEmpty else { return lines }
        return lines.filter { l in on.contains { $0.contains(CGPoint(x: l.box.midX, y: l.box.midY)) } }
    }
}
