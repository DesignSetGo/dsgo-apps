/**
 * Secrets tab admin JS. Wires per-row Set/Replace/Clear forms + an optional
 * Test connection button to the admin-ajax endpoints registered by
 * RestApi::register_admin_ajax (dsgo_apps_secret_set / _clear / _http_test).
 *
 * Permission posture (echoed from PHP side):
 *   - manage_options gate enforced server-side.
 *   - Per-app nonce, embedded into the section's data-dsgo-secrets-nonce
 *     attribute; sent with every request.
 *   - No secret value ever round-trips back to the iframe.
 *
 * Vanilla ES2020. No build step, no jQuery. Matches the convention used by
 * admin-page.js across the same admin surface.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-dsgo-secrets-app-id]');
    if (!root) return;   // Tab isn't active on this page

    var appId   = root.getAttribute('data-dsgo-secrets-app-id') || '';
    var nonce   = root.getAttribute('data-dsgo-secrets-nonce') || '';
    var ajaxUrl = root.getAttribute('data-dsgo-secrets-ajax-url') || '';
    var toast   = root.querySelector('[data-dsgo-secrets-toast]');
    var i18n    = (window.wp && window.wp.i18n) || { __: function (s) { return s; } };
    var __      = i18n.__;

    // ---------- toast ----------

    var toastTimer = 0;
    function showToast(message, kind) {
        if (!toast) return;
        toast.textContent = message;
        toast.dataset.kind = kind || 'info';
        toast.hidden = false;
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            toast.hidden = true;
        }, 4000);
    }

    // ---------- ajax ----------

    /**
     * POST a form-urlencoded body to admin-ajax.php. Returns the parsed JSON
     * payload — wp_send_json_success / wp_send_json_error give us
     * { success: bool, data: {...} }. We pass through to the caller.
     */
    function postAjax(action, params) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', nonce);
        body.set('app_id', appId);
        Object.keys(params || {}).forEach(function (k) {
            body.set(k, params[k]);
        });
        return fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        }).then(function (r) {
            // admin-ajax returns 200 even for wp_send_json_error; the success
            // flag inside the body is the source of truth.
            return r.json().catch(function () { return { success: false, data: { code: 'parse_error', message: 'Could not parse server response' } }; });
        });
    }

    // ---------- row state ----------

    function setRowState(row, isSet) {
        var badge = row.querySelector('[data-dsgo-secret-status]');
        var clearBtn = row.querySelector('[data-dsgo-secret-clear]');
        var editBtn  = row.querySelector('[data-dsgo-secret-edit]');
        if (badge) {
            badge.textContent = isSet ? __('Set', 'designsetgo-apps') : __('Not set', 'designsetgo-apps');
            badge.classList.toggle('dsgo-secrets__badge--set', isSet);
            badge.classList.toggle('dsgo-secrets__badge--unset', !isSet);
        }
        if (clearBtn) clearBtn.disabled = !isSet;
        if (editBtn)  editBtn.textContent = isSet ? __('Replace', 'designsetgo-apps') : __('Set', 'designsetgo-apps');
    }

    function rowForAlias(alias) {
        return root.querySelector('[data-dsgo-secret-alias="' + alias.replace(/"/g, '\\"') + '"]');
    }
    function formRowFor(row) {
        // The form row is the NEXT sibling tr.dsgo-secrets__form-row.
        var next = row.nextElementSibling;
        return next && next.hasAttribute('data-dsgo-secret-form') ? next : null;
    }

    // ---------- handlers ----------

    function bindEditButton(btn) {
        var row = btn.closest('[data-dsgo-secret-alias]');
        var formRow = formRowFor(row);
        if (!formRow) return;
        btn.addEventListener('click', function () {
            // Close any other open form rows for visual clarity.
            root.querySelectorAll('[data-dsgo-secret-form]').forEach(function (fr) {
                if (fr !== formRow) fr.hidden = true;
            });
            formRow.hidden = !formRow.hidden;
            if (!formRow.hidden) {
                var input = formRow.querySelector('[data-dsgo-secret-input]');
                if (input) {
                    input.value = '';
                    input.focus();
                }
                clearError(formRow);
            }
        });
    }

    function bindCancelButton(btn) {
        var formRow = btn.closest('[data-dsgo-secret-form]');
        btn.addEventListener('click', function () {
            formRow.hidden = true;
            var input = formRow.querySelector('[data-dsgo-secret-input]');
            if (input) input.value = '';
            clearError(formRow);
        });
    }

    function bindToggleVisibility(btn) {
        var input = btn.closest('.dsgo-secrets__input-wrap').querySelector('[data-dsgo-secret-input]');
        btn.addEventListener('click', function () {
            var isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            btn.textContent = isPassword ? __('Hide', 'designsetgo-apps') : __('Show', 'designsetgo-apps');
        });
    }

    function bindFormSubmit(form) {
        var formRow = form.closest('[data-dsgo-secret-form]');
        var row     = formRow.previousElementSibling;
        var alias   = row.getAttribute('data-dsgo-secret-alias');
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearError(formRow);
            var input = form.querySelector('[data-dsgo-secret-input]');
            var value = (input && input.value) || '';
            if (value === '') {
                renderError(formRow, __('Value cannot be empty.', 'designsetgo-apps'));
                return;
            }
            var saveBtn = form.querySelector('button[type="submit"]');
            if (saveBtn) saveBtn.disabled = true;
            postAjax('dsgo_apps_secret_set', { alias: alias, value: value })
                .then(function (resp) {
                    if (resp && resp.success) {
                        setRowState(row, true);
                        formRow.hidden = true;
                        if (input) input.value = '';
                        showToast(__('Secret saved.', 'designsetgo-apps'), 'success');
                    } else {
                        var msg = (resp && resp.data && resp.data.message) || __('Save failed.', 'designsetgo-apps');
                        renderError(formRow, msg);
                    }
                })
                .catch(function () {
                    renderError(formRow, __('Network error — try again.', 'designsetgo-apps'));
                })
                .finally(function () {
                    if (saveBtn) saveBtn.disabled = false;
                });
        });
    }

    function bindClearButton(btn) {
        var row = btn.closest('[data-dsgo-secret-alias]');
        var alias = row.getAttribute('data-dsgo-secret-alias');
        btn.addEventListener('click', function () {
            if (!window.confirm(
                /* translators: %s: secret alias */
                __('Clear the stored value for ', 'designsetgo-apps') + alias + '?'
            )) return;
            btn.disabled = true;
            postAjax('dsgo_apps_secret_clear', { alias: alias })
                .then(function (resp) {
                    if (resp && resp.success) {
                        setRowState(row, false);
                        showToast(__('Secret cleared.', 'designsetgo-apps'), 'success');
                    } else {
                        var msg = (resp && resp.data && resp.data.message) || __('Clear failed.', 'designsetgo-apps');
                        showToast(msg, 'error');
                    }
                })
                .catch(function () { showToast(__('Network error — try again.', 'designsetgo-apps'), 'error'); })
                .finally(function () { btn.disabled = false; });
        });
    }

    function bindTestButton(btn) {
        var output = root.querySelector('[data-dsgo-secret-test-output]');
        btn.addEventListener('click', function () {
            btn.disabled = true;
            if (output) {
                output.hidden = false;
                output.textContent = __('Running…', 'designsetgo-apps');
            }
            postAjax('dsgo_apps_http_test', {})
                .then(function (resp) {
                    if (!output) return;
                    if (resp && resp.success && resp.data) {
                        output.textContent = 'HTTP ' + resp.data.status + '\n\n'
                            + JSON.stringify(resp.data.body, null, 2);
                        showToast(__('Test fetch OK.', 'designsetgo-apps'), 'success');
                    } else {
                        var data = (resp && resp.data) || {};
                        output.textContent = (data.code || 'error') + '\n' + (data.message || '');
                        showToast(__('Test fetch failed.', 'designsetgo-apps'), 'error');
                    }
                })
                .catch(function () {
                    if (output) output.textContent = __('Network error — could not reach admin-ajax.', 'designsetgo-apps');
                    showToast(__('Network error.', 'designsetgo-apps'), 'error');
                })
                .finally(function () { btn.disabled = false; });
        });
    }

    function renderError(formRow, message) {
        var slot = formRow.querySelector('[data-dsgo-secret-error]');
        if (slot) slot.textContent = message;
    }
    function clearError(formRow) {
        var slot = formRow.querySelector('[data-dsgo-secret-error]');
        if (slot) slot.textContent = '';
    }

    // ---------- bootstrap ----------

    root.querySelectorAll('[data-dsgo-secret-edit]').forEach(bindEditButton);
    root.querySelectorAll('[data-dsgo-secret-cancel]').forEach(bindCancelButton);
    root.querySelectorAll('[data-dsgo-secret-toggle]').forEach(bindToggleVisibility);
    root.querySelectorAll('[data-dsgo-secret-form-el]').forEach(bindFormSubmit);
    root.querySelectorAll('[data-dsgo-secret-clear]').forEach(bindClearButton);
    root.querySelectorAll('[data-dsgo-secret-test]').forEach(bindTestButton);
}());
