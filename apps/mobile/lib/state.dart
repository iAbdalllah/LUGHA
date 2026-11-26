import 'dart:async';
import 'dart:math' as math;
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:lugha_engine/lugha_engine.dart';
import 'package:lugha_remoteplay/lugha_remoteplay.dart';
import 'package:path_provider/path_provider.dart';

import 'api.dart';
import 'display.dart';
import 'matching.dart';
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
class AppState extends ChangeNotifier with WidgetsBindingObserver {
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
  DisplayPrefs display = const DisplayPrefs();
  late final StableGate _gate = StableGate(_onStableText);

  bool get signedIn => api.token != null;
  String get deviceKind => Platform.isIOS ? 'ios' : 'android';

  /// Release builds use the live server; debug builds a local backend (the emulator reaches the host at 10.0.2.2).
  static Uri defaultServer() => kReleaseMode
      ? Uri.parse('https://lugha.iabdallah.com/')
      : Uri.parse(Platform.isAndroid ? 'http://10.0.2.2:8040/' : 'http://127.0.0.1:8040/');

  Future<void> init() async {
    final server = await _secure.read(key: 'server');
    L.lang = await _secure.read(key: 'lang') ?? 'ar';
    api = ApiClient(baseUrl: server != null ? Uri.parse(server) : defaultServer(), token: await _secure.read(key: 'token'), language: L.lang);
    await loadRemotePlay();
    final d = await _secure.read(key: 'display');
    if (d != null) display = DisplayPrefs.fromJson(jsonDecode(d) as Map<String, dynamic>);
    final tvs = await _secure.read(key: 'paired_tvs');
    if (tvs != null) pairedTvs = (jsonDecode(tvs) as List).map((e) => PairedTv.fromJson(e as Map<String, dynamic>)).toList();
    // Back on the TV by itself: now, and whenever the app returns to the screen.
    WidgetsBinding.instance.addObserver(this);
    unawaited(ensureTv());
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
      _tvWanted = true;
      _watchTv(await TvSender.pair(host: host, port: port, code: code, name: name, senderName: _senderName, secretHex: k.secretHex));
      pairedTvs = [...pairedTvs.where((t) => t.publicKey != tv!.tv.publicKey), tv!.tv];
      await _secure.write(key: 'paired_tvs', value: jsonEncode(pairedTvs.map((t) => t.toJson()).toList()));
    } on LinkException catch (e) {
      status = e.message == 'wrong_code' ? L.t('Wrong code') : e.message;
    } catch (e) {
      status = '$e';
    }
    notifyListeners();
  }

  Future<void> reconnectTv() async {
    if (pairedTvs.isEmpty) return;
    _tvWanted = true;
    try {
      final k = await _linkKeys();
      _watchTv(await TvSender.connect(pairedTvs.last, senderName: _senderName, secretHex: k.secretHex));
      status = '';
    } catch (e) {
      status = '$e';
    }
    notifyListeners();
  }

  bool _tvWanted = true;
  bool _tvConnecting = false;
  Timer? _tvRetry;
  int _tvRetries = 0;

  /// Uses [s] as the TV link and reconnects by itself when the TV drops it (a paused app, a Wi-Fi blip, a TV
  /// restart) — the phone used to keep "sending" into a dead link until Reconnect was pressed.
  void _watchTv(TvSender s) {
    tv = s;
    _tvRetries = 0;
    s.onClosed = () {
      if (tv != s) return;
      tv = null;
      notifyListeners();
      _scheduleTvReconnect();
    };
    _startTvKeepAlive();
    _pushStyle();
  }

  void _scheduleTvReconnect() {
    if (!_tvWanted || pairedTvs.isEmpty || _tvRetry != null) return;
    final wait = Duration(seconds: [1, 2, 4, 8, 15][_tvRetries.clamp(0, 4)]);
    _tvRetries++;
    _tvRetry = Timer(wait, () async {
      _tvRetry = null;
      await ensureTv();
      if (tv == null) _scheduleTvReconnect();
    });
  }

  /// Connects to the paired TV when not connected (at launch, when the app comes back, after a drop).
  Future<void> ensureTv() async {
    if (!_tvWanted || pairedTvs.isEmpty || _tvConnecting) return;
    if (tv != null && !tv!.closed) return;
    _tvConnecting = true;
    try {
      final k = await _linkKeys();
      _watchTv(await TvSender.connect(pairedTvs.last, senderName: _senderName, secretHex: k.secretHex));
      notifyListeners();
    } catch (_) {
      // Not reachable right now: the retry timer tries again.
    } finally {
      _tvConnecting = false;
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) ensureTv();
  }

  Future<void> disconnectTv() async {
    _tvWanted = false;
    _tvRetry?.cancel();
    _tvRetry = null;
    final t = tv;
    tv = null;
    await t?.close();
    notifyListeners();
  }

  void setTvStyle({double? fontScale, bool? bottom, double? bgOpacity}) {
    tvFontScale = fontScale ?? tvFontScale;
    tvBottom = bottom ?? tvBottom;
    tvBgOpacity = bgOpacity ?? tvBgOpacity;
    _pushStyle();
    notifyListeners();
  }

  void _pushStyle() => tv?.setStyle(fontScale: tvFontScale, bottom: tvBottom, bgOpacity: tvBgOpacity, extra: display.tvStyle());

  List<Area> _rpAreas() => [for (final a in display.areas.where((a) => a.enabled)) Area(a.x, a.y, a.w, a.h)];

  /// Display options (TV look, history panel, timing): saved on the phone and sent to the TV.
  Future<void> setDisplay(DisplayPrefs p) async {
    final areasChanged = jsonEncode(p.toJson()['areas']) != jsonEncode(display.toJson()['areas']);
    display = p;
    _pushStyle();
    if (areasChanged && rpRunning) await RemotePlay.setArea(Area.subtitles, areas: _rpAreas());
    notifyListeners();
    await _secure.write(key: 'display', value: jsonEncode(p.toJson()));
  }

  // ── PS5 Remote Play (play on the TV; this phone reads the subtitles from the console's stream) ──

  String? psnAccountId;
  Registration? ps5;
  bool rpRunning = false;
  String rpStatus = '';
  String? rpLastSource;
  StreamSubscription<RpEvent>? _rpSub;
  Timer? _rpHide;
  Timer? _tvKeepAlive;

  /// LUGHA TV drops a link that is silent for 20 s; subtitles can be minutes apart.
  void _startTvKeepAlive() {
    _tvKeepAlive?.cancel();
    _tvKeepAlive = Timer.periodic(const Duration(seconds: 8), (_) {
      try {
        tv?.keepAlive();
      } catch (_) {
        tv = null;
        notifyListeners();
        _scheduleTvReconnect();
      }
      // Also covers a link that never came back after a retry gave up.
      if (tv == null) ensureTv();
    });
  }

  Future<void> loadRemotePlay() async {
    psnAccountId = await _secure.read(key: 'psn_account_id');
    final r = await _secure.read(key: 'ps5_registration');
    ps5 = r == null ? null : Registration.fromJson(r);
    notifyListeners();
  }

  Future<void> setPsnAccountId(String id) async {
    psnAccountId = id;
    await _secure.write(key: 'psn_account_id', value: id);
    notifyListeners();
  }

  Future<void> registerPs5(Console c, String pin) async {
    rpStatus = '';
    try {
      final r = await RemotePlay.register(host: c.host, psnAccountId: psnAccountId!, pin: pin, ps5: c.ps5);
      ps5 = r;
      await _secure.write(key: 'ps5_registration', value: r.toJson());
    } on RemotePlayException catch (e) {
      rpStatus = '$e';
    }
    notifyListeners();
  }

  Future<void> forgetPs5() async {
    await stopRemotePlay();
    ps5 = null;
    await _secure.delete(key: 'ps5_registration');
    notifyListeners();
  }

  /// Finds the registered PS5 (its address may have changed), wakes it if resting, and starts reading subtitles.
  Future<void> startRemotePlay({Area area = Area.subtitles}) async {
    final reg = ps5;
    if (reg == null) return;
    rpStatus = L.t('Connecting to PS5…');
    notifyListeners();
    try {
      final consoles = await RemotePlay.discover();
      final match = consoles.where((c) => c.host == reg.host).firstOrNull ?? (consoles.length == 1 ? consoles.first : null);
      var r = reg;
      if (match != null && match.host != reg.host) {
        r = reg.withHost(match.host);
        ps5 = r;
        await _secure.write(key: 'ps5_registration', value: r.toJson());
      }
      if (match != null && !match.ready) {
        await RemotePlay.wakeup(r);
        rpStatus = L.t('Waking the PS5…');
        notifyListeners();
        await Future<void>.delayed(const Duration(seconds: 12));
      }
      _tvWanted = true;
      unawaited(ensureTv());
      _rpSub ??= RemotePlay.events.listen(_onRp);
      await RemotePlay.start(r, area: area, areas: _rpAreas());
    } on RemotePlayException catch (e) {
      rpStatus = '$e';
    }
    notifyListeners();
  }

  Future<void> stopRemotePlay() async {
    if (!rpRunning && _rpSub == null) return;
    await RemotePlay.stop();
    await _rpSub?.cancel();
    _rpSub = null;
    rpRunning = false;
    rpStatus = '';
    tv?.clearAll();
    notifyListeners();
  }

  void _onRp(RpEvent e) {
    switch (e) {
      case RpConnected():
        rpRunning = true;
        rpStatus = L.t('Reading subtitles from the PS5');
      case RpPinRequest():
        rpStatus = L.t('The PS5 asks for its login passcode');
      case RpStopped(:final reason, :final error):
        rpRunning = false;
        rpStatus = error ? reason : '';
        _rpSub?.cancel();
        _rpSub = null;
        tv?.clear();
      case RpText(:final lines):
        _rpLast = e;
        _onSubtitleText(lines);
    }
    notifyListeners();
  }

  /// Recognized text → reviewed Arabic line → TV. Unknown text clears the TV after a short silence.
  void _onSubtitleText(List<String> lines) {
    final text = lines.join(' ').trim();
    if (text.isEmpty) {
      _gate.cancel();
      _rpHide ??= Timer(Duration(milliseconds: display.goneAfterMs), () {
        _rpHide = null;
        rpLastSource = null;
        tv?.clear();
      });
      return;
    }
    _rpHide?.cancel();
    _rpHide = null;
    if (text == rpLastSource) return;
    _gate.hold = Duration(milliseconds: display.stableMs);
    _gate.feed(lines);
  }

  /// The last recognized frame (lines with their areas and boxes), for sending each area's line to the TV.
  RpText? _rpLast;

  /// Text that held for the "wait for stable text" time.
  void _onStableText(List<String> lines) {
    final text = lines.join(' ').trim();
    if (text == rpLastSource) return;
    rpLastSource = text;
    notifyListeners();
    Hit? look(String q) => engine?.lookup(q);
    // Reading areas with their positions: every area's text is matched on its own and goes to the TV with its design.
    final ev = _rpLast;
    final areas = display.areas.where((a) => a.enabled).toList();
    if (ev != null && areas.isNotEmpty && ev.areas.length == lines.length && ev.boxes.length == lines.length && listEquals(ev.lines, lines)) {
      _showByArea(ev, areas, look);
      return;
    }
    final f = findLine(text, look);
    if (f != null) {
      final target = f.counter == null ? f.hit.target : '${f.hit.target} ${f.counter}';
      final speaker = f.hit.speaker ?? f.speaker;
      history.insert(0, Line(text, target, speaker, DateTime.now(), entryId: f.hit.entryId, packageId: f.hit.packageId));
      if (history.length > 50) history.removeLast();
      tv?.show(target, speaker: speaker);
      return;
    }
    // A menu or list: each line (and each prompt in a row joined by button icons) is its own entry.
    final known = [
      for (final l in lines.take(12))
        for (final part in glyphParts(l).length > 1 ? glyphParts(l) : [l.trim()]) findLine(part, look),
    ].whereType<Found<Hit>>().map((f) => f.counter == null ? f.hit.target : '${f.hit.target} ${f.counter}').toList();
    if (known.isEmpty) {
      tv?.clear();
      return;
    }
    tv?.show(known.toSet().join('  ·  '));
  }

  void _showByArea(RpText ev, List<ReadingArea> areas, Hit? Function(String) look) {
    // A subtitle of two lines whose second line strays into a neighbouring area is still one paragraph: lines right
    // under each other (gap under one line height, overlapping horizontally) take the area of the paragraph's first line.
    final area = [...ev.areas];
    for (var n = 1; n < ev.lines.length; n++) {
      final p = ev.boxes[n - 1], b = ev.boxes[n];
      final gap = b[1] - (p[1] + p[3]);
      final overlap = math.min(p[0] + p[2], b[0] + b[2]) - math.max(p[0], b[0]);
      if (gap < math.max(p[3], b[3]) && overlap > 0) area[n] = area[n - 1];
    }
    final groups = <int, List<int>>{};
    for (var n = 0; n < ev.lines.length; n++) {
      groups.putIfAbsent(area[n], () => []).add(n);
    }
    final items = <Map<String, dynamic>>[];
    final shown = <String>[];
    for (final MapEntry(key: a, value: idx) in groups.entries) {
      if (a < 0 || a >= areas.length) continue;
      final area = areas[a];
      final text = fromSpeaker([for (final n in idx) ev.lines[n]]).join(' ').trim();
      String? target, speaker;
      final f = findLine(text, look);
      if (f != null) {
        target = f.counter == null ? f.hit.target : '${f.hit.target} ${f.counter}';
        speaker = f.hit.speaker ?? f.speaker;
        history.insert(0, Line(text, target, speaker, DateTime.now(), entryId: f.hit.entryId, packageId: f.hit.packageId));
        if (history.length > 50) history.removeLast();
      } else {
        // A list or a row of prompts: each line (or part between button icons) on its own.
        final known = [
          for (final n in idx.take(12))
            for (final part in glyphParts(ev.lines[n]).length > 1 ? glyphParts(ev.lines[n]) : [ev.lines[n].trim()]) findLine(part, look),
        ].whereType<Found<Hit>>().map((f) => f.counter == null ? f.hit.target : '${f.hit.target} ${f.counter}').toSet();
        if (known.isEmpty) continue;
        target = known.join('\n');
      }
      final bs = [for (final n in idx) ev.boxes[n]];
      final x = bs.map((b) => b[0]).reduce(math.min), y = bs.map((b) => b[1]).reduce(math.min);
      final r = bs.map((b) => b[0] + b[2]).reduce(math.max), b = bs.map((b) => b[1] + b[3]).reduce(math.max);
      items.add({'text': target, 'speaker': ?speaker, 'x': x, 'y': y, 'w': r - x, 'h': b - y, 'lines': idx.length, 'area': area.tvStyle()});
      shown.add(target);
    }
    if (items.isEmpty) {
      tv?.clear();
      RemotePlay.caption('');
      return;
    }
    tv?.showItems(shown.join('\n'), items);
    RemotePlay.caption(shown.join('\n'));
  }

  Future<void> sendTestLine() async {
    final line = history.firstWhere((l) => l.target != null, orElse: () => Line('', 'نطلع مع الفجر. وأحد ما يتكلم عن الليلة.', 'ريم', DateTime.now()));
    await tv?.show(line.target!, speaker: line.speaker);
  }
}
