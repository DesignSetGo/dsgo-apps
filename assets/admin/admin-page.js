/* DesignSetGo Apps — admin page client.
 *
 * Vanilla JS, no build step. Wires up the install dropzone, the installed-apps
 * list with delete + "set as site home" affordances, and the consent panel
 * that handles promote / replace / step-down flows against
 * /wp-json/dsgo/v1/apps and /wp-json/dsgo/v1/site-home.
 */

(function () {
    'use strict';

    var cfg = window.DSGoAdmin || {};
    var __ = (window.wp && window.wp.i18n && window.wp.i18n.__) || function (s) { return s; };
    var sprintf = (window.wp && window.wp.i18n && window.wp.i18n.sprintf) || function (s) { return s; };

    var root = document.querySelector('[data-dsgo-admin]');
    if (!root) return;

    var dropzone = root.querySelector('[data-dsgo-dropzone]');
    var fileInput = root.querySelector('[data-dsgo-input]');
    var pickButton = root.querySelector('[data-dsgo-pick]');
    var status = root.querySelector('[data-dsgo-status]');
    var statusFill = root.querySelector('[data-dsgo-progress]');
    var statusText = root.querySelector('[data-dsgo-status-text]');
    var listEl = root.querySelector('[data-dsgo-list]');
    var listSubtitle = root.querySelector('[data-dsgo-list-subtitle]');
    var rowTemplate = document.querySelector('[data-dsgo-row-template]');
    var consentTemplate = document.querySelector('[data-dsgo-consent-template]');
    var installPanel = root.querySelector('[data-dsgo-install-panel]');
    var installToggle = root.querySelector('[data-dsgo-install-toggle]');

    if (installToggle && installPanel) {
        installToggle.addEventListener('click', function () {
            var open = installPanel.classList.toggle('is-open');
            installToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                // Move focus into the panel for keyboard users so they don't
                // have to tab past the toggle to reach the dropzone.
                window.setTimeout(function () {
                    if (dropzone) dropzone.focus();
                }, 0);
            }
        });
    }

    /** Cached list of apps from the most recent fetch. Used by the consent
     *  copy ("step the existing home down...") to know who's currently home. */
    var apps = [];
    /** Currently open consent panel DOM node, or null. */
    var openConsent = null;

    function clearChildren(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function getCurrentHome() {
        for (var i = 0; i < apps.length; i++) {
            if (apps[i].is_site_home) return apps[i];
        }
        return null;
    }

    // ─── List rendering ─────────────────────────────────────────────────

    function fetchApps() {
        return fetch(cfg.restRoot + 'apps', {
            headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
            credentials: 'same-origin',
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    function applyState() {
        var hasApps = apps.length > 0;
        root.classList.toggle('dsgo-admin--has-apps', hasApps);
        root.classList.toggle('dsgo-admin--empty', !hasApps);
        // Collapse the install panel whenever we flip back to empty so the
        // hero presentation isn't clobbered by an open-panel state.
        if (!hasApps && installPanel) {
            installPanel.classList.remove('is-open');
            if (installToggle) installToggle.setAttribute('aria-expanded', 'false');
        }
    }

    function renderList(payload) {
        apps = Array.isArray(payload) ? payload : [];
        closeConsent();
        clearChildren(listEl);
        listEl.removeAttribute('aria-busy');
        applyState();
        if (apps.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'dsgo-applist__empty';
            empty.textContent = __('No apps installed yet. Drop a bundle to begin.', 'dsgo-apps');
            listEl.appendChild(empty);
            listSubtitle.textContent = __('Nothing here yet.', 'dsgo-apps');
            return;
        }
        listSubtitle.textContent = sprintf(
            /* translators: %d: number of installed apps */
            __('%d installed', 'dsgo-apps'),
            apps.length,
        );
        apps.forEach(function (app) {
            listEl.appendChild(renderRow(app));
        });
    }

    function renderRow(app) {
        var node = rowTemplate.content.firstElementChild.cloneNode(true);
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
            homeBtn.textContent = __('Step down', 'dsgo-apps');
            homeBtn.setAttribute('aria-label', sprintf(__('Step %s down from site home', 'dsgo-apps'), app.name || app.id));
            homeBtn.classList.add('dsgo-applist__home--demote');
            homeBtn.addEventListener('click', function () { openStepDownConsent(app, node); });
        } else if (app.home_eligible) {
            homeBtn.textContent = __('Set home', 'dsgo-apps');
            homeBtn.setAttribute('aria-label', sprintf(__('Make %s your site home', 'dsgo-apps'), app.name || app.id));
            homeBtn.addEventListener('click', function () { openPromoteConsent(app, node); });
        } else {
            homeBtn.remove();
        }

        var del = node.querySelector('[data-dsgo-delete]');
        del.setAttribute('aria-label', sprintf(__('Uninstall %s', 'dsgo-apps'), app.name || app.id));
        del.addEventListener('click', function () { confirmDelete(app); });

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
        listEl.setAttribute('aria-busy', 'true');
        return fetchApps()
            .then(function (payload) {
                renderList(payload);
                // Extension seam: dispatched after the full list re-renders so
                // Pro can repaint its drafts heading / supplemental rows.
                document.dispatchEvent(new CustomEvent('dsgo:apps:list-refreshed', {
                    detail: { apps: apps, listEl: listEl },
                }));
            })
            .catch(function (err) {
                clearChildren(listEl);
                var msg = document.createElement('li');
                msg.className = 'dsgo-applist__empty';
                msg.textContent = sprintf(__('Could not load apps: %s', 'dsgo-apps'), err.message);
                listEl.appendChild(msg);
                listSubtitle.textContent = __('Failed to load.', 'dsgo-apps');
            });
    }

    // Public API: extensions can call window.DSGoAdminPage.refresh() after
    // mutating server state (e.g. deploying a draft → installs an app).
    window.DSGoAdminPage = { refresh: refresh };

    // ─── Consent panels ─────────────────────────────────────────────────

    function closeConsent() {
        if (openConsent && openConsent.parentNode) {
            openConsent.parentNode.removeChild(openConsent);
        }
        openConsent = null;
    }

    function buildConsent(opts) {
        var panel = consentTemplate.content.firstElementChild.cloneNode(true);
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
        openConsent = panel;
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
                __('Replace your site home — %2$s → %1$s?', 'dsgo-apps'),
                app.name || app.id,
                current.name || current.id,
            );
            confirmLabel = __('Replace', 'dsgo-apps');
            body.push(makeParagraph(
                sprintf(
                    /* translators: 1: current home app, 2: prefix path */
                    __('"%1$s" steps down to %2$s. "%3$s" becomes the site home.', 'dsgo-apps'),
                    current.name || current.id,
                    '/' + cfg.urlPrefix + '/' + current.id,
                    app.name || app.id,
                ),
            ));
        } else {
            title = sprintf(
                /* translators: %s: app name */
                __('Make "%s" your site home?', 'dsgo-apps'),
                app.name || app.id,
            );
            confirmLabel = __('Set as home', 'dsgo-apps');
        }

        body.push(makeParagraph(__('This app will own:', 'dsgo-apps'), 'dsgo-consent__heading'));
        var willOwn = [{ label: 'https://' + host + '/', sub: app.isolation === 'iframe'
            ? __('renders the app at the site root', 'dsgo-apps')
            : __('the app\'s "/" route', 'dsgo-apps') }];
        if (app.isolation === 'inline') {
            willOwn.push({
                label: __('Any other URL WordPress would 404', 'dsgo-apps'),
                sub: __('the app\'s matching route, when one exists', 'dsgo-apps'),
            });
        }
        body.push(makeBulletList(willOwn, 'dsgo-consent__list'));

        body.push(makeParagraph(__('Real WordPress content keeps working unchanged:', 'dsgo-apps'), 'dsgo-consent__heading'));
        body.push(makeBulletList([
            { label: '/sample-page',         sub: __('your existing pages', 'dsgo-apps') },
            { label: '/2026/some-post',      sub: __('posts and archives', 'dsgo-apps') },
            { label: '/wp-admin/*',          sub: __('WordPress admin', 'dsgo-apps') },
        ], 'dsgo-consent__list'));

        body.push(makeParagraph(
            sprintf(
                /* translators: %s: prefix URL like /apps/foo */
                __('The app currently lives at %s. That URL keeps working, and any block embeds in your posts are unaffected.', 'dsgo-apps'),
                prefixPath,
            ),
            'dsgo-consent__footnote',
        ));

        if (app.isolation === 'iframe') {
            body.push(makeParagraph(
                __('Note: iframe-mode apps render at "/" but cannot catch 404s for other URLs.', 'dsgo-apps'),
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
                    __('"%1$s" will move back to %2$s.', 'dsgo-apps'),
                    app.name || app.id,
                    prefixPath,
                ),
            ),
            makeParagraph(
                __('Your site root will return to WordPress\'s default front page (latest posts, or the static page set in Settings → Reading).', 'dsgo-apps'),
                'dsgo-consent__footnote',
            ),
        ];
        attachConsentToRow(row, buildConsent({
            title: sprintf(
                /* translators: %s: app name */
                __('Step "%s" down from site home?', 'dsgo-apps'),
                app.name || app.id,
            ),
            body: body,
            confirmLabel: __('Step down', 'dsgo-apps'),
            onConfirm: function (btn) { setSiteHome(null, btn); },
        }));
    }

    function setSiteHome(appId, btn) {
        btn.disabled = true;
        var prevText = btn.textContent;
        btn.textContent = __('Working…', 'dsgo-apps');
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
            return refresh();
        }).catch(function (err) {
            btn.disabled = false;
            btn.textContent = prevText;
            window.alert(sprintf(__('Could not update site home: %s', 'dsgo-apps'), err.message));
        });
    }

    // ─── Delete ─────────────────────────────────────────────────────────

    function confirmDelete(app) {
        var name = app.name || app.id;
        var msg = app.is_site_home
            ? sprintf(__('Uninstall "%s"? It is currently your site home — your root URL will return to WordPress\'s default front page.', 'dsgo-apps'), name)
            : sprintf(__('Uninstall "%s"? This removes the bundle and any per-user storage.', 'dsgo-apps'), name);
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
            refresh();
        }).catch(function (err) {
            window.alert(sprintf(__('Delete failed: %s', 'dsgo-apps'), err.message));
        });
    }

    // ─── Upload (unchanged) ─────────────────────────────────────────────

    function showStatus(text, kind) {
        status.hidden = false;
        status.classList.remove('is-error', 'is-success');
        if (kind === 'error') status.classList.add('is-error');
        if (kind === 'success') status.classList.add('is-success');
        statusText.textContent = text;
    }

    function setProgress(pct) {
        statusFill.style.width = Math.min(100, Math.max(0, pct)) + '%';
    }

    function upload(file) {
        if (!file) return;
        if (!/\.zip$/i.test(file.name)) {
            showStatus(__('Bundle must be a .zip file.', 'dsgo-apps'), 'error');
            return;
        }
        if (file.size > cfg.maxUploadBytes) {
            showStatus(
                sprintf(__('Bundle is too large (max %d MB).', 'dsgo-apps'), Math.round(cfg.maxUploadBytes / (1024 * 1024))),
                'error',
            );
            return;
        }

        dropzone.classList.add('is-uploading');
        showStatus(sprintf(__('Uploading %s…', 'dsgo-apps'), file.name));
        setProgress(8);

        var form = new FormData();
        form.append('bundle', file);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.restRoot + 'apps');
        xhr.setRequestHeader('X-WP-Nonce', cfg.nonce);
        xhr.setRequestHeader('Accept', 'application/json');

        xhr.upload.addEventListener('progress', function (ev) {
            if (!ev.lengthComputable) return;
            setProgress(8 + (ev.loaded / ev.total) * 84);
        });
        xhr.upload.addEventListener('load', function () {
            setProgress(94);
            showStatus(__('Validating bundle…', 'dsgo-apps'));
        });
        xhr.addEventListener('load', function () {
            dropzone.classList.remove('is-uploading');
            var body = {};
            try { body = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status >= 200 && xhr.status < 300) {
                setProgress(100);
                showStatus(
                    sprintf(__('Installed %s. Live at %s', 'dsgo-apps'), body.id || file.name, body.url || ''),
                    'success',
                );
                refresh();
                window.setTimeout(function () { setProgress(0); status.hidden = true; }, 4000);
            } else {
                showStatus(body.message || ('HTTP ' + xhr.status), 'error');
                setProgress(0);
            }
        });
        xhr.addEventListener('error', function () {
            dropzone.classList.remove('is-uploading');
            showStatus(__('Upload failed — check your connection and try again.', 'dsgo-apps'), 'error');
            setProgress(0);
        });
        xhr.send(form);
    }

    // ─── Wiring ─────────────────────────────────────────────────────────

    pickButton.addEventListener('click', function (ev) {
        ev.stopPropagation();
        fileInput.click();
    });
    dropzone.addEventListener('click', function (ev) {
        if (ev.target === pickButton) return;
        fileInput.click();
    });
    dropzone.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            fileInput.click();
        }
    });
    fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) upload(fileInput.files[0]);
        fileInput.value = '';
    });

    ['dragenter', 'dragover'].forEach(function (evt) {
        dropzone.addEventListener(evt, function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            dropzone.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        dropzone.addEventListener(evt, function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            dropzone.classList.remove('is-dragover');
        });
    });
    dropzone.addEventListener('drop', function (ev) {
        var files = ev.dataTransfer && ev.dataTransfer.files;
        if (files && files[0]) upload(files[0]);
    });

    window.addEventListener('dragover', function (ev) { ev.preventDefault(); });
    window.addEventListener('drop', function (ev) { ev.preventDefault(); });

    // ─── Tabs + HTML importer ───────────────────────────────────────────

    var tabs = root.querySelectorAll('[data-dsgo-tab]');
    var panels = root.querySelectorAll('[data-dsgo-panel]');
    var htmlDropzone = root.querySelector('[data-dsgo-html-dropzone]');
    var htmlInput = root.querySelector('[data-dsgo-html-input]');
    var htmlPickButton = root.querySelector('[data-dsgo-html-pick]');
    var htmlPrimary = root.querySelector('[data-dsgo-html-primary]');
    var idInput = root.querySelector('[data-dsgo-id]');
    var nameInput = root.querySelector('[data-dsgo-name]');
    var htmlSubmit = root.querySelector('[data-dsgo-html-submit]');
    var selectedHtmlFile = null;

    function switchTab(target) {
        tabs.forEach(function (t) {
            var active = t.getAttribute('data-dsgo-tab') === target;
            t.classList.toggle('is-active', active);
            t.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach(function (p) {
            p.hidden = p.getAttribute('data-dsgo-panel') !== target;
        });
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            switchTab(t.getAttribute('data-dsgo-tab'));
        });
    });

    var idDirty = false;
    var nameDirty = false;
    if (idInput) idInput.addEventListener('input', function () { idDirty = true; });
    if (nameInput) nameInput.addEventListener('input', function () { nameDirty = true; });

    function deriveSlugFromName(filename) {
        if (!filename) return '';
        var base = filename.replace(/\.[^.]+$/, '');
        var slug = base.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/-+/g, '-').replace(/^-+|-+$/g, '');
        if (slug.length > 60) slug = slug.substring(0, 60);
        if (!/^[a-z][a-z0-9-]{2,63}$/.test(slug)) {
            slug = 'artifact-' + Math.random().toString(36).slice(2, 8);
        }
        return slug;
    }

    function prettifyId(slug) {
        if (!slug) return '';
        return slug.split('-').map(function (s) {
            return s.length ? s.charAt(0).toUpperCase() + s.slice(1) : s;
        }).join(' ');
    }

    function selectHtmlFile(file) {
        selectedHtmlFile = file;
        if (!file) {
            if (htmlPrimary) htmlPrimary.textContent = __('Drop an HTML or zip file here', 'dsgo-apps');
            if (htmlSubmit) htmlSubmit.disabled = true;
            return;
        }
        if (htmlPrimary) htmlPrimary.textContent = file.name;
        if (htmlSubmit) htmlSubmit.disabled = false;
        var slug = deriveSlugFromName(file.name);
        if (!idDirty && idInput) idInput.value = slug;
        if (!nameDirty && nameInput) nameInput.value = prettifyId(slug);
    }

    function isArtifactFile(file) {
        if (!file) return false;
        if (/\.(html?|htm)$/i.test(file.name)) return true;
        if (/text\/html/i.test(file.type || '')) return true;
        if (/\.zip$/i.test(file.name)) return true;
        if (/zip/i.test(file.type || '')) return true;
        return false;
    }

    if (htmlPickButton) {
        htmlPickButton.addEventListener('click', function (ev) {
            ev.stopPropagation();
            htmlInput.click();
        });
    }
    if (htmlDropzone) {
        htmlDropzone.addEventListener('click', function (ev) {
            if (ev.target === htmlPickButton) return;
            htmlInput.click();
        });
        htmlDropzone.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                htmlInput.click();
            }
        });
        ['dragenter', 'dragover'].forEach(function (evt) {
            htmlDropzone.addEventListener(evt, function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                htmlDropzone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            htmlDropzone.addEventListener(evt, function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                htmlDropzone.classList.remove('is-dragover');
            });
        });
        htmlDropzone.addEventListener('drop', function (ev) {
            var files = ev.dataTransfer && ev.dataTransfer.files;
            var file = files && files[0];
            if (!isArtifactFile(file)) {
                showStatus(__('Pick a .html file or a .zip of a static export.', 'dsgo-apps'), 'error');
                return;
            }
            selectHtmlFile(file);
        });
    }
    if (htmlInput) {
        htmlInput.addEventListener('change', function () {
            var file = htmlInput.files && htmlInput.files[0];
            if (!isArtifactFile(file)) {
                showStatus(__('Pick a .html file or a .zip of a static export.', 'dsgo-apps'), 'error');
                htmlInput.value = '';
                return;
            }
            selectHtmlFile(file);
        });
    }

    function importErrorMessage(code, fallback) {
        switch (code) {
            case 'invalid_id':
            case 'invalid_version':
                return __('App ID must be lowercase letters, numbers, and hyphens (3–64 chars).', 'dsgo-apps');
            case 'empty_html':
                return __('That file is empty.', 'dsgo-apps');
            case 'empty_bundle':
                return __('That zip didn\'t contain any supported files.', 'dsgo-apps');
            case 'artifact_too_large':
                return __('That file is over 25 MB. Use the CLI for larger bundles.', 'dsgo-apps');
            case 'invalid_html':
                return __('That HTML couldn\'t be read as UTF-8. Re-save the file as UTF-8 and try again.', 'dsgo-apps');
            case 'invalid_zip':
                return __('That zip couldn\'t be opened.', 'dsgo-apps');
            case 'manifest_present':
                return __('That zip already contains a dsgo-app.json — use the “Upload bundle” tab instead.', 'dsgo-apps');
            case 'missing_entry_html':
                return __('That zip needs a .html file at its root (e.g. index.html or home.html).', 'dsgo-apps');
            default:
                return fallback || __('Import failed.', 'dsgo-apps');
        }
    }

    if (htmlSubmit) {
        htmlSubmit.addEventListener('click', function () {
            if (!selectedHtmlFile) return;
            if (!idInput) return;
            var id = idInput.value.trim();
            var name = nameInput ? nameInput.value.trim() : '';
            if (!/^[a-z][a-z0-9-]{2,63}$/.test(id)) {
                showStatus(__('App ID must be lowercase letters, numbers, and hyphens (3–64 chars).', 'dsgo-apps'), 'error');
                idInput.focus();
                return;
            }

            htmlSubmit.disabled = true;
            showStatus(sprintf(__('Uploading %s…', 'dsgo-apps'), selectedHtmlFile.name));
            setProgress(15);

            var form = new FormData();
            form.append('file', selectedHtmlFile);
            form.append('id', id);
            if (name) form.append('name', name);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', cfg.restRoot + 'apps/import-html');
            xhr.setRequestHeader('X-WP-Nonce', cfg.nonce);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.addEventListener('progress', function (ev) {
                if (!ev.lengthComputable) return;
                setProgress(15 + (ev.loaded / ev.total) * 75);
            });
            xhr.upload.addEventListener('load', function () {
                setProgress(94);
                showStatus(__('Wrapping into a sandboxed bundle…', 'dsgo-apps'));
            });
            xhr.addEventListener('load', function () {
                htmlSubmit.disabled = false;
                var body = {};
                try { body = JSON.parse(xhr.responseText); } catch (e) {}
                if (xhr.status >= 200 && xhr.status < 300) {
                    setProgress(100);
                    showStatus(
                        sprintf(__('Installed %s. Live at %s', 'dsgo-apps'), body.id || id, body.url || ''),
                        'success',
                    );
                    refresh();
                    selectHtmlFile(null);
                    if (idInput) idInput.value = '';
                    if (nameInput) nameInput.value = '';
                    if (htmlInput) htmlInput.value = '';
                    idDirty = false;
                    nameDirty = false;
                    window.setTimeout(function () { setProgress(0); status.hidden = true; }, 4000);
                } else {
                    showStatus(importErrorMessage(body.code, body.message), 'error');
                    setProgress(0);
                }
            });
            xhr.addEventListener('error', function () {
                htmlSubmit.disabled = false;
                showStatus(__('Upload failed — check your connection and try again.', 'dsgo-apps'), 'error');
                setProgress(0);
            });
            xhr.send(form);
        });
    }

    // ─── Bundled starter installer ──────────────────────────────────────

    var starterButton = root.querySelector('[data-dsgo-starter-install]');
    if (starterButton) {
        starterButton.addEventListener('click', function () {
            starterButton.disabled = true;
            showStatus(__('Installing starter app…', 'dsgo-apps'));
            setProgress(20);
            fetch(cfg.restRoot + 'apps/install-starter', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': cfg.nonce,
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            }).then(function (r) {
                return r.json().then(function (body) { return { ok: r.ok, status: r.status, body: body }; });
            }).then(function (res) {
                starterButton.disabled = false;
                if (res.ok) {
                    setProgress(100);
                    showStatus(
                        sprintf(__('Installed %s. Live at %s', 'dsgo-apps'), res.body.id || 'dsgo-starter', res.body.url || ''),
                        'success',
                    );
                    refresh();
                    window.setTimeout(function () { setProgress(0); status.hidden = true; }, 4000);
                } else {
                    showStatus(res.body && res.body.message ? res.body.message : ('HTTP ' + res.status), 'error');
                    setProgress(0);
                }
            }).catch(function (err) {
                starterButton.disabled = false;
                showStatus(
                    sprintf(__('Install failed: %s', 'dsgo-apps'), err && err.message ? err.message : String(err)),
                    'error',
                );
                setProgress(0);
            });
        });
    }

    refresh();
})();
