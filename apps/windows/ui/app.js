// LUGHA for Windows — UI. Talks to the Rust side through Tauri commands (src/app.rs).
// Opened in a plain browser (no Tauri), it runs against a small in-page mock so the layout can be previewed.

const T = window.__TAURI__;
const invoke = T ? T.core.invoke : mockInvoke;
const listen = T ? T.event.listen : () => {};
const $ = (id) => document.getElementById(id);

let state = null;
let dcTimer = null;
let ps5Chosen = false; // the PS5 source is picked but not linked yet
let ps5 = null;        // ps5_status
let ps5Pick = null;    // console chosen in the search list
let shotTimer = null;

function toast(text, err = false) {
  const t = $('toast');
  t.textContent = text;
  t.className = 'toast' + (err ? ' err' : '');
  t.hidden = false;
  clearTimeout(toast.h);
  toast.h = setTimeout(() => (t.hidden = true), 4200);
}

async function call(cmd, args) {
  try {
    return await invoke(cmd, args);
  } catch (e) {
    toast(String(e), true);
    throw e;
  }
}

function showTab(name) {
  document.querySelectorAll('nav button').forEach((b) => b.classList.toggle('on', b.dataset.tab === name));
  document.querySelectorAll('.tab').forEach((s) => (s.hidden = s.id !== 'tab-' + name));
  if (name === 'history') loadHistory();
}

async function refresh() {
  state = await call('status');
  if (state.last_error) toast(state.last_error, true);
  const signedIn = state.signed_in;
  $('signin').hidden = signedIn;
  document.querySelector('nav').style.visibility = signedIn ? 'visible' : 'hidden';
  document.querySelectorAll('.tab').forEach((s) => { if (!signedIn) s.hidden = true; });
  if (signedIn && [...document.querySelectorAll('.tab')].every((s) => s.hidden)) showTab('play');
  $('who').textContent = signedIn ? (state.user || '') : '';
  $('server').value = state.server;

  // Play
  const r = state.region;
  [['rx', r.x], ['ry', r.y], ['rw', r.w], ['rh', r.h]].forEach(([id, v]) => ($(id).value = v));
  const cap = state.capturing;
  $('cap-title').textContent = cap ? 'الترجمة تعمل' : 'الترجمة متوقفة';
  $('cap-sub').textContent = state.use_ps5
    ? (cap ? 'تُقرأ الترجمة من PS5 مباشرة وتظهر على التلفاز.' : 'اللعب على التلفاز: لغة تقرأ صورة PS5 بنفسها.')
    : cap
    ? (state.tv_connected ? 'تظهر الترجمة فوق اللعبة وعلى التلفاز.' : 'تظهر الترجمة فوق اللعبة.')
    : (state.windows ? 'شغّل اللعبة في وضع نافذة أو ملء الشاشة بلا حدود، ثم ابدأ.' : 'الالتقاط متاح على ويندوز فقط.');
  $('cap-toggle').textContent = cap ? 'إيقاف' : 'ابدأ الترجمة';
  $('cap-toggle').className = cap ? 'stop big' : 'primary big';

  // Library
  const ul = $('packages');
  ul.innerHTML = '';
  if (!state.packages.length) ul.innerHTML = '<li class="muted">لا توجد حزم مثبتة بعد. اضغط «مزامنة» بعد الشراء.</li>';
  state.packages.forEach(([id, v]) => {
    const li = document.createElement('li');
    li.innerHTML = '<span><span class="dot ok"></span></span><span class="src"></span>';
    li.firstChild.append(id);
    li.lastChild.textContent = 'v' + v;
    ul.append(li);
  });

  // TV
  const tv = $('tv-state');
  tv.innerHTML = '';
  const h = document.createElement('h2');
  const p = document.createElement('p');
  p.className = 'muted';
  if (state.tv_paired) {
    h.innerHTML = `<span class="dot ${state.tv_connected ? 'ok' : ''}"></span>`;
    h.append(state.tv_connected ? 'متصل بالتلفاز' : 'التلفاز غير متصل');
    p.textContent = state.tv || '';
    p.dir = 'ltr';
    const row = document.createElement('div');
    row.className = 'row';
    row.append(btn(state.tv_connected ? 'اختبار' : 'اتصال', async () => {
      if (state.tv_connected) toast(`وصلت للتلفاز خلال ${await call('tv_test')} ms`);
      else { await call('tv_connect', { addr: null }); toast('تم الاتصال'); refresh(); }
    }), btn('إلغاء الاقتران', async () => { await call('tv_forget'); refresh(); }, 'ghost'));
    tv.append(h, p, row);
  } else {
    h.textContent = 'لا يوجد تلفاز مقترن';
    p.textContent = 'ثبّت لغة على Android TV ثم اقترن من هنا مرة واحدة.';
    tv.append(h, p);
  }
  if (state.tv && !$('tv-addr').value) $('tv-addr').value = state.tv;

  // Account
  const acc = $('account');
  acc.innerHTML = '';
  const n = document.createElement('h2');
  n.textContent = state.user || '—';
  const d = document.createElement('p');
  d.className = 'muted';
  d.textContent = `هذا الجهاز: ${state.device_id || '—'} · الخادم: ${state.server}`;
  acc.append(n, d);
  $('ocr-lang').value = state.ocr_language;

  // Read from: screen region or a window (PS Remote Play)
  const win = state.capture_window;
  if (win && ![...$('source').options].some((o) => o.value === win)) $('source').append(new Option(win + ' (غير مفتوحة)', win));
  const usePs5 = state.use_ps5 || ps5Chosen;
  $('source').value = usePs5 ? '__ps5__' : (win || '');
  $('window-opts').hidden = !win || usePs5;
  $('region-card').hidden = !!win || usePs5;
  $('ps5-card').hidden = !usePs5;
  if (usePs5) loadPs5();
  const a = state.window_area;
  [['wx', a[0]], ['wy', a[1]], ['ww', a[2]], ['wh', a[3]]].forEach(([id, v]) => ($(id).value = Math.round(v * 100)));
  $('show-pc').checked = state.show_on_pc;
  renderAreas(state.areas || []);
  // Display options (also sent to the TV)
  const disp = state.display || {};
  $('d-display').value = disp.display || 'over';
  $('d-history').value = disp.history || 'off';
  $('d-plate').value = disp.plate_color || '#000000';
  $('d-hold').checked = disp.read_hold !== false;
  $('d-stable').value = disp.stable_ms ?? 200;
  $('d-gone').value = disp.gone_after_ms ?? 1200;
  $('d-fade').value = disp.fade_ms ?? 150;
}

