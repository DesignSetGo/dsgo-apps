<?php
/**
 * Build a Content-Security-Policy header value for inline-mode app responses.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

final class CSPBuilder {

    /**
     * @param array{
     *     script_src:string[],
     *     style_src:string[],
     *     img_src:string[],
     *     connect_src:string[],
     *     font_src?:string[],
     * } $csp
     * @param string[] $embed_origins  Optional iframe-embed allowlist (from
     *                                 `manifest.runtime.embeds`); used to
     *                                 populate `frame-src`.
     */
    public static function build(array $csp, string $nonce, array $embed_origins = []): string {
        $script_src = self::format_sources($csp['script_src']) . " 'nonce-" . $nonce . "'";
        // Style: `'unsafe-inline'` covers both `<style>` blocks and `style=`
        // attributes. Per CSP3, listing both `'nonce-...'` and `'unsafe-inline'`
        // makes the browser ignore the `'unsafe-inline'`, so the nonce is
        // omitted here. Style is far lower risk than script — no JS execution
        // is reachable from CSS — so the trade-off is acceptable, and it
        // matches the "open by default, guide on risk" philosophy.
        $style_src = self::format_sources($csp['style_src']) . " 'unsafe-inline'";
        $img_src = self::format_sources($csp['img_src']);
        $connect_src = self::format_sources($csp['connect_src']);
        // Default `font-src` covers self + data: (icon fonts inlined as base64).
        // Authors can add CDN origins via manifest.runtime.csp.font_src.
        $font_src = isset($csp['font_src']) && $csp['font_src'] !== []
            ? self::format_sources($csp['font_src'])
            : "'self' data:";

        $directives = [
            "default-src 'none'",
            "script-src $script_src",
            "style-src $style_src",
            "img-src $img_src",
            "connect-src $connect_src",
            "font-src $font_src",
            "media-src 'self'",
            $embed_origins !== []
                ? "frame-src " . self::format_sources($embed_origins)
                : "frame-src 'none'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
        ];

        return implode('; ', $directives);
    }

    private static function format_sources(array $sources): string {
        $parts = [];
        foreach ($sources as $src) {
            if ($src === 'self') {
                $parts[] = "'self'";
            } elseif ($src === 'data:') {
                $parts[] = 'data:';
            } else {
                $parts[] = $src;
            }
        }
        return implode(' ', $parts);
    }
}
