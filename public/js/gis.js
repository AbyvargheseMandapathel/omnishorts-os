// Google Identity Services callback — POSTs the JWT credential to the server.
(function () {
    'use strict';

    window.handleGoogleCredential = function (response) {
        if (!response || !response.credential) return;
        const config = document.getElementById('g_id_onload');
        if (!config) return;

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = config.dataset.loginAction;

        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = '_token';
        csrf.value = config.dataset.csrf;

        const cred = document.createElement('input');
        cred.type = 'hidden';
        cred.name = 'credential';
        cred.value = response.credential;

        form.appendChild(csrf);
        form.appendChild(cred);
        document.body.appendChild(form);
        form.submit();
    };
})();
