document.addEventListener('DOMContentLoaded', () => {
  const panel = document.querySelector('[data-media-upload-panel]');
  const open = () => { if (!panel) return; panel.hidden = false; panel.scrollIntoView({behavior:'smooth', block:'start'}); };
  const close = () => { if (panel) panel.hidden = true; };
  document.querySelectorAll('[data-media-upload-toggle]').forEach((button) => button.addEventListener('click', open));
  document.querySelectorAll('[data-media-upload-close]').forEach((button) => button.addEventListener('click', close));
  document.querySelectorAll('[data-media-copy]').forEach((button) => button.addEventListener('click', async () => {
    const input = button.closest('.nn-media-url')?.querySelector('[data-media-url]');
    if (!input) return;
    try { await navigator.clipboard.writeText(input.value); button.classList.add('is-copied'); setTimeout(() => button.classList.remove('is-copied'), 1200); }
    catch (_) { input.select(); document.execCommand('copy'); }
  }));
});
