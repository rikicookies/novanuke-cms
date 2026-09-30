(() => {
    const messages = Object.assign({
        ajaxFailed: 'The request failed.',
        ajaxRefreshFailed: 'The page could not be updated in place.',
        ajaxCompleted: 'Action completed.',
        ajaxAttention: 'The action needs attention.',
        ajaxNetworkError: 'The request could not be completed. Please try again.',
    }, document.body.dataset);
    const parser = new DOMParser();

    const status = (() => {
        let node = document.querySelector('[data-ajax-status]');
        if (node) return node;
        node = document.createElement('div');
        node.className = 'ajax-action-status';
        node.dataset.ajaxStatus = '';
        node.setAttribute('role', 'status');
        node.setAttribute('aria-live', 'polite');
        node.hidden = true;
        document.body.appendChild(node);
        return node;
    })();

    let statusTimer = null;
    const announce = (message, error = false) => {
        if (!message) return;
        window.clearTimeout(statusTimer);
        status.textContent = message;
        status.classList.toggle('is-error', error);
        status.hidden = false;
        statusTimer = window.setTimeout(() => {
            status.hidden = true;
        }, 3200);
    };

    const selectorFor = (form) => form.dataset.ajaxReplace || '';
    const busy = (form, active) => {
        form.setAttribute('aria-busy', String(active));
        form.querySelectorAll('button, input[type="submit"]').forEach((button) => {
            button.disabled = active;
        });
    };

    const parseResponse = (html) => parser.parseFromString(html, 'text/html');

    const textFrom = (doc) => (doc.body?.textContent || '')
        .trim()
        .replace(/\s+/g, ' ')
        .slice(0, 300);

    const feedbackFrom = (doc) => {
        const error = doc.querySelector('.form-errors, .alert-error, .alert-danger');
        if (error?.textContent.trim()) return {message: error.textContent.trim(), error: true};
        const success = doc.querySelector('.success-message, .alert-success');
        if (success?.textContent.trim()) return {message: success.textContent.trim(), error: false};
        return null;
    };

    const replaceFromDocument = (doc, selector) => {
        if (!selector) return false;
        const incoming = doc.querySelector(selector);
        const current = document.querySelector(selector);
        if (!incoming || !current) return false;
        const scrollX = window.scrollX;
        const scrollY = window.scrollY;
        current.replaceWith(incoming);
        window.scrollTo(scrollX, scrollY);
        document.dispatchEvent(new CustomEvent('ajax:replaced', {detail: {selector}}));
        return true;
    };

    const closeDialogFor = (form) => {
        const dialog = form.closest('dialog');
        if (!dialog) return;
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
    };

    const removeWhenAbsent = (doc, form, selector) => {
        if (form.dataset.ajaxRemoveIfAbsent !== 'true' || !selector || doc.querySelector(selector)) return false;
        const current = document.querySelector(selector);
        if (!current) return false;
        const scrollX = window.scrollX;
        const scrollY = window.scrollY;
        closeDialogFor(form);
        current.remove();
        window.scrollTo(scrollX, scrollY);
        document.dispatchEvent(new CustomEvent('ajax:replaced', {detail: {selector, removed: true}}));
        return true;
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-ajax-action]');
        if (!form || event.defaultPrevented) return;

        event.preventDefault();
        if (form.dataset.ajaxPending === 'true') return;

        form.dataset.ajaxPending = 'true';
        const selector = selectorFor(form);
        const submitter = event.submitter;
        const formData = new FormData(form);
        if (submitter?.name) formData.append(submitter.name, submitter.value);

        busy(form, true);
        try {
            const response = await fetch(form.action, {
                method: (form.method || 'POST').toUpperCase(),
                body: formData,
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
            });

            const contentType = response.headers.get('content-type') || '';
            const body = await response.text();

            if (!contentType.includes('text/html')) {
                announce(body || messages.ajaxFailed, true);
                return;
            }

            const doc = parseResponse(body);
            const feedback = feedbackFrom(doc);
            const dialogForm = Boolean(form.closest('dialog'));
            const replaced = (!dialogForm || response.ok) && replaceFromDocument(doc, selector);
            const removed = !replaced && response.ok && removeWhenAbsent(doc, form, selector);

            if (!replaced && !removed) {
                announce(
                    feedback?.message || textFrom(doc) || messages.ajaxRefreshFailed,
                    true,
                );
                return;
            }

            if (replaced) closeDialogFor(form);

            announce(
                feedback?.message || form.dataset.ajaxSuccess || (response.ok ? messages.ajaxCompleted : messages.ajaxAttention),
                feedback?.error ?? !response.ok,
            );
        } catch (error) {
            announce(messages.ajaxNetworkError, true);
        } finally {
            delete form.dataset.ajaxPending;
            busy(form, false);
        }
    });
})();
