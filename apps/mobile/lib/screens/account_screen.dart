import 'package:flutter/material.dart';
import 'package:lugha_engine/lugha_engine.dart';

import '../l10n.dart';
import '../state.dart';

class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key, required this.app});
  final AppState app;

  @override
  Widget build(BuildContext context) {
    return ListView(padding: const EdgeInsets.all(16), children: [
      Text(L.t('Account'), style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.bold)),
      ListTile(leading: const Icon(Icons.person), title: Text(app.userName ?? '—'), subtitle: Text(app.api.baseUrl.toString(), textDirection: TextDirection.ltr)),
      ListTile(
        leading: const Icon(Icons.language),
        title: Text(L.t('Language')),
        trailing: SegmentedButton<String>(
          segments: const [ButtonSegment(value: 'ar', label: Text('العربية')), ButtonSegment(value: 'en', label: Text('English'))],
          selected: {L.lang},
          onSelectionChanged: (s) => app.setLanguage(s.first),
        ),
      ),
      if (app.lastSync != null) ListTile(leading: const Icon(Icons.sync), title: Text(L.t('Last sync')), subtitle: Text(TimeOfDay.fromDateTime(app.lastSync!).format(context))),
      ListTile(leading: const Icon(Icons.info_outline), title: Text('LUGHA ${AppState.appVersion}'), subtitle: Text('engine ${Engine.version()}')),
      const SizedBox(height: 16),
      OutlinedButton(onPressed: app.signOut, child: Text(L.t('Sign out'))),
    ]);
  }
}
