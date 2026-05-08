<?php
/**
 * Hand-curated typed summaries of bridge methods, fed into the harness
 * <bridge_tools> prompt section. Source of truth: BRIDGE-API.md.
 *
 * Keep entries terse — they go into every Harness generation request, and
 * model attention is the bottleneck. Match production method names exactly
 * (e.g. dsgo.storage.app.* / dsgo.storage.user.*, not dsgo.storage.*).
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

return [
    // --- Site / content ----------------------------------------------------
    [
        'name'        => 'dsgo.site.info',
        'permission'  => 'site_info',
        'description' => 'Site metadata: title, description, URL, language, timezone, date/time formats.',
        'arguments'   => [],
        'returns'     => '{ title, description, url, language, timezone, gmt_offset, date_format, time_format, admin_email? }',
        'example'     => 'const info = await dsgo.site.info();',
    ],
    [
        'name'        => 'dsgo.posts.list',
        'permission'  => 'posts',
        'description' => 'List posts visible to the current visitor. Drafts/private require sufficient caps.',
        'arguments'   => [
            'per_page' => 'integer (1-100, default 10)',
            'page'     => 'integer (default 1)',
            'search'   => 'string (optional)',
            'category' => 'integer | string (term ID or slug)',
            'tag'      => 'integer | string',
            'orderby'  => '"date" | "modified" | "title" | "id"',
            'order'    => '"asc" | "desc"',
            'status'   => '"publish" | "draft" | "private" | "pending" | "future" | "any"',
        ],
        'returns'     => '{ items: Post[], total: number, total_pages: number }',
        'example'     => 'const { items } = await dsgo.posts.list({ per_page: 5 });',
    ],
    [
        'name'        => 'dsgo.posts.get',
        'permission'  => 'posts',
        'description' => 'Fetch one post by ID. Rejects with not_found if the visitor cannot read it.',
        'arguments'   => [ 'id' => 'number' ],
        'returns'     => '{ id, slug, title, excerpt, content, status, protected, date, modified, author, link, featured_media_url, categories, tags }',
        'example'     => 'const post = await dsgo.posts.get(42);',
    ],
    [
        'name'        => 'dsgo.pages.list',
        'permission'  => 'pages',
        'description' => 'List pages. Same shape as posts.list minus category/tag filters.',
        'arguments'   => [ 'per_page' => 'number', 'page' => 'number', 'search' => 'string', 'orderby' => 'string', 'order' => 'string', 'status' => 'string' ],
        'returns'     => '{ items: Page[], total: number, total_pages: number }',
        'example'     => 'const { items } = await dsgo.pages.list();',
    ],
    [
        'name'        => 'dsgo.pages.get',
        'permission'  => 'pages',
        'description' => 'Fetch one page by ID.',
        'arguments'   => [ 'id' => 'number' ],
        'returns'     => 'Page',
        'example'     => 'const page = await dsgo.pages.get(7);',
    ],

    // --- User / capabilities ----------------------------------------------
    [
        'name'        => 'dsgo.user.current',
        'permission'  => 'user',
        'description' => 'Current logged-in user, or null for anonymous visitors.',
        'arguments'   => [],
        'returns'     => '{ id, name, slug, email, avatar_url, roles } | null',
        'example'     => 'const u = await dsgo.user.current();',
    ],
    [
        'name'        => 'dsgo.user.can',
        'permission'  => 'user',
        'description' => 'Boolean check for a WordPress capability (e.g. "edit_posts", "manage_options"). Returns false for anonymous visitors and unknown caps.',
        'arguments'   => [ 'cap' => 'string' ],
        'returns'     => 'boolean',
        'example'     => 'if (await dsgo.user.can("edit_posts")) { /* show editor UI */ }',
    ],

    // --- Storage (no permission required) ---------------------------------
    [
        'name'        => 'dsgo.storage.app.get',
        'permission'  => null,
        'description' => 'Read a per-(app, site) value (shared across all visitors). Returns null if unset.',
        'arguments'   => [ 'key' => 'string (1-128 chars, [a-zA-Z0-9._-])' ],
        'returns'     => 'unknown | null',
        'example'     => 'const cfg = await dsgo.storage.app.get("settings");',
    ],
    [
        'name'        => 'dsgo.storage.app.set',
        'permission'  => null,
        'description' => 'Write a per-(app, site) value. Quota: 256 KB total per app. No delete in v1 — pass null to clear an entry.',
        'arguments'   => [ 'key' => 'string', 'value' => 'JSON-serializable | null' ],
        'returns'     => 'void',
        'example'     => 'await dsgo.storage.app.set("settings", { theme: "dark" });',
    ],
    [
        'name'        => 'dsgo.storage.user.get',
        'permission'  => null,
        'description' => 'Read a per-(app, logged-in-user) value. Returns null for anonymous visitors. App-scoped storage is invisible to other apps.',
        'arguments'   => [ 'key' => 'string' ],
        'returns'     => 'unknown | null',
        'example'     => 'const pref = await dsgo.storage.user.get("theme");',
    ],
    [
        'name'        => 'dsgo.storage.user.set',
        'permission'  => null,
        'description' => 'Write a per-(app, user) value. Rejects not_authenticated for anonymous visitors. Quota: 256 KB per (app, user). No delete in v1 — pass null to clear an entry.',
        'arguments'   => [ 'key' => 'string', 'value' => 'JSON-serializable | null' ],
        'returns'     => 'void',
        'example'     => 'await dsgo.storage.user.set("theme", "dark");',
    ],

    // --- Routing (no permission required) ---------------------------------
    [
        'name'        => 'dsgo.router.navigate',
        'permission'  => null,
        'description' => 'Navigate within the app mount. Path must start with "/" and stay inside the mount. Inline + iframe-full-page update the URL; block/admin update state only.',
        'arguments'   => [
            'path' => 'string',
            'opts' => '{ replace?, state?, search?, hash? }',
        ],
        'returns'     => 'Promise<void>',
        'example'     => 'await dsgo.router.navigate("/about");',
    ],
    [
        'name'        => 'dsgo.router.subscribe',
        'permission'  => null,
        'description' => 'Register a handler for navigation events (programmatic + browser back/forward). Returns an unsubscribe function. Read initial location from dsgo.context.path/search/hash.',
        'arguments'   => [ 'handler' => '(loc: { path, search, hash, state }) => void' ],
        'returns'     => '() => void',
        'example'     => 'const off = dsgo.router.subscribe(loc => render(loc.path));',
    ],

    // --- Email -----------------------------------------------------------
    [
        'name'        => 'dsgo.email.send',
        'permission'  => 'email',
        'description' => 'Send mail via wp_mail(). Recipient must be one of the symbolic types declared in manifest.email.recipients ("admin" or "current_user"). Subject/body sanitized; rate-limited to 100/hour.',
        'arguments'   => [
            'to'      => '"admin" | "current_user"',
            'subject' => 'string (1-200 chars)',
            'body'    => 'string (<= 64 KB after sanitization)',
            'isHtml'  => 'boolean (default false)',
            'replyTo' => 'string (optional, valid email)',
        ],
        'returns'     => '{ sent: true }',
        'example'     => 'await dsgo.email.send({ to: "admin", subject: "New lead", body: "..." });',
    ],

    // --- AI / abilities (WP 7.0+) ----------------------------------------
    [
        'name'        => 'dsgo.ai.prompt',
        'permission'  => 'ai',
        'description' => 'Run a prompt through the site\'s configured Connector. Tool calls draw from manifest.abilities.consumes. The site pays for inference; the app never sees an API key.',
        'arguments'   => [
            'messages'   => 'Array<{ role: "user" | "assistant" | "system"; content: string }>',
            'max_tokens' => 'number (optional)',
            'tools'      => '"auto" | string[] (ability names)',
        ],
        'returns'     => '{ content, usage: { input_tokens, output_tokens }, tool_calls: [...] }',
        'example'     => 'const r = await dsgo.ai.prompt({ messages: [{ role: "user", content: "Hi" }] });',
    ],
    [
        'name'        => 'dsgo.abilities.list',
        'permission'  => 'abilities',
        'description' => 'List abilities visible to the visitor that match manifest.abilities.consumes patterns.',
        'arguments'   => [],
        'returns'     => 'Array<{ name, label, description, category, input_schema, output_schema, annotations }>',
        'example'     => 'const tools = await dsgo.abilities.list();',
    ],
    [
        'name'        => 'dsgo.abilities.invoke',
        'permission'  => 'abilities',
        'description' => 'Invoke a registered ability by name. Honors each ability\'s own permission_callback server-side.',
        'arguments'   => [ 'name' => 'string (e.g. "yoast/analyze-page-seo")', 'args' => 'object (optional)' ],
        'returns'     => 'unknown (per ability)',
        'example'     => 'const out = await dsgo.abilities.invoke("yoast/analyze-page-seo", { post_id: 42 });',
    ],
    [
        'name'        => 'dsgo.abilities.implement',
        'permission'  => null,
        'description' => 'Iframe-mode only. Register a handler for an ability declared in manifest.abilities.publishes. The handler runs when other plugins / the site\'s AI agent invoke the ability.',
        'arguments'   => [ 'name' => 'string', 'handler' => '(input: I) => Promise<O> | O' ],
        'returns'     => 'void',
        'example'     => 'dsgo.abilities.implement("recipe/import", async ({ url }) => fetchRecipe(url));',
    ],

    // --- Bridge utilities (no permission required) -----------------------
    [
        'name'        => 'dsgo.ready',
        'permission'  => null,
        'description' => 'Promise that resolves once the bridge has delivered context. Await before reading dsgo.context.* directly.',
        'arguments'   => [],
        'returns'     => 'Promise<void>',
        'example'     => 'await dsgo.ready;',
    ],
    [
        'name'        => 'dsgo.context',
        'permission'  => null,
        'description' => 'Runtime metadata. Available after dsgo.ready resolves; null before.',
        'arguments'   => [],
        'returns'     => '{ bridgeVersion, appId, mode, locale, theme, blockProps, routeParams, path, search, hash, mountPrefix } | null',
        'example'     => 'await dsgo.ready; console.log(dsgo.context.path);',
    ],
    [
        'name'        => 'dsgo.bridge.ping',
        'permission'  => null,
        'description' => 'Liveness check with server clock for skew diagnostics.',
        'arguments'   => [],
        'returns'     => '{ ok: true, bridge_version: 1, server_time: string }',
        'example'     => 'await dsgo.bridge.ping();',
    ],
    [
        'name'        => 'dsgo.bridge.requestResize',
        'permission'  => null,
        'description' => 'Block-embed mode + blockProps.autoResize=true only. Fire-and-forget; height clamped to [100, 2000] px.',
        'arguments'   => [ 'height' => 'number' ],
        'returns'     => 'void',
        'example'     => 'dsgo.bridge.requestResize(document.body.scrollHeight);',
    ],
];
