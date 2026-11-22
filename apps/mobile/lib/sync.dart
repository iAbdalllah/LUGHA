import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:lugha_engine/lugha_engine.dart';

import 'api.dart';

/// Installed packages live in `<app support>/packages/<package_id>/` (engine layout).
class PackageStore {
  PackageStore(this.root) {
    root.createSync(recursive: true);
  }
  final Directory root;

  String dirFor(String packageId) => '${root.path}/$packageId';

  Map<String, String> installed() {
    final out = <String, String>{};
    for (final d in root.listSync().whereType<Directory>()) {
      if (d.path.contains('.')) continue; // *.installing / *.old
      final m = File('${d.path}/manifest.json');
      if (!m.existsSync()) continue;
      final j = jsonDecode(m.readAsStringSync()) as Map<String, dynamic>;
      out[j['package_id'] as String] = j['version'] as String;
    }
    return out;
  }

  void remove(String packageId) {
    final d = Directory(dirFor(packageId));
    if (d.existsSync()) d.deleteSync(recursive: true);
  }
}

class SyncReport {
  final installed = <String>[];
  final updated = <String>[];
  final removed = <String>[];
  final errors = <String>[];
  List<LoadedPackage> loaded = [];
}

/// Same job as the Mac's SyncService: install purchases, apply updates (delta with full fallback),
/// refresh licenses, drop refunded packages, reload the engine.
class PackageSync {
  PackageSync(this.api, this.engine, this.store, this.appVersion);
  final ApiClient api;
  final Engine engine;
  final PackageStore store;
  final String appVersion;

  Future<SyncReport> sync(String deviceId) async {
    final r = SyncReport();
    try {
      final current = store.installed();
      for (final item in await api.library()) {
        final latest = item.latestVersion;
        if (latest == null || current[item.package] == latest) continue;
        try {
          await installPackage(item.package, deviceId, from: current[item.package]);
          (current.containsKey(item.package) ? r.updated : r.installed).add(item.package);
        } catch (e) {
          r.errors.add('${item.package}: $e');
        }
      }
      final beat = await api.heartbeat(deviceId, appVersion, store.installed());
      for (final gone in beat.revoked) {
        store.remove(gone);
        r.removed.add(gone);
      }
      for (final e in (await api.refreshLicenses(deviceId)).entries) {
        try {
          engine.refreshLicense(packageDir: store.dirFor(e.key), licenseJson: e.value);
        } catch (_) {}
      }
    } catch (e) {
      r.errors.add('$e');
    }
    try {
      r.loaded = engine.load(store.root.path);
    } catch (e) {
      r.errors.add('$e');
    }
    return r;
  }

  Future<void> installPackage(String package, String deviceId, {String? from}) async {
    var grant = await api.authorizeDownload(package, deviceId, fromVersion: from);
    var file = await _download(grant);
    try {
      engine.install(packageDir: store.dirFor(package), lpkPath: file.path, licenseJson: grant.licenseJson);
    } on EngineException {
      if (grant.kind != 'delta') rethrow;
      file.deleteSync();
      grant = await api.authorizeDownload(package, deviceId);
      file = await _download(grant);
      engine.install(packageDir: store.dirFor(package), lpkPath: file.path, licenseJson: grant.licenseJson);
    } finally {
      if (file.existsSync()) file.deleteSync();
    }
    await api.completeDownload(grant.id, grant.sha256);
  }

  Future<File> _download(DownloadGrant g) async {
    final bytes = await api.fetch(g.url);
    if (sha256.convert(bytes).toString() != g.sha256) throw EngineException('checksum mismatch');
    final f = File('${Directory.systemTemp.path}/${g.id}.lpk');
    await f.writeAsBytes(bytes, flush: true);
    return f;
  }
}
