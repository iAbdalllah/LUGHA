import 'dart:async';

/// How subtitles look and behave on the TV, and the reader's timing — the same options as the Mac and PC apps.
/// A box to read from, as fractions of the picture (origin top-left). Only text inside enabled boxes is read.
class ReadingArea {
  const ReadingArea(this.name, this.x, this.y, this.w, this.h,
      {this.enabled = true,
      this.place = 'auto',
      this.tx = 0.1,
      this.ty = 0.76,
      this.tw = 0.8,
      this.th = 0.06,
      this.valign = 'bottom',
      this.look = 'auto',
      this.align = 'center',
      this.font = 1,
      this.boxW = 1,
      this.boxH = 1,
      this.accent});
  final String name;
  final double x, y, w, h;
  final bool enabled;

  // ---- How this area's translation looks and where it goes (sent to the TV; same keys on every app) ----
  /// 'auto' (the general rules), 'over' / 'below' / 'above' the English, or 'custom' = inside tx, ty, tw, th.
  final String place;
  final double tx, ty, tw, th;

  /// Inside the custom box: 'top', 'center' or 'bottom'.
  final String valign;

  /// 'auto', 'subtitle' (text on the picture), 'box' (the general plate colour) or 'card' (dark, accent edge).
  final String look;

  /// 'center', 'right' or 'left'.
  final String align;

  /// Text size, box width and box height (padding); 1 = like the game's text.
  final double font, boxW, boxH;

  /// Accent edge colour (#RRGGBB) or null.
  final String? accent;

  /// Drawn by its own rules on the TV (not the general subtitle block).
  bool get designed => place != 'auto' || look != 'auto';

  ReadingArea copyWith({String? name, double? x, double? y, double? w, double? h, bool? enabled, String? place, double? tx, double? ty, double? tw, double? th,
          String? valign, String? look, String? align, double? font, double? boxW, double? boxH, String? accent, bool clearAccent = false}) =>
      ReadingArea(name ?? this.name, x ?? this.x, y ?? this.y, w ?? this.w, h ?? this.h,
          enabled: enabled ?? this.enabled,
          place: place ?? this.place,
          tx: tx ?? this.tx,
          ty: ty ?? this.ty,
          tw: tw ?? this.tw,
          th: th ?? this.th,
          valign: valign ?? this.valign,
          look: look ?? this.look,
          align: align ?? this.align,
          font: font ?? this.font,
          boxW: boxW ?? this.boxW,
          boxH: boxH ?? this.boxH,
          accent: clearAccent ? null : (accent ?? this.accent));

  Map<String, dynamic> toJson() => {
        'name': name, 'x': x, 'y': y, 'w': w, 'h': h, 'enabled': enabled, 'place': place, 'tx': tx, 'ty': ty, 'tw': tw, 'th': th,
        'valign': valign, 'look': look, 'align': align, 'font': font, 'box_w': boxW, 'box_h': boxH, 'accent': ?accent,
      };

  /// The design as the TV reads it (`area` on a sub.show item).
  Map<String, dynamic> tvStyle() => {
        'name': name, 'place': place, 'valign': valign, 'look': look, 'align': align, 'font': font, 'box_w': boxW, 'box_h': boxH,
        // The reading area itself, so the TV can size and anchor the card steadily.
        'rx': x, 'ry': y, 'rw': w, 'rh': h,
        if (place == 'custom') ...{'tx': tx, 'ty': ty, 'tw': tw, 'th': th},
        'accent': ?accent,
      };

  static ReadingArea fromJson(Map<String, dynamic> j) {
    double f(String k, double d) => ((j[k] as num?)?.toDouble() ?? d).clamp(0.0, 1.0);
    double g(String k, double d, double lo, double hi) => ((j[k] as num?)?.toDouble() ?? d).clamp(lo, hi);
    String o(String k, Set<String> allowed, String d) => allowed.contains(j[k]) ? j[k] as String : d;
    return ReadingArea(j['name'] as String? ?? '', f('x', 0.1), f('y', 0.8), f('w', 0.8), f('h', 0.12),
        enabled: j['enabled'] as bool? ?? true,
        place: o('place', {'auto', 'over', 'below', 'above', 'custom'}, 'auto'),
        tx: f('tx', 0.1),
        ty: f('ty', 0.76),
        tw: f('tw', 0.8),
        th: f('th', 0.06),
        valign: o('valign', {'top', 'center', 'bottom'}, 'bottom'),
        look: o('look', {'auto', 'subtitle', 'box', 'card'}, 'auto'),
        align: o('align', {'center', 'right', 'left'}, 'center'),
        font: g('font', 1, 0.4, 2.5),
        boxW: g('box_w', 1, 0.3, 2.5),
        boxH: g('box_h', 1, 0, 3),
        accent: j['accent'] is String && RegExp(r'^#[0-9A-Fa-f]{6}$').hasMatch(j['accent'] as String) ? j['accent'] as String : null);
  }

