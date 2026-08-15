// Upload page: dropzone file selection + simulated upload progress.
(function () {
    'use strict';

    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('videoFileInput');
    const selectBtn = document.getElementById('selectFileBtn');
    const titleInput = document.getElementById('videoTitleInput');
    const uploadForm = document.getElementById('uploadForm');
    const progressSection = document.getElementById('progressSection');
    const progressBar = document.getElementById('progressBar');
    const progressPercent = document.getElementById('progressPercent');
    const progressLabel = document.getElementById('progressLabel');

    if (!dropzone || !fileInput || !uploadForm) return;

    dropzone.addEventListener('click', () => fileInput.click());
    if (selectBtn) {
        selectBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            fileInput.click();
        });
    }

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            const file = fileInput.files[0];
            document.getElementById('dropzoneTitle').innerText = 'Selected: ' + file.name;
            if (titleInput && !titleInput.value) {
                const cleanName = file.name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ');
                titleInput.value = cleanName.charAt(0).toUpperCase() + cleanName.slice(1);
            }
        }
    });

    // Simulated visual upload progression on submit.
    uploadForm.addEventListener('submit', () => {
        if (titleInput && !titleInput.value) return;
        progressSection.style.display = 'block';
        let progress = 10;
        const interval = setInterval(() => {
            progress += 15;
            if (progress > 95) progress = 95;
            progressBar.style.width = progress + '%';
            progressPercent.innerText = progress + '%';
            if (progress > 40) progressLabel.innerText = 'Analyzing Frame 0-3s Retention Hook...';
            if (progress > 70) progressLabel.innerText = 'Generating Multi-Platform Captions...';
        }, 150);
    });
})();
