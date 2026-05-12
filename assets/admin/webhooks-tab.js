/* DesignSetGo Apps — Webhooks tab client.
 *
 * Vanilla ES2020, no build step. Wires the "Send a test payload" form
 * under each declared endpoint. POSTs body bytes to the server-side
 * test handler, which signs them with the configured secret and runs
 * them through the full WebhookHandler pipeline. The HTTP status and
 * response body are rendered inline.
 *
 * Reads its config off `window.dsgoCronWebhooks`, localized by
 * AdminPage::render_webhooks_tab().
 */

(function () {
    'use strict';

    var cfg = window.dsgoCronWebhooks || null;
    if (!cfg || !cfg.ajaxUrl || !cfg.appId || !cfg.nonce) {
        return;
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('.dsgo-webhook-test-form');
        if (!form) return;
        event.preventDefault();
        var endpointId = form.getAttribute('data-endpoint-id');
        if (!endpointId) return;

        var bodyEl   = form.querySelector('.dsgo-webhook-test-body');
        var resultEl = form.querySelector('.dsgo-webhook-test-result');
        var submit   = form.querySelector('button[type="submit"]');
        var body     = bodyEl ? bodyEl.value : '';

        resultEl.hidden = false;
        resultEl.innerHTML = '<em>…sending…</em>';
        if (submit) submit.disabled = true;

        var payload = new URLSearchParams();
        payload.set('action', 'dsgo_apps_webhook_send_test');
        payload.set('app_id',      cfg.appId);
        payload.set('endpoint_id', endpointId);
        payload.set('body',        body);
        payload.set('nonce',       cfg.nonce);

        fetch(cfg.ajaxUrl, {
            method:      'POST',
            credentials: 'same-origin',
            body:        payload,
        }).then(function (res) {
            return res.json().then(function (json) { return [res, json]; });
        }).then(function (pair) {
            renderResult(resultEl, pair[1]);
        }).catch(function (err) {
            resultEl.innerHTML = '<span class="dsgo-status dsgo-status--error">request failed</span>' +
                (err && err.message ? ' <code>' + escapeHtml(err.message) + '</code>' : '');
        }).finally(function () {
            if (submit) submit.disabled = false;
        });
    });

    function renderResult(target, json) {
        // wp_send_json_success: { success:true, data:{ ok, status, body } }
        // wp_send_json_error:   { success:false, data:{ code, message } }
        if (json && json.success && json.data) {
            var status = json.data.status;
            var ok     = !!json.data.ok;
            target.innerHTML = '<span class="dsgo-status ' +
                (ok ? 'dsgo-status--ok' : 'dsgo-status--error') + '">' +
                escapeHtml('HTTP ' + status) + '</span> ' +
                '<code>' + escapeHtml(JSON.stringify(json.data.body)) + '</code>';
            return;
        }
        var code = (json && json.data && json.data.code) || 'unknown';
        var msg  = (json && json.data && json.data.message) || '';
        target.innerHTML = '<span class="dsgo-status dsgo-status--error">error</span> ' +
            '<code>' + escapeHtml(code) + '</code>' +
            (msg ? '<div class="dsgo-webhook-test-result__msg">' + escapeHtml(msg) + '</div>' : '');
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
}());
