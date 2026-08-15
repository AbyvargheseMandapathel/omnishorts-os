// Rendered inside the OAuth popup after a successful connect: reload the
// opener (so it reflects the new connection) and close the window.
(function () {
    'use strict';

    setTimeout(() => {
        try {
            if (window.opener && !window.opener.closed) {
                window.opener.location.reload();
            }
        } catch (e) { /* cross-origin: opener will reload itself */ }
        window.close();
    }, 1200);
})();
