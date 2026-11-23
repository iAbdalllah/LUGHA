import CoreText
import Foundation

/// Arabic fonts shared by the Mac and LUGHA TV (same ids on both, sent in style.set "font"). The four non-system ones
/// ship inside the app (SIL Open Font License, Resources/Fonts/OFL-*.txt) so both screens look the same.
enum ArabicFonts {
    /// (id, label, family name; nil = system font)
    static let all: [(id: String, label: String, family: String?)] = [
        ("", "SF Arabic", nil),
        ("naskh", "Noto Naskh", "Noto Naskh Arabic"),
        ("kufi", "Noto Kufi", "Noto Kufi Arabic"),
        ("cairo", "Cairo", "Cairo"),
        ("tajawal", "Tajawal", "Tajawal"),
        ("amiri", "Amiri", "Amiri"),
    ]

    static func family(_ id: String) -> String? { all.first { $0.id == id }?.family }

    /// Makes the bundled fonts available to this process (call once at launch).
    static func registerBundled() {
        guard let dir = Bundle.main.resourceURL?.appendingPathComponent("Fonts"),
              let files = try? FileManager.default.contentsOfDirectory(at: dir, includingPropertiesForKeys: nil) else { return }
        let fonts = files.filter { $0.pathExtension.lowercased() == "ttf" } as CFArray
        CTFontManagerRegisterFontURLs(fonts, .process, true, nil)
    }
}
