<?php
/**
 * Bridge method → required permission map, plus the Bucket enum that groups
 * permissions into the seven canonical install-dialog rows.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

// Exception messages constructed below are never echoed to clients; the REST
// layer catches them and returns sanitized error_code + filtered messages.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * The seven canonical permission buckets shown in the install dialog. Buckets
 * GROUP individual Permission values + supplementary manifest blocks into a
 * legible consent surface (see docs/superpowers/specs/2026-05-09-permission-buckets-and-harness-pruning-design.md).
 *
 * Storage (dsgo.storage.*) intentionally has no bucket — it's described in a
 * passive footer note in the install dialog rather than a row.
 */
enum Bucket: string {
    case ReadContent      = 'read_content';
    case WriteContent     = 'write_content';
    case ExternalServices = 'external_services';
    case SendMessages     = 'send_messages';
    case Ai               = 'ai';
    case RunAutomatically = 'run_automatically';
    case Commerce         = 'commerce';

    /**
     * Map a single Permission to its read-side bucket. Returns null for
     * permissions that don't activate a bucket via permissions.read (Email
     * activates via permissions.send; Storage has no bucket).
     */
    public static function from_permission(Permission $p): ?self {
        return match ($p) {
            Permission::SiteInfo,
            Permission::Posts,
            Permission::Pages,
            Permission::User,
            Permission::Abilities => self::ReadContent,
            Permission::Ai        => self::Ai,
            Permission::Commerce  => self::Commerce,
            // Email is detected via permissions.send (active_for handles it).
            Permission::Email     => null,
        };
    }

    /**
     * Compute the active bucket set for a manifest. Reads the manifest's
     * permission arrays AND its supplementary blocks (http, scheduled,
     * webhooks, commerce.endpoints) since several buckets activate from
     * non-permissions fields. Uses Manifest::raw_field() for fields whose
     * typed accessors haven't shipped yet — keeps the bucket model
     * independent of which bridge specs have shipped.
     *
     * @return self[] Deduplicated, in stable order: ReadContent first, then
     *                WriteContent, ExternalServices, SendMessages, Ai,
     *                RunAutomatically, Commerce.
     */
    /**
     * Compute the active bucket set for a manifest.
     *
     * **CALLER CONTRACT — freshly-validated manifests only.**
     *
     * This method is correct only for Manifests freshly returned by
     * Manifest::validate($raw). Manifests hydrated via from_array_unchecked()
     * (e.g. read out of post meta after install) WILL silently miss
     * WriteContent / ExternalServices / SendMessages / RunAutomatically
     * activations because Manifest::to_array() does not serialize those
     * supplementary blocks (permissions.write non-empty, permissions.http,
     * permissions.send, scheduled.jobs, webhooks.endpoints) — they don't
     * have typed accessors yet, so the round-trip drops them.
     *
     * Two consequences for callers:
     *   1. At install/update time, call active_for($m) where $m came from
     *      Manifest::validate($upload). Persist the resulting bucket set as
     *      post meta (`dsgo_apps_active_buckets`) — that becomes the source
     *      of truth for "what was active here last install," used as the
     *      diff baseline by Installer::preview() on the next update. Note:
     *      "active" not "approved" — v1 does not enforce admin consent.
     *   2. After install, DO NOT call active_for() against a hydrated
     *      manifest to re-derive the bucket set. Read the post meta instead.
     *      Or use active_for_raw() against the original raw payload if you
     *      have it.
     *
     * The gap closes naturally as later bridge specs ship: each spec adds
     * typed accessors AND extends to_array() to serialize its fields, at
     * which point hydrated manifests survive the round-trip for that bucket.
     *
     * @return self[] Deduplicated, in stable order matching Bucket::cases().
     */
    public static function active_for(Manifest $m): array {
        $perms_read = array_map(fn (Permission $p) => $p->value, $m->permissions_read);
        return self::active_for_raw($m->raw, $perms_read);
    }

