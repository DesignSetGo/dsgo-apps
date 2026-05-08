<?php
/**
 * Server-side render callback for the dsgo-apps/embed block.
 *
 * Block-mode embeds render directly into the parent post's DOM. The iframe
 * loads the bundle URL with `sandbox="allow-scripts"` (opaque origin), and
 * parent-bridge.js runs once on the parent post — same origin as WP, so
 * wp.apiFetch passes the REST nonce check via the session cookie. Multiple
 * embeds on one page share that single bridge instance and route by
 * `data-dsgo-embed-id`. No nested WP request per embed.
 *
 * @var array $attributes
 * @var string $content
 * @var WP_Block $block
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$dsgo_app_id      = isset($attributes['appId']) ? sanitize_key((string) $attributes['appId']) : '';
$dsgo_height      = isset($attributes['height']) ? max(100, min(2000, (int) $attributes['height'])) : 480;
$dsgo_auto_resize = !empty($attributes['autoResize']);
$dsgo_align_raw   = $attributes['align'] ?? '';
$dsgo_align_class = is_string($dsgo_align_raw) && $dsgo_align_raw !== '' ? 'align' . sanitize_html_class($dsgo_align_raw) : '';

if ($dsgo_app_id === '') {
    // Output is a static iframe wrapper rendered by IframeLoader; height is int, align_class is sanitize_html_class().
    echo \DSGo_Apps\IframeLoader::render_block_placeholder('No app selected.', $dsgo_height, $dsgo_align_class); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    return;
}

// Output is a static iframe wrapper rendered by IframeLoader; all dynamic parts are pre-escaped or numeric.
echo \DSGo_Apps\IframeLoader::render_block_embed($dsgo_app_id, $dsgo_height, $dsgo_auto_resize, $dsgo_align_class); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
