/* DesignSetGo Apps — admin page client: installed-apps list module.
 *
 * Renders the installed-apps list: list/row rendering, the has-apps/empty
 * state toggle, and the `refresh()` fetch-and-render entry point. Reads shared
 * state and helpers off `window.DSGoAdmin` (created by admin-page-core.js).
 *
 * Load order: core → consent → list → install → bootstrap. Cross-module calls
 * made from inside event handlers (openPromoteConsent, confirmDelete, …) are
 * call-time references through the namespace, so they resolve fine regardless
 * of file load order.
 */

(function () {
    'use strict';

    var ns = window.DSGoAdmin || {};
    var cfg = ns.cfg || {};
    var __ = ns.__;
    var sprintf = ns.sprintf;
    var root = ns.root;
    var dom = ns.dom || {};
    var clearChildren = ns.clearChildren;

    function applyState() {
        var hasApps = ns.apps.length > 0;
        root.classList.toggle('dsgo-admin--has-apps', hasApps);
        root.classList.toggle('dsgo-admin--empty', !hasApps);
        // Collapse the install panel whenever we flip back to empty so the
        // hero presentation isn't clobbered by an open-panel state.
        if (!hasApps && dom.installPanel) {
            dom.installPanel.classList.remove('is-open');
            if (dom.installToggle) dom.installToggle.setAttribute('aria-expanded', 'false');
        }
    }

    function renderList(payload) {
        ns.apps = Array.isArray(payload) ? payload : [];
        ns.closeConsent();
        clearChildren(dom.listEl);
        dom.listEl.removeAttribute('aria-busy');
        applyState();
        if (ns.apps.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'dsgo-applist__empty';
            empty.textContent = __('No apps installed yet. Drop a bundle to begin.', 'designsetgo-apps');
            dom.listEl.appendChild(empty);
            dom.listSubtitle.textContent = __('Nothing here yet.', 'designsetgo-apps');
            return;
        }
        dom.listSubtitle.textContent = sprintf(
            /* translators: %d: number of installed apps */
            __('%d installed', 'designsetgo-apps'),
            ns.apps.length,
        );
        ns.apps.forEach(function (app) {
            dom.listEl.appendChild(renderRow(app));
        });
    }

    function renderRow(app) {
        var node = dom.rowTemplate.content.firstElementChild.cloneNode(true);
        node.dataset.appId = app.id;

        node.querySelector('.dsgo-applist__title').textContent = app.name || app.id;

        if (app.is_site_home) {
            node.classList.add('is-home');
            node.querySelector('.dsgo-applist__home-badge').hidden = false;
        }

        var meta = node.querySelector('.dsgo-applist__meta');
        var bits = [];
        if (app.version) bits.push('v' + app.version);
        if (app.isolation) bits.push(app.isolation);
        if (Array.isArray(app.modes) && app.modes.length) bits.push(app.modes.join(' · '));
        meta.textContent = bits.join('  ·  ');

        var url = node.querySelector('.dsgo-applist__url');
        url.href = app.url || '#';
        url.textContent = (app.url || '').replace(/^https?:\/\//, '');

        var homeBtn = node.querySelector('[data-dsgo-home]');
        if (app.is_site_home) {
            homeBtn.textContent = __('Step down', 'designsetgo-apps');
            /* translators: %s: app name */
            homeBtn.setAttribute('aria-label', sprintf(__('Step %s down from site home', 'designsetgo-apps'), app.name || app.id));
            homeBtn.classList.add('dsgo-applist__home--demote');
            homeBtn.addEventListener('click', function () { ns.openStepDownConsent(app, node); });
        } else if (app.home_eligible) {
            homeBtn.textContent = __('Set home', 'designsetgo-apps');
            /* translators: %s: app name */
            homeBtn.setAttribute('aria-label', sprintf(__('Make %s your site home', 'designsetgo-apps'), app.name || app.id));
            homeBtn.addEventListener('click', function () { ns.openPromoteConsent(app, node); });
        } else {
            homeBtn.remove();
        }

        var del = node.querySelector('[data-dsgo-delete]');
        /* translators: %s: app name */
        del.setAttribute('aria-label', sprintf(__('Uninstall %s', 'designsetgo-apps'), app.name || app.id));
        del.addEventListener('click', function () { ns.confirmDelete(app); });

        // Per-app Secrets link. Only renders when the manifest declares
        // secrets[]; the apps-list REST response exposes `has_secrets` for
        // this purpose so we don't have to fetch every manifest.
        if (app.has_secrets) {
            var secretsLink = document.createElement('a');
            secretsLink.className = 'button button-secondary dsgo-applist__secrets';
            secretsLink.textContent = __('Secrets', 'designsetgo-apps');
            secretsLink.setAttribute('aria-label',
                /* translators: %s: app name */
                sprintf(__('Manage secrets for %s', 'designsetgo-apps'), app.name || app.id));
            var url = window.location.pathname
                + '?page=designsetgo-apps&app_id=' + encodeURIComponent(app.id)
                + '&tab=secrets';
            secretsLink.href = url;
            del.parentNode.insertBefore(secretsLink, del);
        }

        // "Pro features inactive" badge. Shown when the manifest declares
        // Pro-gated features that the current license hasn't unlocked.
        if (Array.isArray(app.inactive_pro_features) && app.inactive_pro_features.length) {
            var featureLabels = {
                cron:               __('scheduled jobs', 'designsetgo-apps'),
                webhooks:           __('webhook endpoints', 'designsetgo-apps'),
                abilities_publish:  __('abilities publishing', 'designsetgo-apps'),
                dynamic_routes:     __('dynamic routes', 'designsetgo-apps'),
            };
            var labels = app.inactive_pro_features.map(function (f) {
                return featureLabels[f] || f;
            });
            var badge = document.createElement('p');
            badge.className = 'dsgo-applist__pro-inactive';
            var badgeText = document.createTextNode(
                sprintf(
                    /* translators: %s: comma-separated list of inactive Pro features */
                    __('Pro features inactive: %s.', 'designsetgo-apps'),
                    labels.join(', '),
                ) + ' '
            );
            badge.appendChild(badgeText);
            var upgradeLink = document.createElement('a');
            upgradeLink.href = cfg.pricingUrl || 'https://designsetgo.dev/pricing';
            upgradeLink.textContent = __('Activate Pro →', 'designsetgo-apps');
            badge.appendChild(upgradeLink);
            node.querySelector('.dsgo-applist__main').appendChild(badge);
        }

        // Extension seam: Pro (or any third-party plugin enqueued on this
        // page) listens for this event to inject row-level actions.
        // detail.app: the app object from /dsgo/v1/apps; detail.node: the
        // <li>; detail.beforeDelete: the delete button (insertion anchor).
        document.dispatchEvent(new CustomEvent('dsgo:apps:row-rendered', {
            detail: { app: app, node: node, beforeDelete: del },
        }));

        return node;
    }

    function refresh() {
        dom.listEl.setAttribute('aria-busy', 'true');
        return ns.fetchApps()
            .then(function (payload) {
                renderList(payload);
                // Extension seam: dispatched after the full list re-renders so
                // Pro can repaint its drafts heading / supplemental rows.
                document.dispatchEvent(new CustomEvent('dsgo:apps:list-refreshed', {
                    detail: { apps: ns.apps, listEl: dom.listEl },
                }));
            })
            .catch(function (err) {
                clearChildren(dom.listEl);
                var msg = document.createElement('li');
                msg.className = 'dsgo-applist__empty';
                /* translators: %s: error message from the REST request */
                msg.textContent = sprintf(__('Could not load apps: %s', 'designsetgo-apps'), err.message);
                dom.listEl.appendChild(msg);
                dom.listSubtitle.textContent = __('Failed to load.', 'designsetgo-apps');
            });
    }

    // Expose list functions on the namespace for the other modules.
    ns.applyState = applyState;
    ns.renderList = renderList;
    ns.renderRow = renderRow;
    ns.refresh = refresh;

    // Public API: extensions can call window.DSGoAdminPage.refresh() after
    // mutating server state (e.g. deploying a draft → installs an app).
    window.DSGoAdminPage = { refresh: refresh };
})();
