(function () {
    'use strict';

    const BRIDGE_ERROR_CODES = [
        'permission_denied',
        'not_authenticated',
        'unknown_method',
        'invalid_params',
        'not_found',
        'rate_limited',
        'payload_too_large',
        'internal_error',
        'ai_not_configured',
        'not_implemented',
        'ability_handler_error',
        'app_load_failed',
        'ability_not_implemented',
        'ability_timeout',
        // HTTP proxy errors — soft TS-break for apps that exhaustively switch
        // over BridgeError.code (add new arms or fall through `default:`).
        'http_permission_denied',
        'http_invalid_url',
        'http_method_not_allowed',
        'http_host_not_allowed',
        'http_invalid_header',
        'http_invalid_body',
        'http_unknown_secret',
        'http_secret_not_set',
        'http_ssrf_blocked',
        'http_request_too_large',
        'http_response_too_large',
        'http_timeout',
        'http_rate_limited',
        'http_network_error',
        'sodium_unavailable',
    ];

    /**
     * transport.ts — transport-agnostic request dispatcher.
     *
     * Both the iframe transport (parent-bridge.ts) and the upcoming inline
     * transport (Task 13) can call `handleRequest` with their own apiFetch
     * instance and globals, keeping all method-routing logic in one place.
     */
    function makeOk(id, data) {
        return { type: 'dsgo:response', id, ok: true, data };
    }
    function makeErr(id, code, message) {
        return { type: 'dsgo:response', id, ok: false, error: { code, message } };
    }
    // ---------------------------------------------------------------------------
    // Response shapers — live here because they are transport-agnostic; they turn
    // raw WP REST responses into the typed bridge payload shapes.
    // ---------------------------------------------------------------------------
    function shapePost(raw) {
        if (!raw || typeof raw !== 'object')
            return raw;
        return {
            id: raw.id,
            slug: raw.slug,
            title: raw.title?.rendered ?? '',
            excerpt: raw.excerpt?.rendered ?? '',
            content: raw.content?.rendered ?? '',
            // Sibling field; only present when the manifest opts in via
            // `content.blockStyles` / `content.themeStyles`. See class-block-styles.php.
            content_styles: raw.content_styles ?? null,
            status: raw.status,
            protected: raw.content?.protected ?? raw.excerpt?.protected ?? false,
            date: raw.date_gmt ? raw.date_gmt + 'Z' : raw.date,
            modified: raw.modified_gmt ? raw.modified_gmt + 'Z' : raw.modified,
            author: raw.author,
            link: raw.link,
            featured_media_url: null,
            categories: raw.categories ?? [],
            tags: raw.tags ?? [],
        };
    }
    // ---------------------------------------------------------------------------
    // Synchronous guard — call before any async work to short-circuit fast.
    // Returns a BridgeResponse if the request should be rejected or answered
    // inline (unknown method, missing permission, bridge.ping).
    // Returns null if the request should proceed to WP REST routing.
    // ---------------------------------------------------------------------------
    function guardRequest(req, deps) {
        const { manifest, permMap } = deps;
        const required = permMap[req.method];
        if (required === undefined) {
            return makeErr(req.id, 'unknown_method', req.method);
        }
        if (required !== null && !manifest.permissions.read.includes(required)) {
            return makeErr(req.id, 'permission_denied', `app does not have "${required}" permission`);
        }
        if (req.method === 'bridge.ping') {
            return makeOk(req.id, { ok: true, bridge_version: 1, server_time: new Date().toISOString() });
        }
        return null;
    }
    // ---------------------------------------------------------------------------
    // Core dispatcher
    // ---------------------------------------------------------------------------
    async function handleRequest(req, deps) {
        const { nonce, apiFetch, manifest, appNonce } = deps;
        // 1–3. Fast-path checks (unknown method, permission, bridge.ping)
        const early = guardRequest(req, deps);
        if (early !== null)
            return early;
        // 4. Route to WP REST API
        // Storage routes carry the per-(user, app) nonce so the server can confirm
        // this call came from our app's bootstrap, not from another app forging a
        // direct fetch. See RestApi::permit_storage.
        const headers = { 'X-WP-Nonce': nonce };
        if (req.method.startsWith('storage.') && appNonce) {
            headers['X-DSGo-App-Nonce'] = appNonce;
        }
        // posts/pages calls hit `/wp/v2/...` directly; the server-side
        // `rest_prepare_post`/`rest_prepare_page` filter reads this header to
        // resolve the calling app's manifest and attach `content_styles` when the
        // app has opted in via `content.blockStyles`/`content.themeStyles`. Sent
        // unconditionally — the server no-ops cleanly when the manifest opts out.
        if (req.method.startsWith('posts.') || req.method.startsWith('pages.')) {
            headers['X-DSGo-App-Id'] = manifest.id;
        }
        // Suppress the parent's apiFetch JSON content-type middleware — the
        // browser will set `multipart/form-data; boundary=...` itself when we
        // pass FormData as the request body. Setting it manually here would
        // strip the boundary parameter and break parsing on the PHP side.
        try {
            const result = await routeToWp(req, { apiFetch, headers, manifest });
            return makeOk(req.id, result);
        }
        catch (err) {
            // wp.apiFetch has two failure shapes:
            //   1. Default (`parse: true`): rejects with the parsed JSON error body
            //      `{ code, message, data: { status } }`.
            //   2. `parse: false` (used by posts.list/pages.list to read X-WP-Total
            //      headers): rejects with the raw `Response` on non-2xx.
            // Map both to a BridgeError so callers get a consistent code + message.
            let restCode;
            let restMsg;
            let status;
            let body;
            if (typeof Response !== 'undefined' && err instanceof Response) {
                status = err.status;
                try {
                    body = await err.clone().json();
                }
                catch {
                    // body wasn't JSON — fall through to status-based mapping
                }
            }
            else {
                body = err;
                if (typeof body?.data?.status === 'number')
                    status = body.data.status;
            }
            if (body) {
                if (typeof body.code === 'string')
                    restCode = body.code;
                if (typeof body.message === 'string')
                    restMsg = body.message;
            }
            // WP normalizes `posts.list` / `pages.list` capability violations on the
            // `status` param (subscriber asking for `status=draft`, etc.) to a 400
            // `rest_invalid_param`. The actual signal is buried in
            // `data.details.status.code === 'rest_forbidden_status'`. Bridge spec
            // requires `permission_denied` for that case — map it here.
            const detailsStatusCode = body?.data?.details?.status?.code;
            if (restCode === 'rest_invalid_param' && detailsStatusCode === 'rest_forbidden_status') {
                restCode = 'permission_denied';
                if (!restMsg)
                    restMsg = 'Status is forbidden.';
            }
            const isKnownBridgeCode = restCode !== undefined &&
                BRIDGE_ERROR_CODES.includes(restCode);
            const code = isKnownBridgeCode
                ? restCode
                : status === 401 ? 'not_authenticated'
                    : status === 403 ? 'permission_denied'
                        : status === 404 ? 'not_found'
                            : status === 413 ? 'payload_too_large'
                                : status === 429 ? 'rate_limited'
                                    : 'internal_error';
            return makeErr(req.id, code, restMsg ?? (status !== undefined ? `HTTP ${status}` : 'request failed'));
        }
    }
    // ---------------------------------------------------------------------------
    // Internal routing — called only by handleRequest
    // ---------------------------------------------------------------------------
    async function routeToWp(req, ctx) {
        const { apiFetch: af, headers, manifest } = ctx;
        switch (req.method) {
            case 'help.method': {
                // Always-available bridge method docs lookup. No permission gate.
                // The model uses this to discover method signatures without the
                // harness having to enumerate every method in the system prompt.
                const { name } = (req.params ?? {});
                if (typeof name !== 'string' || name === '') {
                    throw { code: 'invalid_params', message: 'name is required' };
                }
                return await af({ path: `/dsgo/v1/apps/${manifest.id}/help/methods/${encodeURIComponent(name)}`, headers });
            }
            case 'site.info': {
                // The built-in WP REST root index (`/`) doesn't expose admin_email,
                // language, or the date/time formats — call our /dsgo/v1/site-info
                // helper which assembles the spec-required shape server-side. The
                // body is already in bridge shape; pass through unchanged.
                return await af({ path: '/dsgo/v1/site-info', headers });
            }
            case 'posts.list': {
                const q = { ...(req.params ?? {}) };
                // Optional `type` routes to a CPT's REST endpoint (`/wp/v2/<type>`).
                // Public, show_in_rest post types are readable; the server enforces
                // visibility and capability the same way it does for the default
                // `posts` route. Defaults to `posts` when omitted.
                const type = typeof q.type === 'string' && q.type ? q.type : 'posts';
                delete q.type;
                const resp = await af({ path: `/wp/v2/${type}?` + new URLSearchParams(q).toString(), headers, parse: false });
                const rawItems = await resp.json();
                return {
                    items: Array.isArray(rawItems) ? rawItems.map(shapePost) : [],
                    total: parseInt(resp.headers.get('X-WP-Total') ?? '0', 10),
                    total_pages: parseInt(resp.headers.get('X-WP-TotalPages') ?? '0', 10),
                };
            }
            case 'posts.get': {
                const { id, type } = req.params;
                const route = typeof type === 'string' && type ? type : 'posts';
                return shapePost(await af({ path: `/wp/v2/${route}/${id}`, headers }));
            }
            case 'pages.list': {
                const q = (req.params ?? {});
                const resp = await af({ path: '/wp/v2/pages?' + new URLSearchParams(q).toString(), headers, parse: false });
                const rawItems = await resp.json();
                return {
                    items: Array.isArray(rawItems) ? rawItems.map(shapePost) : [],
                    total: parseInt(resp.headers.get('X-WP-Total') ?? '0', 10),
                    total_pages: parseInt(resp.headers.get('X-WP-TotalPages') ?? '0', 10),
                };
            }
            case 'pages.get': {
                const { id } = req.params;
                return shapePost(await af({ path: `/wp/v2/pages/${id}`, headers }));
            }
            case 'user.current': {
                try {
                    const raw = await af({ path: '/wp/v2/users/me?context=edit', headers });
                    return {
                        id: raw.id,
                        name: raw.name,
                        slug: raw.slug,
                        email: raw.email ?? '',
                        avatar_url: raw.avatar_urls?.['96'] ?? raw.avatar_urls?.['48'] ?? raw.avatar_urls?.['24'] ?? '',
                        roles: raw.roles ?? [],
                    };
                }
                catch (err) {
                    if (err?.data?.status === 401)
                        return null;
                    throw err;
                }
            }
            case 'user.can': {
                const { cap } = req.params;
                try {
                    const r = await af({ path: '/dsgo/v1/can?cap=' + encodeURIComponent(cap), headers });
                    return r.can;
                }
                catch (err) {
                    if (err?.data?.status === 401)
                        return false;
                    throw err;
                }
            }
            case 'storage.app.get': {
                const { key } = req.params;
                const r = await af({ path: `/dsgo/v1/apps/${manifest.id}/storage/app/${encodeURIComponent(key)}`, headers });
                return r.value;
            }
            case 'storage.app.set': {
                const { key, value } = req.params;
                await af({ path: `/dsgo/v1/apps/${manifest.id}/storage/app/${encodeURIComponent(key)}`, method: 'PUT', data: { value }, headers });
                return null;
            }
            case 'storage.user.get': {
                const { key } = req.params;
                const r = await af({ path: `/dsgo/v1/apps/${manifest.id}/storage/user/${encodeURIComponent(key)}`, headers });
                return r.value;
            }
            case 'storage.user.set': {
                const { key, value } = req.params;
                await af({ path: `/dsgo/v1/apps/${manifest.id}/storage/user/${encodeURIComponent(key)}`, method: 'PUT', data: { value }, headers });
                return null;
            }
            case 'abilities.list': {
                return await af({ path: `/dsgo/v1/apps/${manifest.id}/abilities`, headers });
            }
            case 'abilities.invoke': {
                const { name, args } = (req.params ?? {});
                // The ability name slug contains "/" — leave it un-encoded so the route
                // regex (/abilities/<ns>/<name>) matches.
                return await af({
                    path: `/dsgo/v1/apps/${manifest.id}/abilities/${name}`,
                    method: 'POST',
                    data: args !== undefined ? { args } : {},
                    headers,
                });
            }
            case 'ai.prompt': {
                const params = (req.params ?? {});
                return await af({
                    path: `/dsgo/v1/apps/${manifest.id}/ai/prompt`,
                    method: 'POST',
                    data: params,
                    headers,
                });
            }
            case 'email.send': {
                const params = (req.params ?? {});
                return await af({
                    path: `/dsgo/v1/apps/${manifest.id}/email/send`,
                    method: 'POST',
                    data: params,
                    headers,
                });
            }
            case 'media.upload': {
                const params = (req.params ?? {});
                // Accept Blob (the canonical case — SVG, Canvas.toBlob, fetch().blob())
                // and File (which extends Blob, so the runtime check covers it).
                if (typeof Blob === 'undefined' || !(params.file instanceof Blob)) {
                    // eslint-disable-next-line no-throw-literal
                    throw { code: 'invalid_params', message: '"file" must be a Blob or File' };
                }
                const filename = typeof params.filename === 'string' && params.filename !== ''
                    ? params.filename
                    : params.file.name || 'upload.bin';
                const formData = new FormData();
                formData.append('file', params.file, filename);
                formData.append('filename', filename);
                if (typeof params.alt_text === 'string' && params.alt_text !== '') {
                    formData.append('alt_text', params.alt_text);
                }
                return await af({
                    path: `/dsgo/v1/apps/${manifest.id}/media/upload`,
                    method: 'POST',
                    body: formData,
                    headers,
                });
            }
            case 'http.fetch': {
                // The client wrapper passes { url, init } in params; flatten to a
                // single payload so the REST args declaration matches (url at top
                // level, with method/headers/body/timeout_ms siblings).
                const params = (req.params ?? {});
                const init = (params.init ?? {});
                return await af({
                    path: `/dsgo/v1/apps/${manifest.id}/http/fetch`,
                    method: 'POST',
                    data: { url: params.url, ...init },
                    headers,
                });
            }
            // Commerce — route every commerce.* method through the single dispatcher.
            // Method "commerce.<group>.<verb>" maps to "/commerce/<group>/<verb>"
            // (verb's underscores translate to hyphens for URL hygiene).
            case 'commerce.products.list':
            case 'commerce.products.get':
            case 'commerce.cart.get':
            case 'commerce.cart.add_item':
            case 'commerce.cart.update_item':
            case 'commerce.cart.remove_item':
            case 'commerce.checkout.open_hosted_page': {
                const tail = req.method.slice('commerce.'.length).replace(/\./g, '/').replace(/_/g, '-');
                const params = (req.params ?? {});
                return await af({
                    path: `/dsgo/v1/apps/${manifest.id}/commerce/${tail}`,
                    method: 'POST',
                    data: { params },
                    headers,
                });
            }
            default:
                throw new Error('unknown method: ' + req.method);
        }
    }

    /**
     * Initialize the parent-side bridge for inline-mode apps.
     *
     * Listens for {type: 'dsgo:request'} messages on the same window, dispatches
     * via the shared transport, and replies with {type: 'dsgo:response'}.
     *
     * Same-window postMessage trick: window.postMessage fires a message event on
     * window itself with event.source === window. The source guard prevents the
     * bridge from re-dispatching its own response messages.
     */
    function startInlineParentBridge(deps) {
        window.addEventListener('message', async (event) => {
            // Allow window (real browsers) or null (jsdom/sandboxed same-document contexts).
            // Cross-origin frames have a non-null, non-window source so they are still excluded.
            // The type === 'dsgo:request' check below is the primary loop-prevention guard.
            if (event.source !== null && event.source !== window)
                return;
            const msg = event.data;
            if (!msg || msg.type !== 'dsgo:request')
                return;
            const req = msg;
            const response = await handleRequest(req, deps);
            window.postMessage(response, '*');
        });
    }

    if (window.__dsgoBridgeDeps) {
        startInlineParentBridge(window.__dsgoBridgeDeps);
    }

})();
//# sourceMappingURL=parent-bridge-inline.js.map
