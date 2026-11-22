/// LUGHA engine + LUGHA Link sender for Flutter (dart:ffi over engine/lugha-ffi's C ABI).
///
/// Not thread-safe: use one [Engine] from one isolate. Every native call returns JSON
/// ({"ok":true,...} | {"ok":false,"error"}); errors surface as [EngineException].
library;

import 'dart:convert';
import 'dart:ffi';
import 'dart:io';

import 'package:ffi/ffi.dart';

typedef _Str = Pointer<Utf8>;

class EngineException implements Exception {
  EngineException(this.message);
  final String message;
  @override
  String toString() => message;
}

class _Native {
  _Native(DynamicLibrary lib)
      : deviceGenerate = lib.lookupFunction<_Str Function(), _Str Function()>('lugha_device_generate'),
        engineNew = lib.lookupFunction<Pointer<Void> Function(_Str, _Str), Pointer<Void> Function(_Str, _Str)>('lugha_engine_new'),
        engineFree = lib.lookupFunction<Void Function(Pointer<Void>), void Function(Pointer<Void>)>('lugha_engine_free'),
        install = lib.lookupFunction<_Str Function(Pointer<Void>, _Str, _Str, _Str), _Str Function(Pointer<Void>, _Str, _Str, _Str)>('lugha_install'),
        refreshLicense = lib.lookupFunction<_Str Function(Pointer<Void>, _Str, _Str), _Str Function(Pointer<Void>, _Str, _Str)>('lugha_refresh_license'),
        load = lib.lookupFunction<_Str Function(Pointer<Void>, _Str), _Str Function(Pointer<Void>, _Str)>('lugha_load'),
        lookup = lib.lookupFunction<_Str Function(Pointer<Void>, _Str), _Str Function(Pointer<Void>, _Str)>('lugha_lookup'),
        resetContext = lib.lookupFunction<Void Function(Pointer<Void>), void Function(Pointer<Void>)>('lugha_reset_context'),
        stringFree = lib.lookupFunction<Void Function(_Str), void Function(_Str)>('lugha_string_free'),
        version = lib.lookupFunction<_Str Function(), _Str Function()>('lugha_engine_version'),
        linkKeys = lib.lookupFunction<_Str Function(), _Str Function()>('lugha_link_keys_generate'),
        pairStart = lib.lookupFunction<_Str Function(_Str, _Str, Pointer<Pointer<Void>>), _Str Function(_Str, _Str, Pointer<Pointer<Void>>)>('lugha_link_pair_start'),
        pairFinish = lib.lookupFunction<_Str Function(Pointer<Void>, _Str), _Str Function(Pointer<Void>, _Str)>('lugha_link_pair_finish'),
        pairingFree = lib.lookupFunction<Void Function(Pointer<Void>), void Function(Pointer<Void>)>('lugha_link_pairing_free'),
        sessionStart = lib.lookupFunction<_Str Function(_Str, _Str, Pointer<Pointer<Void>>), _Str Function(_Str, _Str, Pointer<Pointer<Void>>)>('lugha_link_session_start'),
        sessionComplete = lib.lookupFunction<_Str Function(Pointer<Void>, _Str), _Str Function(Pointer<Void>, _Str)>('lugha_link_session_complete'),
        encrypt = lib.lookupFunction<_Str Function(Pointer<Void>, _Str), _Str Function(Pointer<Void>, _Str)>('lugha_link_encrypt'),
        decrypt = lib.lookupFunction<_Str Function(Pointer<Void>, _Str), _Str Function(Pointer<Void>, _Str)>('lugha_link_decrypt'),
        sessionFree = lib.lookupFunction<Void Function(Pointer<Void>), void Function(Pointer<Void>)>('lugha_link_session_free');

  final _Str Function() deviceGenerate;
  final Pointer<Void> Function(_Str, _Str) engineNew;
  final void Function(Pointer<Void>) engineFree;
  final _Str Function(Pointer<Void>, _Str, _Str, _Str) install;
  final _Str Function(Pointer<Void>, _Str, _Str) refreshLicense;
  final _Str Function(Pointer<Void>, _Str) load;
  final _Str Function(Pointer<Void>, _Str) lookup;
  final void Function(Pointer<Void>) resetContext;
  final void Function(_Str) stringFree;
  final _Str Function() version;
  final _Str Function() linkKeys;
  final _Str Function(_Str, _Str, Pointer<Pointer<Void>>) pairStart;
  final _Str Function(Pointer<Void>, _Str) pairFinish;
  final void Function(Pointer<Void>) pairingFree;
  final _Str Function(_Str, _Str, Pointer<Pointer<Void>>) sessionStart;
  final _Str Function(Pointer<Void>, _Str) sessionComplete;
  final _Str Function(Pointer<Void>, _Str) encrypt;
  final _Str Function(Pointer<Void>, _Str) decrypt;
  final void Function(Pointer<Void>) sessionFree;

  static _Native? _instance;

  /// [libraryPath] lets host tests load a desktop build (target/release/liblugha_ffi.dylib).
  static _Native get(String? libraryPath) => _instance ??= _Native(_open(libraryPath));

  static DynamicLibrary _open(String? path) {
    if (path != null) return DynamicLibrary.open(path);
    if (Platform.isAndroid) return DynamicLibrary.open('liblugha_ffi.so');
    if (Platform.isIOS) return DynamicLibrary.process(); // statically linked
    final env = Platform.environment['LUGHA_FFI_LIB'];
    if (env != null) return DynamicLibrary.open(env);
    throw UnsupportedError('No LUGHA engine library for this platform');
  }
}

