import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:lugha_mobile/api.dart';

void main() {
  test('sends auth + language headers and parses the library', () async {
    late http.Request seen;
    final api = ApiClient(
      baseUrl: Uri.parse('http://x/'),
      token: 't0k',
      language: 'ar',
      client: MockClient((req) async {
        seen = req;
        return http.Response(jsonEncode({'data': [{'package': 'sandline-sa-full', 'name': 'التعريب الكامل', 'game': {'slug': 'sandline', 'title': 'خط الرمال'}, 'latest_version': '1.0.0'}]}), 200,
            headers: {'content-type': 'application/json; charset=utf-8'});
      }),
    );
    final lib = await api.library();
    expect(seen.url.toString(), 'http://x/api/v1/library');
    expect(seen.headers['Authorization'], 'Bearer t0k');
    expect(seen.headers['Accept-Language'], 'ar');
    expect(lib.single.package, 'sandline-sa-full');
    expect(lib.single.gameTitle, 'خط الرمال');
  });

  test('problem+json errors become ApiException with the code', () async {
    final api = ApiClient(baseUrl: Uri.parse('http://x/'), client: MockClient((_) async => http.Response(jsonEncode({'code': 'device_limit_reached', 'detail': 'Device limit reached.'}), 409)));
    expect(
      () => api.registerDevice(kind: 'android', name: 'p', publicKey: 'k', model: 'm', appVersion: '0.1.0'),
      throwsA(isA<ApiException>().having((e) => e.code, 'code', 'device_limit_reached').having((e) => e.status, 'status', 409)),
    );
  });

  test('download grant carries the license as JSON text', () async {
    final api = ApiClient(baseUrl: Uri.parse('http://x/'), client: MockClient((_) async => http.Response(jsonEncode({
          'download_id': 'd1', 'url': 'http://x/f', 'kind': 'delta', 'version': '1.1.0', 'sha256': 'ab', 'license': {'v': 1, 'payload': 'p', 'key_id': 'k', 'sig': 's'}}), 201)));
    final g = await api.authorizeDownload('p', 'dev', fromVersion: '1.0.0');
    expect(g.kind, 'delta');
    expect(jsonDecode(g.licenseJson)['sig'], 's');
  });
}