async function loadWindows() {
  const titles = await call('list_windows');
  const cur = $('source').value;
  $('source').innerHTML = '<option value="__ps5__">PS5 مباشرة (بدون تطبيق Remote Play) ★</option><option value="">منطقة من الشاشة</option>';
  titles.forEach((t) => $('source').append(new Option(t.toLowerCase().includes('remote play') ? t + ' ★' : t, t)));
  $('source').value = cur;
}

function btn(label, fn, cls = '') {
  const b = document.createElement('button');
  b.textContent = label;
  b.className = cls;
  b.onclick = fn;
  return b;
}

function syncNote(s) {
  const parts = [];
  if (s.installed.length) parts.push(`ثُبّت: ${s.installed.join('، ')}`);
  if (s.updated.length) parts.push(`حُدّث: ${s.updated.join('، ')}`);
  if (s.removed.length) parts.push(`أُزيل: ${s.removed.join('، ')}`);
  $('sync-note').textContent = parts.join(' · ') || 'مكتبتك محدّثة.';
  if (s.errors.length) toast(s.errors.join('\n'), true);
}

function renderSub(line) {
  const p = $('preview');
  p.innerHTML = '';
  if (!line) { p.innerHTML = '<span class="muted">ستظهر الترجمة هنا وفوق اللعبة.</span>'; return; }
  const box = document.createElement('div');
  if (line.speaker) { const s = document.createElement('span'); s.className = 'spk'; s.textContent = line.speaker; box.append(s); }
  const t = document.createElement('span'); t.className = 'sub'; t.textContent = line.text; box.append(t);
  const r = btn('الترجمة خاطئة؟ أبلغ عنها', () => openReport(line), 'link report');
  box.append(document.createElement('br'), r);
  p.append(box);
}

