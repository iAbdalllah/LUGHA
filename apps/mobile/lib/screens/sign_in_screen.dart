import 'package:flutter/material.dart';

import '../l10n.dart';
import '../state.dart';

class SignInScreen extends StatefulWidget {
  const SignInScreen({super.key, required this.app});
  final AppState app;
  @override
  State<SignInScreen> createState() => _SignInScreenState();
}

class _SignInScreenState extends State<SignInScreen> {
  late final _server = TextEditingController(text: widget.app.api.baseUrl.toString());
  final _email = TextEditingController();
  final _password = TextEditingController();

  @override
  Widget build(BuildContext context) {
    final app = widget.app;
    return Scaffold(
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.all(24), children: [
          const SizedBox(height: 32),
          Row(children: [
            Container(width: 48, height: 48, alignment: Alignment.center, decoration: BoxDecoration(color: const Color(0xFFE9B44C), borderRadius: BorderRadius.circular(14)),
                child: const Text('ل', style: TextStyle(fontSize: 26, fontWeight: FontWeight.bold, color: Color(0xFF1A1405)))),
            const SizedBox(width: 12),
            Text(L.t('LUGHA'), style: Theme.of(context).textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.bold)),
          ]),
          const SizedBox(height: 32),
          TextField(key: const Key('email'), controller: _email, keyboardType: TextInputType.emailAddress, textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: L.t('Email'))),
          const SizedBox(height: 12),
          TextField(key: const Key('password'), controller: _password, obscureText: true, decoration: InputDecoration(labelText: L.t('Password'))),
          const SizedBox(height: 12),
          TextField(key: const Key('server'), controller: _server, textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: L.t('Server'))),
          const SizedBox(height: 24),
          FilledButton(
            key: const Key('signin'),
            onPressed: app.busy ? null : () => app.signIn(_server.text.trim(), _email.text.trim(), _password.text),
            child: Padding(padding: const EdgeInsets.all(12), child: app.busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : Text(L.t('Sign in'))),
          ),
          if (app.status.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 12), child: Text(app.status, style: const TextStyle(color: Colors.orange))),
        ]),
      ),
    );
  }
}
