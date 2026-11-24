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
        "Reading areas": "مناطق القراءة", "Add area": "إضافة منطقة", "Show": "إظهار", "Area %d": "منطقة %d",
        "Only text inside these boxes is translated. With none, everything in the capture is read.": "تُترجم النصوص داخل هذه المربعات فقط. بدونها تُقرأ الصورة كلها.",
        "Display": "طريقة العرض", "Over the English": "فوق النص الإنجليزي", "Above the English": "أعلى النص الإنجليزي",
        "The Arabic hides the English.": "العربي يغطي النص الإنجليزي.", "The Arabic sits just above the English, which stays visible.": "العربي فوق النص الإنجليزي مباشرة، والإنجليزي يبقى ظاهرًا.",
        "History only": "السجل فقط", "Nothing over the game; the lines appear only in the history panel.": "لا شيء فوق اللعبة؛ الترجمة تظهر في لوحة السجل فقط.",
        "Box colour": "لون المربع",
        "History panel": "لوحة السجل", "Off": "مغلقة", "Left": "يسار", "Right": "يمين",
        "Recent dialogue at the side of the game; it dims after a few seconds.": "آخر الحوارات بجانب اللعبة، وتخفت بعد ثوانٍ.",
        "Keep a line until it can be read": "إبقاء السطر حتى تقرأه",
        "Timing": "التوقيت", "Wait for stable text (ms)": "انتظار ثبات النص (ملي ثانية)", "English gone after (ms)": "اعتبار الإنجليزي اختفى بعد (ملي ثانية)", "Fade in (ms)": "ظهور تدريجي (ملي ثانية)",
        "Avoids half-drawn lines; adds that much delay.": "يتجنب ترجمة الأسطر نصف الظاهرة، ويضيف تأخيرًا بنفس المدة.",
        "Ignores short OCR blinks; the Arabic leaves this long after the English.": "يتجاهل الومضات القصيرة في القراءة؛ يختفي العربي بعد الإنجليزي بهذه المدة.",
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
        "Syncing… large packages can take a few minutes.": "جارٍ المزامنة… الباقات الكبيرة قد تأخذ بضع دقائق.",
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
"Read from": "اقرأ من", "Text style": "شكل النص", "Native": "أصلي", "Box": "صندوق", "Outline": "محدد",
        "In the game's own colours, as if the game drew it.": "بألوان اللعبة نفسها، كأن اللعبة كتبته.", "Colour": "اللون", "White": "أبيض", "Yellow": "أصفر", "Font": "الخط", "Area": "المنطقة", "Subtitles (bottom)": "الترجمة (أسفل)", "Whole window (menus)": "النافذة كاملة (القوائم)",
        "Custom area": "منطقة مخصصة", "Reading:": "يقرأ الآن:", "Picture:": "الصورة:", "(no text in the area)": "(لا يوجد نص في المنطقة)", "An area of the screen": "منطقة من الشاشة", "%@ (not open)": "%@ (غير مفتوح)",
        "Select subtitle area in the window": "تحديد منطقة الترجمة داخل النافذة",
        "Keeps reading while the window is behind others. Don't minimise it.": "تستمر القراءة والنافذة خلف النوافذ الأخرى. لا تصغّرها إلى الـDock.",
        "Show subtitles on this Mac too": "اعرض الترجمة على هذا الـMac أيضًا",
        "Open PS Remote Play and connect to your PS5 first.": "افتح PS Remote Play واتصل بجهاز PS5 أولًا.",
        "Select the area inside the window.": "حدّد المنطقة داخل النافذة.",
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