// ---- report a line ----
let reporting = null;
function openReport(line) {
  reporting = line;
  $('report-text').textContent = (line.speaker ? line.speaker + ': ' : '') + line.text;
  $('report-src').textContent = line.source;
  $('report-reason').value = 'wrong_meaning';
  $('report-note').value = '';
  $('report').showModal();
}
$('report').onclose = async () => {
  const line = reporting;
  reporting = null;
  if ($('report').returnValue !== 'send' || !line) return;
  try {
    await call('report_line', { package: line.package_id, entryId: line.entry_id, reason: $('report-reason').value, note: $('report-note').value || null });
    toast('شكراً، وصل بلاغك لفريق المراجعة.');
  } catch (_) { /* call() already showed the error */ }
};

async function loadHistory() {
  const items = await call('history');
  const ul = $('history');
  ul.innerHTML = '';
  if (!items.length) ul.innerHTML = '<li class="muted">لم تُعرض أي ترجمة بعد.</li>';
  items.forEach((l) => {
    const li = document.createElement('li');
    const txt = document.createElement('span'); txt.className = 'txt';
    const a = document.createElement('span'); a.textContent = (l.speaker ? l.speaker + ': ' : '') + l.text;
    const b = document.createElement('span'); b.className = 'src'; b.textContent = l.source;
    txt.append(a, b);
    const acts = document.createElement('span'); acts.className = 'acts';
    acts.append(btn('إبلاغ', () => openReport(l), 'link'));
    li.append(txt, acts);
    ul.append(li);
  });
}

// Reading areas: one block per box — name, X/Y/W/H in % of the captured picture, on/off, delete — and its design
// (where the translation goes, its look, alignment, sizes, accent), the same options as the Mac and phone apps.
const AREA_DEFAULTS = { place: 'auto', tx: 0.1, ty: 0.76, tw: 0.8, th: 0.06, valign: 'bottom', look: 'auto', align: 'center', font: 1, box_w: 1, box_h: 1, accent: null };
const PRESETS = {
  subtitles: () => [{ name: 'الترجمة', x: 0.08, y: 0.72, w: 0.84, h: 0.24, enabled: true, place: 'over', look: 'box', box_h: 0.8 }],
  full: () => [{ name: 'الشاشة كاملة', x: 0, y: 0, w: 1, h: 1, enabled: true }],
  game: () => [
    { name: 'الترجمة', x: 0.12, y: 0.79, w: 0.76, h: 0.145, enabled: true, place: 'over', look: 'box', box_h: 0.8 },
    { name: 'التعليمات (أعلى اليسار)', x: 0.01, y: 0.12, w: 0.32, h: 0.36, enabled: true, place: 'below', look: 'card', align: 'left' },
    { name: 'التعليمات (الأسفل)', x: 0.18, y: 0.94, w: 0.64, h: 0.06, enabled: true, place: 'custom', tx: 0.10, ty: 0.765, tw: 0.80, th: 0.05, look: 'subtitle', font: 0.92, accent: '#FF3FA4' },
  ],
};

