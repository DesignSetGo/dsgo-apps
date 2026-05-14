/* DesignSetGo Apps — admin page client: consent + site-home + delete module.
 *
 * Owns the promote / replace / step-down consent flows against
 * /wp-json/dsgo/v1/site-home, plus the uninstall confirmation against
 * /wp-json/dsgo/v1/apps. Reads shared state and the consent-panel
 * infrastructure off `window.DSGoAdmin` (created by admin-page-core.js).
 *
 * Load order: core → consent → list → install → bootstrap. `ns.refresh` is
 * defined by the list module which loads after this one — but it is only ever
 * referenced at call time (inside fetch callbacks), so the ordering is safe.
 */

(function () {
    'use strict';

    var ns = window.DSGoAdmin || {};
    var cfg = ns.cfg || {};
    var __ = ns.__;
    var sprintf = ns.sprintf;
    var getCurrentHome = ns.getCurrentHome;
    var siteHostExample = ns.siteHostExample;
    var makeParagraph = ns.makeParagraph;
    var makeBulletList = ns.makeBulletList;
    var attachConsentToRow = ns.attachConsentToRow;
    var buildConsent = ns.buildConsent;

    function openPromoteConsent(app, row) {
        var current = getCurrentHome();
        var host = siteHostExample();
        var prefixPath = '/' + cfg.urlPrefix + '/' + app.id;
        var body = [];
        var title;
        var confirmLabel;

        if (current && current.id !== app.id) {
            // REPLACE flow.
            title = sprintf(
                /* translators: 1: new app name, 2: current home app name */
                __('Replace your site home — %2$s → %1$s?', 'designsetgo-apps'),
                app.name || app.id,
                current.name || current.id,
            );
            confirmLabel = __('Replace', 'designsetgo-apps');
            body.push(makeParagraph(
                sprintf(
                    /* translators: 1: current home app, 2: prefix path */
                    __('"%1$s" steps down to %2$s. "%3$s" becomes the site home.', 'designsetgo-apps'),
                    current.name || current.id,
                    '/' + cfg.urlPrefix + '/' + current.id,
                    app.name || app.id,
                ),
            ));
        } else {
            title = sprintf(
                /* translators: %s: app name */
                __('Make "%s" your site home?', 'designsetgo-apps'),
                app.name || app.id,
            );
            confirmLabel = __('Set as home', 'designsetgo-apps');
        }

        body.push(makeParagraph(__('This app will own:', 'designsetgo-apps'), 'dsgo-consent__heading'));
        var willOwn = [{ label: 'https://' + host + '/', sub: app.isolation === 'iframe'
            ? __('renders the app at the site root', 'designsetgo-apps')
            : __('the app\'s "/" route', 'designsetgo-apps') }];
        if (app.isolation === 'inline') {
            willOwn.push({
                label: __('Any other URL WordPress would 404', 'designsetgo-apps'),
                sub: __('the app\'s matching route, when one exists', 'designsetgo-apps'),
            });
        }
        body.push(makeBulletList(willOwn, 'dsgo-consent__list'));

        body.push(makeParagraph(__('Real WordPress content keeps working unchanged:', 'designsetgo-apps'), 'dsgo-consent__heading'));
        body.push(makeBulletList([
            { label: '/sample-page',         sub: __('your existing pages', 'designsetgo-apps') },
            { label: '/2026/some-post',      sub: __('posts and archives', 'designsetgo-apps') },
            { label: '/wp-admin/*',          sub: __('WordPress admin', 'designsetgo-apps') },
        ], 'dsgo-consent__list'));

        body.push(makeParagraph(
            sprintf(
                /* translators: %s: prefix URL like /apps/foo */
                __('The app currently lives at %s. That URL keeps working, and any block embeds in your posts are unaffected.', 'designsetgo-apps'),
                prefixPath,
            ),
            'dsgo-consent__footnote',
        ));

        if (app.isolation === 'iframe') {
            body.push(makeParagraph(
                __('Note: iframe-mode apps render at "/" but cannot catch 404s for other URLs.', 'designsetgo-apps'),
                'dsgo-consent__footnote dsgo-consent__footnote--warn',
            ));
        }

        attachConsentToRow(row, buildConsent({
            title: title,
            body: body,
            confirmLabel: confirmLabel,
            onConfirm: function (btn) { setSiteHome(app.id, btn); },
        }));
    }

    function openStepDownConsent(app, row) {
        var prefixPath = '/' + cfg.urlPrefix + '/' + app.id;
        var body = [
            makeParagraph(
                sprintf(
                    /* translators: 1: app name, 2: prefix path */
                    __('"%1$s" will move back to %2$s.', 'designsetgo-apps'),
                    app.name || app.id,
                    prefixPath,
                ),
            ),
            makeParagraph(
                __('Your site root will return to WordPress\'s default front page (latest posts, or the static page set in Settings → Reading).', 'designsetgo-apps'),
                'dsgo-consent__footnote',
            ),
        ];
        attachConsentToRow(row, buildConsent({
            title: sprintf(
                /* translators: %s: app name */
                __('Step "%s" down from site home?', 'designsetgo-apps'),
                app.name || app.id,
            ),
            body: body,
            confirmLabel: __('Step down', 'designsetgo-apps'),
            onConfirm: function (btn) { setSiteHome(null, btn); },
        }));
    }

    function setSiteHome(appId, btn) {
        btn.disabled = true;
        var prevText = btn.textContent;
        btn.textContent = __('Working…', 'designsetgo-apps');
        fetch(cfg.restRoot + 'site-home', {
            method: 'POST',
            headers: {
                'X-WP-Nonce': cfg.nonce,
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ app_id: appId }),
        }).then(function (r) {
            if (!r.ok) {
                return r.json().catch(function () { return {}; }).then(function (body) {
                    throw new Error(body.message || ('HTTP ' + r.status));
                });
            }
            return ns.refresh();
        }).catch(function (err) {
            btn.disabled = false;
            btn.textContent = prevText;
            /* translators: %s: error message from the REST request */
            window.alert(sprintf(__('Could not update site home: %s', 'designsetgo-apps'), err.message));
        });
    }

    // ─── Delete ─────────────────────────────────────────────────────────

    function confirmDelete(app) {
        var name = app.name || app.id;
        /* translators: %s: app name */
        var msg = app.is_site_home
            ? sprintf(__('Uninstall "%s"? It is currently your site home — your root URL will return to WordPress\'s default front page.', 'designsetgo-apps'), name)
            /* translators: %s: app name */
            : sprintf(__('Uninstall "%s"? This removes the bundle and any per-user storage.', 'designsetgo-apps'), name);
        if (!window.confirm(msg)) return;
        fetch(cfg.restRoot + 'apps/' + encodeURIComponent(app.id), {
            method: 'DELETE',
            headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
            credentials: 'same-origin',
        }).then(function (r) {
            if (!r.ok) {
                return r.json().catch(function () { return {}; }).then(function (body) {
                    throw new Error(body.message || ('HTTP ' + r.status));
                });
            }
            ns.refresh();
        }).catch(function (err) {
            /* translators: %s: error message from the REST request */
            window.alert(sprintf(__('Delete failed: %s', 'designsetgo-apps'), err.message));
        });
    }

    // Expose consent + delete functions on the namespace for the other modules.
    ns.openPromoteConsent = openPromoteConsent;
    ns.openStepDownConsent = openStepDownConsent;
    ns.setSiteHome = setSiteHome;
    ns.confirmDelete = confirmDelete;
})();
