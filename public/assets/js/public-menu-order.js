(() => {
    document.querySelectorAll('[data-public-menu-order]').forEach((root) => {
        const form = root.querySelector('[data-public-menu-order-form]');
        const value = root.querySelector('[data-public-menu-order-value]');
        let dragged = null;

        const serializeList = (list) =>
            [...list.children]
                .filter((node) => node.matches('[data-menu-item-id]'))
                .map((item) => {
                    const childList = [...item.children].find((node) => node.matches('[data-public-menu-items]'));
                    return {
                        id: Number(item.dataset.menuItemId),
                        children: childList ? serializeList(childList) : [],
                    };
                });

        const serialize = () => {
            const top = root.querySelector(':scope form > [data-public-menu-items]');
            value.value = JSON.stringify(top ? serializeList(top) : []);
        };

        const move = (node, direction) => {
            const list = node.parentElement;
            if (direction === 'up' && node.previousElementSibling) {
                list.insertBefore(node, node.previousElementSibling);
            } else if (direction === 'down' && node.nextElementSibling) {
                list.insertBefore(node.nextElementSibling, node);
            }
            serialize();
        };

        root.addEventListener('click', (event) => {
            const button = event.target.closest('[data-public-move]');
            if (!button) return;
            const item = button.closest('[data-menu-item-id]');
            if (item) move(item, button.dataset.publicMove);
        });

        root.addEventListener('dragstart', (event) => {
            const item = event.target.closest('[data-menu-item-id]');
            if (!item) return;
            dragged = item;
            item.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', item.dataset.menuItemId || '');
        });

        root.addEventListener('dragover', (event) => {
            if (!dragged) return;
            const candidate = event.target.closest('[data-menu-item-id]');
            if (!candidate || candidate === dragged) return;

            // Keep parent/child relationships intact: reorder only within one list.
            if (candidate.parentElement !== dragged.parentElement) return;

            event.preventDefault();
            const rect = candidate.getBoundingClientRect();
            candidate.parentElement.insertBefore(
                dragged,
                event.clientY < rect.top + rect.height / 2 ? candidate : candidate.nextSibling
            );
        });

        root.addEventListener('dragend', () => {
            dragged?.classList.remove('is-dragging');
            dragged = null;
            serialize();
        });

        form?.addEventListener('submit', serialize);
        serialize();
    });
})();