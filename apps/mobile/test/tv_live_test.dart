// The phone's TV code against a running LUGHA TV (emulator: adb forward tcp:47800 tcp:47800), on the host:
//   LUGHA_FFI_LIB=../../engine/target/release/liblugha_ffi.dylib flutter test test/tv_live_test.dart --dart-define=TV_CODE=123456
// Sends what the phone sends while playing (style with the display options, keep-alive pings, lines with a designed
// reading area, clear-all) and checks the TV keeps the link.
import 'dart:async';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:lugha_engine/lugha_engine.dart';
import 'package:lugha_mobile/display.dart';
import 'package:lugha_mobile/tv_link.dart';

const code = String.fromEnvironment('TV_CODE');

void main() {
  test('the TV keeps the phone link while playing with designed areas', () async {
    final keys = LinkCrypto().generateKeys();
    final tv = await TvSender.pair(host: '127.0.0.1', port: Frame.defaultPort, code: code, name: 'TV', senderName: 'iPhone', secretHex: keys.secretHex);
    final display = DisplayPrefs(areas: ReadingArea.preset('game', (s) => s));
    tv.setStyle(fontScale: 1, bottom: true, bgOpacity: 0.8, extra: display.tvStyle());
    final keep = Timer.periodic(const Duration(seconds: 8), (_) => tv.keepAlive());
    final area = display.areas[2];
    tv.showItems('اضغط مطولاً لتخفيف السرعة.', [
      {'text': 'اضغط مطولاً لتخفيف السرعة.', 'x': 0.2, 'y': 0.93, 'w': 0.6, 'h': 0.06, 'lines': 1, 'area': area.tvStyle()},
    ]);
    await Future<void>.delayed(const Duration(seconds: 30));
    tv.clearAll();
    tv.show('الماء هنا أغلى من الذهب.', speaker: 'تاجر');
    keep.cancel();
    expect(await tv.ping(), isTrue);
    await tv.close();
  }, timeout: const Timeout(Duration(minutes: 2)), skip: code.isEmpty);

  test('the phone notices at once when the TV drops the link', () async {
    final keys = LinkCrypto().generateKeys();
    final tv = await TvSender.pair(host: '127.0.0.1', port: Frame.defaultPort, code: code2, name: 'TV', senderName: 'iPhone', secretHex: keys.secretHex);
    final dropped = Completer<void>();
    tv.onClosed = dropped.complete;
    expect(tv.closed, isFalse);
    // The TV app goes away (a restart, a Wi-Fi drop): the phone must see it, not keep sending into a dead link.
    await Process.run(adb, ['shell', 'am', 'force-stop', 'app.lugha.tv']);
    await dropped.future.timeout(const Duration(seconds: 10));
    expect(tv.closed, isTrue);
    expect(() => tv.keepAlive(), throwsA(isA<LinkException>()));
  }, timeout: const Timeout(Duration(minutes: 1)), skip: code2.isEmpty);
}

const code2 = String.fromEnvironment('TV_CODE2');
final adb = '${Platform.environment['HOME']}/Library/Android/sdk/platform-tools/adb';
