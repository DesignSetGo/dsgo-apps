<?php
/**
 * Lite-side Pro upsell surfaces.
 *
 * Registers an "AI app builder" submenu under DSGo Apps that always appears.
 * For Lite-only users, clicking it lands on an in-admin upgrade page that
 * explains what Riff (the Pro AI builder) does and links to the trial. When
 * Pro is installed, Pro removes this stub via remove_submenu_page() and
 * registers the real Riff page in its place — see Pro_Plugin::register_hooks.
 *
 * Also provides a small "What's in Pro?" card renderer that the apps-list
 * page calls when the cap is at the default (i.e. Pro is not lifting it).
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class ProUpsell {

    public const MENU_SLUG = 'designsetgo-apps-builder';

    public static function register(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 11);
    }

    public static function register_menu(): void {
        // Inline-styled "PRO" badge in the menu label so the user can see at a
        // glance that this surface is gated. Pro removes this stub via
        // remove_submenu_page() before this label ever renders for licensed
        // users — they get the real Riff submenu instead, with the same
        // "Build with AI" verb.
        $label = __('Build with AI', 'designsetgo-apps');
        $badge = ' <span style="background:#1f5b4a;color:#fff;padding:1px 7px;border-radius:10px;font-size:9px;font-weight:600;letter-spacing:0.06em;vertical-align:middle;margin-left:4px;">PRO</span>';

        add_submenu_page(
            AdminPage::MENU_SLUG,
            __('Build with AI · Riff', 'designsetgo-apps'),
            $label . $badge,
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render'],
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) return;

        // URLs are filterable so DesignSetGo Apps Pro (when installed) can
        // override with the real Freemius checkout URL via dsgo()->checkout_url().
        // Until Pro is installed, both default to the website pricing page —
        // that page hosts the Freemius checkout iframe.
        $pricing_url = (string) apply_filters('dsgo_apps_pro_pricing_url', 'https://designsetgo.dev/pricing/');
        $trial_url   = (string) apply_filters('dsgo_apps_pro_trial_url', $pricing_url);
        ?>
        <div class="wrap dsgo-upsell">
            <header class="dsgo-upsell__head">
                <p class="dsgo-upsell__eyebrow"><?php esc_html_e('Pro feature · Riff', 'designsetgo-apps'); ?></p>
                <h1 class="dsgo-upsell__title"><?php esc_html_e('Meet Riff — build sandboxed apps with AI right inside WordPress.', 'designsetgo-apps'); ?></h1>
                <p class="dsgo-upsell__lede">
                    <?php esc_html_e('Riff is the in-admin AI app builder bundled with DesignSetGo Apps Pro. Tell Riff what you want — a calculator, a quiz, a custom landing page, a multi-page mini-site — and it writes the code, runs the validator, and ships it as a sandboxed app on your site. Iterate by chatting; redeploy in seconds; never leave WP-admin.', 'designsetgo-apps'); ?>
                </p>
                <div class="dsgo-upsell__cta-row">
                    <a class="button button-primary button-hero" href="<?php echo esc_url($trial_url); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Start 14-day free trial', 'designsetgo-apps'); ?>
                    </a>
                    <a class="button button-hero" href="<?php echo esc_url($pricing_url); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('See full pricing', 'designsetgo-apps'); ?>
                    </a>
                    <span class="dsgo-upsell__cta-hint"><?php esc_html_e('No credit card to start.', 'designsetgo-apps'); ?></span>
                </div>
            </header>

            <section class="dsgo-upsell__pillars" aria-label="<?php esc_attr_e('What Riff does', 'designsetgo-apps'); ?>">
                <article class="dsgo-upsell__pillar">
                    <span class="dsgo-upsell__pillar-num">01</span>
                    <h2><?php esc_html_e('Chat. Ship. Repeat.', 'designsetgo-apps'); ?></h2>
                    <p><?php esc_html_e('Tell Riff what you want in plain English. It writes the HTML/CSS/JS, validates it against the sandbox, and deploys — all without you opening a code editor.', 'designsetgo-apps'); ?></p>
                </article>
                <article class="dsgo-upsell__pillar">
                    <span class="dsgo-upsell__pillar-num">02</span>
                    <h2><?php esc_html_e('Iterate in seconds', 'designsetgo-apps'); ?></h2>
                    <p><?php esc_html_e('"Make the headline bigger" → done. "Add a contact form that emails me" → done. Each turn produces a runnable draft you can deploy with one click or roll back if you don\'t like it.', 'designsetgo-apps'); ?></p>
                </article>
                <article class="dsgo-upsell__pillar">
                    <span class="dsgo-upsell__pillar-num">03</span>
                    <h2><?php esc_html_e('Run as many apps as you want', 'designsetgo-apps'); ?></h2>
                    <p><?php esc_html_e('Pro removes the Free version\'s 1-app-per-site limit. Build a calculator, a portal, and a landing page on the same site — they all run sandboxed alongside your existing WordPress pages.', 'designsetgo-apps'); ?></p>
                </article>
            </section>

            <section class="dsgo-upsell__plans">
                <h2 class="dsgo-upsell__plans-title"><?php esc_html_e('What it costs', 'designsetgo-apps'); ?></h2>
                <ul class="dsgo-upsell__plans-list">
                    <li>
                        <strong><?php esc_html_e('Personal — $99/year', 'designsetgo-apps'); ?></strong>
                        <span><?php esc_html_e('3 sites · unlimited apps · Riff · CLI deploy', 'designsetgo-apps'); ?></span>
                    </li>
                    <li class="dsgo-upsell__plans-featured">
                        <strong><?php esc_html_e('Plus — $199/year', 'designsetgo-apps'); ?></strong>
                        <span><?php esc_html_e('10 sites · everything in Personal · most popular', 'designsetgo-apps'); ?></span>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Agency — $399/year', 'designsetgo-apps'); ?></strong>
                        <span><?php esc_html_e('Unlimited sites · push to many sites at once · white-label', 'designsetgo-apps'); ?></span>
                    </li>
                </ul>
                <p class="dsgo-upsell__plans-note">
                    <?php esc_html_e('Annual billing only. 14-day free trial on every plan, no credit card to start. 14-day money-back guarantee. Cancel anytime.', 'designsetgo-apps'); ?>
                </p>
            </section>

            <footer class="dsgo-upsell__footer">
                <p>
                    <strong><?php esc_html_e('About AI costs (no surprise bill):', 'designsetgo-apps'); ?></strong>
                    <?php esc_html_e('Riff does not run on DesignSetGo\'s AI; it runs on yours. WordPress 7.0 ships with a built-in Connectors API that lets you plug your own Anthropic, OpenAI, or Google account into wp-admin (Settings → Connectors). Riff calls whichever Connector you have set up, so every prompt is billed by your provider at provider rates. DesignSetGo never holds your API keys and never marks up tokens. Pro covers the authoring tools (Riff, CLI deploy, multi-site, white-label), not the AI calls themselves.', 'designsetgo-apps'); ?>
                </p>
            </footer>
        </div>

        <style>
            .dsgo-upsell {
                max-width: 1100px;
                margin: 24px auto 40px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                color: #171615;
            }
            .dsgo-upsell__head {
                background: linear-gradient(180deg, #ffffff 0%, #f5efe3 100%);
                border: 1px solid #e8e3da;
                border-radius: 14px;
                padding: 44px 48px 40px;
                margin-bottom: 24px;
            }
            .dsgo-upsell__eyebrow {
                margin: 0 0 14px;
                font-size: 11px;
                letter-spacing: 0.22em;
                text-transform: uppercase;
                color: #1f5b4a;
                font-weight: 600;
            }
            .dsgo-upsell__title {
                font-family: "Iowan Old Style", "Source Serif Pro", "Apple Garamond", "Baskerville", Georgia, "Times New Roman", serif;
                font-size: clamp(28px, 3.4vw, 40px);
                line-height: 1.1;
                font-weight: 400;
                letter-spacing: -0.02em;
                margin: 0 0 18px;
                max-width: 24ch;
                color: #171615;
                padding: 0;
            }
            .dsgo-upsell__lede {
                font-size: 16px;
                line-height: 1.6;
                color: #4a4744;
                max-width: 64ch;
                margin: 0 0 28px;
            }
            .dsgo-upsell__cta-row {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                align-items: center;
            }
            .dsgo-upsell__cta-row .button-primary {
                background: #1f5b4a;
                border-color: #1f5b4a;
                box-shadow: none;
                text-shadow: none;
            }
            .dsgo-upsell__cta-row .button-primary:hover,
            .dsgo-upsell__cta-row .button-primary:focus {
                background: #174433;
                border-color: #174433;
            }
            .dsgo-upsell__cta-hint {
                color: #8a8580;
                font-size: 13px;
                margin-left: 4px;
            }
            .dsgo-upsell__pillars {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 16px;
                margin-bottom: 24px;
            }
            @media (max-width: 900px) {
                .dsgo-upsell__pillars { grid-template-columns: 1fr; }
            }
            .dsgo-upsell__pillar {
                background: #ffffff;
                border: 1px solid #e8e3da;
                border-radius: 14px;
                padding: 28px 28px 24px;
            }
            .dsgo-upsell__pillar-num {
                display: inline-block;
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 11px;
                color: #8a8580;
                margin-bottom: 14px;
                letter-spacing: 0.08em;
            }
            .dsgo-upsell__pillar h2 {
                font-family: "Iowan Old Style", "Source Serif Pro", Georgia, serif;
                font-size: 21px;
                line-height: 1.2;
                font-weight: 400;
                margin: 0 0 10px;
                padding: 0;
                color: #171615;
            }
            .dsgo-upsell__pillar p {
                margin: 0;
                color: #4a4744;
                font-size: 14.5px;
                line-height: 1.55;
            }
            .dsgo-upsell__plans {
                background: #ffffff;
                border: 1px solid #e8e3da;
                border-radius: 14px;
                padding: 28px 32px;
                margin-bottom: 20px;
            }
            .dsgo-upsell__plans-title {
                font-family: "Iowan Old Style", "Source Serif Pro", Georgia, serif;
                font-size: 22px;
                font-weight: 400;
                margin: 0 0 16px;
                padding: 0;
                color: #171615;
            }
            .dsgo-upsell__plans-list {
                margin: 0 0 14px;
                padding: 0;
                list-style: none;
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .dsgo-upsell__plans-list li {
                display: flex;
                gap: 16px;
                padding: 12px 14px;
                border-radius: 8px;
                background: #fbf9f4;
                font-size: 14px;
                color: #4a4744;
                align-items: baseline;
                flex-wrap: wrap;
            }
            .dsgo-upsell__plans-list li strong {
                color: #171615;
                min-width: 180px;
                font-size: 14.5px;
            }
            .dsgo-upsell__plans-featured {
                outline: 1px solid #1f5b4a;
                outline-offset: -1px;
            }
            .dsgo-upsell__plans-note {
                margin: 0;
                color: #8a8580;
                font-size: 12.5px;
                line-height: 1.5;
            }
            .dsgo-upsell__footer {
                background: transparent;
                padding: 20px 8px 0;
            }
            .dsgo-upsell__footer p {
                margin: 0;
                color: #8a8580;
                font-size: 13px;
                line-height: 1.6;
                max-width: 90ch;
            }
            .dsgo-upsell__footer strong { color: #4a4744; }
        </style>
        <?php
    }

    /**
     * Small "What's in Pro?" card rendered inside the apps-list page when the
     * cap is in force (i.e. Pro is not lifting it). Hooks `dsgo_apps_admin_actions`
     * is used by Pro to inject "Generate with AI"; here we don't want to fight
     * with that. This card renders directly from the apps-list page header
     * via a separate explicit call from AdminPage::render. Kept small and
     * informational — not a popup, not blocking, not animated.
     */
    public static function render_apps_list_pro_card(): void {
        // Only show when the Lite cap is genuinely in force. If Pro filtered it
        // off, the user is already a Pro customer and doesn't need the pitch.
        if (Installer::lite_app_cap() === null) return;

        // See class-admin-page.php — filterable so Pro can swap in its own
        // admin pricing page (Lite's stub is removed once Pro is active).
        $upgrade_url = (string) apply_filters(
            'dsgo_apps_pro_upgrade_url',
            admin_url('admin.php?page=' . self::MENU_SLUG),
        );
        ?>
        <aside class="dsgo-pro-hint" aria-label="<?php esc_attr_e('What Pro adds', 'designsetgo-apps'); ?>">
            <div class="dsgo-pro-hint__copy">
                <strong class="dsgo-pro-hint__eyebrow"><?php esc_html_e('PRO', 'designsetgo-apps'); ?></strong>
                <span class="dsgo-pro-hint__text">
                    <?php esc_html_e('Want unlimited apps + Riff, the in-admin AI app builder?', 'designsetgo-apps'); ?>
                </span>
            </div>
            <a class="dsgo-pro-hint__cta" href="<?php echo esc_url($upgrade_url); ?>">
                <?php esc_html_e('Meet Riff →', 'designsetgo-apps'); ?>
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
                background: #1f5b4a;
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
