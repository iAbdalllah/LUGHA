// Draws the "Subtitles + instructions" preset on a real LUGHA TV, with the recogniser's usual wobble, for a screenshot:
//   LUGHA_FFI_LIB=… flutter test test/tv_layout_demo_test.dart --dart-define=TV_HOST=192.168.8.130 --dart-define=TV_CODE=123456
import 'package:flutter_test/flutter_test.dart';
import 'package:lugha_engine/lugha_engine.dart';
import 'package:lugha_mobile/display.dart';
import 'package:lugha_mobile/tv_link.dart';

const host = String.fromEnvironment('TV_HOST');
const code = String.fromEnvironment('TV_CODE');

void main() {
  test('preset layout on a real TV', () async {
    final keys = LinkCrypto().generateKeys();
    final tv = await TvSender.pair(host: host, port: Frame.defaultPort, code: code, name: 'TV', senderName: 'Layout check', secretHex: keys.secretHex);
    final areas = ReadingArea.preset('game', (s) => s);
    final display = DisplayPrefs(areas: areas);
    tv.setStyle(fontScale: 1, bottom: true, bgOpacity: 0.85, extra: display.tvStyle());
    for (var i = 0; i < 8; i++) {
      final j = (i % 3 - 1) * 0.004; // the recogniser's boxes move a little every frame
      // A busy scene: three people talking at once, plus both kinds of hints.
      tv.showItems('demo', [
        {'text': 'إذا كنت تريد العمل معي، عليك أن تثق بي.', 'speaker': 'لوسيا', 'x': 0.30 + j, 'y': 0.80 + j, 'w': 0.40 - j, 'h': 0.03, 'lines': 1, 'area': areas[0].tvStyle()},
        {'text': 'أنا أثق بك، لكن ليس بأصدقائك.', 'speaker': 'جيسون', 'x': 0.32, 'y': 0.835 + j, 'w': 0.36, 'h': 0.03, 'lines': 1, 'area': areas[0].tvStyle()},
        {'text': 'اسكتا، الشرطة قادمة!', 'speaker': 'كال', 'x': 0.38, 'y': 0.87, 'w': 0.24, 'h': 0.03, 'lines': 1, 'area': areas[0].tvStyle()},
        {'text': 'اضغط مطوّلًا على LT لتخفيف السرعة أثناء الهبوط والاقتراب من الماء.', 'x': 0.02 + j, 'y': 0.22, 'w': 0.22, 'h': 0.07 + j, 'lines': 2, 'area': areas[1].tvStyle()},
        {'text': 'اتبع الشاحنة.', 'x': 0.44, 'y': 0.94 + j, 'w': 0.12, 'h': 0.03, 'lines': 1, 'area': areas[2].tvStyle()},
      ]);
      await Future<void>.delayed(const Duration(milliseconds: 250));
    }
    await Future<void>.delayed(const Duration(seconds: 12)); // stays on screen for the screenshot
  }, timeout: const Timeout(Duration(minutes: 1)), skip: code.isEmpty);
}
