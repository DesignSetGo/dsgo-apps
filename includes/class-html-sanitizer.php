<?php
/**
 * HTML sanitizer for inline-mode app HTML.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

// Exception messages constructed below are never echoed to clients; the REST
// layer catches them and returns sanitized error_code + filtered messages.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

final class HtmlSanitizerError extends \RuntimeException {
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}

final class HtmlSanitizer {

    /**
     * @param array{
     *     nonce: string,
     *     allow_root_paths?: bool,
     *     allow_url_prefix?: string|null,
     * } $opts
     *
     * `allow_root_paths`  — when true, any `/...` site-absolute URL counts as
     *                       bundle-relative (root-mounted apps own the site root).
     * `allow_url_prefix`  — additional URL prefix that counts as bundle-relative
     *                       (e.g., `/myapps/loyalty-dashboard`). The legacy
     *                       `/apps/` prefix is always accepted.
     */
    public static function sanitize(string $html, array $opts): string {
        $nonce = $opts['nonce'];
        $url_ctx = [
            'allow_root_paths' => (bool) ($opts['allow_root_paths'] ?? false),
            'allow_url_prefix' => isset($opts['allow_url_prefix']) && is_string($opts['allow_url_prefix'])
                ? $opts['allow_url_prefix']
                : null,
            'stylesheet_origins' => is_array($opts['stylesheet_origins'] ?? null)
                ? $opts['stylesheet_origins']
                : [],
            'script_origins' => is_array($opts['script_origins'] ?? null)
                ? $opts['script_origins']
                : [],
        ];
        $embed_origins = is_array($opts['embed_origins'] ?? null)
            ? $opts['embed_origins']
            : [];

        // Strip <script> tags inside SVG contexts before running the executable-script checks,
        // so they are silently removed rather than causing an error.
        $html = self::strip_svg_scripts($html);

        self::reject_inline_executable_scripts($html, $nonce);
        self::reject_remote_script_src($html, $nonce, $url_ctx);
        self::reject_remote_link_stylesheet($html, $url_ctx);
        // Drop <iframe>s whose src isn't on the manifest's embeds allowlist
        // (or that don't carry a sandbox attribute) before the kses pass —
        // wp_kses sees a sanitized iframe element so it doesn't strip it.
        $html = self::filter_iframes($html, $embed_origins);

        $allowed = self::allowed_html($embed_origins !== []);
        $allowed_protocols = ['https', 'mailto', 'tel', 'data'];

        // wp_kses HTML-encodes characters inside <script> bodies (e.g. `=>`
        // becomes `=&gt;`, `&&` becomes `&amp;&amp;`), which silently breaks
        // any non-trivial JS — frameworks like Astro / Vite emit hydration
        // scripts that use `<`, `>`, `&` operators heavily. Extract every
        // <script> block up front, kses-sanitize the surrounding HTML, then
        // splice the original script bodies back in. The script tags
        // themselves have already passed reject_inline_executable_scripts +
        // reject_remote_script_src above, so their contents are trusted.
        [$html_no_scripts, $script_blocks] = self::extract_script_blocks($html);

        // wp_kses strips DOCTYPE declarations; without one the browser falls
        // back to quirks mode and layouts subtly break. Capture it separately
        // and prepend to the sanitized output.
        $doctype = '';
        if (preg_match('#^\s*(<!DOCTYPE[^>]*>)\s*#i', $html_no_scripts, $dm)) {
            $doctype = $dm[1];
            $html_no_scripts = (string) substr($html_no_scripts, strlen($dm[0]));
        }

        $sanitized = wp_kses($html_no_scripts, $allowed, $allowed_protocols);

        if ($doctype !== '') {
            $sanitized = $doctype . "\n" . $sanitized;
        }

        $sanitized = self::restore_script_blocks($sanitized, $script_blocks);

        $sanitized = self::strip_javascript_in_attrs($sanitized);
        $sanitized = self::strip_data_uri_outside_img($sanitized);

        return $sanitized;
    }

    /**
     * Extract every `<script>...</script>` block (including its body) and
     * replace it with an opaque placeholder kses won't touch. Returns the
     * placeholder-laden HTML and the list of original script blocks in order.
     *
     * Script attributes have already been validated by the upstream
     * reject_* checks, so we preserve them verbatim alongside the body.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function extract_script_blocks(string $html): array {
        $blocks = [];
        $out = preg_replace_callback(
            '#<script\b[^>]*>.*?</script>#is',
            static function (array $m) use (&$blocks): string {
                $idx = count($blocks);
                $blocks[] = $m[0];
                // No HTML-special chars in the marker, so kses won't munge it.
                return "<!--DSGO_SCRIPT_BLOCK_{$idx}-->";
            },
            $html,
        );
        return [$out ?? $html, $blocks];
    }

    /**
     * @param list<string> $blocks
     */
    private static function restore_script_blocks(string $html, array $blocks): string {
        if ($blocks === []) return $html;
        return preg_replace_callback(
            '#<!--DSGO_SCRIPT_BLOCK_(\d+)-->#',
            static function (array $m) use ($blocks): string {
                $idx = (int) $m[1];
                return $blocks[$idx] ?? '';
            },
            $html,
        ) ?? $html;
    }

    public static function allowed_html(bool $allow_iframe = false): array {
        $universal = [
            'class' => true, 'id' => true, 'lang' => true, 'dir' => true,
            'title' => true, 'tabindex' => true, 'role' => true,
            'style' => true, 'nonce' => true,
            'data-*' => true, 'aria-*' => true,
        ];
        $tag = fn(array $extra = []) => array_merge($universal, $extra);

        return [
            'html' => $tag(), 'head' => $tag(), 'body' => $tag(),
            'meta' => $tag(['name' => true, 'content' => true, 'charset' => true, 'property' => true, 'http-equiv' => true]),
            'title' => $tag(),
            'link' => $tag(['rel' => true, 'href' => true, 'type' => true, 'sizes' => true, 'media' => true, 'crossorigin' => true]),
            'style' => $tag(['type' => true, 'media' => true]),
            'script' => $tag(['src' => true, 'type' => true, 'async' => true, 'defer' => true, 'crossorigin' => true]),

            'h1' => $tag(), 'h2' => $tag(), 'h3' => $tag(), 'h4' => $tag(), 'h5' => $tag(), 'h6' => $tag(),
            'p' => $tag(), 'div' => $tag(), 'span' => $tag(),
            'a' => $tag(['href' => true, 'target' => true, 'rel' => true, 'download' => true]),
            'em' => $tag(), 'strong' => $tag(), 'b' => $tag(), 'i' => $tag(), 'u' => $tag(), 's' => $tag(),
            'del' => $tag(), 'ins' => $tag(), 'mark' => $tag(), 'small' => $tag(), 'sub' => $tag(), 'sup' => $tag(),
            'time' => $tag(['datetime' => true]), 'address' => $tag(),
            'blockquote' => $tag(['cite' => true]), 'cite' => $tag(), 'q' => $tag(['cite' => true]),
            'pre' => $tag(), 'code' => $tag(), 'kbd' => $tag(), 'samp' => $tag(), 'var' => $tag(),
            'br' => $tag(), 'hr' => $tag(), 'wbr' => $tag(),

            'ul' => $tag(), 'ol' => $tag(['start' => true, 'reversed' => true]), 'li' => $tag(['value' => true]),
            'dl' => $tag(), 'dt' => $tag(), 'dd' => $tag(),

            'table' => $tag(), 'thead' => $tag(), 'tbody' => $tag(), 'tfoot' => $tag(),
            'tr' => $tag(), 'th' => $tag(['scope' => true, 'colspan' => true, 'rowspan' => true]),
            'td' => $tag(['colspan' => true, 'rowspan' => true]),
            'caption' => $tag(), 'colgroup' => $tag(['span' => true]), 'col' => $tag(['span' => true]),

            'nav' => $tag(), 'main' => $tag(), 'article' => $tag(), 'section' => $tag(),
            'header' => $tag(), 'footer' => $tag(), 'aside' => $tag(),

            'img' => $tag(['src' => true, 'alt' => true, 'width' => true, 'height' => true, 'srcset' => true, 'sizes' => true, 'loading' => true, 'decoding' => true]),
            'picture' => $tag(),
            'source' => $tag(['src' => true, 'srcset' => true, 'media' => true, 'sizes' => true, 'type' => true]),
            'video' => $tag(['src' => true, 'poster' => true, 'controls' => true, 'autoplay' => true, 'loop' => true, 'muted' => true, 'preload' => true, 'width' => true, 'height' => true]),
            'audio' => $tag(['src' => true, 'controls' => true, 'autoplay' => true, 'loop' => true, 'muted' => true, 'preload' => true]),
            'track' => $tag(['kind' => true, 'src' => true, 'srclang' => true, 'label' => true, 'default' => true]),
            'canvas' => $tag(['width' => true, 'height' => true]),
            'figure' => $tag(), 'figcaption' => $tag(),

            'details' => $tag(['open' => true]), 'summary' => $tag(),
            'dialog' => $tag(['open' => true]),
            'progress' => $tag(['value' => true, 'max' => true]),
            'meter' => $tag(['value' => true, 'min' => true, 'max' => true, 'low' => true, 'high' => true, 'optimum' => true]),

            'form' => $tag(['action' => true, 'method' => true, 'enctype' => true, 'novalidate' => true, 'autocomplete' => true]),
            'input' => $tag(['type' => true, 'name' => true, 'value' => true, 'placeholder' => true, 'required' => true, 'disabled' => true, 'readonly' => true, 'min' => true, 'max' => true, 'step' => true, 'pattern' => true, 'maxlength' => true, 'minlength' => true, 'autocomplete' => true, 'list' => true, 'checked' => true, 'multiple' => true, 'accept' => true]),
            'textarea' => $tag(['name' => true, 'placeholder' => true, 'required' => true, 'disabled' => true, 'readonly' => true, 'rows' => true, 'cols' => true, 'maxlength' => true, 'minlength' => true]),
            'select' => $tag(['name' => true, 'required' => true, 'disabled' => true, 'multiple' => true, 'size' => true]),
            'option' => $tag(['value' => true, 'selected' => true, 'disabled' => true]),
            'optgroup' => $tag(['label' => true, 'disabled' => true]),
            'button' => $tag(['type' => true, 'name' => true, 'value' => true, 'disabled' => true]),
            'label' => $tag(['for' => true]),
            'fieldset' => $tag(['disabled' => true]), 'legend' => $tag(),
            'datalist' => $tag(), 'output' => $tag(['for' => true, 'name' => true]),

            'template' => $tag(), 'slot' => $tag(['name' => true]),

            'svg' => $tag(['xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'preserveaspectratio' => true]),
            'g' => $tag(['transform' => true, 'fill' => true, 'stroke' => true]),
            'path' => $tag(['d' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true]),
            'circle' => $tag(['cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true]),
            'rect' => $tag(['x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true]),
            'line' => $tag(['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true]),
            'polyline' => $tag(['points' => true, 'fill' => true, 'stroke' => true]),
            'polygon' => $tag(['points' => true, 'fill' => true, 'stroke' => true]),
            'ellipse' => $tag(['cx' => true, 'cy' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true]),
            'text' => $tag(['x' => true, 'y' => true, 'dx' => true, 'dy' => true, 'text-anchor' => true, 'fill' => true]),
            'use' => $tag(['x' => true, 'y' => true, 'width' => true, 'height' => true]),
            'defs' => $tag(), 'symbol' => $tag(['viewbox' => true]),
        ] + ($allow_iframe ? [
            // <iframe> only enters the allowlist when the manifest declares
            // `runtime.embeds`. filter_iframes() above has already dropped
            // any iframe whose src isn't on the allowlist or that lacks a
            // sandbox attribute, so by the time wp_kses sees this element
            // it's already vetted.
            'iframe' => $tag([
                'src' => true, 'width' => true, 'height' => true,
                'sandbox' => true, 'allow' => true, 'allowfullscreen' => true,
                'loading' => true, 'referrerpolicy' => true, 'name' => true,
            ]),
        ] : []);
    }

    /**
     * Drop any `<iframe>` whose `src` origin isn't in the manifest's embeds
     * allowlist. Iframes whose origin is allowed but that lack a `sandbox`
     * attribute have a safe default injected, since the dominant authoring
     * path (WordPress oEmbed: YouTube, Vimeo, etc.) emits unsandboxed
     * iframes that the author cannot easily edit.
     *
     * The strip is silent — the rule of thumb is "if the author declared
     * the embed, it works; if not, drop it and let the deploy preflight
     * tell them how to enable it."
     *
     * @param string[] $allowed_origins
     */
    private static function filter_iframes(string $html, array $allowed_origins): string {
        return preg_replace_callback(
            '#<iframe\b([^>]*)>(.*?)</iframe>#is',
            static function (array $m) use ($allowed_origins): string {
                if ($allowed_origins === []) {
                    return ''; // No embeds declared at all — drop everything.
                }
                $attrs = $m[1];
                $src = self::extract_attr($attrs, 'src');
                if ($src === null) {
                    return ''; // No src — useless and not on any allowlist.
                }
                if (!self::href_origin_allowed($src, $allowed_origins)) {
                    return '';
                }
                if (!preg_match('~\bsandbox\s*=~i', $attrs)) {
                    // Origin is vouched-for by the manifest but the author
                    // (or oEmbed handler) didn't set sandbox. Inject a default
                    // that allows trusted-embed players (YouTube, Vimeo) to
                    // function without granting top-frame navigation.
                    $attrs = ' sandbox="' . self::DEFAULT_EMBED_SANDBOX . '"' . $attrs;
                    return '<iframe' . $attrs . '>' . $m[2] . '</iframe>';
                }
                return $m[0]; // Vetted — keep as-is.
            },
            $html,
        ) ?? $html;
    }

    private const DEFAULT_EMBED_SANDBOX = 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-presentation';

    private static function strip_svg_scripts(string $html): string {
        // Remove any <script> elements that appear inside <svg>...</svg> blocks.
        return preg_replace_callback(
            '#(<svg\b[^>]*>)(.*?)(</svg>)#is',
            static function (array $m): string {
                $inner = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $m[2]) ?? $m[2];
                return $m[1] . $inner . $m[3];
            },
            $html,
        ) ?? $html;
    }

    private static function reject_inline_executable_scripts(string $html, string $nonce): void {
        $offset = 0;
        while (preg_match('#<script\b([^>]*)>(.*?)</script>#is', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $attrs = $m[1][0];
            $body = $m[2][0];
            $type = self::extract_attr($attrs, 'type');
            $src = self::extract_attr($attrs, 'src');
            $is_inert_type = in_array(strtolower($type ?? ''), ['application/json', 'speculationrules', 'importmap'], true);
            if ($is_inert_type) {
                $offset = $m[0][1] + strlen($m[0][0]);
                continue;
            }
            if ($src === null) {
                // Inline executable body. CSP `script-src 'self' 'nonce-XXX'`
                // blocks unstamped inline scripts at the browser, so they only
                // execute once stamp_nonce_on_existing_tags adds the per-request
                // nonce. Mirror the <script src> rule: missing/empty nonce is
                // fine (will be stamped); explicit non-matching nonce is a
                // silent CSP-break and we reject it.
                $script_nonce = self::extract_attr($attrs, 'nonce');
                if ($script_nonce !== null && $script_nonce !== '' && $script_nonce !== $nonce) {
                    throw new HtmlSanitizerError(
                        'script_nonce_mismatch',
                        'when an inline <script> declares a nonce, it must match the per-request nonce; or omit it and the runtime will stamp one',
                    );
                }
                $offset = $m[0][1] + strlen($m[0][0]);
                continue;
            }
            if (trim($body) !== '') {
                throw new HtmlSanitizerError('script_with_body', '<script src> must have empty body content');
            }
            $offset = $m[0][1] + strlen($m[0][0]);
        }
    }

    private static function reject_remote_script_src(string $html, string $nonce, array $url_ctx): void {
        $offset = 0;
        $allowed_origins = $url_ctx['script_origins'] ?? [];
        while (preg_match('#<script\b([^>]*)>#i', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $attrs = $m[1][0];
            $type = self::extract_attr($attrs, 'type');
            if (in_array(strtolower($type ?? ''), ['application/json', 'speculationrules', 'importmap'], true)) {
                $offset = $m[0][1] + strlen($m[0][0]);
                continue;
            }
            $src = self::extract_attr($attrs, 'src');
            if ($src !== null) {
                if (!self::is_bundle_relative($src, $url_ctx)) {
                    // Same pattern as stylesheets: a manifest that explicitly
                    // declared an https origin in csp.script_src has trusted
                    // it for the runtime, so accept the same origin here at
                    // sanitize time. Anything not on the list is still rejected.
                    if (!self::href_origin_allowed($src, $allowed_origins)) {
                        throw new HtmlSanitizerError('remote_script', "script src must be bundle-relative or listed in csp.script_src: $src");
                    }
                }
                // Authors don't know the per-request nonce when bundling, so a missing
                // nonce on a bundle-relative script is fine — the inline renderer's
                // stamp_nonce_on_existing_tags adds one before serving. We only reject
                // if the author set a nonce that disagrees with the per-request nonce
                // (which would silently break CSP at render time).
                $script_nonce = self::extract_attr($attrs, 'nonce');
                if ($script_nonce !== null && $script_nonce !== $nonce) {
                    throw new HtmlSanitizerError(
                        'script_nonce_mismatch',
                        'when a <script src> declares a nonce, it must match the per-request nonce; or omit it and the runtime will stamp one',
                    );
                }
            }
            $offset = $m[0][1] + strlen($m[0][0]);
        }
    }

    private static function reject_remote_link_stylesheet(string $html, array $url_ctx): void {
        $offset = 0;
        $allowed_origins = $url_ctx['stylesheet_origins'] ?? [];
        while (preg_match('#<link\b([^>]*)>#i', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $attrs = $m[1][0];
            $rel = self::extract_attr($attrs, 'rel');
            if (strtolower($rel ?? '') === 'stylesheet') {
                $href = self::extract_attr($attrs, 'href');
                if ($href !== null && !self::is_bundle_relative($href, $url_ctx)) {
                    // Allow https stylesheet hrefs from origins the manifest's
                    // CSP `style_src` already trusts. Frameworks like Astro
                    // pull Google Fonts / Stripe / etc.; the CSP is the source
                    // of truth for which hosts the app intends to load from.
                    if (!self::href_origin_allowed($href, $allowed_origins)) {
                        // Error-message text — not enqueued output; safe to keyword "stylesheet" here.
                        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
                        throw new HtmlSanitizerError('remote_link_stylesheet', "link rel=stylesheet href must be bundle-relative: $href");
                    }
                }
            }
            $offset = $m[0][1] + strlen($m[0][0]);
        }
    }

    /**
     * @param string[] $origins  e.g. ['https://fonts.googleapis.com', 'https://stripe.com']
     */
    private static function href_origin_allowed(string $href, array $origins): bool {
        if ($origins === []) return false;
        if (!preg_match('~^https://([^/?#]+)~i', $href, $m)) return false;
        $host = strtolower($m[1]);
        foreach ($origins as $origin) {
            if (!is_string($origin)) continue;
            if (preg_match('~^https://([^/?#]+)~i', $origin, $om) && strtolower($om[1]) === $host) {
                return true;
            }
        }
        return false;
    }

    private static function strip_javascript_in_attrs(string $html): string {
        // Match javascript:/vbscript: URIs in any HTML5 attribute-value form
        // (double-quoted, single-quoted, unquoted). Anchors `action`/`formaction`
        // aren't always in WP's URL-protocol enforcement, so this is the only
        // line of defense for those — supporting just double-quoted left
        // single-quoted/unquoted javascript: URIs intact.
        return preg_replace(
            '#\s+(href|src|action|formaction)\s*=\s*'
            . '(?:"\s*(?:javascript|vbscript):[^"]*"'
            . '|\'\s*(?:javascript|vbscript):[^\']*\''
            . '|(?:javascript|vbscript):[^\s>]*)#i',
            '',
            $html,
        ) ?? $html;
    }

    private static function strip_data_uri_outside_img(string $html): string {
        $html = preg_replace_callback(
            '#<a\b([^>]*)>#i',
            fn($m) => self::strip_data_in_attrs($m[0], ['href']),
            $html,
        ) ?? $html;
        return $html;
    }

    private static function strip_data_in_attrs(string $tag, array $attrs): string {
        foreach ($attrs as $a) {
            $tag = preg_replace(
                '#\s+' . preg_quote($a, '#') . '\s*=\s*'
                . '(?:"data:[^"]*"|\'data:[^\']*\'|data:[^\s>]*)#i',
                '',
                $tag,
            ) ?? $tag;
        }
        return $tag;
    }

    /** @var array<string, string> */
    private static array $extract_attr_pattern_cache = [];

    private static function extract_attr(string $attrs, string $name): ?string {
        // Match all three HTML5 attribute-value forms:
        //   name="value"   double-quoted
        //   name='value'   single-quoted
        //   name=value     unquoted (terminated by whitespace, `>`, or end)
        // Matching only double-quoted previously let single-quoted/unquoted
        // attributes slip past every callsite that read a value here —
        // including `src` on the remote-script-rejection path, which made
        // `<script src='https://evil/x.js'>` survive sanitize and then get
        // nonce-stamped by the inline renderer.
        //
        // Cache compiled pattern strings per attribute name so the hot-path
        // callers (every <script>, <link>, <iframe> in the document) don't
        // burn CPU on `preg_quote()` for the same half-dozen attribute names
        // per request.
        if (!isset(self::$extract_attr_pattern_cache[$name])) {
            self::$extract_attr_pattern_cache[$name] =
                '#\b' . preg_quote($name, '#')
                . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))#i';
        }
        if (preg_match(self::$extract_attr_pattern_cache[$name], $attrs, $m)) {
            // PCRE leaves un-matched alternation groups as empty strings (or
            // unset on older versions); whichever quoting form matched is the
            // first non-empty capture.
            if (isset($m[1]) && $m[1] !== '') return $m[1];
            if (isset($m[2]) && $m[2] !== '') return $m[2];
            if (isset($m[3]) && $m[3] !== '') return $m[3];
            // All three groups empty means an explicit empty value
            // (`name=""` / `name=''`); preserve that distinction.
            return '';
        }
        return null;
    }

    /**
     * Decides whether a URL points to something the runtime will serve out of
     * the bundle. Context-aware: root-mounted apps own the site root, so any
     * `/...` path is bundle-relative for them; prefixed-mount apps additionally
     * accept their per-app prefix `/{configured-prefix}/{appId}/...`.
     *
     * @param array{allow_root_paths: bool, allow_url_prefix: ?string} $ctx
     */
    private static function is_bundle_relative(string $url, array $ctx): bool {
        if ($url === '') return false;
        if (str_starts_with($url, '../')) return false;       // path-traversal
        if (str_starts_with($url, '//')) return false;        // protocol-relative
        if (str_starts_with($url, './')) return true;
        // Legacy default prefix — accept regardless of current `dsgo_apps_url_prefix`,
        // so a bundle authored under the default ships unchanged.
        if (str_starts_with($url, '/apps/')) return true;
        if (str_starts_with($url, '/wp-content/uploads/designsetgo-apps/')) return true;
        if ($ctx['allow_url_prefix'] !== null && $ctx['allow_url_prefix'] !== ''
            && str_starts_with($url, rtrim($ctx['allow_url_prefix'], '/') . '/')
        ) {
            return true;
        }
        if (str_starts_with($url, '/')) {
            // Root-mounted apps own all `/...` paths. Reject WP internals to
            // avoid hiding obvious mistakes (an app shouldn't reference admin
            // or REST URLs from its bundled HTML).
            if (!$ctx['allow_root_paths']) return false;
            if (str_starts_with($url, '/wp-admin/') ||
                str_starts_with($url, '/wp-includes/') ||
                str_starts_with($url, '/wp-json/') ||
                str_starts_with($url, '/wp-login')) return false;
            return true;
        }
        // A scheme like http:, https:, data:, etc. — definitely not bundle-local.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $url) === 1) return false;
        // Bare relative path (e.g. "main.js", "assets/app.js") — emitted by every
        // bundler that doesn't know it's targeting a sub-path.
        return true;
    }
}
