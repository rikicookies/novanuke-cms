(() => {
    const root = document.querySelector('[data-admin-nav-order]');
    if (!root) return;

    const groups = root.querySelector('[data-admin-nav-groups]');
    const form = root.querySelector('[data-admin-nav-order-form]');
    const value = root.querySelector('[data-admin-nav-order-value]');
    let dragged = null;

    const serialize = () => {
        const state = [...groups.querySelectorAll(':scope > [data-nav-group]')].map((group) => ({
            slug: group.dataset.navGroup,
            items: [...group.querySelectorAll(':scope > [data-nav-items] > [data-nav-url]')].map((item) => item.dataset.navUrl),
        }));
        value.value = JSON.stringify(state);
    };

    const move = (node, direction) => {
        if (direction === 'up' && node.previousElementSibling) {
            node.parentElement.insertBefore(node, node.previousElementSibling);
        } else if (direction === 'down' && node.nextElementSibling) {
            node.parentElement.insertBefore(node.nextElementSibling, node);
        }
        serialize();
    };

    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-move]');
        if (!button) return;
        const item = button.closest('[data-nav-url]');
        const group = button.closest('[data-nav-group]');
        move(item || group, button.dataset.move);
    });

    root.addEventListener('dragstart', (event) => {
        const handle = event.target.closest('.admin-nav-drag-handle');
        const candidate = event.target.closest('[data-nav-url], [data-nav-group]');
        if (!candidate || (!handle && event.pointerType === 'touch')) return;
        dragged = candidate;
        candidate.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', candidate.dataset.navUrl || candidate.dataset.navGroup || '');
    });

    root.addEventListener('dragover', (event) => {
        if (!dragged) return;
        const candidate = event.target.closest('[data-nav-url], [data-nav-group]');
        if (!candidate || candidate === dragged) return;

        const draggedIsItem = dragged.hasAttribute('data-nav-url');
        const candidateIsItem = candidate.hasAttribute('data-nav-url');
        if (draggedIsItem !== candidateIsItem) return;
        if (draggedIsItem && dragged.closest('[data-nav-group]') !== candidate.closest('[data-nav-group]')) return;

        event.preventDefault();
        const rect = candidate.getBoundingClientRect();
        candidate.parentElement.insertBefore(dragged, event.clientY < rect.top + rect.height / 2 ? candidate : candidate.nextSibling);
    });

    root.addEventListener('dragend', () => {
        dragged?.classList.remove('is-dragging');
        dragged = null;
        serialize();
    });

    form?.addEventListener('submit', serialize);
    serialize();
})();