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
  p.append(box);
}

async function loadHistory() {
  const items = await call('history');
  const ul = $('history');
  ul.innerHTML = '';
  if (!items.length) ul.innerHTML = '<li class="muted">لم تُعرض أي ترجمة بعد.</li>';
  items.forEach((l) => {
    const li = document.createElement('li');
    const a = document.createElement('span'); a.textContent = (l.speaker ? l.speaker + ': ' : '') + l.text;
    const b = document.createElement('span'); b.className = 'src'; b.textContent = l.source;
    li.append(a, b);
    ul.append(li);
  });
}

// ---- wiring ----

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

refresh();

// ---- browser preview mock (not used inside the app) ----
function mockInvoke(cmd, args) {
  const m = (mockInvoke.s ||= {
    signed_in: location.hash !== '#signin', user: 'لاعب تجريبي', server: 'http://127.0.0.1:8040', device_id: '01JD7W…',
    packages: [['sandline-sa-full', '1.1.0']], region: { x: 192, y: 810, w: 1536, h: 243 }, capturing: false,
    tv: '192.168.1.20:47800', tv_paired: true, tv_connected: true, ocr_language: 'en-US', windows: true, last_error: null,
  });
  const lines = { 'water costs more than gold out here': { text: 'الماء هنا أغلى من الذهب.', speaker: 'سالم', source: 'Water costs more than gold out here' } };
  switch (cmd) {
    case 'status': return Promise.resolve({ ...m });
    case 'start_capture': m.capturing = true; renderSub(lines['water costs more than gold out here']); return Promise.resolve();
    case 'stop_capture': m.capturing = false; renderSub(null); return Promise.resolve();
    case 'lookup': return Promise.resolve(lines[args.text.trim().toLowerCase()] || null);
    case 'history': return Promise.resolve(Object.values(lines));
    case 'sync_now': return Promise.resolve({ installed: [], updated: [], removed: [], errors: [] });
    case 'tv_test': return Promise.resolve(18);
    case 'start_device_sign_in': return Promise.resolve({ device_code: 'x', user_code: '7K4-Q9P', url: 'http://127.0.0.1:8040/activate', interval: 5 });
    case 'poll_device_sign_in': return Promise.resolve(null);
    default: return Promise.resolve();
  }
}