function renderAreas(areas) {
  const box = $('areas');
  box.replaceChildren(...areas.map((a0) => {
    const a = { ...AREA_DEFAULTS, ...a0 };
    const wrap = document.createElement('div');
    wrap.className = 'area';
    const row = document.createElement('div');
    row.className = 'row';
    const num = (k, v, key) => { const l = document.createElement('label'); l.textContent = k + ' %'; const i = document.createElement('input'); i.type = 'number'; i.min = 0; i.max = 100; i.step = 0.5; i.value = Math.round(v * 1000) / 10; i.dataset.k = key; l.append(i); return l; };
    const name = document.createElement('input'); name.value = a.name; name.dataset.k = 'name'; name.style.maxWidth = '11em';
    const on = document.createElement('input'); on.type = 'checkbox'; on.checked = a.enabled !== false; on.dataset.k = 'enabled';
    const del = btn('حذف', () => { wrap.remove(); drawBoxes(); }, 'link');
    row.append(on, name, num('X', a.x, 'x'), num('Y', a.y, 'y'), num('W', a.w, 'w'), num('H', a.h, 'h'), del);

    const sel = (label, key, value, options) => {
      const l = document.createElement('label'); l.textContent = label;
      const s = document.createElement('select'); s.dataset.k = key;
      options.forEach(([v, t]) => s.append(new Option(t, v)));
      s.value = value; l.append(s); return l;
    };
    const range = (label, key, value, min, max) => {
      const l = document.createElement('label'); l.textContent = label;
      const i = document.createElement('input'); i.type = 'range'; i.min = min; i.max = max; i.step = 0.05; i.value = value; i.dataset.k = key;
      const out = document.createElement('span'); out.className = 'muted'; out.textContent = Math.round(value * 100) + '%';
      i.oninput = () => (out.textContent = Math.round(i.value * 100) + '%');
      l.append(i, out); return l;
    };
    const design = document.createElement('details');
    const sum = document.createElement('summary'); sum.textContent = 'التصميم';
    const r1 = document.createElement('div'); r1.className = 'row';
    r1.append(
      sel('الترجمة', 'place', a.place, [['auto', 'تلقائي'], ['over', 'فوق النص الإنجليزي'], ['below', 'أسفل مربع النص'], ['above', 'أعلى مربع النص'], ['custom', 'مكان مخصص']]),
      sel('الشكل', 'look', a.look, [['auto', 'تلقائي'], ['subtitle', 'نص فوق الصورة'], ['box', 'مربع'], ['card', 'بطاقة بأسلوب اللعبة']]),
      sel('المحاذاة', 'align', a.align, [['right', 'يمين'], ['center', 'وسط'], ['left', 'يسار']]),
      sel('موضع النص', 'valign', a.valign, [['top', 'الأعلى'], ['center', 'الوسط'], ['bottom', 'الأسفل']]),
    );
    const r2 = document.createElement('div'); r2.className = 'row custom-rect';
    const cap = document.createElement('span'); cap.className = 'muted'; cap.textContent = 'منطقة الترجمة (المكان المخصص):';
    r2.append(cap, num('X', a.tx, 'tx'), num('Y', a.ty, 'ty'), num('W', a.tw, 'tw'), num('H', a.th, 'th'));
    const r3 = document.createElement('div'); r3.className = 'row';
    r3.append(range('حجم النص', 'font', a.font, 0.5, 2), range('عرض المربع', 'box_w', a.box_w, 0.3, 2), range('ارتفاع المربع', 'box_h', a.box_h, 0, 2.5));
    const r4 = document.createElement('div'); r4.className = 'row';
    const acc = document.createElement('label'); acc.textContent = 'لون الحافة';
    const accOn = document.createElement('input'); accOn.type = 'checkbox'; accOn.checked = !!a.accent; accOn.dataset.k = 'accent_on';
    const accC = document.createElement('input'); accC.type = 'color'; accC.value = a.accent || '#E85FA0'; accC.dataset.k = 'accent';
    acc.append(accOn, accC); r4.append(acc);
    design.append(sum, r1, r2, r3, r4);
    wrap.append(row, design);
    return wrap;
  }));
}

function readAreas() {
  return [...document.querySelectorAll('#areas .area')].map((row, n) => {
    const q = (k) => row.querySelector(`[data-k="${k}"]`);
    const v = (k) => +q(k).value / 100;
    return {
      name: q('name').value || `منطقة ${n + 1}`, x: v('x'), y: v('y'), w: v('w'), h: v('h'), enabled: q('enabled').checked,
      place: q('place').value, tx: v('tx'), ty: v('ty'), tw: v('tw'), th: v('th'), valign: q('valign').value,
      look: q('look').value, align: q('align').value, font: +q('font').value, box_w: +q('box_w').value, box_h: +q('box_h').value,
      accent: q('accent_on').checked ? q('accent').value.toUpperCase() : null,
    };
  });
}

function applyPreset(kind) {
  renderAreas(PRESETS[kind]());
  drawBoxes();
  toast('اضغط «حفظ» في «مناطق القراءة» لاعتماد القالب.');
}

// ---- PS5 (LUGHA's own Remote Play) ----
const PS5_STATE = { off: 'غير متصل', connecting: 'جارٍ الاتصال…', connected: 'متصل', pin: 'يطلب رمز الدخول' };

