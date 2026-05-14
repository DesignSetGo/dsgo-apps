<?php
/**
 * Inline-app 404 page. Rendered by InlineRenderer::render_route_not_found()
 * when a request resolves to neither a declared route nor a bundle asset.
 *
 * Two render paths:
 *   1. When the app declares theme.wrap == "header_footer" and the active
 *      theme exposes get_header()/get_footer(), the error body is wrapped in
 *      the theme chrome. If the theme throws or emits nothing, we fall back.
 *   2. Otherwise (or on fallback) a self-contained <!doctype html> document
 *      with the same inline error styles.
 *
 * $ctx (PHP keys read directly off $ctx — no extract() call):
 *   - title           string   localized "Page not found"
 *   - body            string   localized "That route is not part of ..."
 *   - home_link       string   pre-escaped <a> back to the site root
 *   - lang            string   esc_attr'd document language attribute
 *   - can_theme_wrap  bool     manifest opted into header_footer AND theme supports it
 *
 * Headers (status 404, content-type, nosniff) are sent by the caller before
 * this partial runs.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

/** @var array{
 *   title:string,
 *   body:string,
 *   home_link:string,
 *   lang:string,
 *   can_theme_wrap:bool,
 * } $ctx */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Template-scope locals (passed in by InlineRenderer::render_route_not_found), not plugin globals.

$dsgo_error_style = '<style>'
    . '.dsgo-error{max-width:48rem;margin:6rem auto 4rem;padding:0 1.5rem;text-align:center}'
    . '.dsgo-error__status{font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;opacity:.55;margin:0 0 .75rem}'
    . '.dsgo-error__title{font-size:clamp(1.75rem,3.5vw,2.5rem);margin:0 0 .75rem;font-weight:600;line-height:1.2}'
    . '.dsgo-error__body{font-size:1.0625rem;line-height:1.6;opacity:.8;margin:0 0 2rem}'
    . '.dsgo-error__home a{display:inline-block;padding:.6rem 1.1rem;border:1px solid currentColor;border-radius:999px;text-decoration:none;font-size:.9375rem}'
    . '.dsgo-error__home a:hover{background:currentColor;color:#fff}'
    . '</style>';

$dsgo_rendered = false;
if ($ctx['can_theme_wrap']) {
    ob_start();
    try {
        get_header();
        // $dsgo_error_style is a static <style> block; home_link is built with
        // esc_url + esc_html__ by the caller; title/body are esc_html'd inline.
        echo '<main class="dsgo-error">' . $dsgo_error_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<p class="dsgo-error__status">404</p>';
        echo '<h1 class="dsgo-error__title">' . esc_html($ctx['title']) . '</h1>';
        echo '<p class="dsgo-error__body">' . esc_html($ctx['body']) . '</p>';
        echo '<p class="dsgo-error__home">' . $ctx['home_link'] . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</main>';
        get_footer();
        $dsgo_rendered = (ob_get_length() ?: 0) > 0;
        ob_end_flush();
    } catch (\Throwable $e) {
        ob_end_clean();
        $dsgo_rendered = false;
    }
}

if (!$dsgo_rendered) {
    // Self-contained document. $lang is esc_attr'd by the caller; the style
    // blocks are static; home_link is built from esc_url/esc_html. Safe concat.
    // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '<!doctype html><html lang="' . $ctx['lang'] . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . esc_html($ctx['title']) . '</title>'
        . '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#1a1a1a;background:#fafaf7;margin:0}</style>'
        . $dsgo_error_style
        . '</head><body><main class="dsgo-error">'
        . '<p class="dsgo-error__status">404</p>'
        . '<h1 class="dsgo-error__title">' . esc_html($ctx['title']) . '</h1>'
        . '<p class="dsgo-error__body">' . esc_html($ctx['body']) . '</p>'
        . '<p class="dsgo-error__home">' . $ctx['home_link'] . '</p>'
        . '</main></body></html>';
    // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
}
