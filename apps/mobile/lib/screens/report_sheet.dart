import 'package:flutter/material.dart';

import '../l10n.dart';
import '../state.dart';

/// "Report this line": reason + optional note, sent to the reviewers' queue.
Future<void> showReportSheet(BuildContext context, AppState app, Line line) async {
  final sent = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (_) => Directionality(textDirection: L.dir, child: _ReportSheet(app: app, line: line)),
  );
  if (sent == true && context.mounted) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(L.t('Thanks — your report was sent to the reviewers.'))));
  }
}

class _ReportSheet extends StatefulWidget {
  const _ReportSheet({required this.app, required this.line});
  final AppState app;
  final Line line;
  @override
  State<_ReportSheet> createState() => _ReportSheetState();
}

class _ReportSheetState extends State<_ReportSheet> {
  String _reason = reportReasons.first;
  final _note = TextEditingController();
  bool _sending = false;
  String? _error;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    final err = await widget.app.report(widget.line, _reason, _note.text);
    if (!mounted) return;
    if (err == null) {
      Navigator.of(context).pop(true);
    } else {
      setState(() {
        _sending = false;
        _error = err;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = widget.line;
    final theme = Theme.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.viewInsetsOf(context).bottom),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(L.t('Report this line'), style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold)),
        const SizedBox(height: 4),
        Text(L.t('Reports go to LUGHA’s reviewers. If accepted, the line is fixed in the next package update.'), style: theme.textTheme.bodySmall),
        const SizedBox(height: 12),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(color: theme.colorScheme.surfaceContainerHighest, borderRadius: BorderRadius.circular(12)),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text('${l.speaker != null ? '${l.speaker}: ' : ''}${l.target ?? ''}', textDirection: TextDirection.rtl, style: theme.textTheme.titleMedium),
            const SizedBox(height: 4),
            Text(l.source, textDirection: TextDirection.ltr, style: theme.textTheme.bodySmall),
          ]),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          key: const Key('report-reason'),
          initialValue: _reason,
          decoration: InputDecoration(labelText: L.t('Reason')),
          items: [for (final r in reportReasons) DropdownMenuItem(value: r, child: Text(L.t('reason.$r')))],
          onChanged: (v) => setState(() => _reason = v ?? _reason),
        ),
        const SizedBox(height: 8),
        TextField(key: const Key('report-note'), controller: _note, maxLength: 500, maxLines: 3, minLines: 1, decoration: InputDecoration(labelText: L.t('Note (optional)'))),
        if (_error != null) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(_error!, style: const TextStyle(color: Colors.orange))),
        Row(mainAxisAlignment: MainAxisAlignment.end, children: [
          TextButton(onPressed: _sending ? null : () => Navigator.of(context).pop(false), child: Text(L.t('Cancel'))),
          const SizedBox(width: 8),
          FilledButton(key: const Key('report-send'), onPressed: _sending ? null : _send, child: Text(L.t('Send report'))),
        ]),
      ]),
    );
  }
}
