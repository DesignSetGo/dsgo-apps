<?php
/**
 * Install-dialog consent panel body. Rendered by Installer::render_consent_html().
 *
 * Emits one row per active bucket (via Bucket_Renderer), the
 * "Previously approved (unchanged)" line, the "Removed" section (update flow
 * only), and the passive storage footer. The caller captures the output via
 * an output buffer and returns it as PreviewResult::$rendered_html.
 *
 * $ctx (PHP keys read directly off $ctx — no extract() call):
 *   - manifest             Manifest
 *   - previously_approved  ?string[]   bucket values approved at the prior install, or null on first install
 *   - active_buckets       Bucket[]    active bucket objects for this manifest
 *   - active_values        string[]    active bucket values (string form of $active_buckets)
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

/** @var array{
 *   manifest:\DSGo_Apps\Manifest,
 *   previously_approved:?array<int,string>,
 *   active_buckets:array<int,\DSGo_Apps\Bucket>,
 *   active_values:array<int,string>,
 * } $ctx */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Template-scope locals (passed in by Installer::render_consent_html), not plugin globals.
?>
<div class="dsgo-install-dialog">
    <?php
    // Active bucket rows. New buckets get the dsgo-bucket--new marker.
    foreach ($ctx['active_buckets'] as $bucket) {
        // Bucket_Renderer::render_row returns pre-escaped markup.
        echo \DSGo_Apps\Bucket_Renderer::render_row($bucket, $ctx['manifest'], $ctx['previously_approved']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    // Previously approved + unchanged collapse (update flow only).
    if ($ctx['previously_approved'] !== null) {
        $unchanged = array_values(array_intersect($ctx['previously_approved'], $ctx['active_values']));
        if ($unchanged !== []) {
            ?>
            <p class="dsgo-install-dialog__unchanged"><?php
                echo esc_html__('Previously approved (unchanged):', 'designsetgo-apps') . ' ';
                echo esc_html(implode(', ', $unchanged));
            ?></p>
            <?php
        }
        $removed = array_values(array_diff($ctx['previously_approved'], $ctx['active_values']));
        if ($removed !== []) {
            ?>
            <section class="dsgo-install-dialog__removed">
                <h4><?php esc_html_e('Removed (no action required)', 'designsetgo-apps'); ?></h4>
                <ul>
                    <?php foreach ($removed as $r) : ?>
                        <li><code><?php echo esc_html($r); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php
        }
    }
    ?>
    <p class="dsgo-install-dialog__storage-note"><?php
        // Passive storage footer (always shown — storage has no bucket).
        esc_html_e('This app uses per-app and per-user storage to persist state.', 'designsetgo-apps');
    ?></p>
    <?php
    // Informational runtime-capability notes. WASM and Web Workers are
    // client-side compute with the same threat model as the JS the sandbox
    // already runs, so they are NOT permission buckets — but the admin should
    // still see that binary / background code is present.
    if ($ctx['manifest']->raw_field('runtime.uses_wasm') === true) {
        ?>
        <p class="dsgo-install-dialog__runtime-note"><?php esc_html_e('Uses WebAssembly modules.', 'designsetgo-apps'); ?></p>
        <?php
    }
    if ($ctx['manifest']->raw_field('runtime.uses_workers') === true) {
        ?>
        <p class="dsgo-install-dialog__runtime-note"><?php esc_html_e('Uses Web Workers for background processing.', 'designsetgo-apps'); ?></p>
        <?php
    }
    ?>
</div>
