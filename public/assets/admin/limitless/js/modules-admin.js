(() => {
  'use strict';
  const root = document.querySelector('.nn-modules-page');
  if (!root) return;
  root.addEventListener('click', event => {
    const opener = event.target.closest('[data-module-confirm-open]');
    if (opener && root.contains(opener)) {
      const dialog = document.getElementById(opener.dataset.moduleConfirmTarget || '');
      if (!dialog) return;
      dialog._nnOpener = opener;
      if (typeof dialog.showModal === 'function') dialog.showModal();
      else dialog.setAttribute('open', '');
      dialog.querySelector('input[name="confirm_slug"]')?.focus();
      return;
    }
    const closer = event.target.closest('[data-module-confirm-cancel]');
    if (closer && root.contains(closer)) {
      const dialog = closer.closest('dialog');
      if (dialog?.close) dialog.close();
      else dialog?.removeAttribute('open');
    }
  });
  root.querySelectorAll('[data-module-confirm-target]').forEach(opener => {
    const dialog = document.getElementById(opener.dataset.moduleConfirmTarget || '');
    dialog?.addEventListener('close', () => dialog._nnOpener?.focus());
  });
  const uploadButton = root.querySelector('[data-module-upload-toggle]');
  const uploadPanel = root.querySelector('[data-module-upload-panel]');
  if (uploadButton && uploadPanel) uploadButton.addEventListener('click', () => {
    const opening = uploadPanel.hidden;
    uploadPanel.hidden = !opening;
    uploadButton.setAttribute('aria-expanded', opening ? 'true' : 'false');
    if (opening) uploadPanel.querySelector('input[type="file"]')?.focus();
  });
  const search = root.querySelector('[data-module-search]');
  const filters = [...root.querySelectorAll('[data-module-filter]')];
  const empty = root.querySelector('[data-module-no-results]');
  let filter = 'all';
  const apply = () => {
    const q = (search?.value || '').trim().toLowerCase();
    const items = [...root.querySelectorAll('[data-module-item]')];
    let visible = 0;
    items.forEach(item => {
      const state = item.dataset.moduleState;
      const stateMatch = filter === 'all' || filter === state || (filter === 'update' && item.dataset.moduleUpdate === '1') || (filter === 'issue' && item.dataset.moduleIssue === '1');
      const textMatch = !q || (item.dataset.moduleSearchText || '').includes(q);
      item.hidden = !(stateMatch && textMatch);
      if (!item.hidden) visible++;
    });
    if (empty) empty.hidden = visible !== 0;
  };
  search?.addEventListener('input', apply);
  filters.forEach(button => button.addEventListener('click', () => {
    filter = button.dataset.moduleFilter || 'all';
    filters.forEach(b => b.classList.toggle('active', b === button));
    apply();
  }));
  document.addEventListener('ajax:replaced', apply);
})();
