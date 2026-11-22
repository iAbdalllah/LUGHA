// Phone → LUGHA TV over a real socket. Run with the TV app on the same emulator/device:
//   flutter test integration_test/tv_link_test.dart --dart-define=TV_CODE=123456 [--dart-define=TV_HOST=127.0.0.1]
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:lugha_engine/lugha_engine.dart';
import 'package:lugha_mobile/tv_link.dart';

const code = String.fromEnvironment('TV_CODE');
const host = String.fromEnvironment('TV_HOST', defaultValue: '127.0.0.1');

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('pair, stream with paint acks, style, reconnect', (t) async {
    final keys = LinkCrypto().generateKeys();
    final tv = await TvSender.pair(host: host, port: Frame.defaultPort, code: code, name: 'TV', senderName: 'Android test', secretHex: keys.secretHex);
    final times = <double>[];
    for (var i = 1; i <= 100; i++) {
      times.add((await tv.show('الماء هنا أغلى من الذهب. ($i)', speaker: 'تاجر', waitForAck: true))!);
    }
    times.sort();
    final p95 = times[(times.length * 0.95).floor()];
    // ignore: avoid_print
    print('phone→TV incl. paint: p50=${times[times.length ~/ 2]} ms p95=$p95 ms');
    expect(p95, lessThan(50));
    tv.setStyle(fontScale: 1.25, bottom: true, bgOpacity: 0.9);
    expect(await tv.ping(), isTrue);
    await tv.close();

    final again = await TvSender.connect(tv.tv, senderName: 'Android test', secretHex: keys.secretHex);
    expect(await again.ping(), isTrue);
    await again.show('نطلع مع الفجر. وأحد ما يتكلم عن الليلة.', speaker: 'ريم', waitForAck: true);
    await again.close();
  }, skip: code.isEmpty);
}
