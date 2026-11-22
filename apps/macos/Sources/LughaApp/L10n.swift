import Foundation

/// Arabic-first UI strings with English fallback (follows the app's language setting).
enum L {
    static var language = "ar"

    private static let ar: [String: String] = [
        "LUGHA": "لغة",
        "Running · offline": "يعمل · بدون إنترنت",
        "Paused": "متوقف مؤقتًا",
        "Not signed in": "لم يتم تسجيل الدخول",
        "Start": "تشغيل", "Pause": "إيقاف مؤقت",
        "Select capture region": "تحديد منطقة الالتقاط",
        "Font size": "حجم الخط", "Background": "الخلفية", "Position": "الموضع",
        "Recent lines": "آخر الترجمات",
        "Library & account…": "المكتبة والحساب…",
        "Quit LUGHA": "إنهاء لغة",
        "Sign in": "تسجيل الدخول",
        "Sign in with a code": "سجّل الدخول برمز",
        "Open lugha.app/activate in your browser and enter this code:": "افتح صفحة التفعيل في متصفحك وأدخل هذا الرمز:",
        "Open activation page": "فتح صفحة التفعيل",
        "Waiting for approval…": "بانتظار الموافقة…",
        "Signed in as %@": "مسجّل باسم %@",
        "Sign out": "تسجيل الخروج",
        "Your packages": "باقاتك",
        "Sync now": "مزامنة الآن",
        "Installed %@": "مثبّتة %@",
        "No packages yet. Buy one in the LUGHA store.": "لا توجد باقات بعد. اشترِ باقة من متجر لغة.",
        "Screen Recording permission is needed to read subtitles.": "يلزم إذن «تسجيل الشاشة» لقراءة الترجمة.",
        "Open System Settings": "فتح إعدادات النظام",
        "Drag to select the subtitle area · Esc to cancel": "اسحب لتحديد منطقة الترجمة · Esc للإلغاء",
        "Choose a region first": "اختر منطقة الالتقاط أولًا",
        "Server": "الخادم",
        "Language": "اللغة",
        "packages loaded": "باقات محمّلة",
        "Last sync": "آخر مزامنة",
        "Send to TV": "إرسال إلى التلفاز", "Disconnect": "قطع الاتصال", "Reconnect": "إعادة الاتصال", "TV": "التلفاز",
        "Report this line": "الإبلاغ عن هذه الترجمة", "Reason": "السبب", "Note (optional)": "ملاحظة (اختياري)",
        "Cancel": "إلغاء", "Send report": "إرسال البلاغ",
        "Thanks — your report was sent to the reviewers.": "شكرًا، وصل بلاغك لفريق المراجعة.",
        "reason.wrong_meaning": "معنى خاطئ", "reason.typo": "خطأ إملائي", "reason.awkward": "صياغة ركيكة",
        "reason.wrong_speaker": "المتحدث خاطئ", "reason.offensive": "محتوى مسيء", "reason.other": "سبب آخر",
        "Choose a TV…": "اختر تلفازًا…", "or address (IP:port)": "أو العنوان (IP:المنفذ)", "Code": "الرمز", "Pair": "ربط",
    ]

    /// English only where the key is not itself the English text.
    private static let en: [String: String] = [
        "reason.wrong_meaning": "Wrong meaning", "reason.typo": "Typo", "reason.awkward": "Awkward wording",
        "reason.wrong_speaker": "Wrong speaker", "reason.offensive": "Offensive", "reason.other": "Other",
    ]

    static func t(_ key: String, _ args: CVarArg...) -> String {
        let s = language == "ar" ? (ar[key] ?? key) : (en[key] ?? key)
        return args.isEmpty ? s : String(format: s, arguments: args)
    }
}
