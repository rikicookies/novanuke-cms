(function () {
    'use strict';

    const form = document.querySelector('[data-wiki-folder-form]');
    if (!form) return;
    const input = form.querySelector('[data-wiki-folder-input]');
    const drop = form.querySelector('[data-wiki-folder-drop]');
    const preview = form.querySelector('[data-wiki-folder-preview]');
    const pathFields = form.querySelector('[data-wiki-folder-paths]');
    const expectedCount = form.querySelector('[data-wiki-folder-count]');

    const wikiPath = (path) => path.replace(/\\/g, '/').split('/').map((segment, index, parts) => {
        const source = index === parts.length - 1 ? segment.replace(/\.md$/i, '') : segment;
        return source.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    }).join(':');

    const show = (items) => {
        preview.replaceChildren();
        pathFields.replaceChildren();
        expectedCount.value = String(items.length);
        if (!items.length) {
            const empty = document.createElement('li');
            empty.textContent = form.dataset.emptyLabel;
            preview.append(empty);
            return;
        }
        items.forEach(({ path }) => {
            const row = document.createElement('li');
            row.textContent = /\.md$/i.test(path) ? wikiPath(path) : `${path} (${form.dataset.ignoredLabel})`;
            preview.append(row);
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'markdown_paths[]';
            hidden.value = path;
            pathFields.append(hidden);
        });
    };

    input.addEventListener('change', () => show(Array.from(input.files).map((file) => ({
        file,
        path: file.webkitRelativePath || file.name,
    }))));

    const readDirectory = async (entry, prefix) => {
        if (entry.isFile) return new Promise((resolve, reject) => entry.file(
            (file) => resolve([{ file, path: prefix + file.name }]), reject,
        ));
        if (!entry.isDirectory) return [];
        const reader = entry.createReader();
        const children = [];
        while (true) {
            const batch = await new Promise((resolve, reject) => reader.readEntries(resolve, reject));
            if (!batch.length) break;
            children.push(...batch);
        }
        const nested = await Promise.all(children.map((child) => readDirectory(child, prefix + entry.name + '/')));
        return nested.flat();
    };

    ['dragenter', 'dragover'].forEach((eventName) => drop.addEventListener(eventName, (event) => {
        event.preventDefault();
        drop.setAttribute('data-drag-active', '1');
    }));
    ['dragleave', 'drop'].forEach((eventName) => drop.addEventListener(eventName, (event) => {
        event.preventDefault();
        drop.removeAttribute('data-drag-active');
    }));
    drop.addEventListener('drop', async (event) => {
        const entries = Array.from(event.dataTransfer.items)
            .map((item) => item.webkitGetAsEntry ? item.webkitGetAsEntry() : null)
            .filter(Boolean);
        if (!entries.length || typeof DataTransfer === 'undefined') return;
        const items = (await Promise.all(entries.map((entry) => readDirectory(entry, '')))).flat();
        const transfer = new DataTransfer();
        items.forEach(({ file }) => transfer.items.add(file));
        input.files = transfer.files;
        show(items);
    });
}());
