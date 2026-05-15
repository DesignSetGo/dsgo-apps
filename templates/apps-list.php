<?php
/**
 * Apps-list admin surface (Settings → DSGo Apps top-level page, no ?app_id).
 * Rendered by AdminPage::render(). Holds the hero, the installed-apps list
 * card, the install card (starter / artifact upload / bundle upload / AI
 * prompt builder / CLI), and the JS row/consent/success <template> blocks.
 *
 * $ctx (PHP keys read directly off $ctx — no extract() call):
 *   - initial_state  string  best-effort layout class so the page doesn't
 *                            flicker before the REST fetch resolves
 *                            ('dsgo-admin--has-apps' | 'dsgo-admin--empty')
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

/** @var array{initial_state:string} $ctx */

defined('ABSPATH') || exit;

use DSGo_Apps\AiContextPack;
use DSGo_Apps\PostType;
use DSGo_Apps\Settings;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// Template-scope locals (passed in by AdminPage::render), not plugin globals.
?>
        <div class="dsgo-admin <?php echo esc_attr($ctx['initial_state']); ?>" data-dsgo-admin>
            <header class="dsgo-admin__hero">
                <p class="dsgo-admin__eyebrow"><?php
                    /**
                     * Filter the brand-name eyebrow on the apps-list admin page.
                     * Pro's white-label feature (Agency tier) replaces this with a
                     * customer-configured brand name; free returns the default.
                     */
                    echo esc_html((string) apply_filters('dsgo_apps_brand_name', __('DesignSetGo', 'designsetgo-apps')));
                ?></p>
                <h1 class="dsgo-admin__title">
                    <?php esc_html_e('Apps', 'designsetgo-apps'); ?>
                </h1>
                <p class="dsgo-admin__lede">
                    <?php esc_html_e('Run sandboxed apps on your WordPress site, from single-block widgets to full multi-page experiences, wired to your posts, pages, users, and abilities through a permissioned bridge. Drop in a bundle, or deploy from your terminal.', 'designsetgo-apps'); ?>
                </p>
                <?php
                /**
                 * Single extension point for Pro (or any third party) to inject
                 * page-level actions into the apps-list admin surface. Free
                 * intentionally has zero references to Pro — Pro registers a
                 * listener on this hook from its own bootstrap.
                 *
                 * @param array{page:string} $context
                 */
                do_action('dsgo_apps_admin_actions', ['page' => 'apps-list']);
                ?>
            </header>

            <div class="dsgo-admin__layout">
                <section class="dsgo-card dsgo-card--list" aria-labelledby="dsgo-list-heading">
                    <header class="dsgo-card__header dsgo-card__header--list">
                        <div class="dsgo-card__header-text">
                            <h2 id="dsgo-list-heading" class="dsgo-card__title"><?php esc_html_e('Installed apps', 'designsetgo-apps'); ?></h2>
                            <p class="dsgo-card__subtitle" data-dsgo-list-subtitle><?php esc_html_e('Loading…', 'designsetgo-apps'); ?></p>
                        </div>
                        <button type="button" class="dsgo-install-toggle" data-dsgo-install-toggle
                                aria-expanded="false" aria-controls="dsgo-install-panel">
                            <span aria-hidden="true" class="dsgo-install-toggle__icon">
                                <svg viewBox="0 0 12 12" width="12" height="12" fill="none"
                                     stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                                    <path d="M6 1.5v9M1.5 6h9" />
                                </svg>
                            </span>
                            <span class="dsgo-install-toggle__label"><?php esc_html_e('Install another app', 'designsetgo-apps'); ?></span>
                        </button>
                    </header>
                    <ul class="dsgo-applist" data-dsgo-list role="list" aria-busy="true">
                        <li class="dsgo-applist__skel"></li>
                        <li class="dsgo-applist__skel"></li>
                    </ul>
                </section>

                <section class="dsgo-card dsgo-card--install" id="dsgo-install-panel"
                         data-dsgo-install-panel aria-labelledby="dsgo-install-heading">
                    <header class="dsgo-card__header">
                        <h2 id="dsgo-install-heading" class="dsgo-card__title"><?php esc_html_e('Install an app', 'designsetgo-apps'); ?></h2>
                        <p class="dsgo-card__subtitle" data-dsgo-card-subtitle><?php esc_html_e('Try the starter, upload an AI artifact, or bring a packaged bundle from your editor.', 'designsetgo-apps'); ?></p>
                    </header>

                    <div class="dsgo-first-run" aria-label="<?php esc_attr_e('First app options', 'designsetgo-apps'); ?>">
                        <article class="dsgo-first-run__card dsgo-first-run__card--primary">
                            <p class="dsgo-first-run__eyebrow"><?php esc_html_e('Fastest first win', 'designsetgo-apps'); ?></p>
                            <h3><?php esc_html_e('Try the starter app', 'designsetgo-apps'); ?></h3>
                            <p><?php esc_html_e('Install a guided multi-page tour with live bridge examples. No file, terminal, or AI prompt needed.', 'designsetgo-apps'); ?></p>
                            <button type="button" class="button button-primary" data-dsgo-quick-action="starter" data-dsgo-starter-install>
                                <?php esc_html_e('Install starter app', 'designsetgo-apps'); ?>
                            </button>
                        </article>
                        <article class="dsgo-first-run__card">
                            <p class="dsgo-first-run__eyebrow"><?php esc_html_e('Have an AI page?', 'designsetgo-apps'); ?></p>
                            <h3><?php esc_html_e('Upload an artifact', 'designsetgo-apps'); ?></h3>
                            <p><?php esc_html_e('Drop a saved Claude, ChatGPT, v0, or static HTML/zip export and DSGo wraps it safely.', 'designsetgo-apps'); ?></p>
                            <button type="button" class="button" data-dsgo-quick-action="artifact">
                                <?php esc_html_e('Choose artifact', 'designsetgo-apps'); ?>
                            </button>
                        </article>
                        <article class="dsgo-first-run__card">
                            <p class="dsgo-first-run__eyebrow"><?php esc_html_e('Need something custom?', 'designsetgo-apps'); ?></p>
                            <h3><?php esc_html_e('Build with AI', 'designsetgo-apps'); ?></h3>
                            <p><?php esc_html_e('Generate a bridge-aware prompt for your AI chat, then upload the HTML it creates.', 'designsetgo-apps'); ?></p>
                            <button type="button" class="button" data-dsgo-quick-action="ai">
                                <?php esc_html_e('Open prompt builder', 'designsetgo-apps'); ?>
                            </button>
                        </article>
                    </div>

                    <div class="dsgo-tabs" role="tablist" aria-label="<?php esc_attr_e('Install method', 'designsetgo-apps'); ?>">
                        <button type="button" class="dsgo-tab is-active" role="tab"
                                aria-selected="true" aria-controls="dsgo-panel-html"
                                id="dsgo-tab-html" data-dsgo-tab="html">
                            <?php esc_html_e('Upload artifact', 'designsetgo-apps'); ?>
                        </button>
                        <button type="button" class="dsgo-tab" role="tab"
                                aria-selected="false" aria-controls="dsgo-panel-upload"
                                id="dsgo-tab-upload" data-dsgo-tab="upload">
                            <?php esc_html_e('Upload bundle', 'designsetgo-apps'); ?>
                        </button>
                    </div>

                    <div id="dsgo-panel-upload" role="tabpanel" aria-labelledby="dsgo-tab-upload"
                         class="dsgo-panel" data-dsgo-panel="upload" hidden>
                        <div class="dsgo-dropzone" data-dsgo-dropzone tabindex="0" role="button"
                             aria-label="<?php esc_attr_e('Choose a bundle zip to install', 'designsetgo-apps'); ?>">
                            <div class="dsgo-dropzone__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 16V4" />
                                    <path d="m6 10 6-6 6 6" />
                                    <path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
                                </svg>
                            </div>
                            <div class="dsgo-dropzone__copy">
                                <p class="dsgo-dropzone__primary"><?php esc_html_e('Drop a bundle here', 'designsetgo-apps'); ?></p>
                                <p class="dsgo-dropzone__secondary">
                                    <?php esc_html_e('or', 'designsetgo-apps'); ?>
                                    <button type="button" class="dsgo-link" data-dsgo-pick><?php esc_html_e('choose a file', 'designsetgo-apps'); ?></button>
                                </p>
                            </div>
                            <input type="file" accept=".zip,application/zip" data-dsgo-input hidden>
                        </div>
                    </div>

                    <div id="dsgo-panel-html" role="tabpanel" aria-labelledby="dsgo-tab-html"
                         class="dsgo-panel" data-dsgo-panel="html">
                        <p class="dsgo-panel__lede">
                            <?php
                            echo wp_kses(
                                __('Drop a single <code>.html</code> file (a Claude Artifact, a single-file game, or any standalone page) or a <code>.zip</code> of a static export (a Claude Design bundle, a built static site without a manifest) and we’ll wrap it in a sandboxed app.', 'designsetgo-apps'),
                                ['code' => []],
                            );
                            ?>
                        </p>

                        <div class="dsgo-dropzone" data-dsgo-html-dropzone tabindex="0" role="button"
                             aria-label="<?php esc_attr_e('Choose an HTML or zip file to install', 'designsetgo-apps'); ?>">
                            <div class="dsgo-dropzone__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 3v5h5" />
                                    <path d="M19 8v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7z" />
                                </svg>
                            </div>
                            <div class="dsgo-dropzone__copy">
                                <p class="dsgo-dropzone__primary" data-dsgo-html-primary><?php esc_html_e('Drop an HTML or zip file here', 'designsetgo-apps'); ?></p>
                                <p class="dsgo-dropzone__secondary">
                                    <?php esc_html_e('or', 'designsetgo-apps'); ?>
                                    <button type="button" class="dsgo-link" data-dsgo-html-pick><?php esc_html_e('choose a file', 'designsetgo-apps'); ?></button>
                                </p>
                            </div>
                            <input type="file" accept=".html,.htm,text/html,.zip,application/zip" data-dsgo-html-input hidden>
                        </div>

                        <div class="dsgo-field-row">
                            <div class="dsgo-field">
                                <label for="dsgo-id-input" class="dsgo-field__label"><?php esc_html_e('App ID', 'designsetgo-apps'); ?></label>
                                <input type="text" class="dsgo-input" id="dsgo-id-input" data-dsgo-id
                                       autocomplete="off" maxlength="64">
                                <p class="dsgo-field__hint"><?php esc_html_e('Lowercase letters, numbers, hyphens. 3–64 chars.', 'designsetgo-apps'); ?></p>
                            </div>
                            <div class="dsgo-field">
                                <label for="dsgo-name-input" class="dsgo-field__label"><?php esc_html_e('Display name', 'designsetgo-apps'); ?></label>
                                <input type="text" class="dsgo-input" id="dsgo-name-input" data-dsgo-name
                                       autocomplete="off" maxlength="80">
                                <p class="dsgo-field__hint"><?php esc_html_e('Shown in the apps list and tab title.', 'designsetgo-apps'); ?></p>
                            </div>
                        </div>

                        <div class="dsgo-actions">
                            <button type="button" class="button button-primary" data-dsgo-html-submit disabled>
                                <?php esc_html_e('Install artifact', 'designsetgo-apps'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="dsgo-status" data-dsgo-status hidden>
                        <div class="dsgo-status__bar"><div class="dsgo-status__fill" data-dsgo-progress></div></div>
                        <p class="dsgo-status__text" data-dsgo-status-text></p>
                    </div>

                    <details class="dsgo-altpath dsgo-altpath--ai" data-dsgo-ai-details>
                        <summary><?php esc_html_e('Or build one with Claude, ChatGPT, or any AI chat', 'designsetgo-apps'); ?></summary>
                        <p class="dsgo-altpath__note">
                            <?php
                            echo wp_kses(
                                __('Copy the prompt below into <strong>Claude</strong>, <strong>ChatGPT</strong>, or any AI chat. It briefs the model on this site\'s bridge API, available abilities, and connector &mdash; so the artifact it produces actually works when you upload it.', 'designsetgo-apps'),
                                ['strong' => []],
                            );
                            ?>
                        </p>
                        <div class="dsgo-ai-prompt">
                            <fieldset class="dsgo-ai-perms" data-dsgo-ai-perms>
                                <legend class="dsgo-ai-perms__legend">
                                    <?php esc_html_e('Capabilities the app should use', 'designsetgo-apps'); ?>
                                    <span class="dsgo-ai-perms__hint"><?php esc_html_e('checked items get their bridge methods + docs added to the prompt', 'designsetgo-apps'); ?></span>
                                </legend>
                                <div class="dsgo-ai-perms__grid">
                                    <?php
                                    $perm_labels = AiContextPack::permission_labels();
                                    $defaults    = AiContextPack::default_permissions();
                                    foreach (AiContextPack::all_permissions() as $perm):
                                        $is_default = in_array($perm, $defaults, true);
                                        $info       = $perm_labels[$perm] ?? ['label' => $perm, 'help' => ''];
                                    ?>
                                        <label class="dsgo-ai-perms__option">
                                            <input type="checkbox"
                                                   data-dsgo-ai-perm
                                                   value="<?php echo esc_attr($perm); ?>"
                                                   <?php checked($is_default); ?>>
                                            <span class="dsgo-ai-perms__name"><?php echo esc_html($info['label']); ?></span>
                                            <span class="dsgo-ai-perms__help"><?php echo esc_html($info['help']); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="dsgo-ai-perms__note">
                                    <?php esc_html_e('Storage (storage.app, storage.user), bridge.ping, and resize requests need no permission and are always included.', 'designsetgo-apps'); ?>
                                </p>
                            </fieldset>
                            <div class="dsgo-ai-prompt__head">
                                <span class="dsgo-ai-prompt__label"><?php esc_html_e('AI prompt — copy & paste', 'designsetgo-apps'); ?></span>
                                <button type="button" class="button dsgo-ai-prompt__copy" data-dsgo-ai-copy
                                        aria-live="polite">
                                    <?php esc_html_e('Copy prompt', 'designsetgo-apps'); ?>
                                </button>
                            </div>
                            <textarea class="dsgo-ai-prompt__text" data-dsgo-ai-text readonly rows="14"
                                      aria-label="<?php esc_attr_e('AI prompt text', 'designsetgo-apps'); ?>"><?php echo esc_textarea(AiContextPack::render_prompt()); ?></textarea>
                            <div class="dsgo-ai-prompt__actions">
                                <a class="button button-primary" href="https://claude.ai/new" target="_blank" rel="noopener noreferrer">
                                    <?php esc_html_e('Open Claude →', 'designsetgo-apps'); ?>
                                </a>
                                <a class="button" href="https://chatgpt.com/" target="_blank" rel="noopener noreferrer">
                                    <?php esc_html_e('Open ChatGPT →', 'designsetgo-apps'); ?>
                                </a>
                            </div>
                            <p class="dsgo-ai-prompt__hint">
                                <?php esc_html_e('Tell the AI what you want to build. It will produce a single .html file. Save it, then drag it onto the Upload artifact tab above.', 'designsetgo-apps'); ?>
                            </p>
                        </div>
                    </details>

                    <details class="dsgo-altpath">
                        <summary><?php esc_html_e('Or vibe-code it in your favorite IDE', 'designsetgo-apps'); ?></summary>
                        <p class="dsgo-altpath__note">
                            <?php
                            echo wp_kses(
                                __('Build your app with <strong>Claude Code</strong>, <strong>Cursor</strong>, <strong>Codex</strong>, or any AI coding IDE &mdash; then ship it to this site with one command.', 'designsetgo-apps'),
                                ['strong' => []],
                            );
                            ?>
                        </p>
                        <pre class="dsgo-code"><code>npx designsetgo apps init my-app
cd my-app
npx designsetgo apps login
npx designsetgo apps deploy --build</code></pre>
                        <p class="dsgo-altpath__note">
                            <?php
                            echo wp_kses(
                                __('The CLI uses an <strong>Application Password</strong> generated from your WordPress profile. Generate one at <a href="profile.php#application-passwords-section">your profile page</a>.', 'designsetgo-apps'),
                                ['strong' => [], 'a' => ['href' => true]],
                            );
                            ?>
                        </p>
                    </details>
                </section>
            </div>

            <footer class="dsgo-admin__footnote">
                <span><?php esc_html_e('URL prefix', 'designsetgo-apps'); ?>: <code data-dsgo-prefix><?php echo esc_html(Settings::get_url_prefix()); ?></code></span>
                <a href="<?php echo esc_url(admin_url('admin.php?page=designsetgo-apps-settings')); ?>"><?php esc_html_e('Configure routing →', 'designsetgo-apps'); ?></a>
            </footer>
        </div>

        <template data-dsgo-row-template>
            <li class="dsgo-applist__row">
                <div class="dsgo-applist__main">
                    <div class="dsgo-applist__title-row">
                        <span class="dsgo-applist__title"></span>
                        <span class="dsgo-applist__home-badge" hidden>
                            <span aria-hidden="true">⌂</span>
                            <?php esc_html_e('Site home', 'designsetgo-apps'); ?>
                        </span>
                    </div>
                    <div class="dsgo-applist__meta"></div>
                </div>
                <a class="dsgo-applist__url" target="_blank" rel="noopener noreferrer"></a>
                <div class="dsgo-applist__actions">
                    <button type="button" class="dsgo-applist__home" data-dsgo-home></button>
                    <button type="button" class="dsgo-applist__delete" data-dsgo-delete aria-label=""><?php esc_html_e('Delete', 'designsetgo-apps'); ?></button>
                </div>
            </li>
        </template>

        <template data-dsgo-consent-template>
            <div class="dsgo-consent" role="region">
                <h3 class="dsgo-consent__title" data-dsgo-consent-title></h3>
                <div class="dsgo-consent__body" data-dsgo-consent-body></div>
                <div class="dsgo-consent__actions">
                    <button type="button" class="dsgo-consent__cancel" data-dsgo-consent-cancel><?php esc_html_e('Cancel', 'designsetgo-apps'); ?></button>
                    <button type="button" class="dsgo-consent__confirm" data-dsgo-consent-confirm></button>
                </div>
            </div>
        </template>

        <template data-dsgo-success-template>
            <div class="dsgo-success" role="status" aria-live="polite">
                <div>
                    <p class="dsgo-success__eyebrow"><?php esc_html_e('Installed', 'designsetgo-apps'); ?></p>
                    <h3 class="dsgo-success__title" data-dsgo-success-title></h3>
                    <p class="dsgo-success__url" data-dsgo-success-url></p>
                </div>
                <div class="dsgo-success__actions">
                    <a class="button button-primary" data-dsgo-success-open target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open app', 'designsetgo-apps'); ?></a>
                    <a class="button" data-dsgo-success-embed><?php esc_html_e('Embed in a page', 'designsetgo-apps'); ?></a>
                    <button type="button" class="button" data-dsgo-success-home><?php esc_html_e('Set as site home', 'designsetgo-apps'); ?></button>
                    <button type="button" class="button" data-dsgo-success-copy><?php esc_html_e('Copy URL', 'designsetgo-apps'); ?></button>
                </div>
            </div>
        </template>
        <?php
// End of apps-list template.
