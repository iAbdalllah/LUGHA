import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

class ApiException implements Exception {
  ApiException(this.status, this.code, [this.detail]);
  final int status;
  final String code;
  final String? detail;
  @override
  String toString() => detail ?? code;
}

class LibraryItem {
  LibraryItem.fromJson(Map<String, dynamic> j)
      : package = j['package'] as String,
        name = j['name'] as String,
        gameTitle = (j['game'] as Map)['title'] as String,
        latestVersion = j['latest_version'] as String?;
  final String package;
  final String name;
  final String gameTitle;
  final String? latestVersion;
}

class DeviceInfo {
  DeviceInfo.fromJson(Map<String, dynamic> j)
      : id = j['id'] as String,
        name = j['name'] as String,
        kind = j['kind'] as String,
        lastSeen = j['last_seen_at'] == null ? null : DateTime.parse(j['last_seen_at'] as String);
  final String id;
  final String name;
  final String kind;
  final DateTime? lastSeen;
}

class DownloadGrant {
  DownloadGrant(this.id, this.url, this.kind, this.version, this.sha256, this.licenseJson);
  final String id;
  final Uri url;
  final String kind;
  final String version;
  final String sha256;
  final String licenseJson;
}

/// LUGHA REST API v1 (docs/03-api.md) — what the phone app needs.
class ApiClient {
  ApiClient({required this.baseUrl, this.token, this.language = 'ar', http.Client? client}) : _http = client ?? http.Client();

  Uri baseUrl;
  String? token;
  String language;
  final http.Client _http;

  Uri _u(String path) => baseUrl.resolve('api/v1/$path');

  Future<dynamic> _req(String method, String path, {Map<String, dynamic>? body}) async {
    final req = http.Request(method, _u(path))
      ..headers.addAll({
        'Accept': 'application/json',
        'Accept-Language': language,
        if (token != null) 'Authorization': 'Bearer $token',
        if (body != null) 'Content-Type': 'application/json',
      });
    if (body != null) req.body = jsonEncode(body);
    final res = await http.Response.fromStream(await _http.send(req));
    final data = res.body.isEmpty ? null : jsonDecode(res.body);
    if (res.statusCode < 200 || res.statusCode >= 300) {
      final m = data is Map ? data : const {};
      throw ApiException(res.statusCode, (m['code'] ?? 'http_${res.statusCode}') as String, _message(m));
    }
    return data;
  }

  /// Problem detail, or the first validation message (`{"errors": {"field": ["…"]}}`).
  static String? _message(Map m) {
    final errors = m['errors'];
    if (errors is Map) {
      for (final v in errors.values) {
        if (v is List && v.isNotEmpty) return '${v.first}';
      }
    }
    return (m['detail'] ?? m['message']) as String?;
  }

  /// Report a wrong line to the reviewers. [entryId] is the line id from the package (`Hit.entryId`).
  Future<void> reportLine({required String package, String? version, required int entryId, required String reason, String? note, required String source}) =>
      _req('POST', 'translation-reports', body: {
        'package': package,
        if (version != null) 'version': version,
        'entry_id': entryId,
        'reason': reason,
        if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
        'source': source,
      });

  Future<({String token, String name})> login(String email, String password, String deviceName) async {
    final j = await _req('POST', 'auth/login', body: {'email': email, 'password': password, 'device_name': deviceName});
    return (token: j['token'] as String, name: (j['user'] as Map)['name'] as String);
  }

  Future<String> me() async => ((await _req('GET', 'me'))['user'] as Map)['name'] as String;

  Future<String> trustJson() async => jsonEncode(await _req('GET', 'trust'));

  Future<String> registerDevice({required String kind, required String name, required String publicKey, required String model, required String appVersion}) async {
    final j = await _req('POST', 'devices', body: {'kind': kind, 'name': name, 'public_key': publicKey, 'model': model, 'app_version': appVersion, 'os_version': Platform.operatingSystemVersion});
    return (j['data'] as Map)['id'] as String;
  }

  Future<List<DeviceInfo>> devices() async => ((await _req('GET', 'devices'))['data'] as List).map((d) => DeviceInfo.fromJson(d as Map<String, dynamic>)).toList();

  Future<void> removeDevice(String id) => _req('DELETE', 'devices/$id');

  Future<List<LibraryItem>> library() async => ((await _req('GET', 'library'))['data'] as List).map((d) => LibraryItem.fromJson(d as Map<String, dynamic>)).toList();

  Future<DownloadGrant> authorizeDownload(String package, String device, {String? fromVersion}) async {
    final j = await _req('POST', 'downloads', body: {'package': package, 'device': device, if (fromVersion != null) 'from_version': fromVersion});
    return DownloadGrant(j['download_id'] as String, Uri.parse(j['url'] as String), j['kind'] as String, j['version'] as String, j['sha256'] as String, jsonEncode(j['license']));
  }

  Future<List<int>> fetch(Uri url) async {
    final res = await _http.get(url);
    if (res.statusCode != 200) throw ApiException(res.statusCode, 'download_failed');
    return res.bodyBytes;
  }

  Future<void> completeDownload(String id, String sha256) => _req('POST', 'downloads/$id/complete', body: {'sha256': sha256});

  Future<({List<String> revoked})> heartbeat(String device, String appVersion, Map<String, String> installed) async {
    final j = await _req('POST', 'devices/$device/heartbeat', body: {
      'app_version': appVersion,
      'installations': [for (final e in installed.entries) {'package': e.key, 'version': e.value}],
    });
    return (revoked: (j['revoked'] as List).cast<String>());
  }

  Future<Map<String, String>> refreshLicenses(String device) async {
    final j = await _req('POST', 'devices/$device/licenses');
    return {for (final e in ((j['licenses'] as Map?) ?? {}).entries) e.key as String: jsonEncode(e.value)};
  }
}