async function loadPs5() {
  ps5 = await call('ps5_status');
  $('psn-state').textContent = ps5.psn ? '✓ تم تسجيل الدخول' : '';
  $('psn-btn').textContent = ps5.psn ? 'تبديل الحساب' : 'تسجيل الدخول إلى PlayStation Network';
  $('ps5-setup').hidden = ps5.linked;
  $('ps5-linked').hidden = !ps5.linked;
  if (ps5.linked) {
    $('ps5-name').textContent = ps5.console.name || 'PS5';
    $('ps5-addr').textContent = ps5.console.host;
    showPs5State(ps5.state);
  }
}

function showPs5State(st) {
  const on = st === 'connected';
  $('ps5-conn').textContent = PS5_STATE[st] || st;
  $('ps5-dot').className = 'dot' + (on ? ' ok' : '');
  $('ps5-connect').hidden = on || st === 'connecting';
  $('ps5-disconnect').hidden = !(on || st === 'connecting');
  $('ps5-shot').hidden = !on;
  $('ps5-hint').hidden = !on;
  clearInterval(shotTimer);
  if (on) { loadShot(); shotTimer = setInterval(loadShot, 1500); }
}

async function loadShot() {
  if ($('ps5-card').hidden || $('tab-play').hidden) return;
  const url = await invoke('ps5_picture').catch(() => null);
  if (url) $('ps5-img').src = url;
  drawBoxes();
}

// The reading areas drawn over the live picture; dragging on it adds one.
function drawBoxes() {
  const host = $('ps5-boxes');
  const areas = readAreas();
  const boxes = areas.map((a) => {
    const d = document.createElement('div');
    d.className = 'box' + (a.enabled ? '' : ' off');
    Object.assign(d.style, { left: a.x * 100 + '%', top: a.y * 100 + '%', width: a.w * 100 + '%', height: a.h * 100 + '%' });
    const l = document.createElement('span'); l.textContent = a.name; d.append(l);
    return d;
  });
  // Hand-placed translation boxes, in pink.
  const targets = areas.filter((a) => a.place === 'custom').map((a) => {
    const d = document.createElement('div');
    d.className = 'box tr';
    Object.assign(d.style, { left: a.tx * 100 + '%', top: a.ty * 100 + '%', width: a.tw * 100 + '%', height: a.th * 100 + '%' });
    const l = document.createElement('span'); l.textContent = '↳ ' + a.name; d.append(l);
    return d;
  });
  host.replaceChildren(...boxes, ...targets);
  // What dragging draws: a new area, or where an area's translation goes.
  const sel = $('draw-target');
  const cur = sel.value;
  sel.replaceChildren(new Option('الرسم: منطقة قراءة جديدة', '-1'), ...areas.map((a, n) => new Option(`الرسم: مكان ترجمة «${a.name}»`, String(n))));
  sel.value = [...sel.options].some((o) => o.value === cur) ? cur : '-1';
}

(() => {
  const shot = $('ps5-shot');
  let start = null, ghost = null;
  const at = (e) => { const r = shot.getBoundingClientRect(); return [Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)), Math.min(1, Math.max(0, (e.clientY - r.top) / r.height))]; };
  shot.onpointerdown = (e) => {
    start = at(e);
    shot.setPointerCapture(e.pointerId);
    ghost = document.createElement('div'); ghost.className = 'box'; $('ps5-boxes').append(ghost);
  };
  shot.onpointermove = (e) => {
    if (!start) return;
    const [x, y] = at(e);
    Object.assign(ghost.style, { left: Math.min(x, start[0]) * 100 + '%', top: Math.min(y, start[1]) * 100 + '%', width: Math.abs(x - start[0]) * 100 + '%', height: Math.abs(y - start[1]) * 100 + '%' });
  };
  shot.onpointerup = (e) => {
    if (!start) return;
    const [x, y] = at(e);
    const box = { x: Math.min(x, start[0]), y: Math.min(y, start[1]), w: Math.abs(x - start[0]), h: Math.abs(y - start[1]) };
    start = null;
    if (box.w > 0.03 && box.h > 0.02) {
      const now = readAreas();
      const t = +$('draw-target').value;
      if (t >= 0 && now[t]) {
        // Where this area's translation goes.
        Object.assign(now[t], { place: 'custom', tx: box.x, ty: box.y, tw: box.w, th: box.h });
        renderAreas(now);
        toast('حُدد مكان الترجمة. اضغط «حفظ» في «مناطق القراءة».');
      } else {
        renderAreas([...now, { name: `منطقة ${now.length + 1}`, ...box, enabled: true }]);
        toast('أُضيفت المنطقة. اضغط «حفظ» في «مناطق القراءة».');
      }
    }
    drawBoxes();
  };
})();

