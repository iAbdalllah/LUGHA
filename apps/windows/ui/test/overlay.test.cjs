// node --test apps/windows/ui/test — display rules of the Windows overlay (same as the Mac and TV apps).
const test = require('node:test');
const assert = require('node:assert');
const { readMs, ReadingHold, Recent } = require('../overlay-logic.js');

test('a replaced line stays dimmed above the new one until it could be read', () => {
  const h = ReadingHold();
  const a = { text: 'أوه! المشتبه فيه ترك مركبته!', speaker: 'Cop' };
  const b = { text: 'ازحف أسرع يا خوي.', speaker: 'Jason' };
  h.update(a, 0);
  h.update(b, 300);
  assert.deepStrictEqual(h.visible(400), [{ line: a, old: true }, { line: b, old: false }]);
  assert.deepStrictEqual(h.visible(readMs(a.text) + 1), [{ line: b, old: false }]);
});

test('only one earlier line is kept; a returning line is current again', () => {
  const h = ReadingHold();
  const a = { text: 'السطر الأول من الحوار' }, b = { text: 'السطر الثاني من الحوار' }, c = { text: 'السطر الثالث من الحوار' };
  h.update(a, 0); h.update(b, 100); h.update(c, 200);
  assert.deepStrictEqual(h.visible(300).map(x => x.line.text), [b.text, c.text]);
  h.update(b, 400);
  assert.deepStrictEqual(h.visible(500).map(x => [x.line.text, x.old]), [[c.text, true], [b.text, false]]);
});

test('recent dialogue keeps each line once, newest last', () => {
  const r = Recent(2);
  r.add({ text: 'a' }, 1); r.add({ text: 'b' }, 2); r.add({ text: 'a' }, 3); r.add({ text: 'c' }, 4);
  assert.deepStrictEqual(r.items.map(i => i.text), ['b', 'c']);
  assert.strictEqual(r.changedAt, 4);
});
