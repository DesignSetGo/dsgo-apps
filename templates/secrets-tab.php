<?php
/**
 * Per-app Secrets tab template. Rendered by AdminPage::render_secrets_tab().
 *
 * $ctx (declared by the caller via extract-or-inline-array; PHP keys read
 *       directly off $ctx — no extract() call):
 *   - app_id          string
 *   - app_name        string
 *   - secrets         array<int, array{alias:string, description:string}>
 *   - required        string[]
 *   - set_aliases     string[]   currently configured aliases (vault non-null)
 *   - test_endpoint   ?string    manifest.http.test_endpoint (or null)
 *   - nonce           string     wp_create_nonce('dsgo_apps_secret_nonce_'.$app_id)
 *   - just_installed  bool       set when admin landed here from the post-install redirect
 *   - sodium_ok       bool       Secret_Vault::is_available()
 *
 * The template emits a <table> of declared secrets + inline forms; the
 * companion secrets-tab.js wires the AJAX set / clear / test handlers.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

/** @var array{
 *   app_id:string,
 *   app_name:string,
 *   secrets:array<int, array{alias:string, description:string}>,
 *   required:string[],
 *   set_aliases:string[],
 *   test_endpoint:?string,
 *   nonce:string,
 *   just_installed:bool,
 *   sodium_ok:bool,
 * } $ctx */

defined('ABSPATH') || exit;

