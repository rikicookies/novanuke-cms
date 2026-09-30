(function () {
    'use strict';

    const form = document.querySelector('[data-wiki-bulk-form]');
    if (!form) return;
    const all = form.querySelector('[data-wiki-select-all]');
    const pages = Array.from(form.querySelectorAll('[data-wiki-page-checkbox]'));

    all.addEventListener('change', () => pages.forEach((page) => { page.checked = all.checked; }));
    pages.forEach((page) => page.addEventListener('change', () => {
        all.checked = pages.length > 0 && pages.every((item) => item.checked);
        all.indeterminate = !all.checked && pages.some((item) => item.checked);
    }));
    form.addEventListener('submit', (event) => {
        const selected = pages.filter((page) => page.checked).length;
        if (selected === 0) {
            event.preventDefault();
            window.alert(form.dataset.selectLabel);
            return;
        }
        const deleteMessage = (selected === 1 ? form.dataset.deleteLabel : form.dataset.deletePluralLabel)
            .replace('{count}', String(selected));
        if (form.elements.bulk_action.value === 'delete' && !window.confirm(deleteMessage)) {
            event.preventDefault();
        }
    });
}());
