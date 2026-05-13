<?php
/**
 * Server-side render callback for the designsetgo-apps/embed block.
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

$app_id      = isset($attributes['appId']) ? sanitize_key((string) $attributes['appId']) : '';
$height      = isset($attributes['height']) ? max(100, min(2000, (int) $attributes['height'])) : 480;
$auto_resize = !empty($attributes['autoResize']);
$align_raw   = $attributes['align'] ?? '';
$align_class = is_string($align_raw) && $align_raw !== '' ? 'align' . sanitize_html_class($align_raw) : '';

if ($app_id === '') {
    echo \DSGo_Apps\IframeLoader::render_block_placeholder('No app selected.', $height, $align_class);
    return;
}

echo \DSGo_Apps\IframeLoader::render_block_embed($app_id, $height, $auto_resize, $align_class);
