// Rendered inside the OAuth popup when the connection flow fails: pass the
// error to the main window (opener) via a query param so it stays visible
// after this popup closes, then close the popup.
(function () {
    'use strict';

    const message = document.body.dataset.oauthError || '';

    const closePopup = function () {
        try {
            if (window.opener && !window.opener.closed) {
                const url = new URL(window.opener.location.href);
                if (message) {
                    url.searchParams.set('oauth_error', message);
                } else {
                    url.searchParams.delete('oauth_error');
                }
                window.opener.location.replace(url.toString());
            }
        } catch (e) { /* cross-origin opener: it will handle itself */ }
        window.close();
    };

    // Give the user time to read the message before closing.
    const autoClose = setTimeout(closePopup, 5000);

    const closeBtn = document.getElementById('closeBtn');
    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            clearTimeout(autoClose);
            closePopup();
        });
    }

    const retryBtn = document.getElementById('retryBtn');
    if (retryBtn) {
        retryBtn.addEventListener('click', () => {
            clearTimeout(autoClose);
            // Restart the OAuth flow inside this same popup window.
            window.location.href = retryBtn.dataset.retryUrl;
        });
    }
})();
