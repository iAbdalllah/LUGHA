import 'dart:async';

/// How subtitles look and behave on the TV, and the reader's timing — the same options as the Mac and PC apps.
/// A box to read from, as fractions of the picture (origin top-left). Only text inside enabled boxes is read.
class ReadingArea {
  const ReadingArea(this.name, this.x, this.y, this.w, this.h, {this.enabled = true});
  final String name;
  final double x, y, w, h;
  final bool enabled;

  ReadingArea copyWith({String? name, double? x, double? y, double? w, double? h, bool? enabled}) =>
      ReadingArea(name ?? this.name, x ?? this.x, y ?? this.y, w ?? this.w, h ?? this.h, enabled: enabled ?? this.enabled);

  Map<String, dynamic> toJson() => {'name': name, 'x': x, 'y': y, 'w': w, 'h': h, 'enabled': enabled};

  static ReadingArea fromJson(Map<String, dynamic> j) {
    double f(String k, double d) => ((j[k] as num?)?.toDouble() ?? d).clamp(0.0, 1.0);
    return ReadingArea(j['name'] as String? ?? '', f('x', 0.1), f('y', 0.8), f('w', 0.8), f('h', 0.12), enabled: j['enabled'] as bool? ?? true);
  }
}

class DisplayPrefs {
  const DisplayPrefs({this.display = 'over', this.history = 'off', this.readHold = true, this.fadeMs = 150, this.stableMs = 0, this.goneAfterMs = 1200, this.areas = const []});

  /// Reading areas for the PS5 reader (empty: the usual subtitle band).
  final List<ReadingArea> areas;

  /// 'over': the Arabic hides the English; 'above': just above it, the English stays visible.
  final String display;

  /// Side panel with the recent dialogue on the TV: 'off', 'left' or 'right'.
  final String history;

  /// Keep a replaced line up (dimmed, above the new one) until it could be read.
  final bool readHold;
  final int fadeMs;

  /// Look a line up only once the text held this long (avoids half-drawn lines).
  final int stableMs;

  /// Clear the TV after the English has been gone this long.
  final int goneAfterMs;

  DisplayPrefs copyWith({String? display, String? history, bool? readHold, int? fadeMs, int? stableMs, int? goneAfterMs, List<ReadingArea>? areas}) => DisplayPrefs(
        areas: areas ?? this.areas,
        display: display ?? this.display,
        history: history ?? this.history,
        readHold: readHold ?? this.readHold,
        fadeMs: fadeMs ?? this.fadeMs,
        stableMs: stableMs ?? this.stableMs,
        goneAfterMs: goneAfterMs ?? this.goneAfterMs,
      );

  Map<String, dynamic> toJson() => {'display': display, 'history': history, 'read_hold': readHold, 'fade_ms': fadeMs, 'stable_ms': stableMs, 'gone_after_ms': goneAfterMs,
        'areas': [for (final a in areas) a.toJson()]};

  factory DisplayPrefs.fromJson(Map<String, dynamic> j) {
    int ms(String k, int def, int lo, int hi) => ((j[k] as num?)?.toInt() ?? def).clamp(lo, hi);
    return DisplayPrefs(
      display: j['display'] == 'above' ? 'above' : 'over',
      history: const ['left', 'right'].contains(j['history']) ? j['history'] as String : 'off',
      readHold: j['read_hold'] as bool? ?? true,
      fadeMs: ms('fade_ms', 150, 0, 1000),
      stableMs: ms('stable_ms', 0, 0, 1000),
      goneAfterMs: ms('gone_after_ms', 1200, 100, 3000),
      areas: [for (final a in (j['areas'] as List? ?? const [])) ReadingArea.fromJson(a as Map<String, dynamic>)],
    );
  }

  /// The keys LUGHA TV reads from style.set.
  Map<String, dynamic> tvStyle() => {'display': display, 'history': history, 'fade_ms': fadeMs, 'read_hold': readHold};
}

/// Passes text on only after it stayed the same for [hold] (zero: at once). A different text restarts the wait.
class StableGate {
  StableGate(this.onStable);
  final void Function(List<String> lines) onStable;
  Duration hold = Duration.zero;
  Timer? _timer;
  String? _pending;

  void feed(List<String> lines) {
    final text = lines.join(' ').trim();
    if (hold == Duration.zero) {
      onStable(lines);
      return;
    }
    if (text == _pending) return; // already waiting on this text
    _pending = text;
    _timer?.cancel();
    _timer = Timer(hold, () {
      _pending = null;
      onStable(lines);
    });
  }

  void cancel() {
    _timer?.cancel();
    _pending = null;
  }
}
