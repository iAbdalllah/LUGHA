import 'package:flutter_test/flutter_test.dart';
import 'package:lugha_mobile/display.dart';
import 'package:lugha_mobile/matching.dart';

void main() {
  String? table(String q) => const {
        'Absolutely no rush you take your time': 'على راحتك، خذ وقتك!',
        'Okay...': 'تمام...',
        'TAKE AMMO PACK': 'خذ صندوق ذخيرة',
      }[q];

  test('garbled names, punctuation, speaker, counters and button icons from the log', () {
    expect(findLine('Staf Gember Absolutely no rush you take your time', table)!.hit, 'على راحتك، خذ وقتك!');
    final w = findLine('CO Wilson: Okay…', table)!;
    expect([w.hit, w.speaker], ['تمام...', 'CO Wilson']);
    expect(splitSpeaker('co Wilson: keep it straight').$1, 'co Wilson');
    final c = findLine('TAKE AMMO PACK (0/3)', table)!;
    expect([c.hit, c.counter], ['خذ صندوق ذخيرة', '(0/3)']);
    expect(glyphParts('DEFUSE X PROVOKE'), ['DEFUSE', 'PROVOKE']);
    expect(trimGlyphs('[O] Photo Studio'), 'Photo Studio');
    expect(findLine('Something nobody wrote down', table), isNull);
  });

  test('reading areas are saved with the display prefs', () {
    const p = DisplayPrefs(areas: [ReadingArea('Subtitles', 0.1, 0.8, 0.8, 0.118), ReadingArea('Tips', 0, 0.1, 0.25, 0.2, enabled: false)]);
    final back = DisplayPrefs.fromJson(p.toJson());
    expect(back.areas.map((a) => [a.name, a.enabled]).toList(), [['Subtitles', true], ['Tips', false]]);
    expect(back.areas.first.h, closeTo(0.118, 1e-9));
  });
}
