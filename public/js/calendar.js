// Calendar page: post-time input sync + drag & drop scheduling.
(function () {
    'use strict';

    // --- Sync time rows with posts-per-day select ---
    const select = document.getElementById('postsPerDaySelect');
    const list = document.getElementById('postTimesList');
    if (select && list) {
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
    }

    // --- Drag & drop scheduling ---
    const cells = document.querySelectorAll('.calendar-day-cell');
    if (cells.length === 0) return;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let dragType = null;
    let dragId = null;

    document.addEventListener('dragstart', (e) => {
        const chip = e.target.closest('[data-pub-id]');
        if (chip) {
            dragType = 'move';
            dragId = chip.dataset.pubId;
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', 'move:' + dragId);
            }
            chip.style.opacity = '0.45';
            return;
        }
        const tray = e.target.closest('[data-video-id]');
        if (tray) {
            dragType = 'schedule';
            dragId = tray.dataset.videoId;
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', 'schedule:' + dragId);
            }
            tray.style.opacity = '0.45';
        }
    });

    document.addEventListener('dragend', (e) => {
        const el = e.target.closest('[data-pub-id],[data-video-id]');
        if (el) el.style.opacity = '';
        document.querySelectorAll('.calendar-day-cell.drag-over').forEach((c) => c.classList.remove('drag-over'));
    });

    cells.forEach((cell) => {
        cell.addEventListener('dragover', (e) => {
            if (!dragType) return;
            e.preventDefault();
            if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
            cell.classList.add('drag-over');
        });
        cell.addEventListener('dragleave', () => cell.classList.remove('drag-over'));
        cell.addEventListener('drop', async (e) => {
            e.preventDefault();
            cell.classList.remove('drag-over');
            if (!dragType || !dragId) return;
            const date = cell.dataset.date;
            const url = dragType === 'move'
                ? '/calendar/publications/' + dragId + '/move'
                : '/calendar/schedule';
            const body = dragType === 'move' ? { date } : { video_id: dragId, date };
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(body),
                });
                if (res.ok) {
                    window.location.reload();
                } else {
                    const data = await res.json().catch(() => ({}));
                    alert(data.message || data.errors?.date?.[0] || 'Could not update the schedule.');
                }
            } catch (err) {
                alert('Network error while updating the schedule.');
            }
        });
    });
})();
