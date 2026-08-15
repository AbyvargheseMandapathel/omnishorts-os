// Landing page interactions.
(function () {
    'use strict';

    // ---------- Count-up stats ----------
    const statEls = document.querySelectorAll('.stat-value');

    const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

    const runCount = (el, i) => {
        const target = parseFloat(el.dataset.target);
        const decimals = parseInt(el.dataset.decimals || '0', 10);
        const suffix = el.dataset.suffix || '';
        const duration = 1500 + i * 80;
        const start = performance.now() + 480 + i * 90;

        const tick = (now) => {
            if (now < start) { requestAnimationFrame(tick); return; }
            const t = Math.min(1, (now - start) / duration);
            const val = target * easeOutCubic(t);
            el.textContent = val.toFixed(decimals) + suffix;
            if (t < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    };

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reduceMotion || !('IntersectionObserver' in window)) {
        statEls.forEach((el) => {
            el.textContent = parseFloat(el.dataset.target).toFixed(parseInt(el.dataset.decimals || '0', 10)) + (el.dataset.suffix || '');
        });
    } else {
        const io = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    const el = entry.target;
                    runCount(el, Array.from(statEls).indexOf(el));
                    io.unobserve(el);
                }
            });
        }, { threshold: 0.25 });

        statEls.forEach((el) => io.observe(el));
    }

    // ---------- Scroll reveal for sections ----------
    const revealEls = document.querySelectorAll('.reveal');

    if (reduceMotion || !('IntersectionObserver' in window)) {
        revealEls.forEach((el) => el.classList.add('in'));
    } else {
        const revealIo = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in');
                    revealIo.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

        revealEls.forEach((el) => revealIo.observe(el));
    }

    // ---------- Nav scrollspy ----------
    const navLinks = document.querySelectorAll('.nav-pill .nav-link');
    const mLinks = document.querySelectorAll('.m-link');
    const sectionIds = ['hero', 'about', 'features', 'pricing', 'faq', 'contact'];

    const setActive = (id) => {
        navLinks.forEach((l) => l.classList.toggle('active', l.dataset.section === id));
        mLinks.forEach((l) => l.classList.toggle('active', l.getAttribute('href') === '#' + id || (id === 'hero' && l.getAttribute('href') === '#top')));
    };

    let spyTimer = null;
    const onScroll = () => {
        if (spyTimer) return;
        spyTimer = setTimeout(() => {
            spyTimer = null;
            let current = 'hero';
            for (const id of sectionIds) {
                const el = document.getElementById(id === 'hero' ? 'top' : id);
                if (el && el.getBoundingClientRect().top <= 120) current = id;
            }
            setActive(current);
        }, 60);
    };

    window.addEventListener('scroll', onScroll, { passive: true });

    // ---------- FAQ accordion ----------
    document.querySelectorAll('.faq-item').forEach((item) => {
        item.querySelector('.faq-q').addEventListener('click', () => {
            item.classList.toggle('open');
        });
    });

    // ---------- Mobile menu ----------
    const burger = document.getElementById('burger');
    const overlay = document.getElementById('overlay');
    const menu = document.getElementById('mobileMenu');

    const openMenu = () => {
        document.body.classList.add('menu-open');
        burger.setAttribute('aria-expanded', 'true');
        menu.hidden = false;
        requestAnimationFrame(() => menu.classList.add('open'));
        overlay.classList.add('open');
    };

    const closeMenu = () => {
        document.body.classList.remove('menu-open');
        burger.setAttribute('aria-expanded', 'false');
        menu.classList.remove('open');
        overlay.classList.remove('open');
        setTimeout(() => { menu.hidden = true; }, 400);
    };

    burger.addEventListener('click', () => {
        document.body.classList.contains('menu-open') ? closeMenu() : openMenu();
    });

    overlay.addEventListener('click', closeMenu);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && document.body.classList.contains('menu-open')) closeMenu();
    });

    menu.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeMenu));

    window.addEventListener('resize', () => {
        if (window.innerWidth > 720 && document.body.classList.contains('menu-open')) closeMenu();
    });
})();