/// Calls a native function with UTF-8 arguments and decodes its JSON result.
Map<String, dynamic> _call(_Native n, _Str Function(List<_Str>) f, List<String> args) {
  final ptrs = args.map((a) => a.toNativeUtf8()).toList();
  try {
    final out = f(ptrs);
    if (out == nullptr) throw EngineException('native call returned nothing');
    final text = out.toDartString();
    n.stringFree(out);
    final json = jsonDecode(text) as Map<String, dynamic>;
    if (json['ok'] != true) throw EngineException(json['error']?.toString() ?? 'engine error');
    return json;
  } finally {
    for (final p in ptrs) {
      malloc.free(p);
    }
  }
}

class DeviceIdentity {
  DeviceIdentity(this.secretHex, this.publicKey, this.fingerprint);
  final String secretHex;
  final String publicKey;
  final String fingerprint;
}

class Hit {
  Hit(this.json);
  final Map<String, dynamic> json;
  String get target => json['target'] as String;
  String? get speaker => json['speaker'] as String?;
  String get packageId => json['package_id'] as String;
  double get score => (json['score'] as num).toDouble();
  String get method => json['method'] as String;
}

class LoadedPackage {
  LoadedPackage(this.packageId, this.version, this.entries);
  final String packageId;
  final String version;
  final int entries;
}

/// The package engine: install, load, look up.
class Engine {
  Engine._(this._n, this._h);

  final _Native _n;
  Pointer<Void> _h;

  static String version({String? libraryPath}) => _Native.get(libraryPath).version().toDartString();

  static DeviceIdentity generateDevice({String? libraryPath}) {
    final n = _Native.get(libraryPath);
    final j = _call(n, (_) => n.deviceGenerate(), []);
    return DeviceIdentity(j['secret_hex'] as String, j['public'] as String, j['fingerprint'] as String);
  }

  static Engine create({required String trustJson, required String deviceSecretHex, String? libraryPath}) {
    final n = _Native.get(libraryPath);
    final t = trustJson.toNativeUtf8(), s = deviceSecretHex.toNativeUtf8();
    try {
      final h = n.engineNew(t, s);
      if (h == nullptr) throw EngineException('invalid trust document or device key');
      return Engine._(n, h);
    } finally {
      malloc.free(t);
      malloc.free(s);
    }
  }

  void _alive() {
    if (_h == nullptr) throw EngineException('engine disposed');
  }

  Map<String, dynamic> install({required String packageDir, required String lpkPath, required String licenseJson}) {
    _alive();
    return _call(_n, (a) => _n.install(_h, a[0], a[1], a[2]), [packageDir, lpkPath, licenseJson]);
  }

  void refreshLicense({required String packageDir, required String licenseJson}) {
    _alive();
    _call(_n, (a) => _n.refreshLicense(_h, a[0], a[1]), [packageDir, licenseJson]);
  }

  List<LoadedPackage> load(String packagesRoot) {
    _alive();
    final j = _call(_n, (a) => _n.load(_h, a[0]), [packagesRoot]);
    return (j['packages'] as List)
        .map((p) => LoadedPackage(p['package_id'] as String, p['version'] as String, (p['entries'] as num).toInt()))
        .toList();
  }

  Hit? lookup(String text) {
    _alive();
    final j = _call(_n, (a) => _n.lookup(_h, a[0]), [text]);
    return j['hit'] == null ? null : Hit(j['hit'] as Map<String, dynamic>);
  }

  void resetContext() {
    _alive();
    _n.resetContext(_h);
  }

  void dispose() {
    if (_h != nullptr) _n.engineFree(_h);
    _h = nullptr;
  }
}

/// LUGHA Link sender crypto (pairing + session); framing/sockets live in the app.
class LinkCrypto {
  LinkCrypto({String? libraryPath}) : _n = _Native.get(libraryPath);
  final _Native _n;

  ({String secretHex, String publicKey}) generateKeys() {
    final j = _call(_n, (_) => _n.linkKeys(), []);
    return (secretHex: j['secret_hex'] as String, publicKey: j['public'] as String);
  }

  /// Returns (pairing handle, PAIR1 bytes).
  (Pointer<Void>, List<int>) pairStart(String code, String secretHex) {
    final out = calloc<Pointer<Void>>();
    try {
      final j = _call(_n, (a) => _n.pairStart(a[0], a[1], out), [code, secretHex]);
      return (out.value, base64Decode(j['msg'] as String));
    } finally {
      calloc.free(out);
    }
  }

  /// Consumes the pairing. Returns (PAIR3 bytes, TV public key base64).
  (List<int>, String) pairFinish(Pointer<Void> pairing, List<int> pair2) {
    final j = _call(_n, (a) => _n.pairFinish(pairing, a[0]), [base64Encode(pair2)]);
    return (base64Decode(j['msg'] as String), j['peer'] as String);
  }

  void pairingFree(Pointer<Void> pairing) => _n.pairingFree(pairing);

  (Pointer<Void>, List<int>) sessionStart(String secretHex, String peerB64) {
    final out = calloc<Pointer<Void>>();
    try {
      final j = _call(_n, (a) => _n.sessionStart(a[0], a[1], out), [secretHex, peerB64]);
      return (out.value, base64Decode(j['msg'] as String));
    } finally {
      calloc.free(out);
    }
  }

  void sessionComplete(Pointer<Void> s, List<int> hs2) => _call(_n, (a) => _n.sessionComplete(s, a[0]), [base64Encode(hs2)]);

  List<int> encrypt(Pointer<Void> s, String json) => base64Decode(_call(_n, (a) => _n.encrypt(s, a[0]), [json])['msg'] as String);

  String decrypt(Pointer<Void> s, List<int> cipher) => _call(_n, (a) => _n.decrypt(s, a[0]), [base64Encode(cipher)])['text'] as String;

  void sessionFree(Pointer<Void> s) => _n.sessionFree(s);
}
