// PS5 tab: sign in to PSN, link the PS5 once, then read the game's subtitles from the console while the player plays
// on the TV. Part of the AGPL-3.0 Remote Play feature (packages/lugha_remoteplay/LICENSE).
import 'package:flutter/material.dart';
import 'package:lugha_remoteplay/lugha_remoteplay.dart';

import '../display.dart';
import '../l10n.dart';
import '../psn.dart';
import '../state.dart';

class PlayScreen extends StatefulWidget {
  const PlayScreen({super.key, required this.app});
  final AppState app;
  @override
  State<PlayScreen> createState() => _PlayScreenState();
}

class _PlayScreenState extends State<PlayScreen> {
  List<Console>? _consoles;
  bool _searching = false;
  final _pin = TextEditingController();

  Future<void> _find() async {
    setState(() => _searching = true);
    try {
      final c = await RemotePlay.discover();
      if (mounted) setState(() => _consoles = c);
    } finally {
      if (mounted) setState(() => _searching = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final app = widget.app;
    final t = Theme.of(context).textTheme;
    return ListView(padding: const EdgeInsets.all(16), children: [
      Text(L.t('Play on your TV'), style: t.headlineSmall?.copyWith(fontWeight: FontWeight.bold)),
      const SizedBox(height: 6),
      Text(L.t('Play on the TV as usual. This phone connects to your PS5 over Wi‑Fi, reads the game\'s English subtitles and shows the Arabic on the TV.')),
      const SizedBox(height: 16),

      // 1. PSN
      Card(child: ListTile(
        leading: Icon(app.psnAccountId != null ? Icons.check_circle : Icons.account_circle_outlined, color: app.psnAccountId != null ? Colors.greenAccent : null),
        title: Text(app.psnAccountId != null ? L.t('Signed in to PSN') : L.t('Sign in to PlayStation Network')),
        subtitle: Text(L.t('Your password goes only to Sony; LUGHA keeps just your account number.')),
        onTap: () async {
          final id = await Navigator.of(context).push<String>(MaterialPageRoute(builder: (_) => const PsnSignInPage()));
          if (id != null) await app.setPsnAccountId(id);
        },
      )),

      // 2. Link the PS5
      if (app.psnAccountId != null && app.ps5 == null) Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(L.t('Link your PS5'), style: t.titleMedium),
        const SizedBox(height: 8),
        FilledButton.tonalIcon(key: const Key('find-ps5'), onPressed: _searching ? null : _find, icon: _searching ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.search), label: Text(L.t('Find PS5'))),
        if (_consoles != null && _consoles!.isEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(L.t('No PS5 found on this Wi‑Fi.'))),
        for (final c in _consoles ?? const <Console>[]) ...[
          const Divider(),
          Text('${c.name} · ${c.host}${c.ready ? '' : ' · ${L.t('Resting')}'}', textDirection: TextDirection.ltr),
          const SizedBox(height: 6),
          Text(L.t('On the PS5: Settings › System › Remote Play › Link Device. Enter the 8 digits it shows.'), style: t.bodySmall),
          Row(children: [
            Expanded(child: TextField(key: const Key('ps5-pin'), controller: _pin, keyboardType: TextInputType.number, maxLength: 8, textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: L.t('Code from the PS5')))),
            const SizedBox(width: 8),
            FilledButton(onPressed: () => app.registerPs5(c, _pin.text), child: Text(L.t('Link'))),
          ]),
        ],
      ]))),

      // 3. Play
      if (app.ps5 != null) Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Expanded(child: Text(L.t('Linked to %s', app.ps5!.nickname), style: t.titleMedium)),
          TextButton(onPressed: app.forgetPs5, child: Text(L.t('Unlink'))),
        ]),
        const SizedBox(height: 8),
        if (app.tv == null) Text(L.t('Connect a LUGHA TV first (TV tab) to see the Arabic on the TV.'), style: const TextStyle(color: Colors.orange)),
        FilledButton.icon(
          key: const Key('rp-toggle'),
          onPressed: app.loaded.isEmpty ? null : (app.rpRunning ? app.stopRemotePlay : app.startRemotePlay),
          icon: Icon(app.rpRunning ? Icons.stop : Icons.play_arrow),
          label: Text(app.rpRunning ? L.t('Stop') : L.t('Start reading subtitles')),
        ),
        if (app.rpStatus.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(app.rpStatus)),
        if (app.rpRunning) Padding(padding: const EdgeInsets.only(top: 4), child: Text(L.t('Keep this phone on and LUGHA open while you play.'), style: t.bodySmall)),
        if (app.rpRunning && app.history.isNotEmpty) ...[
          const Divider(),
          // The phone doubles as a history screen: the latest line first, a few earlier ones under it.
          Text(L.t('Recent lines'), style: t.labelMedium),
          for (final (n, l) in app.history.take(6).indexed)
            Opacity(
              opacity: n == 0 ? 1 : 0.75 - n * 0.08,
              child: Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Text('${l.speaker != null ? '${l.speaker}: ' : ''}${l.target ?? ''}', textDirection: TextDirection.rtl, style: n == 0 ? t.titleMedium : t.bodyLarge),
                Text(l.source, textDirection: TextDirection.ltr, style: t.bodySmall),
              ])),
            ),
        ],
      ]))),
      if (app.rpStatus.isNotEmpty && app.ps5 == null) Text(app.rpStatus, style: const TextStyle(color: Colors.orange)),
      if (app.ps5 != null) _AreasEditor(app: app),
    ]);
  }
}

