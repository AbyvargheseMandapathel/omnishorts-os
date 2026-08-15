// Shared app behavior — loaded on every authenticated page.
(function () {
    'use strict';

    // --- Google OAuth popup helpers ---
    window.openGoogleOauthPopup = function (url) {
        const w = 520, h = 640;
        const left = Math.max(0, (screen.width - w) / 2);
        const top = Math.max(0, (screen.height - h) / 2);
        window.open(url, 'google_oauth', `width=${w},height=${h},left=${left},top=${top},resizable=yes,scrollbars=yes`);
    };
    window.openAccountReconnect = function (url) {
        window.openGoogleOauthPopup(url);
    };

    // --- Channel switcher dropdown ---
    const dropdownBtn = document.getElementById('channelDropdownBtn');
    const dropdownMenu = document.getElementById('channelDropdownMenu');
    if (dropdownBtn && dropdownMenu) {
        dropdownBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdownMenu.classList.toggle('show');
        });
        document.addEventListener('click', (e) => {
            if (!dropdownMenu.contains(e.target) && !dropdownBtn.contains(e.target)) {
                dropdownMenu.classList.remove('show');
            }
        });
    }

    // --- Google OAuth modal: switching channel swaps client ID + secret state ---
    const oauthChannelSelect = document.getElementById('oauth_channel_id');
    if (oauthChannelSelect) {
        const oauthClientId = document.getElementById('oauth_client_id');
        const oauthSecret = document.getElementById('oauth_client_secret');
        const oauthClearWrap = document.getElementById('oauth_clear_wrap');
        const oauthClear = document.getElementById('oauth_clear_secret');
        const oauthStatus = document.getElementById('oauth_status');

        const refreshOauthFields = () => {
            const opt = oauthChannelSelect.options[oauthChannelSelect.selectedIndex];
            const clientId = opt.dataset.clientId || '';
            const hasSecret = opt.dataset.hasSecret === '1';
            oauthClientId.value = clientId;
            oauthSecret.value = '';
            oauthClear.checked = false;
            oauthSecret.placeholder = hasSecret ? '••• saved •••' : 'GOCSPX-…';
            oauthClearWrap.style.display = hasSecret ? 'inline-flex' : 'none';
            oauthStatus.innerHTML = hasSecret || clientId
                ? (hasSecret && clientId ? 'This channel uses its own Client ID <span style="color: var(--accent-emerald);">(set below)</span>.'
                    : 'Using app-level Client ID from <code style="font-size: 0.75rem;">.env</code> — set one below to override.')
                : 'Not configured yet. Paste your Client ID + Secret from the <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" style="color: var(--primary); text-decoration: underline;">Google Cloud Console</a>.';
        };
        oauthChannelSelect.addEventListener('change', refreshOauthFields);
        window.refreshOauthFields = refreshOauthFields;
    }

    // --- Per-account cron panels: sync time rows with posts-per-day select ---
    document.querySelectorAll('.cron-posts-per-day').forEach((select) => {
        const list = document.querySelector(select.dataset.target);
        if (!list) return;

        const syncTimeInputs = () => {
            const wanted = parseInt(select.value, 10);
            const rows = Array.from(list.querySelectorAll('.post-time-row'));

            while (rows.length < wanted) {
                const last = rows[rows.length - 1];
                const row = last.cloneNode(true);
                const input = row.querySelector('input');
                input.value = '';
                row.querySelector('span').innerText = '#' + (rows.length + 1);
                list.appendChild(row);
                rows.push(row);
            }
            while (rows.length > wanted) {
                list.removeChild(rows.pop());
            }
        };
        select.addEventListener('change', syncTimeInputs);
    });

    // --- Mobile menu ---
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.getElementById('sidebar');
    if (mobileBtn && sidebar) {
        mobileBtn.addEventListener('click', () => sidebar.classList.toggle('open'));
    }

    // --- Delegated interactions ---
    document.addEventListener('click', (e) => {
        // Modal backdrop click closes the modal.
        if (e.target.classList && e.target.classList.contains('modal-backdrop')) {
            e.target.classList.remove('show');
            return;
        }

        const opener = e.target.closest('[data-open-modal]');
        if (opener) {
            const modal = document.getElementById(opener.dataset.openModal);
            if (modal) {
                modal.classList.add('show');
                if (opener.dataset.closeMenu) {
                    const menu = document.getElementById('channelDropdownMenu');
                    if (menu) menu.classList.remove('show');
                }
            }
            return;
        }

        const closer = e.target.closest('[data-close-modal]');
        if (closer) {
            const modal = document.getElementById(closer.dataset.closeModal);
            if (modal) modal.classList.remove('show');
            return;
        }

        const dismiss = e.target.closest('[data-dismiss]');
        if (dismiss) {
            const alert = dismiss.closest('.alert');
            if (alert) alert.remove();
            return;
        }

        const oauthUrl = e.target.closest('[data-oauth-url]');
        if (oauthUrl) {
            window.openGoogleOauthPopup(oauthUrl.dataset.oauthUrl);
            return;
        }

        const reconnect = e.target.closest('[data-reconnect-url]');
        if (reconnect) {
            window.openAccountReconnect(reconnect.dataset.reconnectUrl);
            return;
        }

        const panel = e.target.closest('[data-toggle-panel]');
        if (panel) {
            const el = document.querySelector(panel.dataset.togglePanel);
            if (el) el.classList.toggle('show');
        }
    });

    // --- Copy to clipboard (data-copy="text") ---
    document.addEventListener('click', (e) => {
        const copy = e.target.closest('[data-copy]');
        if (!copy) return;
        const text = copy.dataset.copy || '';
        navigator.clipboard.writeText(text).then(() => {
            const original = copy.innerText;
            copy.innerText = '✓ Copied';
            setTimeout(() => { copy.innerText = original; }, 1200);
        }).catch(() => {
            /* clipboard unavailable — ignore */
        });
    });

    // --- Confirm-on-submit (data-confirm="message") ---
    document.addEventListener('submit', (e) => {
        const form = e.target.closest('[data-confirm]');
        if (form && !window.confirm(form.dataset.confirm)) {
            e.preventDefault();
        }
    });

    // --- Auto-submit filters (data-submit-on-change) ---
    document.addEventListener('change', (e) => {
        const target = e.target.closest('[data-submit-on-change]');
        if (target && target.form) target.form.submit();

        // Radio-driven show/hide (data-toggles="#el" data-toggles-value="x").
        const toggler = e.target.closest('[data-toggles]');
        if (toggler && toggler.checked) {
            const el = document.querySelector(toggler.dataset.toggles);
            if (el) {
                el.style.display = (toggler.dataset.togglesValue === undefined || toggler.value === toggler.dataset.togglesValue)
                    ? 'block'
                    : 'none';
            }
        }
    });

    // --- Live preview (data-live-preview="#el") ---
    document.addEventListener('input', (e) => {
        const target = e.target.closest('[data-live-preview]');
        if (target) {
            const el = document.querySelector(target.dataset.livePreview);
            if (el) el.innerText = target.value;
        }
    });

    // --- Content Library inline playback (click the play button to start,
    // click the video to pause/resume; the overlay returns when it ends) ---
    document.addEventListener('click', (e) => {
        const overlay = e.target.closest('.play-overlay');
        if (overlay) {
            const thumb = overlay.closest('.library-thumb');
            const video = thumb && thumb.querySelector('video.library-video');
            if (video) {
                video.controls = true;
                video.play().catch(() => {});
                overlay.style.display = 'none';
                return;
            }
            return;
        }

        const video = e.target.closest('video.library-video');
        if (video) {
            if (video.paused) {
                video.play().catch(() => {});
            } else {
                video.pause();
            }
        }
    });

    document.addEventListener('ended', (e) => {
        const video = e.target.closest('video.library-video');
        if (!video) return;
        video.controls = false;
        const overlay = video.closest('.library-thumb') && video.closest('.library-thumb').querySelector('.play-overlay');
        if (overlay) overlay.style.display = 'flex';
    }, true);
})();
