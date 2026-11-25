// PS5 tab: sign in to PSN, link the PS5 once, then read the game's subtitles from the console while the player plays
// on the TV. Part of the AGPL-3.0 Remote Play feature (packages/lugha_remoteplay/LICENSE).
import 'dart:async';
import 'dart:typed_data';

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
  final _host = TextEditingController();

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
        // The address by hand (the PS5 shows it under Settings › Network › Connection Status), when searching finds nothing.
        Row(children: [
          Expanded(child: TextField(key: const Key('ps5-host'), controller: _host, keyboardType: TextInputType.url, textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: L.t('Or the PS5 address'), hintText: '192.168.1.20'))),
          const SizedBox(width: 8),
          OutlinedButton(onPressed: () { final h = _host.text.trim(); if (h.isNotEmpty) setState(() => _consoles = [Console(host: h, name: 'PS5', ready: true, ps5: true)]); }, child: Text(L.t('Use'))),
        ]),
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
        if (app.rpRunning) _PictureAreas(app: app),
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

/// The PS5's picture with the reading areas over it; dragging on it adds an area.
class _PictureAreas extends StatefulWidget {
  const _PictureAreas({required this.app});
  final AppState app;
  @override
  State<_PictureAreas> createState() => _PictureAreasState();
}

class _PictureAreasState extends State<_PictureAreas> {
  Uint8List? _png;
  Timer? _timer;
  Offset? _start, _now;

  /// What dragging draws: -1 = a new reading area; n = the translation box of area n.
  int _target = -1;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(const Duration(milliseconds: 1500), (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    final p = await RemotePlay.snapshot();
    if (mounted && p != null) setState(() => _png = p);
  }

  @override
  Widget build(BuildContext context) {
    final png = _png;
    if (png == null) return const SizedBox.shrink();
    final areas = widget.app.display.areas;
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        AspectRatio(
          aspectRatio: 16 / 9,
          child: LayoutBuilder(builder: (context, box) {
            Offset frac(Offset p) => Offset((p.dx / box.maxWidth).clamp(0.0, 1.0), (p.dy / box.maxHeight).clamp(0.0, 1.0));
            Widget rect(double x, double y, double w, double h, String label, {bool on = true}) => Positioned(
                  left: x * box.maxWidth, top: y * box.maxHeight, width: w * box.maxWidth, height: h * box.maxHeight,
                  child: DecoratedBox(
                    decoration: BoxDecoration(border: Border.all(color: Colors.amber.withValues(alpha: on ? 1 : 0.4), width: 2), color: Colors.amber.withValues(alpha: 0.12)),
                    child: Align(alignment: AlignmentDirectional.topStart, child: Text(label, style: const TextStyle(color: Colors.amber, fontSize: 11))),
                  ),
                );
            return GestureDetector(
              onPanStart: (d) => setState(() { _start = frac(d.localPosition); _now = _start; }),
              onPanUpdate: (d) => setState(() => _now = frac(d.localPosition)),
              onPanEnd: (_) {
                final a = _start, b = _now;
                setState(() { _start = null; _now = null; });
                if (a == null || b == null) return;
                final x = a.dx < b.dx ? a.dx : b.dx, y = a.dy < b.dy ? a.dy : b.dy;
                final w = (a.dx - b.dx).abs(), h = (a.dy - b.dy).abs();
                if (w < 0.03 || h < 0.02) return;
                final t = _target;
                if (t >= 0 && t < areas.length) {
                  // The translation goes exactly here.
                  widget.app.setDisplay(widget.app.display.copyWith(areas: [
                    for (final (j, a) in areas.indexed) j == t ? a.copyWith(place: 'custom', tx: x, ty: y, tw: w, th: h) : a,
                  ]));
                } else {
                  widget.app.setDisplay(widget.app.display.copyWith(areas: [...areas, ReadingArea('${L.t('Area')} ${areas.length + 1}', x, y, w, h)]));
                }
              },
              child: ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: Stack(fit: StackFit.expand, children: [
                  Image.memory(png, fit: BoxFit.fill, gaplessPlayback: true),
                  for (final a in areas) rect(a.x, a.y, a.w, a.h, a.name, on: a.enabled),
                  for (final a in areas.where((a) => a.place == 'custom'))
                    Positioned(
                      left: a.tx * box.maxWidth, top: a.ty * box.maxHeight, width: a.tw * box.maxWidth, height: a.th * box.maxHeight,
                      child: DecoratedBox(
                        decoration: BoxDecoration(border: Border.all(color: Colors.pinkAccent, width: 2)),
                        child: Align(alignment: AlignmentDirectional.bottomStart, child: Text('↳ ${a.name}', style: const TextStyle(color: Colors.pinkAccent, fontSize: 10))),
                      ),
                    ),
                  if (_start != null && _now != null)
                    rect(_start!.dx < _now!.dx ? _start!.dx : _now!.dx, _start!.dy < _now!.dy ? _start!.dy : _now!.dy, (_start!.dx - _now!.dx).abs(), (_start!.dy - _now!.dy).abs(), ''),
                ]),
              ),
            );
          }),
        ),
        const SizedBox(height: 4),
        DropdownButton<int>(
          isExpanded: true,
          value: _target < areas.length ? _target : -1,
          items: [
            DropdownMenuItem(value: -1, child: Text(L.t('Draw: a new reading area'))),
            for (final (n, a) in areas.indexed) DropdownMenuItem(value: n, child: Text(L.t('Draw: where the translation of %s goes', a.name))),
          ],
          onChanged: (v) => setState(() => _target = v ?? -1),
        ),
        Text(L.t('Drag on the picture to draw a box around the English subtitles. Only text inside the boxes is translated.'), style: Theme.of(context).textTheme.bodySmall),
      ]),
    );
  }
}