/// Reading areas: boxes (in % of the picture) the PS5 reader reads text from; everything else is ignored.
class _AreasEditor extends StatelessWidget {
  const _AreasEditor({required this.app});
  final AppState app;

  @override
  Widget build(BuildContext context) {
    final areas = app.display.areas;
    final t = Theme.of(context).textTheme;
    void save(List<ReadingArea> a) => app.setDisplay(app.display.copyWith(areas: a));
    Widget pct(String label, double v, ReadingArea Function(double) set, int i) => SizedBox(
          width: 64,
          child: TextFormField(
            key: ValueKey('area-$i-$label-${areas.length}'),
            initialValue: (v * 100).toStringAsFixed(1),
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textDirection: TextDirection.ltr,
            decoration: InputDecoration(labelText: '$label %', isDense: true),
            onFieldSubmitted: (s) {
              final n = double.tryParse(s);
              if (n == null) return;
              save([for (final (j, a) in areas.indexed) j == i ? set((n / 100).clamp(0.0, 1.0)) : a]);
            },
          ),
        );
    return Card(
      child: ExpansionTile(
        title: Text(L.t('Reading areas')),
        subtitle: Text(L.t('Only text inside these boxes is translated. With none, the usual subtitle band is read.'), style: t.bodySmall),
        children: [
          for (final (i, a) in areas.indexed)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
              child: Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                Switch(value: a.enabled, onChanged: (v) => save([for (final (j, b) in areas.indexed) j == i ? b.copyWith(enabled: v) : b])),
                SizedBox(
                  width: 110,
                  child: TextFormField(
                    key: ValueKey('area-$i-name-${areas.length}'),
                    initialValue: a.name,
                    decoration: InputDecoration(labelText: L.t('Name'), isDense: true),
                    onFieldSubmitted: (v) => save([for (final (j, b) in areas.indexed) j == i ? b.copyWith(name: v) : b]),
                  ),
                ),
                pct('X', a.x, (v) => a.copyWith(x: v), i),
                pct('Y', a.y, (v) => a.copyWith(y: v), i),
                pct('W', a.w, (v) => a.copyWith(w: v), i),
                pct('H', a.h, (v) => a.copyWith(h: v), i),
                IconButton(icon: const Icon(Icons.delete_outline), onPressed: () => save([...areas]..removeAt(i))),
              ]),
            ),
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: TextButton.icon(
              icon: const Icon(Icons.add),
              label: Text(L.t('Add area')),
              onPressed: () => save([...areas, ReadingArea('${L.t('Area')} ${areas.length + 1}', 0.1, 0.8, 0.8, 0.12)]),
            ),
          ),
        ],
      ),
    );
  }
}
