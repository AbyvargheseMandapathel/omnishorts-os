// Bulk upload page: dropzone, file list, and schedule preview.
(function () {
    'use strict';

    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('videoFilesInput');
    const selectBtn = document.getElementById('selectFilesBtn');
    const fileListSection = document.getElementById('fileListSection');
    const fileList = document.getElementById('fileList');
    const fileCount = document.getElementById('fileCount');
    const schedulePreview = document.getElementById('schedulePreview');
    const startDateInput = document.getElementById('startDateInput');
    const accountSelect = document.getElementById('youtubeAccountSelect');
    const autoScheduleLabel = document.getElementById('autoScheduleLabel');

    if (!dropzone || !fileInput) return;

    // Per-account crons: the schedule follows whichever YouTube account is
    // selected, so swap the dropzone data + preview when it changes.
    let schedulePerDay = parseInt(dropzone.dataset.schedulePerDay || '1', 10) || 1;
    if (accountSelect) {
        accountSelect.addEventListener('change', () => {
            const opt = accountSelect.options[accountSelect.selectedIndex];
            schedulePerDay = parseInt(opt.dataset.postsPerDay || '1', 10) || 1;
            dropzone.dataset.schedulePerDay = schedulePerDay;
            if (autoScheduleLabel && opt.dataset.timesLabel) {
                autoScheduleLabel.innerText = '⏰ Auto Schedule: ' + schedulePerDay + ' post' + (schedulePerDay > 1 ? 's' : '') + '/day at ' + opt.dataset.timesLabel;
            }
            updatePreview();
        });
    }

    dropzone.addEventListener('click', () => fileInput.click());
    if (selectBtn) {
        selectBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            fileInput.click();
        });
    }

    fileInput.addEventListener('change', updateFileList);

    function updateFileList() {
        const files = Array.from(fileInput.files);
        if (files.length === 0) {
            fileListSection.style.display = 'none';
            document.getElementById('dropzoneTitle').innerText = 'Select reel pack files or drag & drop here';
            return;
        }

        fileListSection.style.display = 'block';
        fileCount.innerText = files.length + ' file' + (files.length > 1 ? 's' : '');
        document.getElementById('dropzoneTitle').innerText = files.length + ' reels ready to queue';
        fileList.innerHTML = '';
        files.slice(0, 20).forEach((f) => {
            const li = document.createElement('li');
            li.style.padding = '3px 0';
            li.style.display = 'flex';
            li.style.justifyContent = 'space-between';
            li.style.gap = '12px';
            const size = (f.size / (1024 * 1024)).toFixed(1);
            li.innerHTML = '<span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">🎬 ' + f.name + '</span><span style="flex-shrink: 0; color: var(--text-dim);">' + size + ' MB</span>';
            fileList.appendChild(li);
        });
        if (files.length > 20) {
            const li = document.createElement('li');
            li.style.padding = '3px 0';
            li.style.color = 'var(--text-dim)';
            li.innerText = '... and ' + (files.length - 20) + ' more';
            fileList.appendChild(li);
        }

        updatePreview();
    }

    function updatePreview() {
        const count = fileInput.files.length;
        if (count === 0) {
            schedulePreview.innerText = 'Select files to see how your pack spreads across the calendar.';
            return;
        }
        const days = Math.ceil(count / schedulePerDay);
        const start = startDateInput.value ? new Date(startDateInput.value + 'T00:00:00') : new Date(Date.now() + 86400000);
        const end = new Date(start);
        end.setDate(start.getDate() + days - 1);
        const fmt = (d) => d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        schedulePreview.innerText = count + ' reels → ' + schedulePerDay + ' post(s)/day → all live by ' + fmt(end) + ' (starting ' + fmt(start) + '). No manual work needed.';
    }

    if (startDateInput) {
        startDateInput.addEventListener('change', updatePreview);
    }
})();
