document.addEventListener('DOMContentLoaded', () => {
  const trigger = document.querySelector('[data-block-create-toggle]');
  const panel = document.querySelector('[data-block-create-panel]');
  const close = document.querySelector('[data-block-create-close]');
  if (trigger && panel) {
    trigger.addEventListener('click', () => {
      panel.hidden = false;
      panel.scrollIntoView({behavior: 'smooth', block: 'start'});
      const first = panel.querySelector('input[name="title"]');
      if (first) window.setTimeout(() => first.focus(), 250);
    });
  }
  if (close && panel) close.addEventListener('click', () => { panel.hidden = true; trigger?.focus(); });
});
