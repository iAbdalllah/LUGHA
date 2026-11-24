import 'package:flutter/widgets.dart';

/// Arabic-first strings with English fallback.
class L {
  static String lang = 'ar';

  static const _ar = <String, String>{
    'LUGHA': 'لغة',
    'Library': 'المكتبة', 'Devices': 'الأجهزة', 'TV': 'التلفاز', 'Account': 'الحساب',
    'Sign in': 'تسجيل الدخول', 'Email': 'البريد الإلكتروني', 'Password': 'كلمة المرور', 'Server': 'الخادم',
    'Sign out': 'تسجيل الخروج', 'Language': 'اللغة',
    'Sync now': 'مزامنة الآن', 'Installed': 'مثبّتة', 'Not installed': 'غير مثبّتة', 'No release yet': 'لا يوجد إصدار بعد', 'Install': 'تثبيت', 'Update': 'تحديث', 'Remove': 'إزالة',
    'No packages yet. Buy one in the LUGHA store.': 'لا توجد باقات بعد. اشترِ باقة من متجر لغة.',
    'Try a line': 'جرّب سطرًا', 'Type an English line from the game…': 'اكتب سطرًا إنجليزيًا من اللعبة…', 'No translation found': 'لا توجد ترجمة',
    'History': 'السجل', 'This device': 'هذا الجهاز', 'Last seen': 'آخر ظهور',
    'Pair a TV': 'ربط تلفاز', 'TVs on this network': 'أجهزة التلفاز على هذه الشبكة', 'Searching…': 'جارٍ البحث…',
    'Address (IP:port)': 'العنوان (IP:المنفذ)', 'Code on the TV': 'الرمز على التلفاز', 'Pair': 'ربط',
    'Connected to %s': 'متصل بـ %s', 'Disconnect': 'قطع الاتصال', 'Reconnect': 'إعادة الاتصال',
    'TV remote': 'التحكم بالتلفاز', 'Font size': 'حجم الخط', 'Position': 'الموضع', 'Top': 'أعلى', 'Bottom': 'أسفل', 'Background': 'الخلفية',
    'Send a test line': 'إرسال سطر تجريبي', 'Clear the TV': 'مسح التلفاز',
    'Display': 'طريقة العرض', 'Over the English': 'فوق الإنجليزي', 'Above the English': 'أعلى الإنجليزي',
    'History only': 'السجل فقط', 'Nothing over the game; the lines appear only in the history panel.': 'لا شيء فوق اللعبة؛ الترجمة تظهر في لوحة السجل فقط.',
    'Box colour': 'لون المربع',
    'History panel': 'لوحة السجل', 'Off': 'مغلقة', 'Left': 'يسار', 'Right': 'يمين',
    'Recent dialogue at the side of the game; it dims after a few seconds.': 'آخر الحوارات بجانب اللعبة، وتخفت بعد ثوانٍ.',
    'Keep a line until it can be read': 'إبقاء السطر حتى تقرأه', 'Timing': 'التوقيت',
    'Wait for stable text': 'انتظار ثبات النص', 'English gone after': 'اعتبار الإنجليزي اختفى بعد', 'Fade in': 'ظهور تدريجي', 'ms': 'ملي ثانية',
    'Recent lines': 'آخر الترجمات',
    'Reading areas': 'مناطق القراءة', 'Add area': 'إضافة منطقة', 'Area': 'منطقة', 'Name': 'الاسم',
    'Only text inside these boxes is translated. With none, the usual subtitle band is read.': 'تُترجم النصوص داخل هذه المربعات فقط. بدونها تُقرأ منطقة الترجمة المعتادة.',
    'On iPhone, LUGHA can’t read other apps’ screens. Use it to manage packages and control your TV; subtitles come from LUGHA on your Mac or PC.':
        'على iPhone لا يمكن للغة قراءة شاشات التطبيقات الأخرى. استخدمه لإدارة الباقات والتحكم بالتلفاز؛ الترجمة تأتي من لغة على جهاز Mac أو الكمبيوتر.',
    'Report this line': 'الإبلاغ عن هذه الترجمة', 'Wrong translation? Report it': 'الترجمة خاطئة؟ أبلغ عنها',
    'Reports go to LUGHA’s reviewers. If accepted, the line is fixed in the next package update.': 'يصل بلاغك لفريق المراجعة. إن قُبل يُصحَّح السطر في التحديث القادم للحزمة.',
    'Reason': 'السبب', 'Note (optional)': 'ملاحظة (اختياري)', 'Cancel': 'إلغاء', 'Send report': 'إرسال البلاغ',
    'Thanks — your report was sent to the reviewers.': 'شكرًا، وصل بلاغك لفريق المراجعة.',
    'reason.wrong_meaning': 'معنى خاطئ', 'reason.typo': 'خطأ إملائي', 'reason.awkward': 'صياغة ركيكة',
    'reason.wrong_speaker': 'المتحدث خاطئ', 'reason.offensive': 'محتوى مسيء', 'reason.other': 'سبب آخر',
    'PS5': 'PS5', 'Play on your TV': 'العب على التلفاز',
    'Play on the TV as usual. This phone connects to your PS5 over Wi‑Fi, reads the game\'s English subtitles and shows the Arabic on the TV.':
        'العب على التلفاز كالمعتاد. يتصل هذا الجوال بجهاز PS5 عبر الواي فاي، ويقرأ ترجمة اللعبة الإنجليزية ويعرض العربية على التلفاز.',
    'Sign in to PlayStation Network': 'تسجيل الدخول إلى PlayStation Network', 'Signed in to PSN': 'تم تسجيل الدخول إلى PSN',
    'Your password goes only to Sony; LUGHA keeps just your account number.': 'كلمة المرور تذهب إلى Sony فقط؛ تحفظ لغة رقم حسابك فقط.',
    'Link your PS5': 'اربط جهاز PS5', 'Find PS5': 'ابحث عن PS5', 'No PS5 found on this Wi‑Fi.': 'لم يُعثر على PS5 على هذه الشبكة.',
    'On the PS5: Settings › System › Remote Play › Link Device. Enter the 8 digits it shows.': 'على الـPS5: الإعدادات › النظام › التشغيل عن بُعد › ربط جهاز. أدخل الأرقام الثمانية الظاهرة.',
    'Code from the PS5': 'الرمز من الـPS5', 'Link': 'ربط', 'Linked to %s': 'مرتبط بـ %s', 'Unlink': 'إلغاء الربط', 'Resting': 'في وضع الراحة',
    'Start reading subtitles': 'ابدأ قراءة الترجمة', 'Stop': 'إيقاف', 'Connecting to PS5…': 'جارٍ الاتصال بالـPS5…', 'Waking the PS5…': 'جارٍ تشغيل الـPS5…',
    'Reading subtitles from the PS5': 'تُقرأ الترجمة من الـPS5', 'The PS5 asks for its login passcode': 'يطلب الـPS5 رمز الدخول',
    'Connect a LUGHA TV first (TV tab) to see the Arabic on the TV.': 'اربط تلفاز لغة أولًا (تبويب التلفاز) لترى العربية عليه.',
    'Keep this phone on and LUGHA open while you play.': 'أبقِ الجوال مشغّلًا وتطبيق لغة مفتوحًا أثناء اللعب.',
    'Last line': 'آخر سطر',
    'Wrong code': 'رمز خاطئ', 'Device limit reached — remove a device, then sync.': 'وصلت إلى حد الأجهزة — أزل جهازًا ثم زامن.', 'Packages loaded: %s': 'الباقات المحمّلة: %s', 'Last sync': 'آخر مزامنة',
  };

  /// English only where the key is not itself the English text.
  static const _en = <String, String>{
    'reason.wrong_meaning': 'Wrong meaning', 'reason.typo': 'Typo', 'reason.awkward': 'Awkward wording',
    'reason.wrong_speaker': 'Wrong speaker', 'reason.offensive': 'Offensive', 'reason.other': 'Other',
  };

  static String t(String key, [String? arg]) {
    final s = lang == 'ar' ? (_ar[key] ?? key) : (_en[key] ?? key);
    return arg == null ? s : s.replaceFirst('%s', arg);
  }

  static TextDirection get dir => lang == 'ar' ? TextDirection.rtl : TextDirection.ltr;
}
