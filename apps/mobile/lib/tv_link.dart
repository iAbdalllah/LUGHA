import 'dart:async';
import 'dart:convert';
import 'dart:ffi';
import 'dart:io';
import 'dart:typed_data';

import 'package:lugha_engine/lugha_engine.dart';
import 'package:multicast_dns/multicast_dns.dart';

/// LUGHA Link sender (docs/05): TCP framing + pairing + Noise session; crypto from the Rust engine.
class Frame {
  static const pair1 = 0x01, pair2 = 0x02, pair3 = 0x03, pairOk = 0x04, pairFail = 0x0F, hs1 = 0x10, hs2 = 0x11, data = 0x20;
  static const defaultPort = 47800;
}

class LinkException implements Exception {
  LinkException(this.message);
  final String message;
  @override
  String toString() => message;
}

class PairedTv {
  PairedTv(this.publicKey, this.name, this.host, this.port);
  PairedTv.fromJson(Map<String, dynamic> j) : this(j['public_key'] as String, j['name'] as String, j['host'] as String, j['port'] as int);
  final String publicKey;
  final String name;
  String host;
  int port;
  Map<String, dynamic> toJson() => {'public_key': publicKey, 'name': name, 'host': host, 'port': port};
}

class _FramedSocket {
  _FramedSocket(this._s) {
    _sub = _s.listen((d) {
      _buf.add(d);
      _pump();
    }, onDone: () => _fail(LinkException('connection closed')), onError: (Object e) => _fail(LinkException('$e')));
  }

  final Socket _s;
  late final StreamSubscription<Uint8List> _sub;
  final _buf = BytesBuilder(copy: false);
  final _waiters = <Completer<(int, Uint8List)>>[];
  final _ready = <(int, Uint8List)>[];
  Object? _error;

  static Future<_FramedSocket> connect(String host, int port) async {
    final s = await Socket.connect(host, port, timeout: const Duration(seconds: 6));
    s.setOption(SocketOption.tcpNoDelay, true);
    return _FramedSocket(s);
  }

  void _pump() {
    var bytes = _buf.takeBytes();
    var off = 0;
    while (bytes.length - off >= 4) {
      final len = ByteData.sublistView(bytes, off, off + 4).getUint32(0);
      if (len < 1 || len > 65536) return _fail(LinkException('bad frame'));
      if (bytes.length - off - 4 < len) break;
      final type = bytes[off + 4];
      final payload = Uint8List.sublistView(bytes, off + 5, off + 4 + len);
      off += 4 + len;
      final f = (type, Uint8List.fromList(payload));
      if (_waiters.isNotEmpty) {
        _waiters.removeAt(0).complete(f);
      } else {
        _ready.add(f);
      }
    }
    if (off < bytes.length) _buf.add(Uint8List.sublistView(bytes, off));
  }

  void _fail(Object e) {
    _error ??= e;
    for (final w in _waiters) {
      if (!w.isCompleted) w.completeError(e);
    }
    _waiters.clear();
  }

  Future<(int, Uint8List)> read() {
    if (_ready.isNotEmpty) return Future.value(_ready.removeAt(0));
    if (_error != null) return Future.error(_error!);
    final c = Completer<(int, Uint8List)>();
    _waiters.add(c);
    return c.future.timeout(const Duration(seconds: 15));
  }

  void send(int type, List<int> payload) {
    final head = ByteData(5)
      ..setUint32(0, payload.length + 1)
      ..setUint8(4, type);
    _s.add(head.buffer.asUint8List());
    _s.add(payload);
  }

  Future<void> close() async {
    await _sub.cancel();
    _s.destroy();
  }
}

class TvSender {
  TvSender._(this.tv, this._sock, this._crypto);

  final PairedTv tv;
  final _FramedSocket _sock;
  final LinkCrypto _crypto;
  Pointer<Void>? _session;
  int _id = 1;

  static Future<TvSender> pair({required String host, required int port, required String code, required String name, required String senderName, required String secretHex}) async {
    final crypto = LinkCrypto();
    final sock = await _FramedSocket.connect(host, port);
    final (pairing, p1) = crypto.pairStart(code, secretHex);
    sock.send(Frame.pair1, p1);
    final (t2, p2) = await sock.read();
    if (t2 != Frame.pair2) {
      crypto.pairingFree(pairing);
      await sock.close();
      throw LinkException(t2 == Frame.pairFail ? 'locked' : 'unexpected frame');
    }
    List<int> p3;
    String peer;
    try {
      (p3, peer) = crypto.pairFinish(pairing, p2);
    } on EngineException {
      sock.send(Frame.pair3, List.filled(48, 0)); // let the TV count the failed attempt
      await sock.close();
      throw LinkException('wrong_code');
    }
    sock.send(Frame.pair3, p3);
    final (t4, _) = await sock.read();
    if (t4 != Frame.pairOk) {
      await sock.close();
      throw LinkException('wrong_code');
    }
    final s = TvSender._(PairedTv(peer, name, host, port), sock, crypto);
    await s._handshake(secretHex, senderName);
    return s;
  }

