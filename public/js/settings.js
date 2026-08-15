// Settings page: Test Gemini Connection button.
(function () {
    'use strict';

    const btn = document.getElementById('testGeminiBtn');
    const result = document.getElementById('geminiTestResult');
    if (!btn || !result) return;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        result.innerText = 'Testing…';
        result.style.color = 'var(--text-dim)';
        try {
            const res = await fetch(btn.dataset.testUrl || '/settings/gemini/test', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: '{}',
            });
            const data = await res.json().catch(() => ({}));
            result.innerText = data.message || (res.ok ? 'Connected.' : 'Connection failed.');
            result.style.color = data.ok ? '#34d399' : '#f87171';
        } catch (err) {
            result.innerText = 'Could not reach the server.';
            result.style.color = '#f87171';
        } finally {
            btn.disabled = false;
        }
    });
})();
