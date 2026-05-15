/* DesignSetGo Apps — admin page client: install + tabs + bootstrap module.
 *
 * Owns the install dropzone (two-phase preview → install against
 * /wp-json/dsgo/v1/apps), the post-install success panel, the install/HTML
 * importer tabs, the bundled starter installer, the AI-prompt recomposer, and
 * the page bootstrap (event wiring + the initial list refresh).
 *
 * This is the LAST module in the load order (core → consent → list → install)
 * because the bootstrap wiring at the bottom must run after every other module
 * has populated `window.DSGoAdmin`.
 */

(function () {
    'use strict';

    var ns = window.DSGoAdmin || {};
    var cfg = ns.cfg || {};
    var __ = ns.__;
    var sprintf = ns.sprintf;
    var root = ns.root;
    if (!root) return;

    var dom = ns.dom || {};
    var dropzone = dom.dropzone;
    var fileInput = dom.fileInput;
    var pickButton = dom.pickButton;
    var status = dom.status;
    var statusFill = dom.statusFill;
    var statusText = dom.statusText;
    var installPanel = dom.installPanel;
    var installToggle = dom.installToggle;
    var successTemplate = dom.successTemplate;

    function focusInstallTarget() {
        var activeDropzone = installPanel.querySelector('[data-dsgo-panel]:not([hidden]) .dsgo-dropzone');
        if (!activeDropzone) activeDropzone = installPanel.querySelector('.dsgo-dropzone');
        if (!activeDropzone) return;
        try {
            activeDropzone.focus({ preventScroll: true });
        } catch (e) {
            activeDropzone.focus();
        }
    }

    function scrollToInstallPanel() {
        var reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (installPanel.id) {
            var hash = '#' + installPanel.id;
            if (window.location.hash !== hash && window.history && window.history.pushState) {
                window.history.pushState(null, '', hash);
            } else if (window.location.hash !== hash) {
                window.location.hash = installPanel.id;
            }
        }
        installPanel.scrollIntoView({
            behavior: reduceMotion ? 'auto' : 'smooth',
            block: 'start',
        });
    }

    if (installToggle && installPanel) {
        installToggle.addEventListener('click', function () {
            var open = installPanel.classList.toggle('is-open');
            installToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                scrollToInstallPanel();
                // Move focus into the visible panel for keyboard users so they
                // don't have to tab past the toggle to reach the dropzone.
                window.setTimeout(function () {
                    focusInstallTarget();
                }, 0);
            }
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

    function renderSuccessActions(body, fallbackName) {
        if (!successTemplate || !status || !status.parentNode) return;
        var appId = body && body.id ? body.id : '';
        var appUrl = body && body.url ? body.url : '';
        if (!appId || !appUrl) return;

        ns.closeSuccess();
        if (installPanel) installPanel.classList.add('is-open');
        if (installToggle) installToggle.setAttribute('aria-expanded', 'true');
        var panel = successTemplate.content.firstElementChild.cloneNode(true);
        panel.querySelector('[data-dsgo-success-title]').textContent = sprintf(
            /* translators: %s: app name */
            __('%s is ready.', 'designsetgo-apps'),
            body.name || appId || fallbackName,
        );
        panel.querySelector('[data-dsgo-success-url]').textContent = appUrl;

        var open = panel.querySelector('[data-dsgo-success-open]');
        open.href = appUrl;

        var embed = panel.querySelector('[data-dsgo-success-embed]');
        embed.href = cfg.newPostUrl || '/wp-admin/post-new.php';

        var home = panel.querySelector('[data-dsgo-success-home]');
        home.addEventListener('click', function () { ns.setSiteHome(appId, home); });

        var copy = panel.querySelector('[data-dsgo-success-copy]');
        var copyDefault = copy.textContent;
        copy.addEventListener('click', function () {
            var done = function (ok) {
                copy.textContent = ok
                    ? __('Copied', 'designsetgo-apps')
                    : __('Copy failed', 'designsetgo-apps');
                window.setTimeout(function () { copy.textContent = copyDefault; }, 1800);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(appUrl).then(
                    function () { done(true); },
                    function () { done(false); },
                );
            } else {
                done(false);
            }
        });

        status.parentNode.insertBefore(panel, status.nextSibling);
        ns.openSuccess = panel;
    }

    var installInFlight = false;
    function upload(file) {
        if (!file) return;
        if (!/\.zip$/i.test(file.name)) {
            showStatus(__('Bundle must be a .zip file.', 'designsetgo-apps'), 'error');
            return;
        }
        if (file.size > cfg.maxUploadBytes) {
            showStatus(
                /* translators: %d: maximum upload size in megabytes */
                sprintf(__('Bundle is too large (max %d MB).', 'designsetgo-apps'), Math.round(cfg.maxUploadBytes / (1024 * 1024))),
                'error',
            );
            return;
        }
        // Reject overlapping drops: a second drop while a preview is being
        // shown (or a finalize is mid-flight) would stack two consent panels
        // and race their finalize requests. Tell the user to finish or cancel.
        if (installInFlight) {
            showStatus(__('An install is already in progress — finish or cancel it first.', 'designsetgo-apps'), 'error');
            return;
        }
        // Two-phase install: preview first (server validates + returns the
        // bucket consent payload), then on user confirmation re-upload the
        // same file to the install endpoint. The file stays in memory between
        // the two requests so we never ask the user to pick it twice.
        installInFlight = true;
        previewAndInstall(file);
    }

    function previewAndInstall(file) {
        dropzone.classList.add('is-uploading');
        /* translators: %s: bundle filename being validated */
        showStatus(sprintf(__('Validating %s…', 'designsetgo-apps'), file.name));
        setProgress(8);

        var form = new FormData();
        form.append('bundle', file);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.restRoot + 'apps/preview');
        xhr.setRequestHeader('X-WP-Nonce', cfg.nonce);
        xhr.setRequestHeader('Accept', 'application/json');

        xhr.upload.addEventListener('progress', function (ev) {
            if (!ev.lengthComputable) return;
            setProgress(8 + (ev.loaded / ev.total) * 60);
        });
        xhr.addEventListener('load', function () {
            dropzone.classList.remove('is-uploading');
            var body = {};
            try { body = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status >= 200 && xhr.status < 300) {
                setProgress(70);
                showStatus(__('Review permissions and confirm to install.', 'designsetgo-apps'));
                openInstallConsent(file, body);
            } else {
                installInFlight = false;
                showStatus(body.message || ('HTTP ' + xhr.status), 'error');
                setProgress(0);
            }
        });
        xhr.addEventListener('error', function () {
            installInFlight = false;
            dropzone.classList.remove('is-uploading');
            showStatus(__('Upload failed — check your connection and try again.', 'designsetgo-apps'), 'error');
            setProgress(0);
        });
        xhr.send(form);
    }

    function openInstallConsent(file, preview) {
        // Build a body container holding the server-rendered consent HTML.
        var bodyDiv = document.createElement('div');
        bodyDiv.className = 'dsgo-install-consent-body';
        bodyDiv.innerHTML = preview.rendered_html || '';

        var title = preview.is_update
            ? sprintf(
                /* translators: 1: app name, 2: app version */
                __('Update "%1$s" to v%2$s?', 'designsetgo-apps'),
                preview.name || preview.app_id,
                preview.version,
            )
            : sprintf(
                /* translators: 1: app name, 2: app version */
                __('Install "%1$s" v%2$s?', 'designsetgo-apps'),
                preview.name || preview.app_id,
                preview.version,
            );

        var confirmLabel = preview.is_update
            ? __('Update', 'designsetgo-apps')
            : __('Install', 'designsetgo-apps');

        var panel = ns.buildConsent({
            title: title,
            body: [bodyDiv],
            confirmLabel: confirmLabel,
            onConfirm: function (confirmBtn) {
                confirmBtn.disabled = true;
                finalizeInstall(file, preview);
            },
        });

        // Wrap cancel: the existing buildConsent already wires the cancel
        // button to closeConsent — but we also need to release the
        // installInFlight latch so the user can drop another file.
        var cancelBtn = panel.querySelector('[data-dsgo-consent-cancel]');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                installInFlight = false;
                setProgress(0);
                showStatus('');
            });
        }

        // Anchor the consent panel under the dropzone (or the install panel).
        var anchor = installPanel || dropzone.parentNode;
        ns.attachConsentToRow(anchor, panel);
    }

    function finalizeInstall(file, preview) {
        /* translators: %s: bundle filename being installed */
        showStatus(sprintf(__('Installing %s…', 'designsetgo-apps'), file.name));
        setProgress(80);

        // Body is just the bundle file. We deliberately do NOT post the
        // approved_buckets back to the server: today the install endpoint
        // doesn't enforce consent (any admin can POST directly to /apps and
        // skip the consent panel). Echoing approved_buckets[] would be wire
        // bytes that lie about doing something. When real consent
        // enforcement lands (server-side check that the bundle's active set
        // is not a strict superset of the approved set), the field comes
        // back AND the server-side check ships together.
        var form = new FormData();
        form.append('bundle', file);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.restRoot + 'apps');
        xhr.setRequestHeader('X-WP-Nonce', cfg.nonce);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', function (ev) {
            if (!ev.lengthComputable) return;
            setProgress(80 + (ev.loaded / ev.total) * 15);
        });
        xhr.addEventListener('load', function () {
            installInFlight = false;
            ns.closeConsent();
            var body = {};
            try { body = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status >= 200 && xhr.status < 300) {
                setProgress(100);
                // When the just-installed app declares required_secrets and
                // none are configured yet, send the admin straight to the
                // Secrets tab — the app cannot run without those values, and
                // the apps-list view doesn't make that gap visible.
                if (body.needs_secrets && body.secrets_url) {
                    showStatus(
                        /* translators: %s: app id or bundle filename */
                        sprintf(__('Installed %s. Set required credentials to continue.', 'designsetgo-apps'), body.id || file.name),
                        'success',
                    );
                    window.setTimeout(function () { window.location.href = body.secrets_url; }, 600);
                    return;
                }
                showStatus(
                    /* translators: 1: app id or bundle filename, 2: app URL */
                    sprintf(__('Installed %1$s. Live at %2$s', 'designsetgo-apps'), body.id || file.name, body.url || ''),
                    'success',
                );
                renderSuccessActions(body, file.name);
                ns.refresh();
                window.setTimeout(function () { setProgress(0); status.hidden = true; }, 4000);
            } else {
                showStatus(body.message || ('HTTP ' + xhr.status), 'error');
                setProgress(0);
            }
        });
        xhr.addEventListener('error', function () {
            installInFlight = false;
            ns.closeConsent();
            showStatus(__('Install failed — check your connection and try again.', 'designsetgo-apps'), 'error');
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

    root.querySelectorAll('[data-dsgo-quick-action]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = btn.getAttribute('data-dsgo-quick-action');
            if (action === 'starter') return;
            if (action === 'artifact') {
                switchTab('html');
                if (htmlDropzone) htmlDropzone.focus();
                return;
            }
            if (action === 'ai') {
                var aiDetails = root.querySelector('[data-dsgo-ai-details]');
                if (aiDetails) {
                    aiDetails.open = true;
                    aiDetails.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
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
            if (htmlPrimary) htmlPrimary.textContent = __('Drop an HTML or zip file here', 'designsetgo-apps');
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
                showStatus(__('Pick a .html file or a .zip of a static export.', 'designsetgo-apps'), 'error');
                return;
            }
            selectHtmlFile(file);
        });
    }
    if (htmlInput) {
        htmlInput.addEventListener('change', function () {
            var file = htmlInput.files && htmlInput.files[0];
            if (!isArtifactFile(file)) {
                showStatus(__('Pick a .html file or a .zip of a static export.', 'designsetgo-apps'), 'error');
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
                return __('App ID must be lowercase letters, numbers, and hyphens (3–64 chars).', 'designsetgo-apps');
            case 'empty_html':
                return __('That file is empty.', 'designsetgo-apps');
            case 'empty_bundle':
                return __('That zip didn\'t contain any supported files.', 'designsetgo-apps');
            case 'artifact_too_large':
                return __('That file is over 25 MB. Use the CLI for larger bundles.', 'designsetgo-apps');
            case 'invalid_html':
                return __('That HTML couldn\'t be read as UTF-8. Re-save the file as UTF-8 and try again.', 'designsetgo-apps');
            case 'invalid_zip':
                return __('That zip couldn\'t be opened.', 'designsetgo-apps');
            case 'manifest_present':
                return __('That zip already contains a dsgo-app.json — use the “Upload bundle” tab instead.', 'designsetgo-apps');
            case 'missing_entry_html':
                return __('That zip needs a .html file at its root (e.g. index.html or home.html).', 'designsetgo-apps');
            default:
                return fallback || __('Import failed.', 'designsetgo-apps');
        }
    }

    if (htmlSubmit) {
        htmlSubmit.addEventListener('click', function () {
            if (!selectedHtmlFile) return;
            if (!idInput) return;
            var id = idInput.value.trim();
            var name = nameInput ? nameInput.value.trim() : '';
            if (!/^[a-z][a-z0-9-]{2,63}$/.test(id)) {
                showStatus(__('App ID must be lowercase letters, numbers, and hyphens (3–64 chars).', 'designsetgo-apps'), 'error');
                idInput.focus();
                return;
            }

            htmlSubmit.disabled = true;
            /* translators: %s: HTML filename being uploaded */
            showStatus(sprintf(__('Uploading %s…', 'designsetgo-apps'), selectedHtmlFile.name));
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
                showStatus(__('Wrapping into a sandboxed bundle…', 'designsetgo-apps'));
            });
            xhr.addEventListener('load', function () {
                htmlSubmit.disabled = false;
                var body = {};
                try { body = JSON.parse(xhr.responseText); } catch (e) {}
                if (xhr.status >= 200 && xhr.status < 300) {
                    setProgress(100);
                    showStatus(
                        sprintf(__('Installed %s. Live at %s', 'designsetgo-apps'), body.id || id, body.url || ''),
                        'success',
                    );
                    renderSuccessActions(body, id);
                    ns.refresh();
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
                showStatus(__('Upload failed — check your connection and try again.', 'designsetgo-apps'), 'error');
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
            showStatus(__('Installing starter app…', 'designsetgo-apps'));
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
                        sprintf(__('Installed %s. Live at %s', 'designsetgo-apps'), res.body.id || 'dsgo-starter', res.body.url || ''),
                        'success',
                    );
                    renderSuccessActions(res.body, 'dsgo-starter');
                    ns.refresh();
                    window.setTimeout(function () { setProgress(0); status.hidden = true; }, 4000);
                } else {
                    showStatus(res.body && res.body.message ? res.body.message : ('HTTP ' + res.status), 'error');
                    setProgress(0);
                }
            }).catch(function (err) {
                starterButton.disabled = false;
                showStatus(
                    /* translators: %s: error message from the import request */
                    sprintf(__('Install failed: %s', 'designsetgo-apps'), err && err.message ? err.message : String(err)),
                    'error',
                );
                setProgress(0);
            });
        });
    }

    // ─── AI prompt: live recomposition from permission checkboxes ──────
    //
    // Mirrors AiContextPack::compose() in PHP. Keep this in sync — both
    // sides reference the same section keys from DSGoAdmin.aiContext.sections.

    var aiPermContainer = root.querySelector('[data-dsgo-ai-perms]');
    var aiPromptText = root.querySelector('[data-dsgo-ai-text]');
    var aiCtx = cfg.aiContext || null;

    function getSelectedPermissions() {
        if (!aiPermContainer || !aiCtx) return [];
        var boxes = aiPermContainer.querySelectorAll('[data-dsgo-ai-perm]');
        var all = aiCtx.permissions || [];
        var selected = {};
        for (var i = 0; i < boxes.length; i++) {
            if (boxes[i].checked) selected[boxes[i].value] = true;
        }
        // Preserve canonical ordering from aiCtx.permissions so the
        // generated manifest's permissions.read is stable.
        var out = [];
        for (var j = 0; j < all.length; j++) {
            if (selected[all[j]]) out.push(all[j]);
        }
        return out;
    }

    function composeAiPrompt(perms) {
        if (!aiCtx || !aiCtx.sections) return aiPromptText.value;
        var s = aiCtx.sections;
        var parts = [];

        parts.push(s.header);
        if (perms.indexOf('abilities') !== -1 && s.abilitiesListing) {
            parts.push(s.abilitiesListing);
        }
        parts.push(s.deliverable);
        parts.push(s.bridgePrimer);

        // Methods table: header + rows for selected perms + always-on rows.
        var methodRows = [s.methodsTableHeader];
        for (var i = 0; i < perms.length; i++) {
            var row = s.methodsByPerm && s.methodsByPerm[perms[i]];
            if (row) methodRows.push(row);
        }
        if (s.methodsAlways) methodRows.push(s.methodsAlways);
        parts.push(methodRows.join('\n'));

        // Optional shape sub-sections.
        for (var k = 0; k < perms.length; k++) {
            var shape = s.shapesByPerm && s.shapesByPerm[perms[k]];
            if (shape) parts.push(shape);
        }

        parts.push(s.permissionsSection);
        parts.push(s.errorCodes);
        parts.push(s.manifestIntro);
        parts.push(renderManifestBlock(perms));
        parts.push(s.manifestOutro);

        return parts.join('\n\n');
    }

    function renderManifestBlock(perms) {
        return [
            '```json',
            '{',
            '  "manifest_version": 1,',
            '  "id": "my-app",',
            '  "name": "My App",',
            '  "version": "0.1.0",',
            '  "entry": "index.html",',
            '  "isolation": "iframe",',
            '  "display": { "modes": ["page"], "default": "page" },',
            '  "permissions": { "read": ' + JSON.stringify(perms) + ', "write": [] }',
            '}',
            '```'
        ].join('\n');
    }

    function recomposeAiPrompt() {
        if (!aiPromptText || !aiCtx) return;
        aiPromptText.value = composeAiPrompt(getSelectedPermissions());
    }

    if (aiPermContainer) {
        aiPermContainer.addEventListener('change', function (e) {
            if (e.target && e.target.matches('[data-dsgo-ai-perm]')) {
                recomposeAiPrompt();
            }
        });
    }

    // ─── AI prompt copy-to-clipboard ────────────────────────────────────

    var aiCopyButton = root.querySelector('[data-dsgo-ai-copy]');
    if (aiCopyButton && aiPromptText) {
        var aiCopyDefault = aiCopyButton.textContent;
        aiCopyButton.addEventListener('click', function () {
            var text = aiPromptText.value || '';
            var done = function (ok) {
                aiCopyButton.textContent = ok
                    ? __('Copied ✓', 'designsetgo-apps')
                    : __('Copy failed — select & copy', 'designsetgo-apps');
                if (!ok) {
                    aiPromptText.focus();
                    aiPromptText.select();
                }
                window.setTimeout(function () {
                    aiCopyButton.textContent = aiCopyDefault;
                }, 2200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(
                    function () { done(true); },
                    function () { done(false); }
                );
            } else {
                try {
                    aiPromptText.focus();
                    aiPromptText.select();
                    var ok = document.execCommand && document.execCommand('copy');
                    done(!!ok);
                } catch (e) {
                    done(false);
                }
            }
        });
    }

    ns.refresh();
})();
