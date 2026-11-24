import 'package:fake_async/fake_async.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lugha_mobile/display.dart';

void main() {
  test('prefs round-trip and reject unknown values', () {
    const p = DisplayPrefs(display: 'above', history: 'left', readHold: false, fadeMs: 200, stableMs: 100, goneAfterMs: 900);
    expect(DisplayPrefs.fromJson(p.toJson()).toJson(), p.toJson());
    final bad = DisplayPrefs.fromJson({'display': 'sideways', 'history': 'top', 'gone_after_ms': 99999});
    expect([bad.display, bad.history, bad.goneAfterMs], ['over', 'off', 3000]);
    expect(p.tvStyle(), {'display': 'above', 'history': 'left', 'plate_color': '#000000', 'fade_ms': 200, 'read_hold': false});
    const h = DisplayPrefs(display: 'history', plateColor: '#1B2A4A');
    expect(h.tvStyle()['history'], 'right', reason: 'history only always shows the panel');
    expect(DisplayPrefs.fromJson(h.toJson()).plateColor, '#1B2A4A');
    expect(DisplayPrefs.fromJson({'plate_color': 'red'}).plateColor, '#000000');
  });

  test('stable gate waits for the text to hold and restarts on a change', () {
    fakeAsync((t) {
      final out = <String>[];
      final g = StableGate((l) => out.add(l.join(' ')))..hold = const Duration(milliseconds: 200);
      g.feed(['Hello the']);
      t.elapse(const Duration(milliseconds: 100));
      g.feed(['Hello there']); // still drawing: restart
      t.elapse(const Duration(milliseconds: 150));
      g.feed(['Hello there']); // same text: keeps the running wait
      expect(out, isEmpty);
      t.elapse(const Duration(milliseconds: 60));
      expect(out, ['Hello there']);
    });
  });

  test('zero hold passes text at once', () {
    final out = <String>[];
    StableGate((l) => out.add(l.first)).feed(['Now']);
    expect(out, ['Now']);
  });
}
