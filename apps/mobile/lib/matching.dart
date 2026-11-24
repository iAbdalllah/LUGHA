/// What the game shows → the package's line: the same fallbacks as the Mac and PC apps (speaker names, names OCR
/// garbled without their colon, button icons, counters, typographic punctuation).
library;

/// Typographic punctuation OCR reads from game fonts, as plain ASCII.
String plain(String t) => t
    .replaceAll('…', '...')
    .replaceAll(RegExp('[’‘`]'), "'")
    .replaceAll(RegExp('[“”]'), '"')
    .replaceAll(RegExp('[–—]'), '-');

/// "CO Wilson: Okay..." → ("CO Wilson", "Okay...").
(String?, String) splitSpeaker(String text) {
  final i = text.indexOf(':');
  if (i <= 0) return (null, text);
  final name = text.substring(0, i).trim(), rest = text.substring(i + 1).trim();
  final words = name.split(RegExp(r'\s+'));
  bool cap(String w) => w.isNotEmpty && RegExp(r'^[A-Z0-9]').hasMatch(w);
  final ok = rest.isNotEmpty && words.length <= 3 && name.length <= 24 && cap(words.last) &&
      words.sublist(0, words.length - 1).every((w) => cap(w) || w.length <= 3) && RegExp(r"^[A-Za-z0-9 .'\-#]+$").hasMatch(name);
  return ok ? (name, rest) : (null, text);
}

/// A button icon read as text: a lone symbol, or X/O/C/0 alone or in brackets.
bool isGlyph(String w) {
  final core = w.replaceAll(RegExp(r'[\[\](){}]'), '');
  if (core.isEmpty) return true;
  if (core.length == 1) return !RegExp(r'[A-Za-z0-9]').hasMatch(core) || ('XOCxo0'.contains(core) && w.length <= 3);
  return false;
}

String trimGlyphs(String text) {
  final w = text.split(RegExp(r'\s+')).where((e) => e.isNotEmpty).toList();
  while (w.length > 1 && isGlyph(w.first)) {
    w.removeAt(0);
  }
  while (w.length > 1 && isGlyph(w.last)) {
    w.removeLast();
  }
  return w.join(' ');
}

/// A row split at button icons ("DEFUSE X PROVOKE" → DEFUSE, PROVOKE).
List<String> glyphParts(String text) {
  final out = <String>[];
  var cur = <String>[];
  for (final w in text.split(RegExp(r'\s+')).where((e) => e.isNotEmpty)) {
    if (isGlyph(w)) {
      if (cur.isNotEmpty) out.add(cur.join(' '));
      cur = [];
    } else {
      cur.add(w);
    }
  }
  if (cur.isNotEmpty) out.add(cur.join(' '));
  return out;
}

/// "TAKE AMMO PACK (0/3)" → ("TAKE AMMO PACK", "(0/3)").
(String, String?) splitCounter(String text) {
  final m = RegExp(r'^(.+?)\s*(\(\d{1,3}/\d{1,3}\))\s*$').firstMatch(text);
  return m == null ? (text, null) : (m.group(1)!, m.group(2));
}

/// A match: the Arabic, the speaker to show (if any) and what was looked up.
class Found<T> {
  Found(this.hit, this.speaker, this.counter);
  final T hit;
  final String? speaker;
  final String? counter;
}

/// Without the speaker name, then as read, then without the first one or two words (a name OCR garbled and read
/// without its colon: "Staf Gember Absolutely no rush…").
Found<T>? findLine<T>(String read, T? Function(String) lookup) {
  final (raw, counter) = splitCounter(trimGlyphs(plain(read)));
  final (speaker, body) = splitSpeaker(raw);
  final tries = <(String, String?)>[if (speaker != null) (body, speaker), (raw, null)];
  final words = raw.split(RegExp(r'\s+')).where((e) => e.isNotEmpty).toList();
  if (speaker == null && words.length >= 4 && RegExp('[a-z]').hasMatch(raw)) {
    for (var n = 1; n <= 2; n++) {
      if (words.length - n >= 3) tries.add((words.sublist(n).join(' '), null));
    }
  }
  for (final (t, name) in tries) {
    final h = lookup(t);
    if (h != null) return Found(h, name, counter);
  }
  return null;
}
