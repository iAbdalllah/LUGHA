// Runs the real Rust engine on the host (macOS .dylib / Linux .so from `cargo build --release -p lugha-ffi`).
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:lugha_engine/lugha_engine.dart';

void main() {
  final ext = Platform.isMacOS ? 'dylib' : 'so';
  final lib = '${Directory.current.path}/../../../../engine/target/release/liblugha_ffi.$ext';
  final available = File(lib).existsSync();

  test('device keys, version and invalid trust', () {
    final d = Engine.generateDevice(libraryPath: lib);
    expect(d.secretHex.length, 64);
    expect(d.publicKey.length, 44);
    expect(Engine.version(libraryPath: lib), isNotEmpty);
    expect(() => Engine.create(trustJson: '{}', deviceSecretHex: d.secretHex, libraryPath: lib), throwsA(isA<EngineException>()));
  }, skip: available ? false : 'engine dylib not built');

  test('link pairing start produces a SPAKE2 message', () {
    final link = LinkCrypto(libraryPath: lib);
    final k = link.generateKeys();
    final (pairing, msg) = link.pairStart('123456', k.secretHex);
    expect(msg.length, 33);
    link.pairingFree(pairing);
  }, skip: available ? false : 'engine dylib not built');
}
