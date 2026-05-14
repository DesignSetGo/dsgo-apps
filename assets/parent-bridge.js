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
        // Apps-as-abilities: companion-plugin resolution at registration time.
        // Surfaced when AbilitiesPublisher::registration_args hits the
        // !class_exists branch — the published ability is registered with
        // a sentinel callback that returns this code, so any caller
        // (cron, webhook, dsgo.abilities.invoke) gets a structured signal
        // that the companion plugin is missing rather than a generic
        // ability error.
        'execute_php_class_not_loadable',
    ];
    /**
     * Canonical bridge error class. Carries the structured `{code, message,
     * details}` shape on a real `Error` so it can be `throw`n, `instanceof`-
     * checked, and round-tripped onto the wire without reverse-engineering
     * object literals. Implements `BridgeError`, so anywhere a `BridgeError`
     * is expected an instance is assignable.
     *
     * Throw this — never a bare `{ code, message }` literal — so `catch`
     * blocks have exactly one shape to handle.
     */
    class BridgeRequestError extends Error {
        constructor(error) {
            // `.message` stays the raw bridge message: transport.ts and toBridgeError
            // serialize it straight back onto the wire, so prefixing the code here
            // would leak `code: message` into the public error contract.
            super(error.message);
            this.code = error.code;
            this.details = error.details;
            this.name = 'BridgeRequestError';
        }
    }
    // ---------------------------------------------------------------------------
    // Iframe auto-resize clamp — the host shrinks/grows a block-embed iframe to
    // the height the app reports. Bounds keep a misbehaving app from collapsing
    // to nothing or growing without limit. Shared by the client (sender) and
    // parent-bridge (receiver) so both ends agree on the range.
    // ---------------------------------------------------------------------------
    const RESIZE_MIN_HEIGHT_PX = 100;
    const RESIZE_MAX_HEIGHT_PX = 2000;
    function clampResizeHeight(raw) {
        return Math.max(RESIZE_MIN_HEIGHT_PX, Math.min(RESIZE_MAX_HEIGHT_PX, Math.round(raw)));
    }
    // ---------------------------------------------------------------------------
    // Path validation — trust-boundary check shared by the client-side router
    // (router.ts) and the parent-side enforcement (parent-bridge.ts). Both ends
    // MUST agree: the parent re-runs this even if the iframe already validated,
    // because the iframe is untrusted.
    // ---------------------------------------------------------------------------
    /**
     * Reserved paths a root-mounted app may not navigate into. Mirrors the
     * server-side guard in InlineRenderer's mount handling.
     */
    const ROOT_RESERVED_PREFIXES = [
        '/wp-admin/',
        '/wp-login.php',
        '/wp-json/',
        '/feed',
        '/sitemap',
    ];
    /**
     * Validate a navigation path against an app's mount prefix and resolve it to
     * a parent-window URL. Structured `reason`s are preserved so callers can
     * surface a precise `invalid_params` message.
     *
     * `mountPrefix`:
     *   - `null`  — block-embed / admin: no parent URL surface.
     *   - `''`    — root-mounted: any path except WordPress-reserved prefixes.
     *   - `/x`    — prefixed mount: resolved URL is `${mountPrefix}${rawPath}`.
     */
    function validatePath(rawPath, mountPrefix) {
        if (typeof rawPath !== 'string' || rawPath === '') {
            return { ok: false, reason: 'path must be a non-empty string' };
        }
        if (!rawPath.startsWith('/')) {
            return { ok: false, reason: 'path must start with "/"' };
        }
        if (rawPath.includes('..') || rawPath.includes('//')) {
            return { ok: false, reason: 'path must not contain ".." or "//"' };
        }
        // Reject control characters (0x00-0x1F, 0x7F).
        if (/[\x00-\x1F\x7F]/.test(rawPath)) {
            return { ok: false, reason: 'path must not contain control characters' };
        }
        if (mountPrefix === null) {
            // Block-embed / admin contexts have no parent URL surface; the path is
            // valid in the abstract sense but won't change the address bar.
            return { ok: true, resolvedURL: rawPath };
        }
        if (mountPrefix === '') {
            // Root-mounted: any path allowed except WP-reserved prefixes.
            for (const reserved of ROOT_RESERVED_PREFIXES) {
                if (rawPath === reserved.replace(/\/$/, '') || rawPath.startsWith(reserved)) {
                    return { ok: false, reason: `path "${rawPath}" is in a WordPress-reserved prefix` };
                }
            }
            return { ok: true, resolvedURL: rawPath };
        }
        // Prefixed mount: the resolved URL is `${mountPrefix}${rawPath}`, except
        // when path === '/', where we want `${mountPrefix}/` rather than
        // `${mountPrefix}//`.
        const resolvedURL = rawPath === '/' ? mountPrefix + '/' : mountPrefix + rawPath;
        return { ok: true, resolvedURL };
    }

    /**
     * transport.ts — transport-agnostic request dispatcher.
     *
     * Both the iframe transport (parent-bridge.ts) and the upcoming inline
     * transport (Task 13) can call `handleRequest` with their own apiFetch
     * instance and globals, keeping all method-routing logic in one place.
     */
    /**
     * Narrow a WP-REST rejection (which arrives as `unknown` — it could be the
     * parsed JSON error body, a thrown `Response`, or anything else) to the
     * `{ data: { status } }` shape used by the per-method 401 fallbacks below.
     */
    function restStatusOf(err) {
        if (!err || typeof err !== 'object')
            return undefined;
        const data = err.data;
        if (!data || typeof data !== 'object')
            return undefined;
        const status = data.status;
        return typeof status === 'number' ? status : undefined;
    }
    /** Read a single property off an untrusted REST object without `any`. */
    function pick(obj, key) {
        if (!obj || typeof obj !== 'object')
            return undefined;
        return obj[key];
    }
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
        const r = raw;
        const title = r.title;
        const excerpt = r.excerpt;
        const content = r.content;
        return {
            id: r.id,
            slug: r.slug,
            title: title?.rendered ?? '',
            excerpt: excerpt?.rendered ?? '',
            content: content?.rendered ?? '',
            // Sibling field; only present when the manifest opts in via
            // `content.blockStyles` / `content.themeStyles`. See class-block-styles.php.
            content_styles: r.content_styles ?? null,
            status: r.status,
            protected: content?.protected ?? excerpt?.protected ?? false,
            date: r.date_gmt ? String(r.date_gmt) + 'Z' : r.date,
            modified: r.modified_gmt ? String(r.modified_gmt) + 'Z' : r.modified,
            author: r.author,
            link: r.link,
            featured_media_url: null,
            categories: r.categories ?? [],
            tags: r.tags ?? [],
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
            return {
                type: 'dsgo:response',
                id: req.id,
                ok: false,
                error: {
                    code: 'permission_denied',
                    message: `app does not have "${required}" permission`,
                    // Distinguish manifest-level denials (the app never declared this
                    // permission) from runtime denials (server-side abilities/commerce
                    // policy refused this visitor). Internal probes like the commerce
                    // surface's abilities-first lookup use this to fall back to REST
                    // when the manifest doesn't grant `abilities`, instead of failing
                    // a call the developer thought only needed `commerce`.
                    details: { reason: 'manifest_permission_missing', permission: required },
                },
            };
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
            // `routeToWp` itself only ever throws `BridgeRequestError` — a structured
            // error we can map straight through without reverse-engineering a shape.
            if (err instanceof BridgeRequestError) {
                return makeErr(req.id, err.code, err.message);
            }
            // Everything else came out of `wp.apiFetch`, which has two failure shapes:
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
                    throw new BridgeRequestError({ code: 'invalid_params', message: 'name is required' });
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
                    const r = (raw ?? {});
                    const avatars = (r.avatar_urls ?? {});
                    return {
                        id: r.id,
                        name: r.name,
                        slug: r.slug,
                        email: r.email ?? '',
                        avatar_url: avatars['96'] ?? avatars['48'] ?? avatars['24'] ?? '',
                        roles: r.roles ?? [],
                    };
                }
                catch (err) {
                    if (restStatusOf(err) === 401)
                        return null;
                    throw err;
                }
            }
            case 'user.can': {
                const { cap } = req.params;
                try {
                    const r = await af({ path: '/dsgo/v1/can?cap=' + encodeURIComponent(cap), headers });
                    return pick(r, 'can');
                }
                catch (err) {
                    if (restStatusOf(err) === 401)
                        return false;
                    throw err;
                }
            }
            case 'storage.app.get': {
                const { key } = req.params;
                const r = await af({ path: `/dsgo/v1/apps/${manifest.id}/storage/app/${encodeURIComponent(key)}`, headers });
                return pick(r, 'value');
            }
            case 'storage.app.set': {
                const { key, value } = req.params;
                await af({ path: `/dsgo/v1/apps/${manifest.id}/storage/app/${encodeURIComponent(key)}`, method: 'PUT', data: { value }, headers });
                return null;
            }
            case 'storage.user.get': {
                const { key } = req.params;
                const r = await af({ path: `/dsgo/v1/apps/${manifest.id}/storage/user/${encodeURIComponent(key)}`, headers });
                return pick(r, 'value');
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
                    throw new BridgeRequestError({ code: 'invalid_params', message: '"file" must be a Blob or File' });
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
                // level, with method/headers/body/timeout_ms siblings). URL goes
                // LAST so a caller-supplied `init.url` cannot override params.url —
                // belt-and-suspenders against a wrapper that hasn't been TS-checked.
                const params = (req.params ?? {});
                const init = (params.init ?? {});
                return await af({
                    path: `/dsgo/v1/apps/${manifest.id}/http/fetch`,
                    method: 'POST',
                    data: { ...init, url: params.url },
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
                // Unreachable in practice — `guardRequest` rejects unknown methods with
                // `unknown_method` before routing — but keep it a structured error so
                // every throw out of this function has the same shape.
                throw new BridgeRequestError({ code: 'unknown_method', message: req.method });
        }
    }

    /**
     * parent-bridge — host-page transport for sandboxed app iframes.
     *
     * One copy of this script handles every DSGo iframe on the page. Each iframe
     * declares itself with `data-dsgo-embed-id="<n>"` and ships a sibling JSON
     * config island `<script type="application/json" data-dsgo-embed-config="<n>">`
     * that holds its bridge context, manifest, permission map, and REST nonce.
     *
     * On message, we route by `event.source` so multiple block embeds on the
     * same page each see their own context — no globals, no `querySelector('iframe')`.
     */
    const embeds = new Map();
    function readConfig(id) {
        // Embed IDs are server-generated and constrained to `[A-Za-z0-9_-]+`
        // (a per-page counter), so we don't need CSS.escape here. Defensive
        // check rejects anything else rather than building a malformed selector.
        if (!/^[A-Za-z0-9_-]+$/.test(id))
            return null;
        const el = document.querySelector(`script[data-dsgo-embed-config="${id}"]`);
        if (!el?.textContent)
            return null;
        try {
            const parsed = JSON.parse(el.textContent);
            if (!parsed?.context || !parsed?.manifest || !parsed?.permMap || typeof parsed.nonce !== 'string') {
                return null;
            }
            return parsed;
        }
        catch {
            return null;
        }
    }
    function discover() {
        const iframes = document.querySelectorAll('iframe[data-dsgo-embed-id]');
        iframes.forEach((iframe) => {
            const id = iframe.dataset.dsgoEmbedId;
            if (!id)
                return;
            const cfg = readConfig(id);
            if (!cfg)
                return;
            const w = iframe.contentWindow;
            if (!w)
                return;
            if (embeds.has(w))
                return;
            embeds.set(w, { iframe, ...cfg });
        });
    }
    function handleRouterNavigate(entry, req) {
        const target = entry.iframe.contentWindow;
        if (!target)
            return;
        // Block-embed iframes ignore navigation — silently succeed so apps that
        // use a single codepath for both contexts don't have to branch.
        if (entry.context.mode !== 'page') {
            target.postMessage({ type: 'dsgo:response', id: req.id, ok: true, data: null }, '*');
            return;
        }
        const params = (req.params ?? {});
        // `validatePath` is the canonical trust-boundary validator (shared.ts).
        // The parent re-runs it even though the iframe already validated, because
        // the iframe is untrusted — it could lie about the path.
        const v = validatePath(params.path, entry.context.mountPrefix);
        if (!v.ok) {
            target.postMessage({
                type: 'dsgo:response', id: req.id, ok: false,
                error: { code: 'invalid_params', message: v.reason },
            }, '*');
            return;
        }
        const search = typeof params.search === 'string' ? params.search : '';
        const hash = typeof params.hash === 'string' ? params.hash : '';
        try {
            const fullURL = v.resolvedURL + search + hash;
            if (params.replace === true) {
                window.history.replaceState(params.state ?? null, '', fullURL);
            }
            else {
                window.history.pushState(params.state ?? null, '', fullURL);
            }
        }
        catch (e) {
            target.postMessage({
                type: 'dsgo:response', id: req.id, ok: false,
                error: { code: 'internal_error', message: `history API error: ${e instanceof Error ? e.message : String(e)}` },
            }, '*');
            return;
        }
        target.postMessage({ type: 'dsgo:response', id: req.id, ok: true, data: null }, '*');
    }
    /** Strip the embed's mountPrefix from a parent URL pathname. */
    function pathWithinEmbed(pathname, mountPrefix) {
        if (mountPrefix === null || mountPrefix === '')
            return pathname;
        if (pathname === mountPrefix || pathname === mountPrefix + '/')
            return '/';
        if (pathname.startsWith(mountPrefix + '/'))
            return pathname.slice(mountPrefix.length);
        return '/';
    }
    let popstateAttached = false;
    let popstateRequestCounter = 0;
    function attachPopstateListener() {
        if (popstateAttached)
            return;
        popstateAttached = true;
        window.addEventListener('popstate', (event) => {
            for (const entry of embeds.values()) {
                if (entry.context.mode !== 'page')
                    continue;
                const target = entry.iframe.contentWindow;
                if (!target)
                    continue;
                const id = `pop_${++popstateRequestCounter}`;
                target.postMessage({
                    type: 'dsgo:request',
                    id,
                    method: 'router:popstate',
                    params: {
                        path: pathWithinEmbed(window.location.pathname, entry.context.mountPrefix),
                        search: window.location.search,
                        hash: window.location.hash,
                        state: event.state ?? null,
                    },
                }, '*');
            }
        });
    }
    async function dispatch(entry, req) {
        const target = entry.iframe.contentWindow;
        if (!target)
            return;
        if (req.method === 'router.navigate') {
            handleRouterNavigate(entry, req);
            return;
        }
        // Synchronous guard: handles unknown_method, permission_denied, bridge.ping
        // without deferring to a microtask.
        const early = guardRequest(req, { manifest: entry.manifest, permMap: entry.permMap });
        if (early !== null) {
            target.postMessage(early, '*');
            return;
        }
        if (!window.wp?.apiFetch) {
            target.postMessage({ type: 'dsgo:response', id: req.id, ok: false, error: { code: 'internal_error', message: 'wp.apiFetch unavailable' } }, '*');
            return;
        }
        const response = await handleRequest(req, {
            manifest: entry.manifest,
            permMap: entry.permMap,
            nonce: entry.nonce,
            ...(entry.appNonce ? { appNonce: entry.appNonce } : {}),
            apiFetch: window.wp.apiFetch,
        });
        target.postMessage(response, '*');
    }
    function entryFor(source) {
        if (!source)
            return null;
        return embeds.get(source) ?? null;
    }
    window.addEventListener('message', (event) => {
        // Re-discover lazily — iframes inside lazy-rendered blocks may not have
        // been in the DOM at script load.
        if (!entryFor(event.source))
            discover();
        const entry = entryFor(event.source);
        if (!entry)
            return;
        const msg = event.data;
        if (!msg || typeof msg !== 'object')
            return;
        if (msg.type === 'dsgo:hello') {
            entry.iframe.contentWindow?.postMessage({ type: 'dsgo:context', payload: entry.context }, '*');
            return;
        }
        if (msg.type === 'dsgo:resize') {
            if (entry.context.mode !== 'block' || !entry.context.blockProps?.autoResize)
                return;
            const raw = Number(msg.height);
            if (!Number.isFinite(raw))
                return;
            const h = clampResizeHeight(raw);
            entry.iframe.style.height = h + 'px';
            return;
        }
        // Top-window navigation request from a sandboxed app — used by the commerce
        // hosted-checkout handoff. Same-origin only; we trust the URL only after
        // origin validation since the iframe is the source.
        if (msg.type === 'dsgo:nav-top') {
            const rawUrl = msg.url;
            if (typeof rawUrl !== 'string' || rawUrl === '')
                return;
            let target;
            try {
                target = new URL(rawUrl, window.location.href);
            }
            catch {
                return;
            }
            if (target.origin !== window.location.origin)
                return;
            window.location.assign(target.href);
            return;
        }
        if (msg.type === 'dsgo:request') {
            void dispatch(entry, msg);
        }
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', discover, { once: true });
    }
    else {
        discover();
    }
    attachPopstateListener();

})();
