<?php
/**
 * Lite-side information about optional companion features.
 *
 * Keeps the free plugin's in-admin messaging minimal and informational. The
 * paid add-on can still replace these surfaces via filters, but Lite avoids
 * a dedicated marketing page or pricing table in wp-admin.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class ProUpsell {

    public static function register(): void {
        // Intentionally no top-level or submenu marketing page in Lite.
    }

    /**
     * Small companion-feature note rendered inside the apps-list page when the
     * cap is in force (i.e. a companion add-on is not lifting it). Kept
     * informational only: no pricing, trials, or dedicated marketing page in
     * wp-admin.
     */
    public static function render_apps_list_pro_card(): void {
        if (Installer::lite_app_cap() === null) {
            return;
        }

        $learn_more_url = (string) apply_filters(
            'dsgo_apps_pro_learn_more_url',
            'https://designsetgo.dev/pricing/'
        );
        ?>
        <aside class="dsgo-pro-hint" aria-label="<?php esc_attr_e('Optional companion features', 'designsetgo-apps'); ?>">
            <div class="dsgo-pro-hint__copy">
                <strong class="dsgo-pro-hint__eyebrow"><?php esc_html_e('Optional', 'designsetgo-apps'); ?></strong>
                <span class="dsgo-pro-hint__text">
                    <?php esc_html_e('A separate add-on can lift the app cap and add advanced authoring tools.', 'designsetgo-apps'); ?>
                </span>
            </div>
            <a class="dsgo-pro-hint__cta" href="<?php echo esc_url($learn_more_url); ?>" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('Learn more', 'designsetgo-apps'); ?>
            </a>
        </aside>
        <style>
            .dsgo-pro-hint {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 18px;
                box-sizing: border-box;
                max-width: 1100px;
                margin: 28px 0 0;
                padding: 14px 18px;
                background: linear-gradient(90deg, #fbf9f4 0%, #f5efe3 100%);
                border: 1px solid #e8e3da;
                border-radius: 10px;
                font-size: 14px;
                flex-wrap: wrap;
            }
            .dsgo-pro-hint__copy {
                display: flex;
                gap: 12px;
                align-items: center;
                color: #4a4744;
            }
            .dsgo-pro-hint__eyebrow {
                background: #6a665f;
                color: #fff;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 10px;
                letter-spacing: 0.1em;
                font-weight: 700;
            }
            .dsgo-pro-hint__text { color: #171615; }
            .dsgo-pro-hint__cta {
                color: #1f5b4a;
                font-weight: 500;
                text-decoration: none;
                white-space: nowrap;
            }
            .dsgo-pro-hint__cta:hover,
            .dsgo-pro-hint__cta:focus { text-decoration: underline; }
        </style>
        <?php
    }
}
