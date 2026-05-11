<?php
/**
 * Iframe host page template.
 *
 * Note: the iframe DOES NOT carry a csp= attribute. CSP is injected at
 * install time into the bundle's HTML by Installer::inject_bridge_client.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
// Standalone iframe host document with its own <head>; wp_enqueue_script() can't
// register into a non-WP-themed response. Scripts are always loaded from WP core
// or this plugin's own /assets/ via esc_url().
// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
?>
<!doctype html>
<html lang="<?php echo esc_attr(str_replace('_', '-', get_locale())); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html($post->post_title . ' — ' . get_bloginfo('name')); ?></title>
<link rel="preload" as="script" href="<?php echo esc_url($wp_hooks_url); ?>">
<link rel="preload" as="script" href="<?php echo esc_url($wp_i18n_url); ?>">
<link rel="preload" as="script" href="<?php echo esc_url($wp_url_url); ?>">
<link rel="preload" as="script" href="<?php echo esc_url($api_fetch_url); ?>">
<link rel="preload" as="script" href="<?php echo esc_url($parent_bridge_url); ?>">
<link rel="prefetch" as="document" href="<?php echo esc_url($iframe_src); ?>">
<style>
:root { color-scheme: light dark; }
html, body { margin: 0; padding: 0; height: 100%; background: #fafaf7; color: #1a1a1a; }
@media (prefers-color-scheme: dark) {
  html, body { background: #0f0f10; color: #e8e8e6; }
}
.dsgo-host { position: relative; width: 100vw; height: 100vh; }
.dsgo-host iframe {
    border: 0; width: 100%; height: 100%; display: block;
    opacity: 0; transition: opacity .3s ease;
}
.dsgo-host.is-loaded iframe { opacity: 1; }
.dsgo-host__loader {
    position: absolute; inset: 0; display: grid; place-items: center;
    pointer-events: none; transition: opacity .3s ease;
}
.dsgo-host.is-loaded .dsgo-host__loader { opacity: 0; }
.dsgo-host__spinner {
    width: 28px; height: 28px; border-radius: 50%;
    border: 2px solid currentColor; border-top-color: transparent;
    opacity: .35; margin: 0 auto;
    animation: dsgo-spin 1s linear infinite;
}
.dsgo-host__label {
    margin-top: 1rem; text-align: center;
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    font-size: .8125rem; letter-spacing: .04em; opacity: .55;
}
@keyframes dsgo-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) {
    .dsgo-host__spinner { animation: none; }
    .dsgo-host iframe, .dsgo-host__loader { transition: none; }
}
</style>
<script>
window.wpApiSettings = { root: <?php echo wp_json_encode($rest_root); ?>, nonce: <?php echo wp_json_encode($nonce); ?> };
</script>
<script src="<?php echo esc_url($wp_hooks_url); ?>"></script>
<script src="<?php echo esc_url($wp_i18n_url); ?>"></script>
<script src="<?php echo esc_url($wp_url_url); ?>"></script>
<script src="<?php echo esc_url($api_fetch_url); ?>"></script>
<script>
(function () {
  var af = window.wp && window.wp.apiFetch;
  if (!af) return;
  af.use(af.createRootURLMiddleware(window.wpApiSettings.root));
  af.use(af.createNonceMiddleware(window.wpApiSettings.nonce));
})();
</script>
<script src="<?php echo esc_url($parent_bridge_url); ?>" defer></script>
</head>
<body>
<div class="dsgo-host" id="dsgo-host">
    <div class="dsgo-host__loader" aria-hidden="true">
        <div>
            <div class="dsgo-host__spinner"></div>
            <div class="dsgo-host__label"><?php echo esc_html($post->post_title); ?></div>
        </div>
    </div>
    <iframe
        src="<?php echo esc_url($iframe_src); ?>"
        sandbox="allow-scripts allow-forms allow-top-navigation-by-user-activation"
        title="<?php echo esc_attr(sprintf(
            /* translators: %s: app display name */
            __('%s — embedded application', 'designsetgo-apps'),
            $post->post_title,
        )); ?>"
        aria-label="<?php echo esc_attr($post->post_title); ?>"
        data-dsgo-embed-id="1"
        data-dsgo-app-id="<?php echo esc_attr($app_id); ?>"
        onload="this.parentNode.classList.add('is-loaded')"
    ></iframe>
    <script type="application/json" data-dsgo-embed-config="1">
        <?php echo wp_json_encode([
            'context'  => $context,
            'manifest' => $manifest_pub,
            'permMap'  => $perm_map,
            'nonce'    => $nonce,
            // Per-(user, app) storage nonce — see RestApi::permit_storage.
            'appNonce' => $app_nonce,
        ]); ?>
    </script>
</div>
</body>
</html>
