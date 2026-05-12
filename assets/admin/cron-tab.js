/* DesignSetGo Apps — Cron tab client.
 *
 * Vanilla ES2020, no build step. Wires the "Run now" button next to each
 * scheduled job. POSTs to admin-ajax.php?action=dsgo_apps_cron_run_now,
 * then renders the dispatched-now CronLog row in a sibling result row
 * directly under the job's row.
 *
 * Reads its config off `window.dsgoCronWebhooks`, which is localized
 * by AdminPage::render_cron_tab().
 */

(function () {
    'use strict';

    var cfg = window.dsgoCronWebhooks || null;
    if (!cfg || !cfg.ajaxUrl || !cfg.appId || !cfg.nonce) {
        return;
    }

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.dsgo-cron-run-now');
        if (!btn) return;
        event.preventDefault();
        var jobId = btn.getAttribute('data-job-id');
        if (!jobId) return;

        // Locate the inline result row that lives directly after the job row.
        var resultRow = document.querySelector('.dsgo-cron-run-result[data-for="' + cssEscape(jobId) + '"]');
        if (!resultRow) return;
        var resultCell = resultRow.querySelector('td');

        // Lock the button while the request is in flight; reset on
        // success or failure so the operator can retry.
        var originalLabel = btn.textContent;
        btn.disabled = true;
        btn.textContent = '…';
        resultRow.hidden = false;
        resultCell.textContent = '';

        var body = new URLSearchParams();
        body.set('action', 'dsgo_apps_cron_run_now');
        body.set('app_id', cfg.appId);
        body.set('job_id', jobId);
        body.set('nonce',  cfg.nonce);

        fetch(cfg.ajaxUrl, {
            method:      'POST',
            credentials: 'same-origin',
            body:        body,
        }).then(function (res) {
            return res.json().then(function (json) { return [res, json]; });
        }).then(function (pair) {
            var json = pair[1];
            renderResult(resultCell, json);
        }).catch(function (err) {
            resultCell.innerHTML = '<span class="dsgo-status dsgo-status--error">' +
                escapeHtml(err && err.message ? err.message : 'request failed') + '</span>';
        }).finally(function () {
            btn.disabled = false;
            btn.textContent = originalLabel;
        });
    });

    function renderResult(cell, json) {
        var status = (json && json.success && json.data && json.data.log && json.data.log.status) || null;
        var msg    = (json && json.success && json.data && json.data.log && json.data.log.error_msg) || null;
        var code   = (json && json.success && json.data && json.data.log && json.data.log.error_code) || null;
        var dur    = (json && json.success && json.data && json.data.log && json.data.log.duration_ms);

        if (json && json.success && status === 'ok') {
            cell.innerHTML = '<span class="dsgo-status dsgo-status--ok">ok</span> ' +
                '<span class="dsgo-cron-run-result__dur">' + escapeHtml(String(dur || 0)) + ' ms</span>';
            return;
        }
        if (json && json.success && status === 'error') {
            cell.innerHTML = '<span class="dsgo-status dsgo-status--error">error</span> ' +
                '<code>' + escapeHtml(String(code || 'unknown')) + '</code> ' +
                (msg ? '<div class="dsgo-cron-run-result__msg">' + escapeHtml(String(msg)) + '</div>' : '');
            return;
        }
        // wp_send_json_error shape: { success:false, data:{ code, message } }
        var errCode = json && json.data && json.data.code;
        var errMsg  = json && json.data && json.data.message;
        cell.innerHTML = '<span class="dsgo-status dsgo-status--error">error</span> ' +
            '<code>' + escapeHtml(String(errCode || 'unknown')) + '</code>' +
            (errMsg ? '<div class="dsgo-cron-run-result__msg">' + escapeHtml(String(errMsg)) + '</div>' : '');
    }

    function escapeHtml(s) {
        return s.replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    // CSS.escape isn't available everywhere; the job-id charset is already
    // [a-z0-9-] so a defensive identity escape is fine here.
    function cssEscape(s) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(s);
        }
        return s.replace(/[^a-zA-Z0-9_-]/g, '\\$&');
    }
}());
