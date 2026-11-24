// Display rules shared with the Mac and TV apps (pure functions; tested in test/overlay.test.mjs).
(function (root) {
  /** How long a line stays up at least so it can be read: 1.5–5 s by length. */
  function readMs(text) { return Math.min(Math.max(900 + 55 * [...text].length, 1500), 5000); }

  /**
   * Keeps a replaced line on screen until it could be read: at most one earlier line, dimmed, above the current one.
   * update(line|null, now) when the subtitle changes; visible(now) → [{line, old}] earlier first; nextExpiry().
   */
  function ReadingHold() {
    let current = null, since = 0, old = null, oldUntil = 0;
    return {
      update(line, now) {
        if (current && (!line || line.text !== current.text)) { old = current; oldUntil = since + readMs(current.text); }
        if (line && (!current || line.text !== current.text)) { current = line; since = now; }
        if (!line) current = null;
        if (old && line && old.text === line.text) old = null;
      },
      visible(now) {
        const out = [];
        if (old && now < oldUntil) out.push({ line: old, old: true }); else old = null;
        if (current) out.push({ line: current, old: false });
        return out;
      },
      nextExpiry() { return old ? oldUntil : null; },
    };
  }

  /** Recent lines for the side panel: newest last, each once, at most max. */
  function Recent(max = 6) {
    const items = []; let changedAt = 0;
    return {
      add(line, now) {
        if (!line || items.some(i => i.text === line.text)) return;
        items.push(line); while (items.length > max) items.shift(); changedAt = now;
      },
      get items() { return items.slice(); },
      get changedAt() { return changedAt; },
      clear() { items.length = 0; },
    };
  }

  const api = { readMs, ReadingHold, Recent };
  if (typeof module !== 'undefined') module.exports = api; else root.LughaOverlay = api;
})(this);
