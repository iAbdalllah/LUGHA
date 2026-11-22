import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:lugha_engine/lugha_engine.dart';
import 'package:path_provider/path_provider.dart';

import 'api.dart';
import 'l10n.dart';
import 'sync.dart';
import 'tv_link.dart';

class Line {
  Line(this.source, this.target, this.speaker, this.at, {this.entryId, this.packageId});
  final String source;
  final String? target;
  final String? speaker;
  final DateTime at;
  /// Set when the line matched a package entry — what "report this line" sends.
  final int? entryId;
  final String? packageId;
  bool get reportable => entryId != null && packageId != null;
}

/// Reasons the server accepts for a report (TranslationFeedback::REASONS).
const reportReasons = ['wrong_meaning', 'typo', 'awkward', 'wrong_speaker', 'offensive', 'other'];

/// App-wide state. Secrets (API token, device key, link key) are in the platform keystore.
class AppState extends ChangeNotifier {
  AppState({FlutterSecureStorage? storage}) : _secure = storage ?? const FlutterSecureStorage();

  static const appVersion = '0.1.0';
  final FlutterSecureStorage _secure;

  late ApiClient api;
  Engine? engine;
  PackageStore? store;
  String? userName;
  String? deviceId;
  bool busy = false;
  String status = '';
  List<LibraryItem> library = [];
  List<LoadedPackage> loaded = [];
  List<DeviceInfo> devices = [];
  final history = <Line>[];
  DateTime? lastSync;

  // TV
  TvSender? tv;
  List<PairedTv> pairedTvs = [];
  List<FoundTv> found = [];
  double tvFontScale = 1.0;
  bool tvBottom = true;
  double tvBgOpacity = 0.75;

  bool get signedIn => api.token != null;
  String get deviceKind => Platform.isIOS ? 'ios' : 'android';

  static Uri defaultServer() => Uri.parse(Platform.isAndroid ? 'http://10.0.2.2:8040/' : 'http://127.0.0.1:8040/');

  Future<void> init() async {
    final server = await _secure.read(key: 'server');
    L.lang = await _secure.read(key: 'lang') ?? 'ar';
    api = ApiClient(baseUrl: server != null ? Uri.parse(server) : defaultServer(), token: await _secure.read(key: 'token'), language: L.lang);
    final tvs = await _secure.read(key: 'paired_tvs');
    if (tvs != null) pairedTvs = (jsonDecode(tvs) as List).map((e) => PairedTv.fromJson(e as Map<String, dynamic>)).toList();
    store = PackageStore(Directory('${(await getApplicationSupportDirectory()).path}/packages'));
    notifyListeners();
    if (signedIn) {
      try {
        userName = await api.me();
        await _prepareEngine();
        await sync();
      } on ApiException catch (e) {
        if (e.status == 401) await signOut();
      } catch (e) {
        status = '$e';
        await _prepareEngineOffline();
      }
    }
    notifyListeners();
  }

  Future<void> setLanguage(String lang) async {
    L.lang = lang;
    api.language = lang;
    await _secure.write(key: 'lang', value: lang);
    notifyListeners();
  }