/// One area's design: where its Arabic goes, its look and the size of its box (same options as the Mac and PC).
class _AreaDesign extends StatelessWidget {
  const _AreaDesign({required this.area, required this.onChanged});
  final ReadingArea area;
  final ValueChanged<ReadingArea> onChanged;

  @override
  Widget build(BuildContext context) {
    final a = area;
    Widget pick(String label, String value, List<(String, String)> options, ReadingArea Function(String) set) => Row(children: [
          SizedBox(width: 110, child: Text(L.t(label))),
          Expanded(
            child: DropdownButton<String>(
              isExpanded: true,
              value: value,
              items: [for (final (v, l) in options) DropdownMenuItem(value: v, child: Text(L.t(l)))],
              onChanged: (v) => v == null ? null : onChanged(set(v)),
            ),
          ),
        ]);
    Widget slide(String label, double value, double min, double max, ReadingArea Function(double) set) => Row(children: [
          SizedBox(width: 110, child: Text(L.t(label))),
          Expanded(child: Slider(value: value.clamp(min, max), min: min, max: max, onChanged: (v) => onChanged(set(v)))),
          SizedBox(width: 44, child: Text('${(value * 100).round()}%', textDirection: TextDirection.ltr)),
        ]);
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        pick('Translation', a.place, [
          ('auto', 'Automatic'), ('over', 'Over the English'), ('below', 'Below the English box'), ('above', 'Above the English box'), ('custom', 'Custom place'),
        ], (v) => a.copyWith(place: v)),
        if (a.place == 'custom') ...[
          Text(L.t('Draw the translation box on the PS5 picture, or type it (% of the picture):'), style: Theme.of(context).textTheme.bodySmall),
          Wrap(spacing: 8, children: [
            for (final (k, v, set) in <(String, double, ReadingArea Function(double))>[
              ('X', a.tx, (n) => a.copyWith(tx: n)), ('Y', a.ty, (n) => a.copyWith(ty: n)), ('W', a.tw, (n) => a.copyWith(tw: n)), ('H', a.th, (n) => a.copyWith(th: n)),
            ])
              SizedBox(
                width: 64,
                child: TextFormField(
                  key: ValueKey('t-$k-${a.name}-$v'),
                  initialValue: (v * 100).toStringAsFixed(1),
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  textDirection: TextDirection.ltr,
                  decoration: InputDecoration(labelText: '$k %', isDense: true),
                  onFieldSubmitted: (s) { final n = double.tryParse(s); if (n != null) onChanged(set((n / 100).clamp(0.0, 1.0))); },
                ),
              ),
          ]),
          pick('Text in the area', a.valign, [('top', 'Top'), ('center', 'Middle'), ('bottom', 'Bottom')], (v) => a.copyWith(valign: v)),
        ],
        pick('Look', a.look, [('auto', 'Automatic'), ('subtitle', 'Text on the picture'), ('box', 'Box'), ('card', 'Game-style card')], (v) => a.copyWith(look: v)),
        pick('Alignment', a.align, [('right', 'Right'), ('center', 'Centre'), ('left', 'Left')], (v) => a.copyWith(align: v)),
        slide('Text size', a.font, 0.5, 2, (v) => a.copyWith(font: v)),
        slide('Box width', a.boxW, 0.3, 2, (v) => a.copyWith(boxW: v)),
        slide('Box height', a.boxH, 0, 2.5, (v) => a.copyWith(boxH: v)),
        Row(children: [
          SizedBox(width: 110, child: Text(L.t('Accent edge'))),
          for (final hex in <String?>[null, '#E9B44C', '#E85FA0', '#4FC3F7', '#7BD88F'])
            Padding(
              padding: const EdgeInsets.only(left: 6),
              child: GestureDetector(
                onTap: () => onChanged(hex == null ? a.copyWith(clearAccent: true) : a.copyWith(accent: hex)),
                child: Container(
                  width: 24, height: 24,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: hex == null ? Colors.transparent : Color(int.parse('FF${hex.substring(1)}', radix: 16)),
                    border: Border.all(color: a.accent == hex ? Colors.white : Colors.white24, width: a.accent == hex ? 2.5 : 1),
                  ),
                  child: hex == null ? const Icon(Icons.block, size: 14, color: Colors.white38) : null,
                ),
              ),
            ),
        ]),
      ]),
    );
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
          for (final (i, a) in areas.indexed)
            ExpansionTile(
              title: Text('${L.t('Design')}: ${a.name}', style: t.bodyMedium),
              children: [_AreaDesign(area: a, onChanged: (b) => save([for (final (j, x) in areas.indexed) j == i ? b : x]))],
            ),
          Wrap(spacing: 8, children: [
            TextButton.icon(
              icon: const Icon(Icons.add),
              label: Text(L.t('Add area')),
              onPressed: () => save([...areas, ReadingArea('${L.t('Area')} ${areas.length + 1}', 0.1, 0.8, 0.8, 0.12)]),
            ),
            for (final (k, label) in [('subtitles', 'Subtitles only'), ('full', 'Full screen'), ('game', 'Subtitles + instructions')])
              OutlinedButton(onPressed: () => save(ReadingArea.preset(k, L.t)), child: Text(L.t(label))),
          ]),
        ],
      ),
    );
  }
}
