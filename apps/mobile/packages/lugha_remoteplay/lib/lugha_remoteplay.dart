/// PS5 Remote Play for LUGHA: find the console, register this phone with it once (PSN account id + the PIN the PS5
/// shows), then open a Remote Play session whose video is never shown — each frame's subtitle area is read with
/// on-device text recognition and the recognized lines arrive as [RpText] events. The player keeps playing on the TV.
///
/// Built on chiaki-ng (AGPL-3.0). See LICENSE.
library;

import 'dart:async';
import 'dart:convert';

import 'package:flutter/services.dart';

/// A PlayStation found on the local network.
class Console {
  Console({required this.host, required this.name, required this.ready, required this.ps5, this.runningApp, this.hostId});
  factory Console.fromMap(Map m) => Console(
        host: m['host'] as String,
        name: (m['name'] as String?) ?? 'PlayStation',
        ready: m['state'] == 'ready',
        ps5: m['ps5'] == true,
        runningApp: m['app'] as String?,
        hostId: m['id'] as String?,
      );
  final String host;
  final String name;

  /// false = standby (rest mode): [RemotePlay.wakeup] it first.
  final bool ready;
  final bool ps5;
  final String? runningApp;
  final String? hostId;
}

/// What a PS5 hands back when this phone is registered. Secret: store it in the keystore.
class Registration {
  Registration({required this.host, required this.ps5, required this.nickname, required this.registKey, required this.rpKey, required this.mac});
  factory Registration.fromMap(Map m) => Registration(
        host: m['host'] as String,
        ps5: m['ps5'] != false,
        nickname: (m['nickname'] as String?) ?? 'PS5',
        registKey: base64Decode(m['regist_key'] as String),
        rpKey: base64Decode(m['rp_key'] as String),
        mac: (m['mac'] as String?) ?? '',
      );
  factory Registration.fromJson(String s) => Registration.fromMap(jsonDecode(s) as Map);

  /// Last known address; discovery may report a new one under the same [mac].
  final String host;
  final bool ps5;
  final String nickname;
  final Uint8List registKey;

  /// Session key ("morning"). [registKey] (hex text) doubles as the wake-up credential.
  final Uint8List rpKey;
  final String mac;

  Registration withHost(String h) => Registration(host: h, ps5: ps5, nickname: nickname, registKey: registKey, rpKey: rpKey, mac: mac);

  Map<String, Object> toMap() => {
        'host': host,
        'ps5': ps5,
        'nickname': nickname,
        'regist_key': base64Encode(registKey),
        'rp_key': base64Encode(rpKey),
        'mac': mac,
      };
  String toJson() => jsonEncode(toMap());
}

/// Fractions of the video frame (0–1, origin top-left).
class Area {
  const Area(this.x, this.y, this.w, this.h);
  final double x, y, w, h;

  /// Where most games put dialogue: the lower band, centred.
  static const subtitles = Area(0.08, 0.70, 0.84, 0.26);
  Map<String, double> toMap() => {'x': x, 'y': y, 'w': w, 'h': h};
}

sealed class RpEvent {}

/// The session is up and frames are being read.
class RpConnected extends RpEvent {}

/// Text recognized in the subtitle area of one frame, top to bottom (already de-duplicated: sent only on change).
class RpText extends RpEvent {
  RpText(this.lines);
  final List<String> lines;
}

/// The PS5 asked for its login passcode (the account has one set): call [RemotePlay.setLoginPin].
class RpPinRequest extends RpEvent {
  RpPinRequest(this.incorrect);
  final bool incorrect;
}

class RpStopped extends RpEvent {
  RpStopped(this.reason, {this.error = false});
  final String reason;
  final bool error;
}

class RemotePlayException implements Exception {
  RemotePlayException(this.code, [this.message]);
  final String code;
  final String? message;
  @override
  String toString() => message ?? code;
}

class RemotePlay {
  static const _ch = MethodChannel('app.lugha/remoteplay');
  static const _ev = EventChannel('app.lugha/remoteplay/events');

  static Stream<RpEvent>? _events;

  /// Session events. Subscribe before [start].
  static Stream<RpEvent> get events => _events ??= _ev.receiveBroadcastStream().map((e) {
        final m = e as Map;
        return switch (m['type']) {
          'connected' => RpConnected(),
          'text' => RpText((m['lines'] as List).cast<String>()),
          'pin' => RpPinRequest(m['incorrect'] == true),
          _ => RpStopped((m['reason'] as String?) ?? '', error: m['error'] == true),
        };
      }).asBroadcastStream();

  static Future<T?> _call<T>(String method, [Map<String, Object?>? args]) async {
    try {
      return await _ch.invokeMethod<T>(method, args);
    } on PlatformException catch (e) {
      throw RemotePlayException(e.code, e.message);
    }
  }

  /// Consoles answering on the local network within [timeout].
  static Future<List<Console>> discover({Duration timeout = const Duration(seconds: 3)}) async {
    final list = await _call<List>('discover', {'timeoutMs': timeout.inMilliseconds}) ?? const [];
    return list.map((m) => Console.fromMap(m as Map)).toList();
  }

  /// Register this phone with the console. [psnAccountId] is the base64 account id from the PSN sign-in;
  /// [pin] is the 8-digit code the PS5 shows under Settings › System › Remote Play › Link Device.
  static Future<Registration> register({required String host, required String psnAccountId, required String pin, bool ps5 = true}) async {
    final m = await _call<Map>('register', {'host': host, 'accountId': psnAccountId, 'pin': pin, 'ps5': ps5});
    return Registration.fromMap(m!).withHost(host);
  }

  /// Wake a console from rest mode.
  static Future<void> wakeup(Registration r) => _call('wakeup', {'host': r.host, 'registKey': base64Encode(r.registKey), 'ps5': r.ps5});

  /// Open the Remote Play session and start reading [area] of each frame (no video is shown, no audio played).
  static Future<void> start(Registration r, {Area area = Area.subtitles, int fps = 4}) => _call('start', {
        'host': r.host,
        'ps5': r.ps5,
        'registKey': base64Encode(r.registKey),
        'rpKey': base64Encode(r.rpKey),
        'area': area.toMap(),
        'fps': fps,
      });

  static Future<void> setArea(Area area) => _call('setArea', {'area': area.toMap()});
  static Future<void> setLoginPin(String pin) => _call('setLoginPin', {'pin': pin});
  static Future<void> stop() => _call('stop');
}