  static Future<TvSender> connect(PairedTv tv, {required String senderName, required String secretHex}) async {
    final s = TvSender._(tv, await _FramedSocket.connect(tv.host, tv.port), LinkCrypto());
    await s._handshake(secretHex, senderName);
    return s;
  }

  Future<void> _handshake(String secretHex, String senderName) async {
    final (session, hs1) = _crypto.sessionStart(secretHex, tv.publicKey);
    _session = session;
    _sock.send(Frame.hs1, hs1);
    final (t, hs2) = await _sock.read();
    if (t != Frame.hs2) throw LinkException('TV refused the session');
    _crypto.sessionComplete(session, hs2);
    _send({'t': 'hello', 'app': 'lugha-mobile', 'app_version': '0.1.0', 'sender_name': senderName});
    await _receive(); // welcome
  }

  void _send(Map<String, dynamic> m) => _sock.send(Frame.data, _crypto.encrypt(_session!, jsonEncode(m)));

  Future<Map<String, dynamic>> _receive() async {
    final (t, body) = await _sock.read();
    if (t != Frame.data) throw LinkException('unexpected frame');
    return jsonDecode(_crypto.decrypt(_session!, body)) as Map<String, dynamic>;
  }

  /// Shows a line on the TV; with [waitForAck] returns the round trip until painted (ms).
  Future<double?> show(String text, {String? speaker, bool waitForAck = false}) async {
    final id = _id++;
    final sw = Stopwatch()..start();
    _send({'t': 'sub.show', 'id': id, 'sid': id, 'text': text, 'speaker': ?speaker, 'dir': 'rtl', 'ack': waitForAck});
    if (!waitForAck) return null;
    while (true) {
      final r = await _receive();
      if (r['t'] == 'ack' && r['id'] == id) return sw.elapsedMicroseconds / 1000;
    }
  }

  /// Placed pieces: each with where its English is (fractions of the picture) and its reading area's design.
  /// [text] is what older TVs show.
  void showItems(String text, List<Map<String, dynamic>> items, {String? speaker}) {
    final id = _id++;
    _send({'t': 'sub.show', 'id': id, 'sid': id, 'text': text, 'speaker': ?speaker, 'dir': 'rtl', 'ack': false, 'items': items});
  }

  void clear() => _send({'t': 'sub.clear', 'id': _id++});

  /// Translation stopped: the TV empties the screen (no line kept for reading, no history).
  void clearAll() => _send({'t': 'sub.clear', 'id': _id++, 'all': true});

  /// [extra]: display/history/fade_ms/read_hold (DisplayPrefs.tvStyle); older TVs ignore them.
  void setStyle({required double fontScale, required bool bottom, required double bgOpacity, Map<String, dynamic> extra = const {}}) =>
      _send({'t': 'style.set', 'font_scale': fontScale, 'position_y': bottom ? 0.9 : 0.1, 'bg_opacity': bgOpacity, ...extra});

  /// LUGHA TV drops a link that is silent for 20 s; senders call this every few seconds between subtitles.
  void keepAlive() => _send({'t': 'ping', 'id': _id++});

  Future<bool> ping() async {
    _send({'t': 'ping', 'id': _id++});
    return (await _receive())['t'] == 'pong';
  }

  Future<void> close() async {
    try {
      _send({'t': 'bye', 'reason': 'closed'});
    } catch (_) {}
    await _sock.close();
    if (_session != null) _crypto.sessionFree(_session!);
    _session = null;
  }
}

class FoundTv {
  FoundTv(this.name, this.host, this.port);
  final String name;
  final String host;
  final int port;
}

/// Bonjour/mDNS browse for `_lugha._tcp`.
Future<List<FoundTv>> discoverTvs({Duration timeout = const Duration(seconds: 3)}) async {
  final client = MDnsClient();
  final found = <String, FoundTv>{};
  try {
    await client.start();
    await for (final ptr in client.lookup<PtrResourceRecord>(ResourceRecordQuery.serverPointer('_lugha._tcp.local')).timeout(timeout, onTimeout: (s) => s.close())) {
      await for (final srv in client.lookup<SrvResourceRecord>(ResourceRecordQuery.service(ptr.domainName)).timeout(timeout, onTimeout: (s) => s.close())) {
        await for (final ip in client.lookup<IPAddressResourceRecord>(ResourceRecordQuery.addressIPv4(srv.target)).timeout(timeout, onTimeout: (s) => s.close())) {
          final name = ptr.domainName.split('._lugha').first;
          found['${ip.address.address}:${srv.port}'] = FoundTv(name, ip.address.address, srv.port);
        }
      }
    }
  } catch (_) {
    // mDNS unavailable (e.g. emulator network) — the user can still type the TV's address.
  } finally {
    client.stop();
  }
  return found.values.toList();
}