$('psn-btn').onclick = () => call('psn_sign_in');
$('ps5-find').onclick = async (e) => {
  e.target.disabled = true;
  $('ps5-list').textContent = 'جارٍ البحث…';
  try {
    const list = await call('ps5_find');
    $('ps5-list').replaceChildren();
    if (!list.length) $('ps5-list').textContent = 'لم يُعثر على جهاز. تأكد أن PS5 يعمل وعلى نفس الشبكة، أو اكتب عنوانه.';
    list.forEach((c) => {
      const b = btn(`${c.name} (${c.host})${c.ready ? '' : ' · وضع الراحة'}`, () => {
        ps5Pick = c; $('ps5-host').value = c.host;
        [...$('ps5-list').children].forEach((x) => x.classList.toggle('on', x === b));
      }, 'ghost');
      $('ps5-list').append(b);
      if (list.length === 1) b.click();
    });
  } finally { e.target.disabled = false; }
};
$('ps5-link').onclick = async (e) => {
  const host = $('ps5-host').value.trim();
  if (!host) return toast('ابحث عن الجهاز أو اكتب عنوانه.', true);
  e.target.disabled = true;
  try {
    const name = ps5Pick && ps5Pick.host === host ? ps5Pick.name : 'PS5';
    const ps5flag = ps5Pick && ps5Pick.host === host ? ps5Pick.ps5 : true;
    await call('ps5_link', { host, name, ps5: ps5flag, pin: $('ps5-pin').value });
    $('ps5-pin').value = '';
    await call('set_use_ps5', { on: true });
    toast('تم ربط PS5');
    refresh();
  } finally { e.target.disabled = false; }
};
$('ps5-connect').onclick = () => call('ps5_connect');
$('ps5-disconnect').onclick = () => call('ps5_disconnect');
$('ps5-wake').onclick = async () => { await call('ps5_wake'); toast('أُرسل أمر الإيقاظ. انتظر نحو ٢٠ ثانية ثم اتصل.'); };
$('ps5-forget').onclick = async () => {
  if (!confirm('إلغاء ربط هذا الكمبيوتر بجهاز PS5؟')) return;
  await call('ps5_forget');
  ps5Chosen = true;
  refresh();
};

// ---- wiring ----

$('area-add').onclick = () => {
  const now = readAreas();
  renderAreas([...now, { name: `منطقة ${now.length + 1}`, x: 0.1, y: 0.8, w: 0.8, h: 0.12, enabled: true }]);
};
document.querySelectorAll('[data-preset]').forEach((b) => (b.onclick = () => applyPreset(b.dataset.preset)));
$('areas-save').onclick = async () => { await call('set_areas', { areas: readAreas() }); toast('حُفظت مناطق القراءة'); refresh(); };

$('display-save').onclick = async () => {
  const n = (id, def) => Number.isFinite(+$(id).value) && $(id).value !== '' ? Math.round(+$(id).value) : def;
  await call('set_display', { display: {
    display: $('d-display').value, history: $('d-history').value, plate_color: $('d-plate').value, read_hold: $('d-hold').checked,
    stable_ms: n('d-stable', 200), gone_after_ms: n('d-gone', 1200), fade_ms: n('d-fade', 150),
  } });
  toast('حُفظت طريقة العرض');
  refresh();
};

document.querySelectorAll('nav button').forEach((b) => (b.onclick = () => showTab(b.dataset.tab)));

$('dc-start').onclick = async () => {
  const d = await call('start_device_sign_in');
  $('dc-box').hidden = false;
  $('dc-code').textContent = d.user_code;
  $('dc-url').textContent = d.url;
  $('dc-url').href = d.url;
  clearInterval(dcTimer);
  dcTimer = setInterval(async () => {
    try {
      const s = await invoke('poll_device_sign_in', { deviceCode: d.device_code });
      if (s) { clearInterval(dcTimer); syncNote(s); toast('تم تسجيل الدخول'); refresh(); }
    } catch (e) { clearInterval(dcTimer); toast(String(e), true); }
  }, d.interval * 1000);
};