    /**
     * Same as active_for() but operates on a raw manifest array — used during
     * Manifest::validate() before the typed Manifest object exists, AND by
     * any post-install caller that has the original raw payload available.
     *
     * Prefer this over active_for(Manifest) anywhere the original raw is in
     * scope; it is round-trip-safe by construction since it doesn't rely on
     * to_array() preserving the supplementary blocks.
     *
     * @param array<string, mixed> $raw         Raw parsed manifest array.
     * @param string[]             $perms_read  Permission strings from permissions.read.
     * @return self[]                Stable order matching Bucket::cases().
     */
    public static function active_for_raw(array $raw, array $perms_read): array {
        $active = [];

        foreach ($perms_read as $perm_string) {
            $perm = Permission::tryFrom($perm_string);
            if ($perm && ($bucket = self::from_permission($perm))) {
                $active[$bucket->value] = $bucket;
            }
        }

        $write = $raw['permissions']['write'] ?? null;
        if (is_array($write) && $write !== []) {
            $active[self::WriteContent->value] = self::WriteContent;
        }
        $http = $raw['permissions']['http'] ?? null;
        if (is_array($http) && $http !== []) {
            $active[self::ExternalServices->value] = self::ExternalServices;
        }
        $send = $raw['permissions']['send'] ?? null;
        if (is_array($send) && in_array('email', $send, true)) {
            $active[self::SendMessages->value] = self::SendMessages;
        }
        $jobs      = $raw['scheduled']['jobs']      ?? null;
        $endpoints = $raw['webhooks']['endpoints'] ?? null;
        if ((is_array($jobs) && $jobs !== []) || (is_array($endpoints) && $endpoints !== [])) {
            $active[self::RunAutomatically->value] = self::RunAutomatically;
        }
        $commerce_endpoints = $raw['commerce']['endpoints'] ?? null;
        if (is_array($commerce_endpoints) && $commerce_endpoints !== []) {
            $active[self::Commerce->value] = self::Commerce;
        }

        // Stable order = Bucket::cases() declaration order (the canonical list).
        $out = [];
        foreach (self::cases() as $case) {
            if (isset($active[$case->value])) {
                $out[] = $active[$case->value];
            }
        }
        return $out;
    }
}

final class Permissions {

    /** @var array<string, ?Permission>|null */
    private static ?array $cached_map = null;

    /** @var array<string, ?string>|null */
    private static ?array $cached_array = null;

    /**
     * @return array<string, ?Permission>
     */
    private static function map(): array {
        return self::$cached_map ??= [
            'site.info'         => Permission::SiteInfo,
            'posts.list'        => Permission::Posts,
            'posts.get'         => Permission::Posts,
            'pages.list'        => Permission::Pages,
            'pages.get'         => Permission::Pages,
            'user.current'      => Permission::User,
            'user.can'          => Permission::User,
            'storage.app.get'   => null,
            'storage.app.set'   => null,
            'storage.user.get'  => null,
            'storage.user.set'  => null,
            'bridge.ping'       => null,
            'help.method'       => null,
            'abilities.list'    => Permission::Abilities,
            'abilities.invoke'  => Permission::Abilities,
            'ai.prompt'         => Permission::Ai,
            'email.send'        => Permission::Email,
            // media.upload is a core, opt-out feature — gated by the WP
            // `upload_files` cap on the REST permission_callback and by the
            // manifest's `media.uploads` flag (default true) at the bridge.
            // No manifest permission is required.
            'media.upload'      => null,
            'router.navigate'   => null,
            'commerce.products.list'           => Permission::Commerce,
            'commerce.products.get'            => Permission::Commerce,
            'commerce.cart.get'                => Permission::Commerce,
            'commerce.cart.add_item'           => Permission::Commerce,
            'commerce.cart.update_item'        => Permission::Commerce,
            'commerce.cart.remove_item'        => Permission::Commerce,
            'commerce.checkout.open_hosted_page' => Permission::Commerce,
            // http.fetch's permission check lives inside Http_Proxy_Bridge —
            // it's a manifest-driven hostname allowlist (permissions.http),
            // not a 1:1 mapping to a Permission enum case.
            'http.fetch'                         => null,
        ];
    }

    public static function required(string $method): ?Permission {
        $map = self::map();
        if (!array_key_exists($method, $map)) {
            throw new \InvalidArgumentException(sprintf('Unknown bridge method: %s', $method));
        }
        return $map[$method];
    }

    /**
     * @return array<string, ?string>
     */
    public static function to_array(): array {
        if (self::$cached_array !== null) {
            return self::$cached_array;
        }
        $out = [];
        foreach (self::map() as $method => $perm) {
            $out[$method] = $perm?->value;
        }
        return self::$cached_array = $out;
    }
}
