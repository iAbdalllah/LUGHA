// "History only" display on a real LUGHA TV: the dialogue goes to the side panel (each sentence once, even when the
// sender's paragraph changes around it), while the instruction areas still show over the game.
//   LUGHA_FFI_LIB=… flutter test test/tv_history_demo_test.dart --dart-define=TV_HOST=192.168.8.130 --dart-define=TV_CODE=123456
import 'package:flutter_test/flutter_test.dart';
import 'package:lugha_engine/lugha_engine.dart';
import 'package:lugha_mobile/display.dart';
import 'package:lugha_mobile/tv_link.dart';

const host = String.fromEnvironment('TV_HOST');
const code = String.fromEnvironment('TV_CODE');

void main() {
  test('history-only display on a real TV', () async {
    final keys = LinkCrypto().generateKeys();
    final tv = await TvSender.pair(host: host, port: Frame.defaultPort, code: code, name: 'TV', senderName: 'History check', secretHex: keys.secretHex);
    await Future<void>.delayed(const Duration(seconds: 5)); // time to press Home, so the overlay (not the app) draws
    final areas = ReadingArea.preset('game', (s) => s);
    final display = DisplayPrefs(display: 'history', areas: areas);
    tv.setStyle(fontScale: 1, bottom: true, bgOpacity: 0.85, extra: display.tvStyle());
    const a = 'يا رجال، أنا في ذا ريفرز، أحاول ألقى شحنة لدوني.';
    const b = 'المفروض تكون حول المنطقة، إلا إذا أحد سبقك عليها.';
    // What the phone sent in the reported case: the same sentences again and again inside changing paragraphs.
    final frames = ['$a\nكال', 'كال\n$a', '$a\n$b', '$b\nكال', b, 'كال\n$b', 'المفروض تكون حول المنطقة'];
    for (final t in frames) {
      tv.showItems('demo', [
        {'text': t, 'speaker': 'كال', 'x': 0.25, 'y': 0.80, 'w': 0.5, 'h': 0.06, 'lines': 2, 'area': areas[0].tvStyle()},
        {'text': 'اضغط مطوّلًا على LT لتخفيف السرعة أثناء الهبوط.', 'x': 0.02, 'y': 0.22, 'w': 0.22, 'h': 0.07, 'lines': 2, 'area': areas[1].tvStyle()},
        {'text': 'انتهت المكالمة. اتبع الشاحنة.', 'x': 0.40, 'y': 0.94, 'w': 0.2, 'h': 0.03, 'lines': 1, 'area': areas[2].tvStyle()},
      ]);
      await Future<void>.delayed(const Duration(milliseconds: 700));
    }
    await Future<void>.delayed(const Duration(seconds: 10)); // stays on screen for the screenshot
  }, timeout: const Timeout(Duration(minutes: 1)), skip: code.isEmpty);
}
