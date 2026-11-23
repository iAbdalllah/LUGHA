// PSN sign-in for Remote Play registration: the player signs in on Sony's own page (in a WebView — LUGHA never sees
// the password), Sony redirects back with a one-time code, and the code is exchanged for the account's user id,
// which the PS5 needs (as 8 little-endian bytes, base64) to link this phone. Same flow and client as chiaki-ng
// (gui/include/psnaccountid.h, AGPL-3.0); this file is AGPL-3.0 as part of the Remote Play feature.
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:webview_flutter/webview_flutter.dart';

import 'l10n.dart';

class Psn {
  // PS Remote Play's public OAuth client, as used by chiaki-ng.
  static const _clientId = 'ba495a24-818c-472b-b12d-ff231c1b5745';
  static const _clientSecret = 'mvaiZkRsAsI1IBkY';
  static const redirect = 'https://remoteplay.dl.playstation.net/remoteplay/redirect';
  static const _scope =
      'psn:clientapp referenceDataService:countryConfig.read pushNotification:webSocket.desktop.connect sessionManager:remotePlaySession.system.update';
  static const _tokenUrl = 'https://auth.api.sonyentertainmentnetwork.com/2.0/oauth/token';

  static Uri authorizeUrl() => Uri.parse('https://auth.api.sonyentertainmentnetwork.com/2.0/oauth/authorize').replace(queryParameters: {
        'service_entity': 'urn:service-entity:psn',
        'response_type': 'code',
        'client_id': _clientId,
        'redirect_uri': redirect,
        'scope': _scope,
        'request_locale': 'en_US',
        'ui': 'pr',
        'service_logo': 'ps',
        'layout_type': 'popup',
        'smcid': 'remoteplay',
        'prompt': 'always',
        'PlatformPrivacyWs1': 'minimal',
      });

  static String get _basic => 'Basic ${base64Encode(utf8.encode('$_clientId:$_clientSecret'))}';

  /// Exchanges the redirect [code] for the account id the PS5 expects (base64 of the user id, 8 bytes little-endian).
  static Future<String> accountIdFromCode(String code, {http.Client? client}) async {
    final c = client ?? http.Client();
    final tok = await c.post(Uri.parse(_tokenUrl),
        headers: {'Authorization': _basic, 'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'grant_type=authorization_code&code=${Uri.encodeQueryComponent(code)}&scope=${Uri.encodeQueryComponent(_scope)}'
            '&redirect_uri=${Uri.encodeQueryComponent(redirect)}');
    if (tok.statusCode != 200) throw Exception('PSN token: HTTP ${tok.statusCode}');
    final access = (jsonDecode(tok.body) as Map)['access_token'] as String;
    final info = await c.get(Uri.parse('$_tokenUrl/$access'), headers: {'Authorization': _basic, 'Accept': 'application/json'});
    if (info.statusCode != 200) throw Exception('PSN account: HTTP ${info.statusCode}');
    return accountIdFromUserId((jsonDecode(info.body) as Map)['user_id'].toString());
  }

  /// PSN user id (decimal, may exceed 2^63) → 8 bytes little-endian → base64.
  static String accountIdFromUserId(String userId) {
    var v = BigInt.parse(userId);
    final bytes = List<int>.generate(8, (_) {
      final b = (v & BigInt.from(0xff)).toInt();
      v = v >> 8;
      return b;
    });
    return base64Encode(bytes);
  }
}

/// Sony's sign-in page; pops with the account id (base64) or null if closed.
class PsnSignInPage extends StatefulWidget {
  const PsnSignInPage({super.key});
  @override
  State<PsnSignInPage> createState() => _PsnSignInPageState();
}

class _PsnSignInPageState extends State<PsnSignInPage> {
  late final WebViewController _web;
  bool _exchanging = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _web = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(NavigationDelegate(onNavigationRequest: (req) {
        if (!req.url.startsWith(Psn.redirect)) return NavigationDecision.navigate;
        final code = Uri.parse(req.url).queryParameters['code'];
        if (code != null) _finish(code);
        return NavigationDecision.prevent;
      }))
      ..loadRequest(Psn.authorizeUrl());
  }

  Future<void> _finish(String code) async {
    if (_exchanging) return;
    setState(() => _exchanging = true);
    try {
      final id = await Psn.accountIdFromCode(code);
      if (mounted) Navigator.of(context).pop(id);
    } catch (e) {
      if (mounted) setState(() { _exchanging = false; _error = '$e'; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(L.t('Sign in to PlayStation Network'))),
      body: Stack(children: [
        WebViewWidget(controller: _web),
        if (_exchanging) const ColoredBox(color: Color(0xAA000000), child: Center(child: CircularProgressIndicator())),
        if (_error != null)
          Positioned(left: 16, right: 16, bottom: 16, child: Material(color: Colors.red.shade900, borderRadius: BorderRadius.circular(12),
              child: Padding(padding: const EdgeInsets.all(12), child: Text(_error!)))),
      ]),
    );
  }
}
