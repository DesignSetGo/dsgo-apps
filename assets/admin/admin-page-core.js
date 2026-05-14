/* DesignSetGo Apps — admin page client: core module.
 *
 * Vanilla JS, no build step. This file is the first of a small set of IIFE
 * modules that together make up the admin page client. It owns the shared
 * namespace object, the localized config, the shared DOM references, and the
 * generic helpers (DOM clearing, fetch wrapper, consent-panel infrastructure)
 * that the list / install / consent flows all build on.
 *
 * Load order: core → consent → list → install → bootstrap.
 *
 * The shared namespace is `window.DSGoAdmin`. WordPress's wp_localize_script
 * already populates that object with config keys (restRoot, nonce, siteUrl,
 * urlPrefix, …). The modules extend the SAME object with runtime state and
 * shared functions; the config keys and the runtime keys do not collide.
 */

(function () {
    'use strict';

    var ns = window.DSGoAdmin || {};
    window.DSGoAdmin = ns;

    var cfg = ns;
    var __ = (window.wp && window.wp.i18n && window.wp.i18n.__) || function (s) { return s; };
    var sprintf = (window.wp && window.wp.i18n && window.wp.i18n.sprintf) || function (s) { return s; };

    var root = document.querySelector('[data-dsgo-admin]');

    // Shared closure state + config + i18n, promoted onto the namespace so the
    // other modules can read/extend it.
    ns.cfg = cfg;
    ns.__ = __;
    ns.sprintf = sprintf;
    ns.root = root;

    // Shared DOM references. Resolved here once; null when `root` is absent
    // (the bootstrap module bails before any of these are touched).
    ns.dom = {};
    if (root) {
        ns.dom = {
            dropzone: root.querySelector('[data-dsgo-dropzone]'),
            fileInput: root.querySelector('[data-dsgo-input]'),
            pickButton: root.querySelector('[data-dsgo-pick]'),
            status: root.querySelector('[data-dsgo-status]'),
            statusFill: root.querySelector('[data-dsgo-progress]'),
            statusText: root.querySelector('[data-dsgo-status-text]'),
            listEl: root.querySelector('[data-dsgo-list]'),
            listSubtitle: root.querySelector('[data-dsgo-list-subtitle]'),
            rowTemplate: document.querySelector('[data-dsgo-row-template]'),
            consentTemplate: document.querySelector('[data-dsgo-consent-template]'),
            successTemplate: document.querySelector('[data-dsgo-success-template]'),
            installPanel: root.querySelector('[data-dsgo-install-panel]'),
            installToggle: root.querySelector('[data-dsgo-install-toggle]'),
        };
    }

    /** Cached list of apps from the most recent fetch. Used by the consent
     *  copy ("step the existing home down...") to know who's currently home. */
    ns.apps = [];
    /** Currently open consent panel DOM node, or null. */
    ns.openConsent = null;
    /** Currently visible post-install success panel DOM node, or null. */
    ns.openSuccess = null;

    function clearChildren(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function getCurrentHome() {
        for (var i = 0; i < ns.apps.length; i++) {
            if (ns.apps[i].is_site_home) return ns.apps[i];
        }
        return null;
    }

    function fetchApps() {
        return fetch(cfg.restRoot + 'apps', {
            headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
            credentials: 'same-origin',
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    // ─── Consent panels ─────────────────────────────────────────────────

    function closeConsent() {
        if (ns.openConsent && ns.openConsent.parentNode) {
            ns.openConsent.parentNode.removeChild(ns.openConsent);
        }
        ns.openConsent = null;
    }

    function closeSuccess() {
        if (ns.openSuccess && ns.openSuccess.parentNode) {
            ns.openSuccess.parentNode.removeChild(ns.openSuccess);
        }
        ns.openSuccess = null;
    }

    function buildConsent(opts) {
        var panel = ns.dom.consentTemplate.content.firstElementChild.cloneNode(true);
        panel.querySelector('[data-dsgo-consent-title]').textContent = opts.title;
        var body = panel.querySelector('[data-dsgo-consent-body]');
        clearChildren(body);
        opts.body.forEach(function (block) {
            body.appendChild(block);
        });
        var confirm = panel.querySelector('[data-dsgo-consent-confirm]');
        confirm.textContent = opts.confirmLabel;
        if (opts.danger) confirm.classList.add('is-danger');
        confirm.addEventListener('click', function () { opts.onConfirm(confirm); });
        panel.querySelector('[data-dsgo-consent-cancel]').addEventListener('click', closeConsent);
        return panel;
    }

    function makeParagraph(text, className) {
        var p = document.createElement('p');
        p.textContent = text;
        if (className) p.className = className;
        return p;
    }

    function makeBulletList(items, className) {
        var ul = document.createElement('ul');
        if (className) ul.className = className;
        items.forEach(function (item) {
            var li = document.createElement('li');
            // item: { label, sub } — label is rendered as code, sub as plain text after.
            if (item.label) {
                var code = document.createElement('code');
                code.textContent = item.label;
                li.appendChild(code);
            }
            if (item.sub) {
                var span = document.createElement('span');
                span.textContent = ' ' + item.sub;
                li.appendChild(span);
            }
            ul.appendChild(li);
        });
        return ul;
    }

    function attachConsentToRow(row, panel) {
        closeConsent();
        row.appendChild(panel);
        ns.openConsent = panel;
        // Move focus to the heading for SR users; small delay to let the
        // element actually appear in the layout tree.
        window.setTimeout(function () {
            var heading = panel.querySelector('[data-dsgo-consent-title]');
            if (heading) heading.setAttribute('tabindex', '-1'), heading.focus();
        }, 0);
    }

    function siteHostExample() {
        try { return new URL(cfg.siteUrl).host; } catch (e) { return 'your-site.com'; }
    }

    // Expose shared helpers on the namespace for the other modules.
    ns.clearChildren = clearChildren;
    ns.getCurrentHome = getCurrentHome;
    ns.fetchApps = fetchApps;
    ns.closeConsent = closeConsent;
    ns.closeSuccess = closeSuccess;
    ns.buildConsent = buildConsent;
    ns.makeParagraph = makeParagraph;
    ns.makeBulletList = makeBulletList;
    ns.attachConsentToRow = attachConsentToRow;
    ns.siteHostExample = siteHostExample;
})();
