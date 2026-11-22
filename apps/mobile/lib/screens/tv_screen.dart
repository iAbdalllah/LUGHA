import 'dart:io';

import 'package:flutter/material.dart';

import '../l10n.dart';
import '../state.dart';

class TvScreen extends StatefulWidget {
  const TvScreen({super.key, required this.app});
  final AppState app;
  @override
  State<TvScreen> createState() => _TvScreenState();
}

class _TvScreenState extends State<TvScreen> {
  final _address = TextEditingController();
  final _code = TextEditingController();

  @override
  void initState() {
    super.initState();
    widget.app.discover();
  }

  @override
  Widget build(BuildContext context) {
    final app = widget.app;
    final tv = app.tv;
    return ListView(padding: const EdgeInsets.all(16), children: [
      Text(L.t('TV'), style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.bold)),
      if (Platform.isIOS)
        Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: Text(L.t('On iPhone, LUGHA can’t read other apps’ screens. Use it to manage packages and control your TV; subtitles come from LUGHA on your Mac or PC.'), style: Theme.of(context).textTheme.bodySmall)),
      if (tv != null) ...[
        Card(child: ListTile(leading: const Icon(Icons.tv, color: Colors.greenAccent), title: Text(L.t('Connected to %s', tv.tv.name)), trailing: TextButton(onPressed: app.disconnectTv, child: Text(L.t('Disconnect'))))),
        const SizedBox(height: 16),
        Text(L.t('TV remote'), style: Theme.of(context).textTheme.titleMedium),
        ListTile(title: Text(L.t('Font size')), subtitle: Slider(value: app.tvFontScale, min: 0.8, max: 1.6, divisions: 8, onChanged: (v) => app.setTvStyle(fontScale: v))),
        ListTile(title: Text(L.t('Background')), subtitle: Slider(value: app.tvBgOpacity, min: 0.3, max: 1, onChanged: (v) => app.setTvStyle(bgOpacity: v))),
        ListTile(
          title: Text(L.t('Position')),
          trailing: SegmentedButton<bool>(
            segments: [ButtonSegment(value: false, label: Text(L.t('Top'))), ButtonSegment(value: true, label: Text(L.t('Bottom')))],
            selected: {app.tvBottom},
            onSelectionChanged: (s) => app.setTvStyle(bottom: s.first),
          ),
        ),
        Row(children: [
          Expanded(child: FilledButton(key: const Key('tvtest'), onPressed: app.sendTestLine, child: Text(L.t('Send a test line')))),
          const SizedBox(width: 8),
          OutlinedButton(onPressed: () => app.tv?.clear(), child: Text(L.t('Clear the TV'))),
        ]),
      ] else ...[
        if (app.pairedTvs.isNotEmpty) Align(alignment: AlignmentDirectional.centerStart, child: TextButton(onPressed: app.reconnectTv, child: Text('${L.t('Reconnect')} · ${app.pairedTvs.last.name}'))),
        Text(L.t('TVs on this network'), style: Theme.of(context).textTheme.titleMedium),
        if (app.found.isEmpty) Padding(padding: const EdgeInsets.all(8), child: Text(L.t('Searching…'))),
        for (final f in app.found) ListTile(leading: const Icon(Icons.tv), title: Text(f.name), subtitle: Text('${f.host}:${f.port}', textDirection: TextDirection.ltr), onTap: () => _address.text = '${f.host}:${f.port}'),
        const SizedBox(height: 12),
        TextField(key: const Key('tvaddr'), controller: _address, textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: L.t('Address (IP:port)'))),
        const SizedBox(height: 8),
        TextField(key: const Key('tvcode'), controller: _code, keyboardType: TextInputType.number, maxLength: 6, textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: L.t('Code on the TV'))),
        FilledButton(key: const Key('tvpair'), onPressed: _pair, child: Text(L.t('Pair'))),
      ],
      if (app.status.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(app.status, style: const TextStyle(color: Colors.orange))),
    ]);
  }

  void _pair() {
    final parts = _address.text.trim().split(':');
    if (parts.first.isEmpty || _code.text.length != 6) return;
    final found = widget.app.found.where((f) => f.host == parts.first).firstOrNull;
    widget.app.pairTv(parts.first, parts.length > 1 ? int.tryParse(parts[1]) ?? 47800 : 47800, _code.text, found?.name ?? parts.first);
  }
}
