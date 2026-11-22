import 'package:flutter/material.dart';

import '../l10n.dart';
import '../state.dart';

class DevicesScreen extends StatelessWidget {
  const DevicesScreen({super.key, required this.app});
  final AppState app;

  static const _icons = {'macos': Icons.laptop_mac, 'windows': Icons.laptop_windows, 'android': Icons.phone_android, 'ios': Icons.phone_iphone, 'android_tv': Icons.tv};

  @override
  Widget build(BuildContext context) {
    return ListView(padding: const EdgeInsets.all(16), children: [
      Text(L.t('Devices'), style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.bold)),
      if (app.deviceLimitReached)
        Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: Text(app.status, style: const TextStyle(color: Colors.orange))),
      const SizedBox(height: 8),
      for (final d in app.devices)
        Card(
          child: ListTile(
            leading: Icon(_icons[d.kind] ?? Icons.devices),
            title: Text(d.name + (d.id == app.deviceId ? ' · ${L.t('This device')}' : '')),
            subtitle: Text(d.lastSeen != null ? '${L.t('Last seen')}: ${MaterialLocalizations.of(context).formatShortDate(d.lastSeen!.toLocal())}' : ''),
            trailing: TextButton(onPressed: () async {
              await app.removeDevice(d);
              if (app.deviceLimitReached) await app.sync();
            }, child: Text(L.t('Remove'))),
          ),
        ),
    ]);
  }
}