$ctx_set_aliases = array_flip($ctx['set_aliases']);
$ctx_required    = array_flip($ctx['required']);
$missing_required = array_diff($ctx['required'], $ctx['set_aliases']);
?>
<section class="dsgo-secrets"
         data-dsgo-secrets-app-id="<?php echo esc_attr($ctx['app_id']); ?>"
         data-dsgo-secrets-nonce="<?php echo esc_attr($ctx['nonce']); ?>"
         data-dsgo-secrets-ajax-url="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>">

    <?php if (!$ctx['sodium_ok']) : ?>
        <div class="notice notice-error inline">
            <p>
                <strong><?php esc_html_e('Sodium extension unavailable.', 'designsetgo-apps'); ?></strong>
                <?php esc_html_e('The HTTP proxy requires the PHP sodium extension to encrypt secrets at rest. Contact your host to enable it — without sodium, this app cannot use external services.', 'designsetgo-apps'); ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($ctx['just_installed'] && $missing_required !== []) : ?>
        <div class="notice notice-warning inline">
            <p>
                <strong><?php esc_html_e('This app needs credentials before it can run.', 'designsetgo-apps'); ?></strong>
                <?php
                printf(
                    /* translators: %s: comma-separated list of required secret aliases */
                    esc_html__('Required: %s', 'designsetgo-apps'),
                    '<code>' . esc_html(implode('</code>, <code>', $missing_required)) . '</code>'   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <header class="dsgo-secrets__header">
        <h2 class="dsgo-secrets__title"><?php esc_html_e('Secrets', 'designsetgo-apps'); ?></h2>
        <p class="dsgo-secrets__lede">
            <?php esc_html_e('Per-app encrypted credentials. Apps reference these by alias as {{ALIAS}} tokens in outbound HTTP requests; the iframe never sees a resolved value. Stored sodium-encrypted in wp_options with autoload off.', 'designsetgo-apps'); ?>
        </p>
    </header>

    <?php if ($ctx['secrets'] === []) : ?>
        <p class="dsgo-secrets__empty">
            <?php esc_html_e('This app declares no secrets.', 'designsetgo-apps'); ?>
        </p>
    <?php else : ?>
        <table class="widefat striped dsgo-secrets__table">
            <thead>
                <tr>
                    <th class="dsgo-secrets__col-alias"><?php esc_html_e('Alias', 'designsetgo-apps'); ?></th>
                    <th class="dsgo-secrets__col-desc"><?php esc_html_e('Description', 'designsetgo-apps'); ?></th>
                    <th class="dsgo-secrets__col-status"><?php esc_html_e('Status', 'designsetgo-apps'); ?></th>
                    <th class="dsgo-secrets__col-actions"><?php esc_html_e('Actions', 'designsetgo-apps'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ctx['secrets'] as $secret) :
                $alias    = (string) $secret['alias'];
                $desc     = (string) $secret['description'];
                $is_set   = isset($ctx_set_aliases[$alias]);
                $required = isset($ctx_required[$alias]);
                ?>
                <tr class="dsgo-secrets__row" data-dsgo-secret-alias="<?php echo esc_attr($alias); ?>">
                    <td class="dsgo-secrets__alias">
                        <code><?php echo esc_html($alias); ?></code>
                        <?php if ($required) : ?>
                            <span class="dsgo-secrets__required" title="<?php esc_attr_e('Required by manifest', 'designsetgo-apps'); ?>"><?php esc_html_e('required', 'designsetgo-apps'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="dsgo-secrets__desc"><?php echo esc_html($desc); ?></td>
                    <td class="dsgo-secrets__status">
                        <span class="dsgo-secrets__badge dsgo-secrets__badge--<?php echo $is_set ? 'set' : 'unset'; ?>"
                              data-dsgo-secret-status>
                            <?php echo $is_set ? esc_html__('Set', 'designsetgo-apps') : esc_html__('Not set', 'designsetgo-apps'); ?>
                        </span>
                    </td>
                    <td class="dsgo-secrets__actions">
                        <button type="button" class="button dsgo-secrets__edit" data-dsgo-secret-edit>
                            <?php echo $is_set ? esc_html__('Replace', 'designsetgo-apps') : esc_html__('Set', 'designsetgo-apps'); ?>
                        </button>
                        <button type="button" class="button-link-delete dsgo-secrets__clear" data-dsgo-secret-clear
                                <?php disabled(!$is_set); ?>>
                            <?php esc_html_e('Clear', 'designsetgo-apps'); ?>
                        </button>
                    </td>
                </tr>
                <tr class="dsgo-secrets__form-row" data-dsgo-secret-form hidden>
                    <td colspan="4">
                        <form class="dsgo-secrets__form" data-dsgo-secret-form-el>
                            <label class="dsgo-secrets__label" for="dsgo-secret-input-<?php echo esc_attr($alias); ?>">
                                <?php
                                printf(
                                    /* translators: %s: secret alias */
                                    esc_html__('Value for %s', 'designsetgo-apps'),
                                    '<code>' . esc_html($alias) . '</code>'   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                );
                                ?>
                            </label>
                            <div class="dsgo-secrets__input-wrap">
                                <input type="password"
                                       id="dsgo-secret-input-<?php echo esc_attr($alias); ?>"
                                       class="regular-text dsgo-secrets__input"
                                       autocomplete="new-password"
                                       spellcheck="false"
                                       required
                                       data-dsgo-secret-input>
                                <button type="button" class="button dsgo-secrets__toggle-visibility"
                                        data-dsgo-secret-toggle
                                        aria-label="<?php esc_attr_e('Show / hide value', 'designsetgo-apps'); ?>">
                                    <?php esc_html_e('Show', 'designsetgo-apps'); ?>
                                </button>
                            </div>
                            <p class="dsgo-secrets__hint">
                                <?php esc_html_e('Stored encrypted; never shown again after save. To rotate the credential, set a new value here.', 'designsetgo-apps'); ?>
                            </p>
                            <div class="dsgo-secrets__form-actions">
                                <button type="submit" class="button button-primary"><?php esc_html_e('Save', 'designsetgo-apps'); ?></button>
                                <button type="button" class="button" data-dsgo-secret-cancel><?php esc_html_e('Cancel', 'designsetgo-apps'); ?></button>
                                <span class="dsgo-secrets__form-error" data-dsgo-secret-error role="alert"></span>
                            </div>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($ctx['test_endpoint'] !== null) : ?>
        <section class="dsgo-secrets__test">
            <h3 class="dsgo-secrets__test-title"><?php esc_html_e('Test connection', 'designsetgo-apps'); ?></h3>
            <p class="dsgo-secrets__test-lede">
                <?php
                printf(
                    /* translators: %s: test endpoint URL */
                    esc_html__('Send a real GET to %s through the proxy with the values you just configured. Useful for verifying allowlist + secret wiring before pointing real traffic at the app.', 'designsetgo-apps'),
                    '<code>' . esc_html($ctx['test_endpoint']) . '</code>'   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                );
                ?>
            </p>
            <button type="button" class="button" data-dsgo-secret-test>
                <?php esc_html_e('Run test fetch', 'designsetgo-apps'); ?>
            </button>
            <pre class="dsgo-secrets__test-output" data-dsgo-secret-test-output hidden></pre>
        </section>
    <?php endif; ?>

    <div class="dsgo-secrets__toast" data-dsgo-secrets-toast role="status" aria-live="polite" hidden></div>
</section>
