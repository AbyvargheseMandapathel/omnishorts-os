/* OmniShorts AI video: create-page provider summary + show-page progress polling. */
(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /* ------------------------------------------------------------------ */
    /* Create page — live provider summary per content type                 */
    /* ------------------------------------------------------------------ */
    var typeSelect = document.getElementById('aiContentType');
    var providerCard = document.getElementById('aiProviderCard');
    var generateBtn = document.getElementById('aiGenerateBtn');

    if (typeSelect && providerCard) {
        var summary = {};
        try {
            summary = JSON.parse(providerCard.getAttribute('data-summary') || '{}');
        } catch (e) {
            summary = {};
        }

        var kindLabels = { text: 'Text AI', image: 'Image AI', voice: 'Voice AI' };

        function renderProviderSummary(type) {
            var list = document.getElementById('aiProviderList');
            if (!list) {
                return;
            }
            var html = '';
            Object.keys(kindLabels).forEach(function (kind) {
                var chain = (summary[type] && summary[type][kind]) || [];
                html += '<div>';
                html += '<div style="font-size:0.72rem;font-weight:700;color:var(--text-dim);text-transform:uppercase;margin-bottom:5px;">' + kindLabels[kind] + '</div>';
                if (!chain.length) {
                    html += '<div style="font-size:0.78rem;color:#f87171;">Not configured — set it up in Settings first.</div>';
                } else {
                    chain.forEach(function (conn, i) {
                        html += '<div style="font-size:0.8rem;padding:5px 9px;border-radius:7px;background:rgba(255,255,255,0.04);border:1px solid var(--border-subtle);margin-bottom:5px;">';
                        html += '<span style="font-weight:600;">' + escapeHtml(conn.name) + '</span> ';
                        html += '<span style="color:var(--text-dim);font-size:0.72rem;">' + escapeHtml(String(conn.provider || '').replace(/_/g, ' ')) + (conn.model ? ' · ' + escapeHtml(conn.model) : '') + '</span>';
                        if (i === 0 && chain.length > 1) {
                            html += '<span style="color:var(--accent-emerald);font-size:0.7rem;margin-left:6px;">primary</span>';
                        } else if (i > 0) {
                            html += '<span style="color:var(--text-dim);font-size:0.7rem;margin-left:6px;">fallback</span>';
                        }
                        html += '</div>';
                    });
                }
                html += '</div>';
            });
            list.innerHTML = html;

            if (generateBtn) {
                var missing = Object.keys(kindLabels).some(function (kind) {
                    return !((summary[type] || {})[kind] || []).length;
                });
                generateBtn.disabled = missing;
                generateBtn.title = missing ? 'Configure the missing AI providers in Settings first.' : '';
            }
        }

        typeSelect.addEventListener('change', function () {
            renderProviderSummary(typeSelect.value);
        });
        renderProviderSummary(typeSelect.value);
    }

    /* ------------------------------------------------------------------ */
    /* Settings — filter providers by the selected AI type                  */
    /* ------------------------------------------------------------------ */
    function wireProviderFilter(group) {
        var typeSelect = group.querySelector('.ai-provider-type');
        var providerSelect = group.querySelector('.ai-provider-option');
        if (!typeSelect || !providerSelect) {
            return;
        }

        function apply() {
            var type = typeSelect.value;
            var selected = providerSelect.value;
            var firstVisible = null;
            Array.prototype.forEach.call(providerSelect.options, function (option) {
                var matches = option.getAttribute('data-type') === type;
                option.hidden = !matches;
                if (matches && !firstVisible) {
                    firstVisible = option.value;
                }
            });
            if (!providerSelect.value || providerSelect.options[providerSelect.selectedIndex].hidden) {
                providerSelect.value = firstVisible || '';
            }
        }

        typeSelect.addEventListener('change', apply);
        apply();
    }

    document.querySelectorAll('[data-provider-select]').forEach(function (el) {
        var group = el.closest('form') || el.parentElement;
        if (group && !group.dataset.providerWired) {
            group.dataset.providerWired = '1';
            wireProviderFilter(group);
        }
    });

    /* ------------------------------------------------------------------ */
    /* Settings — Test Connection button (real provider probe)             */
    /* ------------------------------------------------------------------ */
    function wireConnectionTest(btn) {
        var form = btn.closest('form');
        var result = form ? form.querySelector('[data-ai-test-result]') : null;
        if (!form || !result) {
            return;
        }

        var meta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = meta ? meta.content : '';

        btn.addEventListener('click', function () {
            function val(name) {
                var el = form.querySelector('[name="' + name + '"]');
                return el ? el.value : '';
            }
            var idEl = form.querySelector('[name="id"]');

            btn.disabled = true;
            result.textContent = 'Testing…';
            result.style.color = 'var(--text-dim)';

            fetch(btn.getAttribute('data-test-url') || '/settings/ai/connections/test', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    id: idEl ? idEl.value : '',
                    type: val('type'),
                    provider: val('provider'),
                    api_key: val('api_key'),
                    model: val('model'),
                    base_url: val('base_url'),
                    config: val('config'),
                }),
            })
                .then(function (res) {
                    return res.json().catch(function () { return {}; });
                })
                .then(function (data) {
                    result.textContent = (data.ok ? '✓ ' : '✗ ') + (data.message || (data.ok ? 'Connected.' : 'Connection failed.'));
                    result.style.color = data.ok ? '#34d399' : '#f87171';
                })
                .catch(function () {
                    result.textContent = '✗ Could not reach the server.';
                    result.style.color = '#f87171';
                })
                .finally(function () {
                    btn.disabled = false;
                });
        });
    }

    document.querySelectorAll('[data-ai-test-connection]').forEach(wireConnectionTest);

    /* ------------------------------------------------------------------ */
    /* Show page — poll job progress                                       */
    /* ------------------------------------------------------------------ */
    var statusBox = document.getElementById('aiJobStatus');
    if (!statusBox) {
        return;
    }

    var progressUrl = statusBox.getAttribute('data-progress-url');
    if (!progressUrl) {
        return;
    }

    var pollMs = 3000;

    function updateStage(key, status, error) {
        var row = document.querySelector('.ai-stage-row[data-stage="' + key + '"]');
        if (!row) {
            return;
        }
        row.setAttribute('data-status', status);
        var icon = row.querySelector('[data-role="icon"]');
        var statusEl = row.querySelector('[data-role="status"]');
        if (icon) {
            icon.textContent = status === 'done' ? '✓' : status === 'failed' ? '✗' : status === 'running' ? '●' : '○';
            icon.style.background = status === 'done'
                ? 'rgba(16,185,129,0.15)'
                : status === 'failed'
                    ? 'rgba(239,68,68,0.15)'
                    : status === 'running'
                        ? 'rgba(59,130,246,0.15)'
                        : 'rgba(255,255,255,0.06)';
            icon.style.color = status === 'done' ? '#34d399' : status === 'failed' ? '#f87171' : status === 'running' ? '#60a5fa' : 'var(--text-dim)';
        }
        row.style.background = status === 'running' ? 'rgba(59,130,246,0.08)' : '';
        if (statusEl) {
            statusEl.textContent = status === 'done' ? 'Done' : status === 'failed' ? (error || 'Failed') : status === 'running' ? 'Running…' : 'Waiting';
        }
    }

    function updateScene(scene) {
        var cell = document.querySelector('.ai-scene-cell[data-scene="' + scene.scene_number + '"]');
        if (!cell) {
            return;
        }
        cell.setAttribute('data-status', scene.image_status);
        var frame = cell.querySelector('[data-role="frame"]');
        if (!frame) {
            return;
        }
        var img = frame.querySelector('img');

        if (scene.image_status === 'done' && scene.image_url) {
            if (!img) {
                img = document.createElement('img');
                img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
                frame.innerHTML = '';
                frame.appendChild(img);
            }
            img.src = scene.image_url;
        } else if (img) {
            img.remove();
            var span = document.createElement('span');
            span.setAttribute('data-role', 'placeholder');
            span.style.cssText = 'font-size:0.68rem;color:var(--text-dim);padding:6px;';
            span.innerHTML = scene.image_status === 'failed'
                ? '<span style="color:#f87171;">Failed<br><span style="font-size:0.6rem;">' + escapeHtml(scene.image_error || '') + '</span></span>'
                : scene.image_status === 'running'
                    ? '<span style="color:#60a5fa;">Generating…</span>'
                    : 'Pending';
            frame.appendChild(span);
        }
    }

    function schedulePoll() {
        setTimeout(poll, pollMs);
    }

    function poll() {
        fetch(progressUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('poll failed');
                }
                return response.json();
            })
            .then(function (data) {
                if (!data) {
                    return schedulePoll();
                }

                // Terminal states (and a finished render) → reload so the
                // server renders the preview / retry / approve UI.
                if (data.status === 'completed' || data.status === 'failed' || data.status === 'cancelled' || data.final_url) {
                    window.location.reload();
                    return;
                }

                if (data.stages) {
                    Object.keys(data.stages).forEach(function (key) {
                        var s = data.stages[key] || {};
                        updateStage(key, s.status || 'pending', s.error);
                    });
                }

                (data.scenes || []).forEach(updateScene);

                var stageEl = document.getElementById('aiCurrentStage');
                if (stageEl && data.stage_label) {
                    stageEl.textContent = data.stage_label;
                }

                var bar = document.getElementById('aiProgressBar');
                if (bar) {
                    var order = Object.keys(data.stages || {});
                    var doneCount = order.filter(function (key) {
                        return (data.stages[key].status || '') === 'done';
                    }).length;
                    bar.style.width = (order.length ? (doneCount / order.length) * 100 : 0) + '%';
                }

                schedulePoll();
            })
            .catch(function () {
                schedulePoll();
            });
    }

    var initialStatus = statusBox.getAttribute('data-status');
    if (initialStatus !== 'completed' && initialStatus !== 'failed' && initialStatus !== 'cancelled') {
        schedulePoll();
    }
})();
