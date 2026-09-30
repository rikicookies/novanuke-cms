(() => {
    const body = document.body;
    const toggles = [...document.querySelectorAll('[data-nav-toggle]')];
    const overlay = document.querySelector('[data-nav-overlay]');
    const mobile = () => window.matchMedia('(max-width: 900px)').matches;

    const sync = () => {
        const mobileOpen = mobile() && body.classList.contains('nav-open');
        const open = mobile() ? mobileOpen : !body.classList.contains('nav-collapsed');
        toggles.forEach((button) => button.setAttribute('aria-expanded', String(open)));
        if (overlay) overlay.hidden = !mobileOpen;
        body.classList.toggle('nav-scroll-lock', mobileOpen);
    };

    const revealActiveItem = () => {
        if (!mobile() || !body.classList.contains('nav-open')) return;
        const active = document.querySelector('.nova-sidebar .is-active');
        active?.scrollIntoView({block: 'nearest'});
    };

    if (!mobile() && localStorage.getItem('novamodern.navigation') === 'collapsed') {
        body.classList.add('nav-collapsed');
    }

    toggles.forEach((button) => button.addEventListener('click', () => {
        if (mobile()) {
            body.classList.toggle('nav-open');
        } else {
            body.classList.toggle('nav-collapsed');
            localStorage.setItem('novamodern.navigation', body.classList.contains('nav-collapsed') ? 'collapsed' : 'open');
        }
        sync();
        revealActiveItem();
    }));

    overlay?.addEventListener('click', () => {
        body.classList.remove('nav-open');
        sync();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && body.classList.contains('nav-open')) {
            body.classList.remove('nav-open');
            sync();
            toggles[0]?.focus();
        }
    });
    window.addEventListener('resize', () => {
        if (!mobile()) body.classList.remove('nav-open');
        sync();
    });
    document.querySelector('.nova-sidebar')?.addEventListener('touchmove', (event) => {
        event.stopPropagation();
    }, {passive: true});
    sync();
})();

