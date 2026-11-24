// Overlay page: covers the monitor (click-through). The subtitle block goes over the English subtitle area ("over")
// or just above it ("above"); the history panel sits at the chosen side and dims a few seconds after a new line.
const { ReadingHold, Recent } = window.LughaOverlay;
const block = document.getElementById('block');
const panel = document.getElementById('history');
const hold = ReadingHold();
const recent = Recent();
let cfg = { region: { x: 192, y: 810, w: 1536, h: 243 }, display: 'over', history: 'off', fade_ms: 150, read_hold: true };
let current = null, timer = null, dimTimer = null;

function el(line, cls) {
  const s = document.createElement('span');
  s.className = cls;
  if (line.speaker) { const n = document.createElement('span'); n.className = 'spk'; n.textContent = line.speaker + ': '; s.append(n); }
  s.append(document.createTextNode(line.text));
  return s;
}

function render() {
  clearTimeout(timer);
  const now = Date.now();
  const shown = cfg.read_hold ? hold.visible(now) : (current ? [{ line: current, old: false }] : []);
  const dpr = window.devicePixelRatio || 1;
  const r = { x: cfg.region.x / dpr, y: cfg.region.y / dpr, w: cfg.region.w / dpr, h: cfg.region.h / dpr };
  document.body.style.setProperty('--fade', cfg.fade_ms + 'ms');
  if (!shown.length) { block.hidden = true; block.replaceChildren(); }
  else {
    const before = block.textContent;
    block.replaceChildren(...shown.map(s => el(s.line, 'line' + (s.old ? ' old' : ''))));
    block.className = cfg.display === 'above' ? 'above' : 'over';
    // On the picture ("above"): text colour adapts to what is behind it, with the opposite edge and a soft backdrop
    // on busy or mid-grey ground (sampled by the app).
    const t = current && current.tone_fg ? current : null;
    if (cfg.display === 'above' && t) {
      const dark = t.tone_fg.toLowerCase() !== '#ffffff';
      const edge = dark ? '255,255,255' : '0,0,0';
      block.style.color = t.tone_fg;
      block.style.textShadow = `0 0 4px rgb(${edge}), 0 0 2px rgb(${edge}), 0 1px 3px rgba(${edge},.8)`;
      block.style.background = `rgba(${edge},${t.tone_shade || 0})`;
    } else { block.style.color = ''; block.style.textShadow = ''; block.style.background = ''; }
    block.style.left = (r.x + r.w / 2) + 'px';
    block.style.minWidth = cfg.display === 'above' ? '0' : Math.min(r.w, window.innerWidth * 0.92) + 'px';
    if (cfg.display === 'above') { block.style.top = ''; block.style.bottom = (window.innerHeight - r.y + 4) + 'px'; }
    else { block.style.top = ''; block.style.bottom = Math.max(window.innerHeight - (r.y + r.h) - 6, 0) + 'px'; }
    if (block.hidden || before !== block.textContent) { block.classList.add('fade'); }
    block.hidden = false;
  }
  if (cfg.history === 'off' || !recent.items.length) panel.hidden = true;
  else {
    const items = recent.items;
    panel.replaceChildren(...items.map((l, n) => { const d = el(l, 'item'); d.style.display = 'block'; d.style.opacity = 0.45 + 0.55 * (n + 1) / items.length; return d; }));
    panel.className = cfg.history;
    panel.style.opacity = now - recent.changedAt < 6000 ? 0.92 : 0.38;
    panel.hidden = false;
    clearTimeout(dimTimer);
    if (now - recent.changedAt < 6000) dimTimer = setTimeout(render, recent.changedAt + 6000 - now + 20);
  }
  const next = cfg.read_hold ? hold.nextExpiry() : null;
  if (next) timer = setTimeout(render, Math.max(next - now, 50));
}

const ev = window.__TAURI__?.event;
ev?.listen('subtitle', ({ payload }) => {
  const now = Date.now();
  current = payload ? { text: payload.text, speaker: payload.speaker, tone_fg: payload.tone_fg, tone_shade: payload.tone_shade } : null;
  hold.update(current, now);
  recent.add(current, now);
  render();
});
ev?.listen('overlay-config', ({ payload }) => { cfg = { ...cfg, ...payload }; render(); });
window.__TAURI__?.core.invoke('overlay_ready').catch(() => {});
