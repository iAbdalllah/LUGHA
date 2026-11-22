import 'package:flutter/material.dart';

import '../l10n.dart';
import '../state.dart';
import 'report_sheet.dart';

class LibraryScreen extends StatefulWidget {
  const LibraryScreen({super.key, required this.app});
  final AppState app;
  @override
  State<LibraryScreen> createState() => _LibraryScreenState();
}

class _LibraryScreenState extends State<LibraryScreen> {
  final _line = TextEditingController();
  Line? _result;

  @override
  Widget build(BuildContext context) {
    final app = widget.app;
    return RefreshIndicator(
      onRefresh: app.sync,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        Row(children: [
          Expanded(child: Text(L.t('Library'), style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.bold))),
          if (app.busy) const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)),
          IconButton(key: const Key('sync'), tooltip: L.t('Sync now'), onPressed: app.busy ? null : app.sync, icon: const Icon(Icons.sync)),
        ]),
        if (app.library.isEmpty) Padding(padding: const EdgeInsets.symmetric(vertical: 24), child: Text(L.t('No packages yet. Buy one in the LUGHA store.'))),
        for (final item in app.library)
          Card(
            child: ListTile(
              title: Text('${item.gameTitle} — ${item.name}'),
              subtitle: Builder(builder: (_) {
                final inst = app.loaded.where((p) => p.packageId == item.package).firstOrNull;
                return Text(inst != null ? '${L.t('Installed')} v${inst.version} · ${inst.entries}' : (item.latestVersion == null ? L.t('No release yet') : L.t('Not installed')),
                    style: TextStyle(color: inst != null ? Colors.greenAccent : null));
              }),
              trailing: app.loaded.any((p) => p.packageId == item.package)
                  ? IconButton(tooltip: L.t('Remove'), icon: const Icon(Icons.delete_outline), onPressed: () => app.removePackage(item.package))
                  : null,
            ),
          ),
        const SizedBox(height: 24),
        Text(L.t('Try a line'), style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        TextField(
          key: const Key('try'),
          controller: _line,
          textDirection: TextDirection.ltr,
          decoration: InputDecoration(hintText: L.t('Type an English line from the game…'), suffixIcon: IconButton(icon: const Icon(Icons.translate), onPressed: _try)),
          onSubmitted: (_) => _try(),
        ),
        if (_result != null)
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: Text(
              _result!.target == null ? L.t('No translation found') : '${_result!.speaker != null ? '${_result!.speaker}: ' : ''}${_result!.target}',
              key: const Key('result'),
              textDirection: TextDirection.rtl,
              style: Theme.of(context).textTheme.titleLarge,
            ),
          ),
        if (_result?.reportable ?? false)
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: TextButton.icon(
              key: const Key('report'),
              icon: const Icon(Icons.flag_outlined, size: 18),
              label: Text(L.t('Wrong translation? Report it')),
              onPressed: () => showReportSheet(context, app, _result!),
            ),
          ),
        if (app.history.isNotEmpty) ...[
          const SizedBox(height: 24),
          Text(L.t('History'), style: Theme.of(context).textTheme.titleMedium),
          for (final l in app.history.take(10))
            ListTile(
              dense: true,
              title: Text(l.target ?? '—', textDirection: TextDirection.rtl),
              subtitle: Text(l.source, textDirection: TextDirection.ltr),
              trailing: l.reportable
                  ? IconButton(tooltip: L.t('Report this line'), icon: const Icon(Icons.flag_outlined), onPressed: () => showReportSheet(context, app, l))
                  : null,
            ),
        ],
        if (app.status.isNotEmpty) Text(app.status, style: const TextStyle(color: Colors.orange)),
      ]),
    );
  }

  void _try() {
    if (_line.text.trim().isEmpty) return;
    setState(() => _result = widget.app.tryLine(_line.text));
  }
}
