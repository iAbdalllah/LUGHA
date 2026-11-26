// The subtitle card on a real LUGHA TV across the cases that used to change its size and place: a short English line,
// a line read taller than it is, and menu text caught beside the subtitle. The card should look the same each time.
//   LUGHA_FFI_LIB=… flutter test test/tv_steady_demo_test.dart --dart-define=TV_HOST=192.168.8.130 --dart-define=TV_CODE=…
import 'package:flutter_test/flutter_test.dart';
import 'package:lugha_engine/lugha_engine.dart';
import 'package:lugha_mobile/display.dart';
import 'package:lugha_mobile/tv_link.dart';

const host = String.fromEnvironment('TV_HOST');
const code = String.fromEnvironment('TV_CODE');

void main() {
  test('steady subtitle card on a real TV', () async {
    final keys = LinkCrypto().generateKeys();
    final tv = await TvSender.pair(host: host, port: Frame.defaultPort, code: code, name: 'TV', senderName: 'Steady check', secretHex: keys.secretHex);
    await Future<void>.delayed(const Duration(seconds: 5)); // time to press Home, so the overlay (not the app) draws
    final areas = ReadingArea.preset('game', (s) => s);
    tv.setStyle(fontScale: 1.25, bottom: true, bgOpacity: 0.75, extra: DisplayPrefs(areas: areas).tvStyle());
    final sub = areas[0].tvStyle();
    final frames = [
      // normal line
      {'text': 'الحين، وش الزفت اللي قاعد يصير هنا؟', 'speaker': 'Brian', 'x': 0.44, 'y': 0.85, 'w': 0.2, 'h': 0.028, 'lines': 1, 'area': sub},
      // short English, read twice as tall
      {'text': 'إيه، أظننا جاهزين.', 'speaker': 'Brian', 'x': 0.47, 'y': 0.83, 'w': 0.08, 'h': 0.06, 'lines': 1, 'area': sub},
      // menu text beside it caught in the same area (wide box)
      {'text': 'روحوا أنتم أول.', 'speaker': 'Lucia', 'x': 0.12, 'y': 0.80, 'w': 0.6, 'h': 0.09, 'lines': 2, 'area': sub},
    ];
    for (var i = 0; i < 3; i++) {
      tv.showItems('demo', [frames[i]]);
      await Future<void>.delayed(const Duration(seconds: 4));
    }
  }, timeout: const Timeout(Duration(minutes: 1)), skip: code.isEmpty);
}
