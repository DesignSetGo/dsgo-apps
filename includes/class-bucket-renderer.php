<?php
/**
 * Renders the install-dialog HTML for a single permission bucket — label,
 * justification line, and an expandable detail panel.
 *
 * Layout (one bucket per <details> element):
 *
 *   <details class="dsgo-bucket dsgo-bucket--read_content [dsgo-bucket--new]"
 *            data-bucket="read_content">
 *     <summary class="dsgo-bucket__summary">
 *       <span class="dsgo-bucket__label">Read content</span>
 *       <span class="dsgo-bucket__justification">…</span>
 *     </summary>
 *     <div class="dsgo-bucket__details">
 *       …per-bucket content…
 *     </div>
 *   </details>
 *
 * NEW-PERMISSION marking: when $previously_approved is non-null and the bucket
 * is NOT in the approved set, the row gets the `dsgo-bucket--new` class so
 * the install dialog CSS can highlight it. On first install
 * ($previously_approved === null), nothing is "new."
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class Bucket_Renderer {

    /**
     * Render a single bucket row.
     *
     * @param Bucket            $bucket
     * @param Manifest          $m
     * @param ?string[]         $previously_approved  Bucket values previously
     *   approved by the admin, or null on first install. When the current
     *   bucket is not in this list AND the list is non-null, the row is
     *   marked as a NEW permission.
     */
    public static function render_row(Bucket $bucket, Manifest $m, ?array $previously_approved = null): string {
        $is_new = $previously_approved !== null && !in_array($bucket->value, $previously_approved, true);

        $classes = ['dsgo-bucket', 'dsgo-bucket--' . $bucket->value];
        if ($is_new) {
            $classes[] = 'dsgo-bucket--new';
        }

        $label         = self::label_for($bucket);
        $justification = self::justification_for($bucket, $m);
        $details_html  = self::details_for($bucket, $m);

        $out  = sprintf(
            '<details class="%s" data-bucket="%s">',
            esc_attr(implode(' ', $classes)),
            esc_attr($bucket->value),
        );
        $out .= '<summary class="dsgo-bucket__summary">';
        $out .= '<span class="dsgo-bucket__label">' . esc_html($label) . '</span>';
        if ($is_new) {
            $out .= '<span class="dsgo-bucket__new-badge">' . esc_html__('New permission', 'designsetgo-apps') . '</span>';
        }
        $out .= '<span class="dsgo-bucket__justification">' . $justification . '</span>';
        $out .= '</summary>';
        $out .= '<div class="dsgo-bucket__details">' . $details_html . '</div>';
        $out .= '</details>';

        return $out;
    }

    /**
     * Localized human-readable bucket label.
     *
     * Each case calls __() with a literal string so wp i18n make-pot (and
     * gettext extractors generally) can find them in the .pot file. Do NOT
     * collapse this to a static table + variable __() lookup — that pattern
     * looks tidy but the extractor silently skips it (WPCS rule
     * WordPress.WP.I18n.NonSingularStringLiteralText), so the labels would
     * be effectively untranslatable.
     */
    private static function label_for(Bucket $bucket): string {
        return match ($bucket) {
            Bucket::ReadContent      => __('Read content',      'designsetgo-apps'),
            Bucket::WriteContent     => __('Write content',     'designsetgo-apps'),
            Bucket::ExternalServices => __('External services', 'designsetgo-apps'),
            Bucket::SendMessages     => __('Send messages',     'designsetgo-apps'),
            Bucket::Ai               => __('AI',                'designsetgo-apps'),
            Bucket::RunAutomatically => __('Run automatically', 'designsetgo-apps'),
            Bucket::Commerce         => __('Commerce',          'designsetgo-apps'),
        };
    }

    /**
     * Resolve the justification text. Author-supplied wins; default copy
     * fills in. Author copy passes through a tight wp_kses allowlist —
     * phrasing-content only (no <p>/<ul>/<div>/etc.) — because the
     * justification renders inside <summary>, where block-level tags would
     * be invalid HTML. The validator already rejects HTML entirely; this is
     * defense in depth for any future caller that bypasses validation.
     *
     * Default copy uses match-on-literal __() per case so wp i18n make-pot
     * extracts every string. See label_for() for the same constraint.
     */
    private static function justification_for(Bucket $bucket, Manifest $m): string {
        $author = $m->raw_field('permissions.justifications.' . $bucket->value);
        if (is_string($author) && trim($author) !== '') {
            return wp_kses($author, self::phrasing_content_allowlist());
        }
        return esc_html(self::default_copy_for($bucket));
    }

    private static function default_copy_for(Bucket $bucket): string {
        return match ($bucket) {
            Bucket::ReadContent      => __('Reads posts, pages, and user data from your site.', 'designsetgo-apps'),
            Bucket::WriteContent     => __('Creates or modifies content on your site.', 'designsetgo-apps'),
            Bucket::ExternalServices => __('Sends requests to external services on your behalf.', 'designsetgo-apps'),
            Bucket::SendMessages     => __('Sends email through your site\'s mail system.', 'designsetgo-apps'),
            Bucket::Ai               => __('Uses AI through your configured provider.', 'designsetgo-apps'),
            Bucket::RunAutomatically => __('Schedules tasks or receives webhooks that run without a logged-in user.', 'designsetgo-apps'),
            Bucket::Commerce         => __('Reads and modifies your WooCommerce store data.', 'designsetgo-apps'),
        };
    }

    /**
     * Phrasing-content allowlist for author justifications. Strict subset of
     * what's safe to put inside <summary>: text-formatting only, no block
     * tags, no event-handler attributes, no script/style/iframe, no anchors
     * (links inside summary would compete with the disclosure click).
     *
     * @return array<string, array<string, bool>>
     */
    private static function phrasing_content_allowlist(): array {
        $no_attrs = [];
        return [
            'em'     => $no_attrs,
            'strong' => $no_attrs,
            'b'      => $no_attrs,
            'i'      => $no_attrs,
            'code'   => $no_attrs,
            'span'   => $no_attrs,
            'br'     => $no_attrs,
        ];
    }

    private static function details_for(Bucket $bucket, Manifest $m): string {
        return match ($bucket) {
            Bucket::ReadContent      => self::render_read_content_details($m),
            Bucket::WriteContent     => self::render_write_content_details($m),
            Bucket::ExternalServices => self::render_external_services_details($m),
            Bucket::SendMessages     => self::render_send_messages_details($m),
            Bucket::Ai               => self::render_ai_details($m),
            Bucket::RunAutomatically => self::render_run_automatically_details($m),
            Bucket::Commerce         => self::render_commerce_details($m),
        };
    }

    // --- Per-bucket detail panels --------------------------------------

    private static function render_read_content_details(Manifest $m): string {
        $perms = array_map(fn (Permission $p) => $p->value, $m->permissions_read);
        $perms = array_values(array_filter($perms, function (string $p): bool {
            // Only show read-side perms here. AI and Commerce belong in their own buckets.
            return in_array($p, ['site_info', 'posts', 'pages', 'user', 'abilities'], true);
        }));
        if ($perms === []) {
            return '<p>' . esc_html__('Read access is enabled but no specific data types declared.', 'designsetgo-apps') . '</p>';
        }
        return self::render_string_list($perms);
    }

    private static function render_write_content_details(Manifest $m): string {
        $write = $m->raw_field('permissions.write');
        if (!is_array($write) || $write === []) {
            return '<p>' . esc_html__('Write access enabled (no specific post types declared).', 'designsetgo-apps') . '</p>';
        }
        $write = array_values(array_filter($write, 'is_string'));
        return self::render_string_list($write);
    }

    private static function render_external_services_details(Manifest $m): string {
        $hosts   = $m->raw_field('permissions.http');
        $secrets = $m->raw_field('secrets');

        $out = '';
        if (is_array($hosts) && $hosts !== []) {
            $out .= '<p class="dsgo-bucket__detail-heading">' . esc_html__('Hosts:', 'designsetgo-apps') . '</p>';
            $out .= self::render_string_list(array_values(array_filter($hosts, 'is_string')));
        }
        if (is_array($secrets) && $secrets !== []) {
            $out .= '<p class="dsgo-bucket__detail-heading">' . esc_html__('Required credentials:', 'designsetgo-apps') . '</p>';
            $out .= '<ul>';
            foreach ($secrets as $entry) {
                if (!is_array($entry) || !isset($entry['alias'])) continue;
                $alias = (string) $entry['alias'];
                $desc  = isset($entry['description']) ? (string) $entry['description'] : '';
                $out  .= '<li><code>' . esc_html($alias) . '</code>';
                if ($desc !== '') {
                    $out .= ' &mdash; ' . esc_html($desc);
                }
                $out  .= '</li>';
            }
            $out .= '</ul>';
        }
        if ($out === '') {
            return '<p>' . esc_html__('External services declared but no specific hosts listed.', 'designsetgo-apps') . '</p>';
        }
        return $out;
    }

    private static function render_send_messages_details(Manifest $m): string {
        $allow_anon = $m->raw_field('email.allow_anonymous') === true;
        $recipients = $m->raw_field('email.anonymous_recipients');
        $recipient_strings = is_array($recipients)
            ? array_values(array_filter($recipients, 'is_string'))
            : [];

        if ($allow_anon && $recipient_strings !== []) {
            return '<p class="dsgo-bucket__detail-heading">'
                 . esc_html__('Anonymous email enabled — recipients restricted to:', 'designsetgo-apps')
                 . '</p>'
                 . self::render_string_list($recipient_strings);
        }
        if ($allow_anon) {
            // allow_anonymous: true but no recipients listed — the validator
            // rejects this combination at install, but we render a clear
            // warning rather than a heading-with-no-list if a future caller
            // ever bypasses validation.
            return '<p>' . esc_html__('Anonymous email enabled (no specific recipients listed).', 'designsetgo-apps') . '</p>';
        }
        return '<p>' . esc_html__('Sends transactional email triggered by logged-in users.', 'designsetgo-apps') . '</p>';
    }

    private static function render_ai_details(Manifest $m): string {
        $out = '<p>' . esc_html__('Calls AI through your configured provider.', 'designsetgo-apps') . '</p>';
        $consumes = $m->raw_field('abilities.consumes');
        if (is_array($consumes) && $consumes !== []) {
            $out .= '<p class="dsgo-bucket__detail-heading">'
                  . esc_html__('Abilities this app may invoke:', 'designsetgo-apps') . '</p>';
            $out .= self::render_string_list(array_values(array_filter($consumes, 'is_string')));
        }
        return $out;
    }

    private static function render_run_automatically_details(Manifest $m): string {
        $jobs      = $m->raw_field('scheduled.jobs');
        $endpoints = $m->raw_field('webhooks.endpoints');

        $out = '';
        if (is_array($jobs) && $jobs !== []) {
            $out .= '<p class="dsgo-bucket__detail-heading">' . esc_html__('Scheduled jobs:', 'designsetgo-apps') . '</p>';
            $out .= '<ul>';
            foreach ($jobs as $job) {
                if (!is_array($job) || !isset($job['id'])) continue;
                $id       = (string) $job['id'];
                $schedule = isset($job['schedule']) ? (string) $job['schedule'] : '';
                $out     .= '<li><code>' . esc_html($id) . '</code>';
                if ($schedule !== '') {
                    $out .= ' (' . esc_html($schedule) . ')';
                }
                $out     .= '</li>';
            }
            $out .= '</ul>';
        }
        if (is_array($endpoints) && $endpoints !== []) {
            $out .= '<p class="dsgo-bucket__detail-heading">' . esc_html__('Webhook endpoints:', 'designsetgo-apps') . '</p>';
            $out .= '<ul>';
            foreach ($endpoints as $endpoint) {
                if (!is_array($endpoint) || !isset($endpoint['id'])) continue;
                $out .= '<li><code>' . esc_html((string) $endpoint['id']) . '</code></li>';
            }
            $out .= '</ul>';
        }
        if ($out === '') {
            return '<p>' . esc_html__('Background tasks declared but no schedules or webhooks listed.', 'designsetgo-apps') . '</p>';
        }
        return $out;
    }

    private static function render_commerce_details(Manifest $m): string {
        $endpoints = $m->raw_field('commerce.endpoints');
        if (!is_array($endpoints) || $endpoints === []) {
            return '<p>' . esc_html__('Reads and modifies your store data.', 'designsetgo-apps') . '</p>';
        }
        return self::render_string_list(array_values(array_filter($endpoints, 'is_string')));
    }

    // --- Helpers --------------------------------------------------------

    /**
     * Render a flat list of strings as <ul><li><code>…</code></li>…</ul>.
     * @param string[] $items
     */
    private static function render_string_list(array $items): string {
        if ($items === []) {
            return '';
        }
        $out = '<ul>';
        foreach ($items as $item) {
            $out .= '<li><code>' . esc_html($item) . '</code></li>';
        }
        $out .= '</ul>';
        return $out;
    }
}