$('pw').onsubmit = async (e) => {
  e.preventDefault();
  const b = e.submitter; b.disabled = true;
  try {
    const s = await call('sign_in', { email: $('email').value, password: $('password').value });
    $('password').value = '';
    syncNote(s);
    toast('تم تسجيل الدخول');
    refresh();
  } finally { b.disabled = false; }
};

$('server-save').onclick = async () => { await call('set_server', { url: $('server').value }); toast('تم الحفظ'); refresh(); };

$('cap-toggle').onclick = async () => {
  await call(state.capturing ? 'stop_capture' : 'start_capture');
  refresh();
};

$('region-save').onclick = async () => {
  const region = { x: +$('rx').value, y: +$('ry').value, w: +$('rw').value, h: +$('rh').value };
  await call('set_region', { region });
  toast('تم حفظ المنطقة');
  refresh();
};
$('source').onchange = async (e) => {
  const v = e.target.value;
  if (v === '__ps5__') {
    ps5Chosen = true;
    const st = await call('ps5_status');
    if (st.linked) await call('set_use_ps5', { on: true });
  } else {
    ps5Chosen = false;
    if (state.use_ps5) await call('set_use_ps5', { on: false });
    await call('set_capture_window', { title: v || null, area: null, showOnPc: null });
  }
  refresh();
};
$('source-refresh').onclick = loadWindows;
$('window-save').onclick = async () => {
  const area = ['wx', 'wy', 'ww', 'wh'].map((id) => Math.min(100, Math.max(0, +$(id).value)) / 100);
  await call('set_capture_window', { title: $('source').value || null, area, showOnPc: $('show-pc').checked });
  toast('تم الحفظ');
  refresh();
};
$('region-reset').onclick = async () => { await call('set_region', { region: null }); refresh(); };

$('try-go').onclick = async () => {
  const hit = await call('lookup', { text: $('try').value });
  $('try-out').textContent = hit ? (hit.speaker ? hit.speaker + ': ' : '') + hit.text : 'لا توجد ترجمة مطابقة في حزمك.';
};
$('try').onkeydown = (e) => { if (e.key === 'Enter') $('try-go').click(); };

$('sync').onclick = async (e) => {
  e.target.disabled = true;
  try { syncNote(await call('sync_now')); refresh(); } finally { e.target.disabled = false; }
};

$('tv-pair').onclick = async (e) => {
  e.target.disabled = true;
  try {
    await call('tv_pair', { addr: $('tv-addr').value, code: $('tv-code').value });
    $('tv-code').value = '';
    toast('تم الاقتران بالتلفاز');
    refresh();
  } finally { e.target.disabled = false; }
};

$('ocr-lang').onchange = (e) => call('set_ocr_language', { lang: e.target.value });

$('sign-out').onclick = async () => {
  if (!confirm('سيُحذف هذا الجهاز من حسابك وتُزال الحزم المثبتة عليه. متابعة؟')) return;
  await call('sign_out');
  refresh();
};

listen('subtitle', ({ payload }) => renderSub(payload));
listen('tv-lost', () => { toast('انقطع الاتصال بالتلفاز', true); refresh(); });
listen('capture-error', ({ payload }) => { toast(payload, true); refresh(); });
listen('psn-signed-in', () => { toast('تم تسجيل الدخول إلى PlayStation Network'); loadPs5(); });
listen('psn-error', ({ payload }) => toast(payload, true));
listen('ps5-state', ({ payload }) => {
  if (payload === 'pin') {
    const pin = prompt('يطلب PS5 رمز الدخول للمستخدم:');
    if (pin) invoke('ps5_set_pin', { pin });
  }
  if (!['off', 'connecting', 'connected', 'pin'].includes(payload)) toast('PS5: ' + payload, true);
  showPs5State(payload);
});

loadWindows().then(refresh);

