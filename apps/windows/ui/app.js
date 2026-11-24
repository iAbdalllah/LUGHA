// LUGHA for Windows — UI. Talks to the Rust side through Tauri commands (src/app.rs).
// Opened in a plain browser (no Tauri), it runs against a small in-page mock so the layout can be previewed.

const T = window.__TAURI__;
const invoke = T ? T.core.invoke : mockInvoke;
const listen = T ? T.event.listen : () => {};
const $ = (id) => document.getElementById(id);

let state = null;
let dcTimer = null;

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
  $('cap-sub').textContent = cap
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
  $('source').value = win || '';
  $('window-opts').hidden = !win;
  $('region-card').hidden = !!win;
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
  $('source').innerHTML = '<option value="">منطقة من الشاشة</option>';
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

// Reading areas: one row per box (name, X/Y/W/H in % of the captured picture, on/off, delete).
function renderAreas(areas) {
  const box = $('areas');
  box.replaceChildren(...areas.map((a, n) => {
    const row = document.createElement('div');
    row.className = 'row area';
    const num = (k, v) => { const l = document.createElement('label'); l.textContent = k + ' %'; const i = document.createElement('input'); i.type = 'number'; i.min = 0; i.max = 100; i.step = 0.5; i.value = Math.round(v * 1000) / 10; i.dataset.k = k.toLowerCase(); l.append(i); return l; };
    const name = document.createElement('input'); name.value = a.name; name.dataset.k = 'name'; name.style.maxWidth = '9em';
    const on = document.createElement('input'); on.type = 'checkbox'; on.checked = a.enabled !== false; on.dataset.k = 'enabled';
    const del = btn('حذف', () => { row.remove(); }, 'link');
    row.append(on, name, num('X', a.x), num('Y', a.y), num('W', a.w), num('H', a.h), del);
    return row;
  }));
}

function readAreas() {
  return [...document.querySelectorAll('#areas .area')].map((row, n) => {
    const v = (k) => +row.querySelector(`[data-k="${k}"]`).value / 100;
    return { name: row.querySelector('[data-k="name"]').value || `منطقة ${n + 1}`, x: v('x'), y: v('y'), w: v('w'), h: v('h'), enabled: row.querySelector('[data-k="enabled"]').checked };
  });
}

// ---- wiring ----

$('area-add').onclick = () => {
  const now = readAreas();
  renderAreas([...now, { name: `منطقة ${now.length + 1}`, x: 0.1, y: 0.8, w: 0.8, h: 0.12, enabled: true }]);
};
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
$('source').onchange = async (e) => { await call('set_capture_window', { title: e.target.value || null, area: null, showOnPc: null }); refresh(); };
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
    default: return Promise.resolve();
  }
}
