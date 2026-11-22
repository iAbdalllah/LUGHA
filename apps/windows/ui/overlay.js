const box = document.getElementById('box');
window.__TAURI__?.event.listen('subtitle', ({ payload }) => {
  if (!payload) { box.hidden = true; return; }
  document.getElementById('spk').textContent = payload.speaker || '';
  document.getElementById('txt').textContent = payload.text;
  box.hidden = false;
});
