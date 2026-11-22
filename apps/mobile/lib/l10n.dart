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
    'On iPhone, LUGHA can’t read other apps’ screens. Use it to manage packages and control your TV; subtitles come from LUGHA on your Mac or PC.':
        'على iPhone لا يمكن للغة قراءة شاشات التطبيقات الأخرى. استخدمه لإدارة الباقات والتحكم بالتلفاز؛ الترجمة تأتي من لغة على جهاز Mac أو الكمبيوتر.',
    'Wrong code': 'رمز خاطئ', 'Device limit reached — remove a device, then sync.': 'وصلت إلى حد الأجهزة — أزل جهازًا ثم زامن.', 'Packages loaded: %s': 'الباقات المحمّلة: %s', 'Last sync': 'آخر مزامنة',
  };

  static String t(String key, [String? arg]) {
    final s = lang == 'ar' ? (_ar[key] ?? key) : key;
    return arg == null ? s : s.replaceFirst('%s', arg);
  }

  static TextDirection get dir => lang == 'ar' ? TextDirection.rtl : TextDirection.ltr;
}
