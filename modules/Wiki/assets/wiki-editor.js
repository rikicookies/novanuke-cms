(() => {
    'use strict';

    const editor = document.querySelector('[data-wiki-editor]');
    if (!(editor instanceof HTMLTextAreaElement)) return;
    const linkLabelInput = document.querySelector('[data-wiki-link-label]');
    const linkPathInput = document.querySelector('[data-wiki-link-path]');
    if (linkPathInput instanceof HTMLInputElement) {
        linkPathInput.addEventListener('input', () => linkPathInput.setCustomValidity(''));
    }

    const insertSnippet = (snippet) => {
        const start = editor.selectionStart ?? editor.value.length;
        const end = editor.selectionEnd ?? start;
        const before = editor.value.slice(0, start);
        const after = editor.value.slice(end);
        const prefix = before !== '' && !/\s$/.test(before) ? '\n' : '';
        const suffix = after !== '' && !/^\s/.test(after) ? '\n' : '';

        editor.setRangeText(prefix + snippet + suffix, start, end, 'end');
        editor.dispatchEvent(new Event('input', {bubbles: true}));
        editor.focus();
    };

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) return;
        const wikiLinkButton = event.target.closest('[data-wiki-link-insert]');
        if (wikiLinkButton instanceof HTMLButtonElement) {
            if (!(linkLabelInput instanceof HTMLInputElement) || !(linkPathInput instanceof HTMLInputElement)) return;

            const path = linkPathInput.value.trim().toLowerCase();
            const segments = path.split(':');
            const valid = path.length <= 240
                && /^[a-z0-9]+(?:-[a-z0-9]+)*(?::[a-z0-9]+(?:-[a-z0-9]+)*)*$/.test(path)
                && segments.every((segment) => segment.length <= 120);
            linkPathInput.setCustomValidity(valid ? '' : linkPathInput.dataset.invalidMessage);
            if (!valid) {
                linkPathInput.reportValidity();
                return;
            }

            const fallback = (segments[segments.length - 1] || path).replace(/-/g, ' ');
            const label = (linkLabelInput.value.trim() || fallback)
                .replace(/\\/g, '\\\\').replace(/\[/g, '\\[').replace(/\]/g, '\\]');
            insertSnippet(`[${label}](/wiki/${path})`);
            linkLabelInput.value = '';
            linkPathInput.value = '';
            return;
        }

        const wrapButton = event.target.closest('[data-wiki-wrap]');
        if (wrapButton instanceof HTMLButtonElement) {
            const parts = (wrapButton.dataset.wikiWrap || '').split('|');
            const start = editor.selectionStart ?? 0;
            const end = editor.selectionEnd ?? start;
            const selected = editor.value.slice(start, end) || 'text';
            editor.setRangeText((parts[0] || '') + selected + (parts[1] || ''), start, end, 'select');
            editor.focus();
            return;
        }

        const prefixButton = event.target.closest('[data-wiki-prefix]');
        if (prefixButton instanceof HTMLButtonElement) {
            const prefix = prefixButton.dataset.wikiPrefix || '';
            const start = editor.selectionStart ?? 0;
            const end = editor.selectionEnd ?? start;
            const lineStart = editor.value.lastIndexOf('\n', Math.max(0, start - 1)) + 1;
            const selected = editor.value.slice(lineStart, end);
            const value = selected.split('\n').map((line) => prefix + line).join('\n');
            editor.setRangeText(value, lineStart, end, 'end');
            editor.focus();
            return;
        }

        const button = event.target.closest('[data-wiki-insert]');
        if (!(button instanceof HTMLButtonElement)) return;

        const snippet = button.dataset.wikiInsert;
        if (!snippet) return;
        insertSnippet(snippet);
    });
})();