  Future<void> signIn(String server, String email, String password) async {
    busy = true;
    status = '';
    notifyListeners();
    try {
      api.baseUrl = Uri.parse(server.endsWith('/') ? server : '$server/');
      final r = await api.login(email, password, 'LUGHA ${Platform.isIOS ? 'iPhone' : 'Android'}');
      api.token = r.token;
      userName = r.name;
      await _secure.write(key: 'token', value: r.token);
      await _secure.write(key: 'server', value: api.baseUrl.toString());
      await _prepareEngine();
      await sync();
    } catch (e) {
      status = '$e';
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> signOut() async {
    for (final k in ['token', 'device_id']) {
      await _secure.delete(key: k);
    }
    api.token = null;
    userName = null;
    deviceId = null;
    library = [];
    devices = [];
    notifyListeners();
  }

  Future<DeviceIdentity> _identity() async {
    final s = await _secure.read(key: 'device_secret'), p = await _secure.read(key: 'device_public');
    if (s != null && p != null) return DeviceIdentity(s, p, '');
    final d = Engine.generateDevice();
    await _secure.write(key: 'device_secret', value: d.secretHex);
    await _secure.write(key: 'device_public', value: d.publicKey);
    return d;
  }

  /// Set when registration hit the device limit; the UI then opens the Devices tab.
  bool deviceLimitReached = false;

  Future<void> _prepareEngine() async {
    final id = await _identity();
    final trust = await api.trustJson();
    await _secure.write(key: 'trust', value: trust);
    engine?.dispose();
    engine = Engine.create(trustJson: trust, deviceSecretHex: id.secretHex);
    deviceId = await _secure.read(key: 'device_id');
    if (deviceId == null) {
      try {
        deviceId = await api.registerDevice(
            kind: deviceKind, name: Platform.isIOS ? 'iPhone' : 'Android phone', publicKey: id.publicKey, model: Platform.operatingSystem, appVersion: appVersion);
      } on ApiException catch (e) {
        if (e.code == 'device_limit_reached') {
          deviceLimitReached = true;
          devices = await api.devices();
          status = L.t('Device limit reached — remove a device, then sync.');
          return;
        }
        rethrow;
      }
      deviceLimitReached = false;
      await _secure.write(key: 'device_id', value: deviceId);
    }
  }

  Future<void> _prepareEngineOffline() async {
    final trust = await _secure.read(key: 'trust'), secret = await _secure.read(key: 'device_secret');
    if (trust == null || secret == null) return;
    engine = Engine.create(trustJson: trust, deviceSecretHex: secret);
    loaded = engine!.load(store!.root.path);
  }

  Future<void> sync() async {
    if (engine != null && deviceId == null) await _prepareEngine(); // retry after the user freed a device slot
    if (engine == null || deviceId == null) {
      notifyListeners();
      return;
    }
    busy = true;
    notifyListeners();
    final r = await PackageSync(api, engine!, store!, appVersion).sync(deviceId!);
    loaded = r.loaded;
    status = r.errors.isEmpty ? '' : r.errors.first;
    try {
      library = await api.library();
      devices = await api.devices();
    } catch (_) {}
    lastSync = DateTime.now();
    busy = false;
    notifyListeners();
  }

  Future<void> removeDevice(DeviceInfo d) async {
    await api.removeDevice(d.id);
    if (d.id == deviceId) {
      await signOut();
      return;
    }
    devices = await api.devices();
    notifyListeners();
  }

  void removePackage(String packageId) {
    store?.remove(packageId);
    loaded = engine?.load(store!.root.path) ?? [];
    notifyListeners();
  }

  Line tryLine(String text) {
    final hit = engine?.lookup(text);
    final line = Line(text, hit?.target, hit?.speaker, DateTime.now(), entryId: hit?.entryId, packageId: hit?.packageId);
    history.insert(0, line);
    if (history.length > 50) history.removeLast();
    notifyListeners();
    return line;
  }

  /// Sends a report for [line]. Returns null on success, or the server's message.
  Future<String?> report(Line line, String reason, String note) async {
    if (!line.reportable) return null;
    final version = loaded.where((p) => p.packageId == line.packageId).firstOrNull?.version;
    try {
      await api.reportLine(package: line.packageId!, version: version, entryId: line.entryId!, reason: reason, note: note, source: deviceKind);
      return null;
    } catch (e) {
      return '$e';
    }
  }

  // ── TV ──

  Future<({String secretHex, String publicKey})> _linkKeys() async {
    final s = await _secure.read(key: 'link_secret'), p = await _secure.read(key: 'link_public');
    if (s != null && p != null) return (secretHex: s, publicKey: p);
    final k = LinkCrypto().generateKeys();
    await _secure.write(key: 'link_secret', value: k.secretHex);
    await _secure.write(key: 'link_public', value: k.publicKey);
    return k;
  }

  Future<void> discover() async {
    found = await discoverTvs();
    notifyListeners();
  }

  String get _senderName => Platform.isIOS ? 'iPhone' : 'Android';

  Future<void> pairTv(String host, int port, String code, String name) async {
    status = '';
    try {
      final k = await _linkKeys();
      tv = await TvSender.pair(host: host, port: port, code: code, name: name, senderName: _senderName, secretHex: k.secretHex);
      pairedTvs = [...pairedTvs.where((t) => t.publicKey != tv!.tv.publicKey), tv!.tv];
      await _secure.write(key: 'paired_tvs', value: jsonEncode(pairedTvs.map((t) => t.toJson()).toList()));
      _pushStyle();
    } on LinkException catch (e) {
      status = e.message == 'wrong_code' ? L.t('Wrong code') : e.message;
    } catch (e) {
      status = '$e';
    }
    notifyListeners();
  }

  Future<void> reconnectTv() async {
    if (pairedTvs.isEmpty) return;
    try {
      final k = await _linkKeys();
      tv = await TvSender.connect(pairedTvs.last, senderName: _senderName, secretHex: k.secretHex);
      _pushStyle();
    } catch (e) {
      status = '$e';
    }
    notifyListeners();
  }

  Future<void> disconnectTv() async {
    await tv?.close();
    tv = null;
    notifyListeners();
  }

  void setTvStyle({double? fontScale, bool? bottom, double? bgOpacity}) {
    tvFontScale = fontScale ?? tvFontScale;
    tvBottom = bottom ?? tvBottom;
    tvBgOpacity = bgOpacity ?? tvBgOpacity;
    _pushStyle();
    notifyListeners();
  }

  void _pushStyle() => tv?.setStyle(fontScale: tvFontScale, bottom: tvBottom, bgOpacity: tvBgOpacity);

  Future<void> sendTestLine() async {
    final line = history.firstWhere((l) => l.target != null, orElse: () => Line('', 'نطلع مع الفجر. وأحد ما يتكلم عن الليلة.', 'ريم', DateTime.now()));
    await tv?.show(line.target!, speaker: line.speaker);
  }
}