// ---- browser preview mock (not used inside the app) ----
function mockInvoke(cmd, args) {
  const m = (mockInvoke.s ||= {
    signed_in: location.hash !== '#signin', user: 'لاعب تجريبي', server: 'http://127.0.0.1:8040', device_id: '01JD7W…',
    packages: [['sandline-sa-full', '1.1.0']], region: { x: 192, y: 810, w: 1536, h: 243 }, capturing: false,
    tv: '192.168.1.20:47800', tv_paired: true, tv_connected: true, ocr_language: 'en-US', windows: true, last_error: null,
    capture_window: null, window_area: [0.08, 0.7, 0.84, 0.26], show_on_pc: true,
    display: { display: 'over', plate_color: '#000000', history: 'off', fade_ms: 150, read_hold: true, stable_ms: 200, gone_after_ms: 1200 },
    areas: [{ name: 'الترجمة', x: 0.1, y: 0.8, w: 0.8, h: 0.118, enabled: true }],
    use_ps5: false, ps5: { psn: false, console: null, linked: false, state: 'off' },
  });
  const lines = { 'water costs more than gold out here': { text: 'الماء هنا أغلى من الذهب.', speaker: 'سالم', source: 'Water costs more than gold out here', entry_id: 6, package_id: 'sandline-sa-full' } };
  switch (cmd) {
    case 'status': return Promise.resolve({ ...m });
    case 'start_capture': m.capturing = true; renderSub(lines['water costs more than gold out here']); return Promise.resolve();
    case 'stop_capture': m.capturing = false; renderSub(null); return Promise.resolve();
    case 'lookup': return Promise.resolve(lines[args.text.trim().toLowerCase()] || null);
    case 'history': return Promise.resolve(Object.values(lines));
    case 'sync_now': return Promise.resolve({ installed: [], updated: [], removed: [], errors: [] });
    case 'tv_test': return Promise.resolve(18);
    case 'list_windows': return Promise.resolve(['PS Remote Play', 'Discord', 'File Explorer']);
    case 'set_capture_window': if (args.title !== undefined) m.capture_window = args.title; if (args.area) m.window_area = args.area; if (args.showOnPc != null) m.show_on_pc = args.showOnPc; return Promise.resolve();
    case 'report_line': mockInvoke.last = args; return Promise.resolve();
    case 'set_display': m.display = args.display; return Promise.resolve();
    case 'set_areas': m.areas = args.areas; return Promise.resolve();
    case 'start_device_sign_in': return Promise.resolve({ device_code: 'x', user_code: '7K4-Q9P', url: 'http://127.0.0.1:8040/activate', interval: 5 });
    case 'poll_device_sign_in': return Promise.resolve(null);
    case 'ps5_status': return Promise.resolve({ ...m.ps5 });
    case 'set_use_ps5': m.use_ps5 = args.on; return Promise.resolve();
    case 'psn_sign_in': m.ps5.psn = true; setTimeout(loadPs5, 300); return Promise.resolve();
    case 'ps5_find': return new Promise((r) => setTimeout(() => r([{ host: '192.168.8.40', name: 'PS5-123', ps5: true, ready: true, app: 'Game' }]), 800));
    case 'ps5_link': m.ps5.linked = true; m.ps5.console = { host: args.host, name: args.name, ps5: true, mac: '' }; return Promise.resolve(m.ps5.console);
    case 'ps5_connect': m.ps5.state = 'connected'; setTimeout(() => showPs5State('connected'), 400); return Promise.resolve();
    case 'ps5_disconnect': m.ps5.state = 'off'; showPs5State('off'); return Promise.resolve();
    case 'ps5_forget': m.ps5 = { psn: m.ps5.psn, console: null, linked: false, state: 'off' }; m.use_ps5 = false; return Promise.resolve();
    case 'ps5_picture': {
      const c = document.createElement('canvas'); c.width = 960; c.height = 540; const g = c.getContext('2d');
      const gr = g.createLinearGradient(0, 0, 0, 540); gr.addColorStop(0, '#3b5b7a'); gr.addColorStop(1, '#a87a4a'); g.fillStyle = gr; g.fillRect(0, 0, 960, 540);
      g.fillStyle = '#fff'; g.font = 'bold 26px sans-serif'; g.textAlign = 'center'; g.fillText('Lucia: Water costs more than gold out here.', 480, 470);
      return Promise.resolve(c.toDataURL());
    }
    default: return Promise.resolve();
  }
}
