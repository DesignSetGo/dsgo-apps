<?php
/**
 * Manifest value object, enums, and validator.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

// Exception messages constructed below are never echoed to clients; manifest
// validation errors are caught by the REST layer and translated into safe
// error responses, and at install time admins see a curated message.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

enum DisplayMode: string {
    case Page  = 'page';
    case Block = 'block';
    case Admin = 'admin';
}

enum Permission: string {
    case SiteInfo  = 'site_info';
    case Posts     = 'posts';
    case Pages     = 'pages';
    case User      = 'user';
    case Ai        = 'ai';
    case Abilities = 'abilities';
    case Email     = 'email';
    case Commerce  = 'commerce';
}

enum EmailRecipient: string {
    case Admin       = 'admin';
    case CurrentUser = 'current_user';
}

enum PostStatus: string {
    case Publish  = 'publish';
    case Draft    = 'draft';
    case Private_ = 'private';
    case Pending  = 'pending';
    case Future   = 'future';
    case Any      = 'any';
}

final class ManifestError extends \RuntimeException {
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct(sprintf('%s: %s', $field, $message));
    }
}

enum MountMode: string {
    case Prefixed = 'prefixed';
    case Root     = 'root';
}

final readonly class Manifest {
    /**
     * @param DisplayMode[] $display_modes
     * @param Permission[] $permissions_read
     * @param string[] $external_origins
     * @param array<int, array{path:string, file:string, title?:string|null, description?:string|null, dataset?:array{source:string,id_field:string}|null}> $routes
     * @param array{script_src:string[], style_src:string[], img_src:string[], connect_src:string[]}|null $csp
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public string $version,
        public ?string $author,
        public string $entry,
        public array $display_modes,
        public DisplayMode $display_default,
        public ?string $display_icon,
        public array $permissions_read,
        public array $external_origins,
        public string $isolation = 'inline',
        public array $routes = [],
        public string $theme_wrap = 'none',
        public string $theme_container = 'none',
        public ?array $csp = null,
        public MountMode $mount_mode = MountMode::Prefixed,
        /** @var string[] */
        public array $embeds = [],
        /** @var string[] */
        public array $abilities_consumes = [],
        public int $ai_max_tool_calls = 5,
        public int $ai_timeout_seconds = 60,
        /** @var array<int, array{name:string,label:string,description:string,category:string,input_schema?:array,output_schema?:array,annotations:array<string,bool>,timeout_seconds:int}> */
        public array $abilities_publishes = [],
        /** @var EmailRecipient[] */
        public array $email_recipients = [],
        /**
         * Whether the app may call `dsgo.media.upload()`. Core, opt-out: every
         * app gets media uploads unless the manifest declares `media.uploads:
         * false`. Capability gating piggybacks on WP's `upload_files`, so the
         * default is safe — only Authors+ can actually upload at runtime.
         */
        public bool $media_uploads_enabled = true,
        /** @var string[] commerce providers e.g. ["woocommerce"] */
        public array $commerce_providers = [],
        /** @var string[] commerce endpoints e.g. ["products","cart","checkout"] */
        public array $commerce_endpoints = [],
        /**
         * Block stylesheet sources to ship alongside post/page content. Empty
         * means "off" — the default; nothing extra is sent. Recognized values:
         *   - "core"        WP core block library + theme stylesheets
         *   - "designsetgo" partner DesignSetGo Blocks plugin styles (when active)
         *   - "auto"        any registered block actually used in the content
         * @var string[]
         */
        public array $content_block_styles = [],
        /**
         * Theme global styles (theme.json compiled CSS). v1 supports:
         *   - "off"    nothing shipped
         *   - "global" wp_get_global_stylesheet() output
         */
        public string $content_theme_styles = 'off',
        /**
         * Allowlist filter applied to the union of "auto" + "designsetgo"
         * block-name lookups before resolving handles. Glob patterns:
         * "namespace/*" or "namespace/name". Empty = no allowlist filtering.
         * @var string[]
         */
        public array $content_block_styles_allowlist = [],
        /**
         * Denylist filter applied last; matched block names are dropped even
         * if allowlisted. Same glob shape as the allowlist.
         * @var string[]
         */
        public array $content_block_styles_denylist = [],
    ) {}

    public static function validate(array $raw): self {
        self::assert_int($raw, 'manifest_version');
        if ($raw['manifest_version'] !== 1) {
            throw new ManifestError('manifest_version', 'must be 1');
        }
        self::assert_string($raw, 'id');
        if (!preg_match('/^[a-z][a-z0-9-]{2,63}$/', $raw['id'])) {
            throw new ManifestError('id', 'must match ^[a-z][a-z0-9-]{2,63}$ (3–64 chars, lowercase, start with a letter)');
        }
        $reserved = ['admin','api','app','apps','dsg','dsgo','designsetgo','wp-admin','wp-json','wp-login','login','logout','edit','new','settings','manifest'];
        if (in_array($raw['id'], $reserved, true)) {
            throw new ManifestError('id', sprintf('"%s" is reserved and cannot be used', $raw['id']));
        }
        self::assert_string($raw, 'name');
        if (trim($raw['name']) === '') {
            throw new ManifestError('name', 'must contain non-whitespace characters');
        }
        if (mb_strlen($raw['name']) > 80) {
            throw new ManifestError('name', 'must be at most 80 characters');
        }
        self::assert_string($raw, 'version');
        $semver_re = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)' .
                     '(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?' .
                     '(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';
        if (!preg_match($semver_re, $raw['version'])) {
            throw new ManifestError('version', 'must be SemVer 2.0.0 (MAJOR.MINOR.PATCH[-prerelease][+build])');
        }
        self::assert_string($raw, 'entry');
        if (!preg_match('/^(?!\/)[^.\/].*\.(html|htm)$/', $raw['entry']) || str_contains($raw['entry'], '..') || str_starts_with($raw['entry'], '/')) {
            throw new ManifestError('entry', 'must be a relative path ending in .html or .htm without ".."');
        }
        self::assert_array($raw, 'display');
        self::assert_array($raw, 'permissions');
        self::assert_array($raw, 'runtime');

        self::assert_array($raw['display'], 'modes', 'display.modes');
        self::assert_string($raw['display'], 'default', 'display.default');
        self::assert_array($raw['permissions'], 'read',  'permissions.read');
        self::assert_array($raw['permissions'], 'write', 'permissions.write');
        if ($raw['permissions']['write'] !== []) {
            throw new ManifestError('permissions.write', 'must be empty in v1');
        }
        self::assert_string($raw['runtime'], 'sandbox', 'runtime.sandbox');
        if ($raw['runtime']['sandbox'] !== 'strict') {
            throw new ManifestError('runtime.sandbox', 'must be "strict" in v1');
        }

        $isolation = $raw['isolation'] ?? 'inline';
        if (!is_string($isolation) || !in_array($isolation, ['inline', 'iframe'], true)) {
            throw new ManifestError('isolation', 'must be "inline" or "iframe"');
        }

        $mount_mode = MountMode::Prefixed;
        if (array_key_exists('mount', $raw)) {
            if (!is_array($raw['mount'])) {
                throw new ManifestError('mount', 'must be an object');
            }
            $mode_raw = $raw['mount']['mode'] ?? 'prefixed';
            if (!is_string($mode_raw) || ($parsed = MountMode::tryFrom($mode_raw)) === null) {
                throw new ManifestError('mount.mode', 'must be "prefixed" or "root"');
            }
            $mount_mode = $parsed;
        }

        $routes = [];
        if ($isolation === 'inline') {
            if (!isset($raw['routes']) || !is_array($raw['routes']) || $raw['routes'] === []) {
                throw new ManifestError('routes', 'is required and must be a non-empty array when isolation is "inline"');
            }
            $seen_paths = [];
            foreach ($raw['routes'] as $i => $r) {
                if (!is_array($r)) {
                    throw new ManifestError("routes[$i]", 'must be an object');
                }
                if (!isset($r['path']) || !is_string($r['path'])) {
                    throw new ManifestError("routes[$i].path", 'is required and must be a string');
                }
                if (!isset($r['file']) || !is_string($r['file'])) {
                    throw new ManifestError("routes[$i].file", 'is required and must be a string');
                }
                $path = $r['path'];
                if (!str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '//')) {
                    throw new ManifestError("routes[$i].path", 'must start with "/" and contain no ".." or "//"');
                }
                // :param validation: at most one placeholder, must be a whole
                // path segment, name matches ^[a-z][a-z0-9_]*$.
                $param_count = substr_count($path, ':');
                if ($param_count > 1) {
                    throw new ManifestError("routes[$i].path", 'route_path_multiple_params: only one ":param" placeholder is allowed');
                }
                $has_param = $param_count === 1;
                if ($has_param) {
                    if (!preg_match('#/:([a-z][a-z0-9_]*)(?:/|$)#', $path)) {
                        throw new ManifestError("routes[$i].path", '":param" must be a complete path segment between "/" boundaries with name matching ^[a-z][a-z0-9_]*$');
                    }
                }
                if (in_array($path, $seen_paths, true)) {
                    throw new ManifestError("routes", "duplicate path: $path");
                }
                $seen_paths[] = $path;
                if ($path === '/__dsgo-host' || $path === '/__dsgo-host/') {
                    throw new ManifestError("routes[$i].path", 'route_path_reserved: "/__dsgo-host" is reserved for the abilities publisher host');
                }
                $file = $r['file'];
                if (str_contains($file, '..') || str_starts_with($file, '/') || !preg_match('/\.(html|htm)$/', $file)) {
                    throw new ManifestError("routes[$i].file", 'must be a relative .html/.htm path without ".."');
                }
                if (isset($r['title']) && (!is_string($r['title']) || $r['title'] === '' || mb_strlen($r['title']) > 80)) {
                    throw new ManifestError("routes[$i].title", 'must be a string of 1-80 chars');
                }
                if (isset($r['description']) && (!is_string($r['description']) || $r['description'] === '' || mb_strlen($r['description']) > 500)) {
                    throw new ManifestError("routes[$i].description", 'must be a string of 1-500 chars');
                }
                $dataset = null;
                if (array_key_exists('dataset', $r)) {
                    if (!$has_param) {
                        throw new ManifestError("routes[$i].dataset", 'is only allowed on routes with a ":param" placeholder');
                    }
                    if (!is_array($r['dataset'])) {
                        throw new ManifestError("routes[$i].dataset", 'must be an object');
                    }
                    $ds_source = $r['dataset']['source'] ?? null;
                    if (!is_string($ds_source) || $ds_source === '') {
                        throw new ManifestError("routes[$i].dataset.source", 'is required and must be a non-empty string');
                    }
                    // Live data sources are resolved at request time from
                    // the host site (or a third-party plugin via the
                    // `dsgo_apps_dataset_resolver` filter) and don't ship
                    // inside the bundle. Built-ins:
                    //   - `wp:posts`              → built-in `post` post type
                    //   - `wp:pages`              → built-in `page` post type
                    //   - `wp:cpt:<post_type>`    → any registered CPT
                    //   - `wc:products`           → WooCommerce products
                    // Custom schemes (e.g. `edd:downloads`, `gf:forms`) are
                    // accepted at install time; the resolver filter decides
                    // at request time whether to serve rows. Sources without
                    // a `<scheme>:` prefix must be a relative .json file in
                    // the bundle.
                    $is_built_in_live = preg_match('/^(wp:(posts|pages|cpt:[a-z][a-z0-9_-]*)|wc:products)$/', $ds_source) === 1;
                    // Custom-scheme syntax: lower-case scheme, colon, then a
                    // non-path identifier (no leading slash, no `..`).
                    $is_custom_live = !$is_built_in_live
                        && preg_match('/^[a-z][a-z0-9_-]*:[a-z0-9][a-zA-Z0-9_:.\/-]*$/', $ds_source) === 1
                        && !str_contains($ds_source, '..');
                    $is_live = $is_built_in_live || $is_custom_live;
                    if (!$is_live) {
                        if (str_starts_with($ds_source, '/') || str_contains($ds_source, '..') || !str_ends_with($ds_source, '.json')) {
                            throw new ManifestError(
                                "routes[$i].dataset.source",
                                'must be a relative .json path in the bundle, a built-in live source ("wp:posts", "wp:pages", "wp:cpt:<slug>", "wc:products"), or a custom "<scheme>:<id>" source backed by the dsgo_apps_dataset_resolver filter',
                            );
                        }
                    }
                    $ds_id = $r['dataset']['id_field'] ?? null;
                    if (!is_string($ds_id) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $ds_id)) {
                        throw new ManifestError("routes[$i].dataset.id_field", 'must match ^[a-zA-Z_][a-zA-Z0-9_]*$ (no dot-notation; top-level field only)');
                    }
                    // Built-in live sources expose only `slug` / `id` as
                    // unique-and-public lookup keys; restrict to those to
                    // catch typos. Custom resolvers control their own row
                    // shape, so any valid identifier is allowed there.
                    if ($is_built_in_live && !in_array($ds_id, ['slug', 'id'], true)) {
                        throw new ManifestError(
                            "routes[$i].dataset.id_field",
                            'built-in live sources only support id_field "slug" or "id"',
                        );
                    }
                    $dataset = ['source' => $ds_source, 'id_field' => $ds_id];
                } elseif ($has_param) {
                    throw new ManifestError("routes[$i].dataset", 'is required on routes with a ":param" placeholder');
                }
                $routes[] = [
                    'path' => $path,
                    'file' => $file,
                    'title' => $r['title'] ?? null,
                    'description' => $r['description'] ?? null,
                    'dataset' => $dataset,
                ];
            }
            if ($routes[0]['path'] !== '/') {
                throw new ManifestError('routes[0].path', 'first route must be "/"');
            }
        }

        $theme_wrap = 'none';
        $theme_container = 'none';
        if (isset($raw['theme'])) {
            if ($isolation !== 'inline') {
                throw new ManifestError('theme', 'is only valid when isolation is "inline"');
            }
            if (!is_array($raw['theme'])) {
                throw new ManifestError('theme', 'must be an object');
            }
            if (isset($raw['theme']['wrap'])) {
                if (!in_array($raw['theme']['wrap'], ['none', 'header_footer', 'full'], true)) {
                    throw new ManifestError('theme.wrap', 'must be "none", "header_footer", or "full"');
                }
                if ($raw['theme']['wrap'] === 'full') {
                    throw new ManifestError('theme.wrap', '"full" is reserved for v2 and rejected in v1');
                }
                $theme_wrap = $raw['theme']['wrap'];
            }
            if (isset($raw['theme']['container'])) {
                if (!in_array($raw['theme']['container'], ['none', 'shadow_dom', 'scoped'], true)) {
                    throw new ManifestError('theme.container', 'must be "none", "shadow_dom", or "scoped"');
                }
                if ($raw['theme']['container'] === 'scoped') {
                    throw new ManifestError('theme.container', '"scoped" is reserved for v2 and rejected in v1');
                }
                $theme_container = $raw['theme']['container'];
            }
        }

        $csp = null;
        if ($isolation === 'inline') {
            if (!isset($raw['runtime']['csp']) || !is_array($raw['runtime']['csp'])) {
                throw new ManifestError('runtime.csp', 'is required when isolation is "inline"');
            }
            if (isset($raw['runtime']['external_origins']) && $raw['runtime']['external_origins'] !== []) {
                throw new ManifestError('runtime.external_origins', 'is mutually exclusive with runtime.csp; use csp.connect_src for inline apps');
            }
            $csp_raw = $raw['runtime']['csp'];
            // Required keys must be present + non-empty + include 'self'.
            foreach (['script_src', 'style_src', 'img_src', 'connect_src'] as $key) {
                if (!isset($csp_raw[$key]) || !is_array($csp_raw[$key]) || $csp_raw[$key] === []) {
                    throw new ManifestError("runtime.csp.$key", 'is required and must be a non-empty array');
                }
                self::validate_csp_sources($csp_raw[$key], $key);
                if (!in_array('self', $csp_raw[$key], true)) {
                    throw new ManifestError("runtime.csp.$key", 'must include "self"');
                }
            }
            // Optional keys (validated when present, but not required).
            // font_src is the common ask — frameworks pull from Google Fonts /
            // Adobe Fonts / a CDN. We keep validation strict (https-only) but
            // don't force authors to declare it.
            foreach (['font_src'] as $opt_key) {
                if (isset($csp_raw[$opt_key])) {
                    if (!is_array($csp_raw[$opt_key])) {
                        throw new ManifestError("runtime.csp.$opt_key", 'must be an array');
                    }
                    self::validate_csp_sources($csp_raw[$opt_key], $opt_key);
                }
            }
            $csp = [
                'script_src'  => $csp_raw['script_src'],
                'style_src'   => $csp_raw['style_src'],
                'img_src'     => $csp_raw['img_src'],
                'connect_src' => $csp_raw['connect_src'],
            ];
            if (isset($csp_raw['font_src']) && is_array($csp_raw['font_src'])) {
                $csp['font_src'] = $csp_raw['font_src'];
            }
        } else {
            if (isset($raw['runtime']['csp'])) {
                throw new ManifestError('runtime.csp', 'is only valid when isolation is "inline"; iframe apps use runtime.external_origins');
            }
        }

        $modes      = [];
        $seen_modes = [];
        foreach ($raw['display']['modes'] as $i => $v) {
            if (!is_string($v) || !in_array($v, ['page','block','admin'], true)) {
                throw new ManifestError(
                    sprintf('display.modes[%d]', $i),
                    sprintf('"%s" is not one of [page, block, admin]', is_scalar($v) ? (string) $v : gettype($v))
                );
            }
            if (isset($seen_modes[$v])) {
                throw new ManifestError(
                    sprintf('display.modes[%d]', $i),
                    sprintf('"%s" is duplicated in display.modes', $v)
                );
            }
            $seen_modes[$v] = true;
            $modes[]        = DisplayMode::from($v);
        }
        if ($modes === []) {
            throw new ManifestError('display.modes', 'must declare at least one mode');
        }

        // Inline-mode apps render through the inline renderer per request,
        // which injects the same-window bridge bootstrap. Block embeds load
        // the bundle as a static iframe via `Bundle::url_for($app_id)` — a
        // path that bypasses the inline renderer, so the bundle has no
        // bridge to talk to. BRIDGE-API.md is explicit: in v1 inline-mode
        // apps only support `page` rendering. Catch this at install time so
        // the author gets a clear error instead of a silently broken embed
        // at runtime.
        if ($isolation === 'inline') {
            $non_page = array_values(array_filter(
                $modes,
                static fn (DisplayMode $m) => $m !== DisplayMode::Page,
            ));
            if ($non_page !== []) {
                throw new ManifestError(
                    'display.modes',
                    sprintf(
                        '"%s" requires isolation == "iframe"; inline-mode apps only support "page" rendering in v1',
                        $non_page[0]->value,
                    ),
                );
            }
        }

        // Root-mounted apps render at the site root URL, which is page-mode
        // territory. An iframe app declaring only `block` or `admin` could
        // never appear at `/`, so reject the combination at install rather
        // than serving a 404 at runtime. Inline apps are already constrained
        // above to page-only, so this only adds a rule for iframe.
        if ($mount_mode === MountMode::Root) {
            $has_page = false;
            foreach ($modes as $m) {
                if ($m === DisplayMode::Page) { $has_page = true; break; }
            }
            if (!$has_page) {
                throw new ManifestError(
                    'mount.mode',
                    '"root" requires display.modes to include "page"',
                );
            }
        }

        $default = $raw['display']['default'];
        if (!in_array($default, array_map(static fn (DisplayMode $m) => $m->value, $modes), true)) {
            throw new ManifestError('display.default', sprintf('"%s" must be one of display.modes', $default));
        }
        $default_mode = DisplayMode::from($default);

        $perms = [];
        foreach ($raw['permissions']['read'] as $i => $v) {
            if (!is_string($v) || Permission::tryFrom($v) === null) {
                throw new ManifestError(
                    sprintf('permissions.read[%d]', $i),
                    sprintf('"%s" is not a valid permission', is_scalar($v) ? (string) $v : gettype($v))
                );
            }
            $perms[] = Permission::from($v);
        }

        // AI options — only valid when "ai" permission is present.
        $ai_max_tool_calls = 5;
        $ai_timeout_seconds = 60;
        $has_ai_perm = in_array(Permission::Ai, $perms, true);
        if (array_key_exists('ai', $raw)) {
            if (!$has_ai_perm) {
                throw new ManifestError('ai', 'ai_options_misplaced: "ai" options require "ai" in permissions.read');
            }
            if (!is_array($raw['ai'])) {
                throw new ManifestError('ai', 'must be an object');
            }
            if (array_key_exists('max_tool_calls', $raw['ai'])) {
                $val = $raw['ai']['max_tool_calls'];
                if (!is_int($val) || $val < 0 || $val > 10) {
                    throw new ManifestError('ai.max_tool_calls', 'must be an integer between 0 and 10');
                }
                $ai_max_tool_calls = $val;
            }
            if (array_key_exists('timeout_seconds', $raw['ai'])) {
                $val = $raw['ai']['timeout_seconds'];
                if (!is_int($val) || $val < 5 || $val > 120) {
                    throw new ManifestError('ai.timeout_seconds', 'must be an integer between 5 and 120');
                }
                $ai_timeout_seconds = $val;
            }
        }

        // Email block — required when "email" permission is present, forbidden otherwise.
        $email_recipients = [];
        $has_email_perm = in_array(Permission::Email, $perms, true);
        if (array_key_exists('email', $raw)) {
            if (!$has_email_perm) {
                throw new ManifestError('email', 'email_options_misplaced: "email" options require "email" in permissions.read');
            }
            if (!is_array($raw['email'])) {
                throw new ManifestError('email', 'must be an object');
            }
            if (!array_key_exists('recipients', $raw['email'])) {
                throw new ManifestError('email.recipients', 'email_recipients_required: must be present when "email" is in permissions.read');
            }
            if (!is_array($raw['email']['recipients']) || $raw['email']['recipients'] === []) {
                throw new ManifestError('email.recipients', 'must be a non-empty array of recipient types');
            }
            $seen_recipient = [];
            foreach ($raw['email']['recipients'] as $i => $r) {
                if (!is_string($r) || EmailRecipient::tryFrom($r) === null) {
                    throw new ManifestError(
                        sprintf('email.recipients[%d]', $i),
                        sprintf('"%s" must be one of "admin", "current_user"', is_scalar($r) ? (string) $r : gettype($r))
                    );
                }
                if (isset($seen_recipient[$r])) {
                    throw new ManifestError(sprintf('email.recipients[%d]', $i), sprintf('duplicate value "%s"', $r));
                }
                $seen_recipient[$r] = true;
                $email_recipients[] = EmailRecipient::from($r);
            }
        } elseif ($has_email_perm) {
            throw new ManifestError('email.recipients', 'email_recipients_required: must be present when "email" is in permissions.read');
        }

        // Abilities consume list — required when "abilities" permission is present.
        $abilities_consumes = [];
        $abilities_publishes = [];
        $has_abilities_perm = in_array(Permission::Abilities, $perms, true);
        if (array_key_exists('abilities', $raw)) {
            if (!is_array($raw['abilities'])) {
                throw new ManifestError('abilities', 'must be an object');
            }
            // consumes requires the "abilities" permission; publishes does not.
            if (array_key_exists('consumes', $raw['abilities']) && !$has_abilities_perm) {
                throw new ManifestError('abilities', 'abilities_consumes_misplaced: "abilities" options require "abilities" in permissions.read');
            }
            // If "abilities" permission is declared, consumes must also be declared.
            if ($has_abilities_perm && !array_key_exists('consumes', $raw['abilities'])) {
                throw new ManifestError('abilities.consumes', 'abilities_consumes_required: must be present when "abilities" is in permissions.read');
            }
            if (array_key_exists('consumes', $raw['abilities'])) {
                if (!is_array($raw['abilities']['consumes'])) {
                    throw new ManifestError('abilities.consumes', 'is required and must be an array');
                }
                $patterns = $raw['abilities']['consumes'];
                if (count($patterns) > 32) {
                    throw new ManifestError('abilities.consumes', sprintf('abilities_consumes_too_many: %d patterns (max 32)', count($patterns)));
                }
                $seen = [];
                foreach ($patterns as $i => $pattern) {
                    if (!is_string($pattern)) {
                        throw new ManifestError("abilities.consumes[$i]", 'must be a string');
                    }
                    if (!str_contains($pattern, '/')) {
                        throw new ManifestError("abilities.consumes[$i]", 'abilities_pattern_no_slash: pattern must contain "/" (namespace/name)');
                    }
                    // Match WP's ability-name charset (registry: ^[a-z0-9-]+\/[a-z0-9-]+$)
                    // plus our trailing-* wildcard extension.
                    if (!preg_match('#^[a-z0-9-]+/(?:[a-z0-9-]+|\*|[a-z0-9-]+\*)$#', $pattern)) {
                        if (str_starts_with($pattern, '*/') || $pattern === '*') {
                            throw new ManifestError("abilities.consumes[$i]", 'abilities_pattern_no_namespace: namespace cannot be a wildcard');
                        }
                        throw new ManifestError("abilities.consumes[$i]", sprintf('abilities_pattern_invalid: "%s" must match WP\'s ability-name charset ([a-z0-9-]) with optional trailing-* wildcard', $pattern));
                    }
                    if (isset($seen[$pattern])) {
                        throw new ManifestError("abilities.consumes[$i]", sprintf('abilities_consumes_duplicate: "%s"', $pattern));
                    }
                    $seen[$pattern] = true;
                }
                $abilities_consumes = $patterns;
            }

            // ----- publishes validation -----
            if (array_key_exists('publishes', $raw['abilities'])) {
                if (!is_array($raw['abilities']['publishes'])) {
                    throw new ManifestError('abilities.publishes', 'must be an array');
                }
                if (count($raw['abilities']['publishes']) > 8) {
                    throw new ManifestError(
                        'abilities.publishes',
                        sprintf('abilities_publishes_too_many: %d entries (max 8)', count($raw['abilities']['publishes'])),
                    );
                }
                $seen_pub = [];
                foreach ($raw['abilities']['publishes'] as $i => $entry) {
                    $abilities_publishes[] = self::validate_published_ability($entry, $i, $raw['id'], $seen_pub);
                }
            }
        } elseif ($has_abilities_perm) {
            throw new ManifestError('abilities.consumes', 'abilities_consumes_required: must be present when "abilities" is in permissions.read');
        }

        // Media block — every key is optional, used only to opt out of the
        // core media-upload feature. No corresponding permission entry: the
        // bridge defaults the feature ON for every app and gates uploads on
        // the WP `upload_files` capability of the rendering visitor.
        $media_uploads_enabled = true;
        if (array_key_exists('media', $raw)) {
            if (!is_array($raw['media'])) {
                throw new ManifestError('media', 'must be an object');
            }
            if (array_key_exists('uploads', $raw['media'])) {
                $val = $raw['media']['uploads'];
                if (!is_bool($val)) {
                    throw new ManifestError('media.uploads', 'must be a boolean');
                }
                $media_uploads_enabled = $val;
            }
        }

        // Commerce — required when "commerce" permission is present.
        // Mirrors abilities.consumes shape: typed allow-list, providers + endpoints.
        $commerce_providers = [];
        $commerce_endpoints = [];
        $has_commerce_perm = in_array(Permission::Commerce, $perms, true);
        if (array_key_exists('commerce', $raw)) {
            if (!$has_commerce_perm) {
                throw new ManifestError('commerce', 'commerce_options_misplaced: "commerce" options require "commerce" in permissions.read');
            }
            if (!is_array($raw['commerce'])) {
                throw new ManifestError('commerce', 'must be an object');
            }
            if (!array_key_exists('providers', $raw['commerce'])) {
                throw new ManifestError('commerce.providers', 'commerce_providers_required: must be present when "commerce" is in permissions.read');
            }
            if (!is_array($raw['commerce']['providers']) || $raw['commerce']['providers'] === []) {
                throw new ManifestError('commerce.providers', 'must be a non-empty array');
            }
            $allowed_providers = ['woocommerce'];
            $seen_p = [];
            foreach ($raw['commerce']['providers'] as $i => $p) {
                if (!is_string($p) || !in_array($p, $allowed_providers, true)) {
                    throw new ManifestError("commerce.providers[$i]", sprintf('"%s" is not a supported provider (v1: woocommerce)', is_scalar($p) ? (string) $p : gettype($p)));
                }
                if (isset($seen_p[$p])) {
                    throw new ManifestError("commerce.providers[$i]", sprintf('commerce_providers_duplicate: "%s"', $p));
                }
                $seen_p[$p] = true;
                $commerce_providers[] = $p;
            }
            if (!array_key_exists('endpoints', $raw['commerce'])) {
                throw new ManifestError('commerce.endpoints', 'commerce_endpoints_required: must be present when "commerce" is in permissions.read');
            }
            if (!is_array($raw['commerce']['endpoints']) || $raw['commerce']['endpoints'] === []) {
                throw new ManifestError('commerce.endpoints', 'must be a non-empty array');
            }
            $allowed_endpoints = ['products', 'cart', 'checkout'];
            $seen_e = [];
            foreach ($raw['commerce']['endpoints'] as $i => $e) {
                if (!is_string($e) || !in_array($e, $allowed_endpoints, true)) {
                    throw new ManifestError("commerce.endpoints[$i]", sprintf('"%s" must be one of products, cart, checkout', is_scalar($e) ? (string) $e : gettype($e)));
                }
                if (isset($seen_e[$e])) {
                    throw new ManifestError("commerce.endpoints[$i]", sprintf('commerce_endpoints_duplicate: "%s"', $e));
                }
                $seen_e[$e] = true;
                $commerce_endpoints[] = $e;
            }
        } elseif ($has_commerce_perm) {
            throw new ManifestError('commerce', 'commerce_options_required: must be present when "commerce" is in permissions.read');
        }

        // Content block — opt-in shipping of block/theme stylesheets alongside
        // posts.get / pages.get / dataset rows so apps can render WP block
        // markup with the styles WP would normally enqueue on the front end.
        // Every key is optional; omitting the block keeps the default-off
        // behavior (only `content.rendered` HTML is shipped, no styles).
        $content_block_styles = [];
        $content_theme_styles = 'off';
        $content_block_styles_allowlist = [];
        $content_block_styles_denylist = [];
        if (array_key_exists('content', $raw)) {
            if (!is_array($raw['content'])) {
                throw new ManifestError('content', 'must be an object');
            }
            if (array_key_exists('blockStyles', $raw['content'])) {
                $bs = $raw['content']['blockStyles'];
                // Accept the "auto" string shorthand for ["core","designsetgo","auto"].
                if (is_string($bs)) {
                    if ($bs === 'off') {
                        $content_block_styles = [];
                    } elseif ($bs === 'auto') {
                        $content_block_styles = ['core', 'designsetgo', 'auto'];
                    } else {
                        throw new ManifestError('content.blockStyles', 'string form must be "off" or "auto"; use an array for explicit sources');
                    }
                } elseif (is_array($bs)) {
                    $allowed_sources = ['core', 'designsetgo', 'auto'];
                    $seen_sources = [];
                    foreach ($bs as $i => $src) {
                        if (!is_string($src) || !in_array($src, $allowed_sources, true)) {
                            throw new ManifestError(
                                "content.blockStyles[$i]",
                                sprintf('"%s" must be one of "core", "designsetgo", "auto"', is_scalar($src) ? (string) $src : gettype($src)),
                            );
                        }
                        if (isset($seen_sources[$src])) {
                            throw new ManifestError("content.blockStyles[$i]", sprintf('duplicate value "%s"', $src));
                        }
                        $seen_sources[$src] = true;
                        $content_block_styles[] = $src;
                    }
                } else {
                    throw new ManifestError('content.blockStyles', 'must be a string ("off"|"auto") or array of sources');
                }
            }
            if (array_key_exists('themeStyles', $raw['content'])) {
                $ts = $raw['content']['themeStyles'];
                if (!is_string($ts) || !in_array($ts, ['off', 'global'], true)) {
                    throw new ManifestError('content.themeStyles', 'must be "off" or "global" in v1');
                }
                $content_theme_styles = $ts;
            }
            foreach (['blockStyleAllowlist' => &$content_block_styles_allowlist,
                     'blockStyleDenylist'  => &$content_block_styles_denylist] as $key => &$target) {
                if (!array_key_exists($key, $raw['content'])) continue;
                $list = $raw['content'][$key];
                if (!is_array($list)) {
                    throw new ManifestError("content.$key", 'must be an array');
                }
                foreach ($list as $i => $pat) {
                    if (!is_string($pat) || !preg_match('#^[a-z0-9-]+/(?:[a-z0-9-]+|\*|[a-z0-9-]+\*)$#', $pat)) {
                        throw new ManifestError(
                            "content.{$key}[$i]",
                            sprintf('"%s" must match "<namespace>/<name>" with optional trailing-* wildcard', is_scalar($pat) ? (string) $pat : gettype($pat)),
                        );
                    }
                    $target[] = $pat;
                }
            }
            unset($target);
        }

        $external = [];
        if (array_key_exists('external_origins', $raw['runtime'])) {
            if (!is_array($raw['runtime']['external_origins'])) {
                throw new ManifestError('runtime.external_origins', 'must be an array');
            }
            foreach ($raw['runtime']['external_origins'] as $i => $origin) {
                if (!is_string($origin) || !self::is_valid_https_origin($origin)) {
                    throw new ManifestError(
                        sprintf('runtime.external_origins[%d]', $i),
                        sprintf('"%s" must be a full https:// origin without path or wildcards', is_scalar($origin) ? (string) $origin : gettype($origin))
                    );
                }
            }
            $external = $raw['runtime']['external_origins'];
        }

        // Embeds: explicit allowlist of origins the app may load via <iframe>.
        // Authors declare which third parties they want to embed (YouTube,
        // Stripe Checkout, Calendly, etc.); the sanitizer accepts <iframe>
        // for those origins (and only with `sandbox` set), and the inline-mode
        // CSP populates `frame-src` from the same list.
        $embeds = [];
        if (array_key_exists('embeds', $raw['runtime'])) {
            if (!is_array($raw['runtime']['embeds'])) {
                throw new ManifestError('runtime.embeds', 'must be an array');
            }
            foreach ($raw['runtime']['embeds'] as $i => $origin) {
                if (!is_string($origin) || !self::is_valid_https_origin($origin)) {
                    throw new ManifestError(
                        sprintf('runtime.embeds[%d]', $i),
                        sprintf('"%s" must be a full https:// origin without path or wildcards', is_scalar($origin) ? (string) $origin : gettype($origin))
                    );
                }
            }
            $embeds = $raw['runtime']['embeds'];
        }

        if (isset($raw['display']['icon'])) {
            if (!is_string($raw['display']['icon'])) {
                throw new ManifestError('display.icon', 'must be a string when provided');
            }
            $icon = $raw['display']['icon'];
            if (!preg_match('/^(?!\/)[^.\/].*\.svg$/', $icon) || str_contains($icon, '..') || str_starts_with($icon, '/')) {
                throw new ManifestError('display.icon', 'must be a relative path ending in .svg without ".."');
            }
        }

        $description = null;
        if (isset($raw['description'])) {
            if (!is_string($raw['description'])) {
                throw new ManifestError('description', 'must be a string when provided');
            }
            if (mb_strlen($raw['description']) > 500) {
                throw new ManifestError('description', 'must be at most 500 characters');
            }
            $description = $raw['description'];
        }

        $author = null;
        if (isset($raw['author'])) {
            if (!is_string($raw['author'])) {
                throw new ManifestError('author', 'must be a string when provided');
            }
            if (mb_strlen($raw['author']) > 120) {
                throw new ManifestError('author', 'must be at most 120 characters');
            }
            $author = $raw['author'];
        }

        return new self(
            id: $raw['id'],
            name: $raw['name'],
            description: $description,
            version: $raw['version'],
            author: $author,
            entry: $raw['entry'],
            display_modes: $modes,
            display_default: $default_mode,
            display_icon: isset($raw['display']['icon']) && is_string($raw['display']['icon']) ? $raw['display']['icon'] : null,
            permissions_read: $perms,
            external_origins: $external,
            isolation: $isolation,
            routes: $routes,
            theme_wrap: $theme_wrap,
            theme_container: $theme_container,
            csp: $csp,
            mount_mode: $mount_mode,
            embeds: $embeds,
            abilities_consumes: $abilities_consumes,
            ai_max_tool_calls: $ai_max_tool_calls,
            ai_timeout_seconds: $ai_timeout_seconds,
            abilities_publishes: $abilities_publishes,
            email_recipients: $email_recipients,
            media_uploads_enabled: $media_uploads_enabled,
            commerce_providers: $commerce_providers,
            commerce_endpoints: $commerce_endpoints,
            content_block_styles: $content_block_styles,
            content_theme_styles: $content_theme_styles,
            content_block_styles_allowlist: $content_block_styles_allowlist,
            content_block_styles_denylist: $content_block_styles_denylist,
        );
    }

    /**
     * @param array{name?:mixed,label?:mixed,description?:mixed,category?:mixed,input_schema?:mixed,output_schema?:mixed,annotations?:mixed,timeout_seconds?:mixed} $entry
     * @param array<string, true> $seen
     * @return array{name:string,label:string,description:string,category:string,input_schema?:array,output_schema?:array,annotations:array<string,bool>,timeout_seconds:int}
     */
    private static function validate_published_ability(mixed $entry, int $index, string $app_id, array &$seen): array {
        $path = "abilities.publishes[$index]";
        if (!is_array($entry)) {
            throw new ManifestError($path, 'must be an object');
        }
        $name = $entry['name'] ?? null;
        if (!is_string($name) || !preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', $name)) {
            throw new ManifestError(
                "$path.name",
                'abilities_publish_invalid_name: must match ^[a-z0-9-]+/[a-z0-9-]+$',
            );
        }
        $namespace = explode('/', $name, 2)[0];
        if ($namespace !== $app_id) {
            throw new ManifestError(
                "$path.name",
                sprintf('abilities_publish_namespace_mismatch: namespace "%s" must equal manifest id "%s"', $namespace, $app_id),
            );
        }
        if (isset($seen[$name])) {
            throw new ManifestError("$path.name", sprintf('abilities_publishes_duplicate: "%s"', $name));
        }
        $seen[$name] = true;

        $label = $entry['label'] ?? null;
        if (!is_string($label) || $label === '' || mb_strlen($label) > 80) {
            throw new ManifestError("$path.label", 'must be a string of 1-80 chars');
        }
        $description = $entry['description'] ?? null;
        if (!is_string($description) || $description === '' || mb_strlen($description) > 500) {
            throw new ManifestError("$path.description", 'must be a string of 1-500 chars');
        }
        $category = $entry['category'] ?? null;
        if (!is_string($category) || !preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*$#', $category)) {
            throw new ManifestError("$path.category", 'must match ^[a-z0-9]+(?:-[a-z0-9]+)*$');
        }

        $out = [
            'name' => $name,
            'label' => $label,
            'description' => $description,
            'category' => $category,
            'annotations' => [],
            'timeout_seconds' => 30,
        ];

        if (array_key_exists('input_schema', $entry)) {
            if (!is_array($entry['input_schema']) || !isset($entry['input_schema']['type'])) {
                throw new ManifestError("$path.input_schema", 'must be an object with a top-level "type" key');
            }
            $out['input_schema'] = $entry['input_schema'];
        }
        if (array_key_exists('output_schema', $entry)) {
            if (!is_array($entry['output_schema']) || !isset($entry['output_schema']['type'])) {
                throw new ManifestError("$path.output_schema", 'must be an object with a top-level "type" key');
            }
            $out['output_schema'] = $entry['output_schema'];
        }
        if (array_key_exists('annotations', $entry)) {
            if (!is_array($entry['annotations'])) {
                throw new ManifestError("$path.annotations", 'must be an object');
            }
            $allowed = ['readonly', 'destructive', 'idempotent'];
            foreach ($entry['annotations'] as $k => $v) {
                if (!in_array($k, $allowed, true)) {
                    throw new ManifestError(
                        "$path.annotations",
                        sprintf('abilities_publish_unknown_annotation: "%s" (allowed: readonly, destructive, idempotent)', $k),
                    );
                }
                if (!is_bool($v)) {
                    throw new ManifestError("$path.annotations.$k", 'must be a boolean');
                }
                $out['annotations'][$k] = $v;
            }
        }
        if (array_key_exists('timeout_seconds', $entry)) {
            $val = $entry['timeout_seconds'];
            if (!is_int($val) || $val < 5 || $val > 120) {
                throw new ManifestError("$path.timeout_seconds", 'must be an integer between 5 and 120');
            }
            $out['timeout_seconds'] = $val;
        }
        return $out;
    }

    /**
     * Validate the entries of a CSP source list (e.g. style_src, font_src).
     * `data:` is only legal in `img_src`; everything else must be `self` or
     * a bare https://host[:port] URL.
     *
     * @param mixed[] $sources
     */
    private static function validate_csp_sources(array $sources, string $key): void {
        foreach ($sources as $j => $src) {
            if (!is_string($src)) {
                throw new ManifestError("runtime.csp.{$key}[$j]", 'must be a string');
            }
            if ($src === 'self') continue;
            if ($src === 'data:') {
                if ($key !== 'img_src') {
                    throw new ManifestError("runtime.csp.$key", '"data:" is only allowed in img_src');
                }
                continue;
            }
            if (!preg_match('#^https://[^/\s]+$#', $src)) {
                throw new ManifestError("runtime.csp.{$key}[$j]", 'must be "self", "data:" (img_src only), or a bare https://host[:port]');
            }
        }
    }

    private static function is_valid_https_origin(string $origin): bool {
        $parsed = wp_parse_url($origin);
        if (!is_array($parsed)) return false;
        if (($parsed['scheme'] ?? '') !== 'https') return false;
        if (!isset($parsed['host']) || $parsed['host'] === '') return false;
        if (isset($parsed['path']) && $parsed['path'] !== '') return false;
        if (isset($parsed['query'])) return false;
        if (isset($parsed['fragment'])) return false;
        if (isset($parsed['user']) || isset($parsed['pass'])) return false;
        return self::is_valid_hostname($parsed['host']);
    }

    private static function is_valid_hostname(string $host): bool {
        if ($host === '' || strlen($host) > 253) return false;
        if (str_contains($host, '..')) return false;
        if (str_starts_with($host, '.') || str_ends_with($host, '.')) return false;
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63) return false;
            if (str_starts_with($label, '-') || str_ends_with($label, '-')) return false;
            if (!preg_match('/^[a-z0-9-]+$/i', $label)) return false;
        }
        return true;
    }

    private static function assert_string(array $arr, string $key, ?string $path = null): void {
        $path ??= $key;
        if (!array_key_exists($key, $arr) || !is_string($arr[$key]) || $arr[$key] === '') {
            throw new ManifestError($path, 'is required and must be a non-empty string');
        }
    }

    private static function assert_int(array $arr, string $key, ?string $path = null): void {
        $path ??= $key;
        if (!array_key_exists($key, $arr) || !is_int($arr[$key])) {
            throw new ManifestError($path, 'is required and must be an integer');
        }
    }

    private static function assert_array(array $arr, string $key, ?string $path = null): void {
        $path ??= $key;
        if (!array_key_exists($key, $arr) || !is_array($arr[$key])) {
            throw new ManifestError($path, 'is required and must be an array');
        }
    }

    /**
     * Hydrate a Manifest value object from an already-validated array
     * (e.g. one that came out of `to_array()` and was stored as post meta
     * by the installer). Skips the regex/loop/CSP/hostname validation that
     * `validate()` runs — those checks ran once at install time and cannot
     * be invalidated by anything other than a re-install. Used by the
     * frontend dispatcher hot path; never call this on untrusted input.
     */
    public static function from_array_unchecked(array $raw): self {
        $modes = [];
        foreach (($raw['display']['modes'] ?? []) as $v) {
            if (is_string($v) && ($m = DisplayMode::tryFrom($v)) !== null) {
                $modes[] = $m;
            }
        }
        $default_mode = DisplayMode::tryFrom((string) ($raw['display']['default'] ?? 'page'))
            ?? ($modes[0] ?? DisplayMode::Page);

        $perms = [];
        foreach (($raw['permissions']['read'] ?? []) as $v) {
            if (is_string($v) && ($p = Permission::tryFrom($v)) !== null) {
                $perms[] = $p;
            }
        }

        $mount_mode = MountMode::tryFrom((string) ($raw['mount']['mode'] ?? 'prefixed'))
            ?? MountMode::Prefixed;

        $isolation = is_string($raw['isolation'] ?? null) ? $raw['isolation'] : 'inline';

        return new self(
            id: (string) ($raw['id'] ?? ''),
            name: (string) ($raw['name'] ?? ''),
            description: isset($raw['description']) && is_string($raw['description']) ? $raw['description'] : null,
            version: (string) ($raw['version'] ?? '0.0.0'),
            author: isset($raw['author']) && is_string($raw['author']) ? $raw['author'] : null,
            entry: (string) ($raw['entry'] ?? 'index.html'),
            display_modes: $modes,
            display_default: $default_mode,
            display_icon: isset($raw['display']['icon']) && is_string($raw['display']['icon']) ? $raw['display']['icon'] : null,
            permissions_read: $perms,
            external_origins: is_array($raw['runtime']['external_origins'] ?? null) ? $raw['runtime']['external_origins'] : [],
            isolation: $isolation,
            routes: is_array($raw['routes'] ?? null) ? $raw['routes'] : [],
            theme_wrap: (string) ($raw['theme']['wrap'] ?? 'none'),
            theme_container: (string) ($raw['theme']['container'] ?? 'none'),
            csp: is_array($raw['runtime']['csp'] ?? null) ? $raw['runtime']['csp'] : null,
            mount_mode: $mount_mode,
            embeds: is_array($raw['runtime']['embeds'] ?? null) ? $raw['runtime']['embeds'] : [],
            abilities_consumes: is_array($raw['abilities']['consumes'] ?? null) ? $raw['abilities']['consumes'] : [],
            ai_max_tool_calls: is_int($raw['ai']['max_tool_calls'] ?? null) ? $raw['ai']['max_tool_calls'] : 5,
            ai_timeout_seconds: is_int($raw['ai']['timeout_seconds'] ?? null) ? $raw['ai']['timeout_seconds'] : 60,
            abilities_publishes: is_array($raw['abilities']['publishes'] ?? null) ? $raw['abilities']['publishes'] : [],
            email_recipients: array_values(array_filter(array_map(
                static fn ($v) => is_string($v) ? EmailRecipient::tryFrom($v) : null,
                is_array($raw['email']['recipients'] ?? null) ? $raw['email']['recipients'] : [],
            ))),
            media_uploads_enabled: !isset($raw['media']['uploads']) || $raw['media']['uploads'] !== false,
            commerce_providers: is_array($raw['commerce']['providers'] ?? null)
                ? array_values(array_filter($raw['commerce']['providers'], 'is_string'))
                : [],
            commerce_endpoints: is_array($raw['commerce']['endpoints'] ?? null)
                ? array_values(array_filter($raw['commerce']['endpoints'], 'is_string'))
                : [],
            content_block_styles: is_array($raw['content']['blockStyles'] ?? null)
                ? array_values(array_filter($raw['content']['blockStyles'], 'is_string'))
                : [],
            content_theme_styles: is_string($raw['content']['themeStyles'] ?? null)
                && in_array($raw['content']['themeStyles'], ['off', 'global'], true)
                ? $raw['content']['themeStyles']
                : 'off',
            content_block_styles_allowlist: is_array($raw['content']['blockStyleAllowlist'] ?? null)
                ? array_values(array_filter($raw['content']['blockStyleAllowlist'], 'is_string'))
                : [],
            content_block_styles_denylist: is_array($raw['content']['blockStyleDenylist'] ?? null)
                ? array_values(array_filter($raw['content']['blockStyleDenylist'], 'is_string'))
                : [],
        );
    }

    public function to_array(): array {
        $out = [
            'manifest_version' => 1,
            'id'               => $this->id,
            'name'             => $this->name,
            'description'      => $this->description,
            'version'          => $this->version,
            'author'           => $this->author,
            'entry'            => $this->entry,
            'isolation'        => $this->isolation,
            'display'          => [
                'modes'   => array_map(fn (DisplayMode $m) => $m->value, $this->display_modes),
                'default' => $this->display_default->value,
                'icon'    => $this->display_icon,
            ],
            'permissions'      => [
                'read'  => array_map(fn (Permission $p) => $p->value, $this->permissions_read),
                'write' => [],
            ],
            'runtime'          => [
                'sandbox' => 'strict',
            ],
        ];
        if ($this->isolation === 'inline') {
            $out['routes'] = array_map(static function (array $r): array {
                $serialized = [
                    'path' => $r['path'],
                    'file' => $r['file'],
                    'title' => $r['title'] ?? null,
                    'description' => $r['description'] ?? null,
                ];
                if (isset($r['dataset']) && is_array($r['dataset'])) {
                    $serialized['dataset'] = $r['dataset'];
                }
                return $serialized;
            }, $this->routes);
            $out['theme'] = ['wrap' => $this->theme_wrap, 'container' => $this->theme_container];
            $out['runtime']['csp'] = $this->csp;
        } else {
            $out['runtime']['external_origins'] = $this->external_origins;
        }
        if ($this->embeds !== []) {
            $out['runtime']['embeds'] = $this->embeds;
        }
        $out['mount'] = ['mode' => $this->mount_mode->value];
        if ($this->abilities_consumes !== []) {
            $out['abilities'] = ['consumes' => $this->abilities_consumes];
        }
        if ($this->abilities_publishes !== []) {
            $out['abilities'] = $out['abilities'] ?? [];
            $out['abilities']['publishes'] = $this->abilities_publishes;
        }
        if (in_array(Permission::Ai, $this->permissions_read, true)) {
            $out['ai'] = [
                'max_tool_calls'  => $this->ai_max_tool_calls,
                'timeout_seconds' => $this->ai_timeout_seconds,
            ];
        }
        if ($this->email_recipients !== []) {
            $out['email'] = [
                'recipients' => array_map(fn (EmailRecipient $r) => $r->value, $this->email_recipients),
            ];
        }
        // Only emit `media` when the app opts out — keeping the manifest
        // round-trip stable for the (overwhelmingly common) default-on case.
        if ($this->media_uploads_enabled === false) {
            $out['media'] = ['uploads' => false];
        }
        if ($this->commerce_providers !== [] || $this->commerce_endpoints !== []) {
            $out['commerce'] = [
                'providers' => $this->commerce_providers,
                'endpoints' => $this->commerce_endpoints,
            ];
        }
        // Only emit `content` when the app has opted into something — keeps
        // round-trip stable for the (default) all-off case.
        if ($this->content_block_styles !== []
            || $this->content_theme_styles !== 'off'
            || $this->content_block_styles_allowlist !== []
            || $this->content_block_styles_denylist !== []
        ) {
            $content = [];
            if ($this->content_block_styles !== []) {
                $content['blockStyles'] = $this->content_block_styles;
            }
            if ($this->content_theme_styles !== 'off') {
                $content['themeStyles'] = $this->content_theme_styles;
            }
            if ($this->content_block_styles_allowlist !== []) {
                $content['blockStyleAllowlist'] = $this->content_block_styles_allowlist;
            }
            if ($this->content_block_styles_denylist !== []) {
                $content['blockStyleDenylist'] = $this->content_block_styles_denylist;
            }
            $out['content'] = $content;
        }
        return $out;
    }
}