  /// Ready-made sets: just the subtitles, the whole picture, or subtitles plus the two kinds of game hints.
  static List<ReadingArea> preset(String kind, String Function(String) t) => switch (kind) {
        'full' => [ReadingArea(t('Full screen'), 0, 0, 1, 1)],
        'game' => [
            // Over the English subtitle on a plate, anchored to its bottom line: steady, and the English is hidden.
            // GTA VI: subtitle lines sit at 85–92% of the height (two lines), centred.
            ReadingArea(t('Subtitles'), 0.12, 0.79, 0.76, 0.145, place: 'over', look: 'box', boxH: 0.8),
            // Under the game's hint box, same left edge and width, as a dark game-style card.
            ReadingArea(t('Instructions (top left)'), 0.01, 0.12, 0.32, 0.36, place: 'below', look: 'card', align: 'left'),
            // The mission hint at the very bottom (95–99%).
            ReadingArea(t('Instructions (bottom)'), 0.18, 0.94, 0.64, 0.06,
                place: 'custom', tx: 0.10, ty: 0.765, tw: 0.80, th: 0.05, look: 'subtitle', font: 0.92, accent: '#FF3FA4'),
          ],
        _ => [ReadingArea(t('Subtitles'), 0.08, 0.72, 0.84, 0.24, place: 'over', look: 'box', boxH: 0.8)],
      };
}

class DisplayPrefs {
  const DisplayPrefs({this.display = 'over', this.plateColor = '#000000', this.history = 'off', this.readHold = true, this.fadeMs = 150, this.stableMs = 0, this.goneAfterMs = 1200, this.areas = const []});

  /// Reading areas for the PS5 reader (empty: the usual subtitle band).
  final List<ReadingArea> areas;

  /// 'over': the Arabic hides the English; 'above': just above it, the English stays visible; 'history': nothing over
  /// the game, only the history panel.
  final String display;

  /// Colour of the text boxes and the history panel on the TV, #RRGGBB.
  final String plateColor;

  /// The history panel's side: history-only display always shows it (right unless left was chosen).
  String get historySide => display == 'history' && history == 'off' ? 'right' : history;

  /// Side panel with the recent dialogue on the TV: 'off', 'left' or 'right'.
  final String history;

  /// Keep a replaced line up (dimmed, above the new one) until it could be read.
  final bool readHold;
  final int fadeMs;

  /// Look a line up only once the text held this long (avoids half-drawn lines).
  final int stableMs;

  /// Clear the TV after the English has been gone this long.
  final int goneAfterMs;

  DisplayPrefs copyWith({String? display, String? plateColor, String? history, bool? readHold, int? fadeMs, int? stableMs, int? goneAfterMs, List<ReadingArea>? areas}) => DisplayPrefs(
        areas: areas ?? this.areas,
        plateColor: plateColor ?? this.plateColor,
        display: display ?? this.display,
        history: history ?? this.history,
        readHold: readHold ?? this.readHold,
        fadeMs: fadeMs ?? this.fadeMs,
        stableMs: stableMs ?? this.stableMs,
        goneAfterMs: goneAfterMs ?? this.goneAfterMs,
      );

  Map<String, dynamic> toJson() => {'display': display, 'plate_color': plateColor, 'history': history, 'read_hold': readHold, 'fade_ms': fadeMs, 'stable_ms': stableMs, 'gone_after_ms': goneAfterMs,
        'areas': [for (final a in areas) a.toJson()]};

  factory DisplayPrefs.fromJson(Map<String, dynamic> j) {
    int ms(String k, int def, int lo, int hi) => ((j[k] as num?)?.toInt() ?? def).clamp(lo, hi);
    return DisplayPrefs(
      display: const ['above', 'history'].contains(j['display']) ? j['display'] as String : 'over',
      plateColor: RegExp(r'^#[0-9A-Fa-f]{6}$').hasMatch(j['plate_color'] as String? ?? '') ? j['plate_color'] as String : '#000000',
      history: const ['left', 'right'].contains(j['history']) ? j['history'] as String : 'off',
      readHold: j['read_hold'] as bool? ?? true,
      fadeMs: ms('fade_ms', 150, 0, 1000),
      stableMs: ms('stable_ms', 0, 0, 1000),
      goneAfterMs: ms('gone_after_ms', 1200, 100, 3000),
      areas: [for (final a in (j['areas'] as List? ?? const [])) ReadingArea.fromJson(a as Map<String, dynamic>)],
    );
  }

  /// The keys LUGHA TV reads from style.set.
  Map<String, dynamic> tvStyle() => {'display': display, 'history': historySide, 'plate_color': plateColor, 'fade_ms': fadeMs, 'read_hold': readHold};
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
