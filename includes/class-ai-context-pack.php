<?php
/**
 * AI Context Pack — builds a copy-paste prompt that briefs Claude / ChatGPT
 * on how to build a DSGo App: bridge API surface, manifest schema, allowed
 * permissions, plus site-specific context (URL prefix, available abilities).
 *
 * The prompt is composed from discrete sections so the admin UI can let the
 * user check which permissions they want and instantly recompose the
 * textarea without a network round-trip.
 *
 * Public surface:
 *   - all_permissions() / default_permissions() — the universe + UI defaults.
 *   - render_prompt(?array $perms) — server-side render used by initial page
 *     load and by /llms.txt integration.
 *   - sections_for_client() — same building blocks, shipped to JS via
 *     wp_localize_script for live recomposition.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class AiContextPack {

    /** Read permissions an app can request, in the order shown in the UI. */
    public const ALL_PERMISSIONS = ['site_info', 'posts', 'pages', 'user', 'abilities', 'ai'];

    /** Sensible starting set — covers ~70% of apps (personalization + read posts). */
    public const DEFAULT_PERMISSIONS = ['site_info', 'posts', 'user'];

    /**
     * Human-readable labels for the permission checkbox row.
     *
     * @return array<string,array{label:string,help:string}>
     */
    public static function permission_labels(): array {
        return [
            'site_info' => [
                'label' => __('Site info', 'designsetgo-apps'),
                'help'  => __('Site title, URL, language, timezone.', 'designsetgo-apps'),
            ],
            'posts' => [
                'label' => __('Posts', 'designsetgo-apps'),
                'help'  => __('List and read posts + custom post types.', 'designsetgo-apps'),
            ],
            'pages' => [
                'label' => __('Pages', 'designsetgo-apps'),
                'help'  => __('List and read static pages.', 'designsetgo-apps'),
            ],
            'user' => [
                'label' => __('User', 'designsetgo-apps'),
                'help'  => __('Current user identity + capability checks.', 'designsetgo-apps'),
            ],
            'abilities' => [
                'label' => __('Abilities', 'designsetgo-apps'),
                'help'  => __('List + invoke abilities registered by other plugins (Yoast SEO, etc.).', 'designsetgo-apps'),
            ],
            'ai' => [
                'label' => __('AI', 'designsetgo-apps'),
                'help'  => __('Send prompts through the site\'s configured AI connector (WP 7.0+).', 'designsetgo-apps'),
            ],
        ];
    }

    public static function all_permissions(): array {
        return self::ALL_PERMISSIONS;
    }

    public static function default_permissions(): array {
        return self::DEFAULT_PERMISSIONS;
    }

    /**
     * Server-side render. Used by the initial page load and by the
     * /llms.txt integration. Pass null to use the default permission set.
     *
     * @param string[]|null $permissions
     */
    public static function render_prompt(?array $permissions = null): string {
        $perms    = self::sanitize_permissions($permissions);
        $sections = self::build_sections();
        return self::compose($sections, $perms);
    }

    /**
     * Structured sections for the client-side recomposer. Identical
     * data to what `render_prompt()` consumes; the JS just concatenates
     * the right pieces based on which checkboxes are ticked.
     *
     * @return array<string,mixed>
     */
    public static function sections_for_client(): array {
        return self::build_sections();
    }

    /**
     * @param string[]|null $permissions
     * @return string[]
     */
    private static function sanitize_permissions(?array $permissions): array {
        if ($permissions === null) {
            return self::DEFAULT_PERMISSIONS;
        }
        $allowed = array_values(array_intersect(self::ALL_PERMISSIONS, $permissions));
        return array_values(array_unique($allowed));
    }

    /**
     * Compact markdown section for /llms.txt — the site-level llms.txt
     * advertises that this site speaks DSGo Apps and points at the
     * developer reference.
     */
    public static function llms_section_summary(): string {
        $site_url = (string) home_url('/');
        $docs_url = 'https://designsetgo.dev/docs';
        $lines = [];
        $lines[] = '## DesignSetGo Apps';
        $lines[] = '';
        $lines[] = '> This WordPress site runs DesignSetGo Apps — sandboxed AI-built HTML apps with a permissioned `postMessage` bridge to site data (posts, pages, user, AI, abilities).';
        $lines[] = '';
        $lines[] = sprintf('- [DSGo Apps developer reference](%s): build a single-file HTML artifact that runs inside this site.', $docs_url);
        $lines[] = sprintf('- [Bridge API spec](%s/bridge): all bridge methods, permissions, error codes.', $docs_url);
        $lines[] = sprintf('- [Manifest spec](%s/manifest): the `dsgo-app.json` schema.', $docs_url);
        $lines[] = sprintf('- This site\'s apps mount under `%s%s/`.', rtrim($site_url, '/'), '/' . Settings::get_url_prefix());
        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * Long-form markdown section for /llms-full.txt — embeds the full
     * prompt (all permissions on) so any AI tool fetching the site's
     * /llms-full.txt picks up the same bridge brief.
     */
    public static function llms_section_full(): string {
        $lines = [];
        $lines[] = '## DesignSetGo Apps — Developer Reference';
        $lines[] = '';
        $lines[] = '> Build a sandboxed HTML app that runs inside this WordPress site and talks to it through a permissioned postMessage bridge. The full prompt below is what a human admin would paste into Claude or ChatGPT to brief it.';
        $lines[] = '';
        $lines[] = self::render_prompt(self::ALL_PERMISSIONS);
        $lines[] = '';
        return implode("\n", $lines);
    }

    /* ─── Composition ────────────────────────────────────────────────── */

    /**
     * Concatenate the section pieces in order, filtering by selected
     * permissions. Mirrors the JS recomposer in admin-page.js — keep
     * the two in sync.
     *
     * @param array<string,mixed> $s
     * @param string[]            $perms
     */
    private static function compose(array $s, array $perms): string {
        $parts = [];
        $parts[] = $s['header'];
        if (in_array('abilities', $perms, true) && $s['abilitiesListing'] !== '') {
            $parts[] = $s['abilitiesListing'];
        }
        $parts[] = $s['deliverable'];
        $parts[] = $s['bridgePrimer'];

        // Methods table — header + selected rows + always-on rows.
        $methods = [$s['methodsTableHeader']];
        foreach ($perms as $p) {
            if (!empty($s['methodsByPerm'][$p])) {
                $methods[] = $s['methodsByPerm'][$p];
            }
        }
        $methods[] = $s['methodsAlways'];
        $parts[] = implode("\n", $methods);

        // Optional shape sub-sections (posts.list query, ai.prompt).
        foreach ($perms as $p) {
            if (!empty($s['shapesByPerm'][$p])) {
                $parts[] = $s['shapesByPerm'][$p];
            }
        }

        $parts[] = $s['permissionsSection'];
        $parts[] = $s['errorCodes'];

        // Manifest example with the actual selected permissions.
        $parts[] = $s['manifestIntro'];
        $parts[] = self::manifest_block($perms);
        $parts[] = $s['manifestOutro'];

        return implode("\n\n", $parts);
    }

    /**
     * @param string[] $perms
     */
    private static function manifest_block(array $perms): string {
        $perm_json = json_encode(array_values($perms), JSON_UNESCAPED_SLASHES);
        $lines = [];
        $lines[] = "```json";
        $lines[] = "{";
        $lines[] = "  \"manifest_version\": 1,";
        $lines[] = "  \"id\": \"my-app\",";
        $lines[] = "  \"name\": \"My App\",";
        $lines[] = "  \"version\": \"0.1.0\",";
        $lines[] = "  \"entry\": \"index.html\",";
        $lines[] = "  \"isolation\": \"iframe\",";
        $lines[] = "  \"display\": { \"modes\": [\"page\", \"block\"], \"default\": \"page\" },";
        $lines[] = "  \"permissions\": { \"read\": {$perm_json}, \"write\": [] }";
        $lines[] = "}";
        $lines[] = "```";
        return implode("\n", $lines);
    }

    /* ─── Section builders ───────────────────────────────────────────── */

    /**
     * @return array<string,mixed>
     */
    private static function build_sections(): array {
        $site_name  = (string) get_bloginfo('name');
        $site_url   = (string) home_url('/');
        $url_prefix = Settings::get_url_prefix();
        $connector  = self::ai_connector_summary();
        $abilities  = self::collect_published_abilities();

        return [
            'header'              => self::build_header($site_name, $site_url, $url_prefix, $connector),
            'abilitiesListing'    => self::build_abilities_listing($abilities),
            'deliverable'         => self::build_deliverable(),
            'bridgePrimer'        => self::build_bridge_primer(),
            'methodsTableHeader'  => self::build_methods_table_header(),
            'methodsByPerm'       => self::build_methods_by_perm(),
            'methodsAlways'       => self::build_methods_always(),
            'shapesByPerm'        => self::build_shapes_by_perm(),
            'permissionsSection'  => self::build_permissions_section(),
            'errorCodes'          => self::build_error_codes(),
            'manifestIntro'       => self::build_manifest_intro(),
            'manifestOutro'       => self::build_manifest_outro(),
        ];
    }

    private static function build_header(string $site_name, string $site_url, string $url_prefix, string $connector): string {
        $lines = [];
        $lines[] = "You are building a **DesignSetGo App** — a sandboxed single-file HTML page that runs inside a WordPress site and talks to it through a permissioned `postMessage` bridge.";
        $lines[] = '';
        $lines[] = "## Target site";
        $lines[] = "- Name: {$site_name}";
        $lines[] = "- URL: {$site_url}";
        $lines[] = "- Apps mount under: `/{$url_prefix}/{app-id}/`";
        $lines[] = "- AI connector: {$connector}";
        return implode("\n", $lines);
    }

    /**
     * @param array<string,string> $abilities
     */
    private static function build_abilities_listing(array $abilities): string {
        if ($abilities === []) return '';
        $lines = [];
        $lines[] = "## Site abilities available to invoke";
        $lines[] = '';
        foreach ($abilities as $name => $desc) {
            $short = mb_substr($desc, 0, 100);
            $lines[] = "- `{$name}` — {$short}";
        }
        $lines[] = '';
        $lines[] = "Declare `permissions.read: [\"abilities\"]` plus `abilities.consumes: [<patterns>]` in the manifest to call these from `dsgo.abilities.invoke(name, args)`.";
        return implode("\n", $lines);
    }

    private static function build_deliverable(): string {
        $lines = [];
        $lines[] = "## Deliverable";
        $lines[] = "Produce a **single self-contained `.html` file**. No build step, no external bundler, no CDN scripts. The user will drop this file into the *Upload artifact* tab on their DSGo Apps admin page and it will run inside a sandboxed iframe.";
        $lines[] = '';
        $lines[] = "Constraints:";
        $lines[] = "- One file. Inline all CSS in `<style>` and all JS in `<script>`. No external `<link>` or `<script src>`.";
        $lines[] = "- Sandboxed iframe with an opaque origin — no parent DOM, real cookies, or real origin storage. The runtime shims `localStorage`, `sessionStorage`, and `document.cookie` in memory so common artifacts do not crash, but persistent state should use `dsgo.storage.*` through the bridge.";
        $lines[] = "- No frameworks unless you inline them (`<script type=\"module\">` + vanilla JS / preact-from-CDN-inlined is fine; React via JSX needs a build step → not viable here).";
        return implode("\n", $lines);
    }

    private static function build_bridge_primer(): string {
        $lines = [];
        $lines[] = "## The bridge";
        $lines[] = '';
        $lines[] = "Drop this minimal wrapper at the top of your `<script>`. The host injects messages over `postMessage` and the wrapper turns them into a `call(method, params)` helper that returns a promise.";
        $lines[] = '';
        $lines[] = "```html";
        $lines[] = "<script type=\"module\">";
        $lines[] = "const pending = new Map();";
        $lines[] = "let nextId = 0;";
        $lines[] = "let context = null;";
        $lines[] = "let contextResolve;";
        $lines[] = "const ready = new Promise(r => { contextResolve = r; });";
        $lines[] = '';
        $lines[] = "window.addEventListener('message', (e) => {";
        $lines[] = "  if (e.source !== window.parent) return;";
        $lines[] = "  const msg = e.data;";
        $lines[] = "  if (!msg || typeof msg !== 'object') return;";
        $lines[] = "  if (msg.type === 'dsgo:context') { context = msg.payload; contextResolve(); return; }";
        $lines[] = "  if (msg.type === 'dsgo:response') {";
        $lines[] = "    const p = pending.get(msg.id); if (!p) return;";
        $lines[] = "    pending.delete(msg.id);";
        $lines[] = "    if (msg.ok) p.resolve(msg.data); else p.reject(Object.assign(new Error(msg.error.message), msg.error));";
        $lines[] = "  }";
        $lines[] = "});";
        $lines[] = "window.parent.postMessage({ type: 'dsgo:hello' }, '*');";
        $lines[] = '';
        $lines[] = "function call(method, params) {";
        $lines[] = "  const id = String(++nextId);";
        $lines[] = "  return new Promise((resolve, reject) => {";
        $lines[] = "    pending.set(id, { resolve, reject });";
        $lines[] = "    window.parent.postMessage({ type: 'dsgo:request', id, method, params }, '*');";
        $lines[] = "  });";
        $lines[] = "}";
        $lines[] = '';
        $lines[] = "await ready;";
        $lines[] = "// Now call bridge methods, e.g.:";
        $lines[] = "// const { items } = await call('posts.list', { per_page: 5 });";
        $lines[] = "</script>";
        $lines[] = "```";
        return implode("\n", $lines);
    }

    private static function build_methods_table_header(): string {
        $lines = [];
        $lines[] = "## Bridge methods";
        $lines[] = '';
        $lines[] = "Every method returns `Promise<T>`. Errors reject with `{ code, message, details? }`. Permission is gated by the app's manifest.";
        $lines[] = '';
        $lines[] = "| Method | Permission | Returns |";
        $lines[] = "|---|---|---|";
        return implode("\n", $lines);
    }

    /**
     * @return array<string,string>
     */
    private static function build_methods_by_perm(): array {
        return [
            'site_info' => "| `site.info()` | `site_info` | `{ title, description, url, language, timezone, ... }` |",
            'posts'     => implode("\n", [
                "| `posts.list(query?)` | `posts` | `{ items: Post[], total, total_pages }` |",
                "| `posts.get(id, opts?)` | `posts` | `Post` |",
            ]),
            'pages'     => implode("\n", [
                "| `pages.list(query?)` | `pages` | `{ items: Post[], total, total_pages }` |",
                "| `pages.get(id)` | `pages` | `Post` |",
            ]),
            'user'      => implode("\n", [
                "| `user.current()` | `user` | `{ id, name, slug, email, avatar_url, roles } \\| null` |",
                "| `user.can(cap)` | `user` | `boolean` |",
            ]),
            'abilities' => implode("\n", [
                "| `abilities.list()` | `abilities` | descriptors for abilities matching `abilities.consumes` |",
                "| `abilities.invoke(name, args)` | `abilities` | result of the named site ability |",
            ]),
            'ai'        => "| `ai.prompt(messages, opts?)` | `ai` | `{ text }` — uses the site's configured Connector |",
        ];
    }

    private static function build_methods_always(): string {
        return implode("\n", [
            "| `storage.app.get(key)` / `.set(key, value)` | none | per-app, shared across visitors |",
            "| `storage.user.get(key)` / `.set(key, value)` | none | per-logged-in-user; rejects `not_authenticated` for anon |",
            "| `bridge.ping()` | none | `{ ok: true, bridge_version: 1, server_time }` |",
            "| `bridge.requestResize(height)` | none | fire-and-forget; only honored in block embeds |",
        ]);
    }

    /**
     * @return array<string,string>
     */
    private static function build_shapes_by_perm(): array {
        $shapes = [];

        $shapes['posts'] = implode("\n", [
            "### `posts.list` query shape",
            "```ts",
            "{ type?: string;  // CPT slug, default 'post'",
            "  per_page?: number; // 1-100, default 10",
            "  page?: number; search?: string;",
            "  category?: number | string; tag?: number | string;",
            "  orderby?: 'date' | 'modified' | 'title' | 'id';",
            "  order?: 'asc' | 'desc';",
            "  status?: 'publish' | 'draft' | 'private' | 'pending' | 'future' | 'any' }",
            "```",
            '',
            "`Post` shape: `{ id, slug, title, excerpt, content, status, date, modified, author, link, featured_media_url, categories, tags }`. `content` is rendered HTML, empty when `protected: true` (password-protected).",
        ]);

        $shapes['ai'] = implode("\n", [
            "### `ai.prompt` shape",
            "```ts",
            "ai.prompt(messages: Array<{ role: 'user'|'assistant'|'system', content: string }>,",
            "          opts?: { model?: string, max_tokens?: number, temperature?: number })",
            "  : Promise<{ text: string }>",
            "```",
            "The site admin's configured Connector handles inference; the app never sees an API key. Rejects with `ai_not_configured` if the site hasn't wired up a Connector.",
        ]);

        return $shapes;
    }

    private static function build_permissions_section(): string {
        $lines = [];
        $lines[] = "## Permissions (declare what the app needs)";
        $lines[] = '';
        $lines[] = "Available read permissions: `site_info`, `posts`, `pages`, `user`, `abilities`, `ai`. Pick only what the app actually uses — manifest validation rejects unused-but-declared permissions on update.";
        $lines[] = "Available write permissions (v1): none — all mutating bridge methods require a granular permission name that's not yet exposed.";
        $lines[] = "Storage methods (`storage.app.*`, `storage.user.*`) require no permission.";
        return implode("\n", $lines);
    }

    private static function build_error_codes(): string {
        $lines = [];
        $lines[] = "## Error codes the artifact must handle";
        $lines[] = "- `permission_denied` — manifest is missing a needed permission. Surface in UI, don't retry.";
        $lines[] = "- `not_authenticated` — user is anonymous; offer a login link if needed.";
        $lines[] = "- `ai_not_configured` — `ai.prompt` called but site has no Connector. Direct admin to Settings → Connectors.";
        $lines[] = "- `not_implemented` — method unavailable on this site (e.g. `ai.prompt` on WP < 7.0). Degrade gracefully.";
        $lines[] = "- `rate_limited` — back off and retry.";
        $lines[] = "- `payload_too_large` — request > 64KB or response > 1MB. Reduce.";
        $lines[] = "- `internal_error` — server-side; surface a friendly fallback.";
        return implode("\n", $lines);
    }

    private static function build_manifest_intro(): string {
        return "## Manifest\n\nThe importer generates this for single-file uploads — but if you want to be explicit:";
    }

    private static function build_manifest_outro(): string {
        $lines = [];
        $lines[] = "For single-HTML artifacts, set `isolation: \"iframe\"` (sandboxed, no SEO). For multi-page or SEO-needed apps, use a build pipeline (Astro/Vite) and `isolation: \"inline\"` instead; not viable from a single artifact.";
        $lines[] = '';
        $lines[] = "## Design";
        $lines[] = '';
        $lines[] = "- Mobile-first, accessible (semantic HTML, keyboard-navigable, real `<button>` and `<form>`).";
        $lines[] = "- The host has no theme — your `<style>` owns the entire visual.";
        $lines[] = "- Match the screen's purpose: lightweight, fast, no animations longer than 200ms.";
        $lines[] = "- Show a loading state during the initial `await ready` + first bridge call.";
        $lines[] = "- Show a clear error state when a bridge call rejects.";
        $lines[] = '';
        $lines[] = "## Workflow";
        $lines[] = "1. Decide what the app does and which bridge methods + permissions it needs.";
        $lines[] = "2. Write a single `index.html` with inline CSS + JS that uses the `call(method, params)` helper above.";
        $lines[] = "3. Test it lives in an iframe — `window.parent` is your only way back to WordPress.";
        $lines[] = "4. Hand the user the `.html` file. They upload it via *DSGo Apps → Install another app → Upload artifact*.";
        $lines[] = '';
        $lines[] = "Now ask me what app I want to build, then produce the artifact.";
        return implode("\n", $lines);
    }

    /* ─── Site-introspection helpers ─────────────────────────────────── */

    /**
     * @return array<string,string>
     */
    private static function collect_published_abilities(): array {
        if (!function_exists('wp_get_abilities')) {
            return [];
        }
        $out = [];
        foreach (wp_get_abilities() as $ability) {
            if (!is_object($ability)) continue;
            $name = method_exists($ability, 'get_name') ? (string) $ability->get_name() : '';
            if ($name === '') continue;
            if (str_starts_with($name, 'dsgo-apps/')) continue;

            $desc = '';
            if (method_exists($ability, 'get_description')) {
                $desc = (string) $ability->get_description();
            }
            if ($desc === '' && method_exists($ability, 'get_label')) {
                $desc = (string) $ability->get_label();
            }
            $out[$name] = $desc !== '' ? $desc : '(no description)';
        }
        return $out;
    }

    private static function ai_connector_summary(): string {
        if (!function_exists('wp_ai_client_get_active_provider')) {
            return 'not available on this WordPress version (requires WP 7.0+)';
        }
        try {
            $provider = wp_ai_client_get_active_provider();
        } catch (\Throwable $e) {
            return 'not configured (Settings → Connectors)';
        }
        if (!$provider) {
            return 'not configured (admin can wire one up at Settings → Connectors)';
        }
        return 'configured (calls to `dsgo.ai.prompt` will route through the site\'s active connector)';
    }
}
