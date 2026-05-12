<?php
/**
 * Per-app encrypted secret vault for the HTTP proxy and webhook signing.
 *
 * One option per app: `dsgo_apps_secrets_<app_id>` (autoload=no). The option
 * value is `array<alias, base64(nonce . ciphertext)>` — each alias is
 * independently encrypted with a fresh nonce so reading one plaintext does
 * not expose others via a shared nonce.
 *
 * The encryption key is DERIVED per-call from `wp_salt('auth') . AUTH_KEY`
 * via `sodium_crypto_generichash`. Two sites with the same DB dump but
 * different `wp-config.php` salts cannot decrypt each other's vaults.
 *
 * Refuses to operate if libsodium isn't available — `is_available()` returns
 * false and `set`/`get` throw RuntimeException. PHP 7.2+ ships sodium with
 * core, so this is defensive.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class Secret_Vault {

    /** Cap per (app, alias) value. 64KB is plenty for any sane API token. */
    private const MAX_VALUE_BYTES = 65536;

    public static function is_available(): bool {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open')
            && function_exists('sodium_crypto_generichash')
            && function_exists('random_bytes')
            && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')
            && defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES');
    }

    /**
     * Store $value under ($app_id, $alias). Overwrites any existing value.
     * Throws if sodium isn't available or $value exceeds MAX_VALUE_BYTES.
     */
    public static function set(string $app_id, string $alias, string $value): void {
        self::require_sodium();
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $alias is a regex-validated [A-Z][A-Z0-9_]{0,63} identifier; exception is caught by the REST layer, not rendered to output
            throw new \RuntimeException(sprintf(
                'Secret value for %s exceeds %d-byte limit',
                $alias,
                self::MAX_VALUE_BYTES,
            ));
        }
        $key   = self::derive_key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($value, $nonce, $key);

        $vault          = get_option(self::option_key($app_id), []);
        if (!is_array($vault)) $vault = [];
        $encoded        = base64_encode($nonce . $ciphertext);
        $vault[$alias]  = $encoded;

        // update_option returns false on DB error AND when the value is
        // unchanged. For a security primitive a silent failure is unacceptable
        // — admin saves a secret, UI says success, value isn't persisted,
        // next proxy call uses stale data. Distinguish failure from "no-op"
        // by reading the row back and verifying the alias matches what we
        // intended to write.
        update_option(self::option_key($app_id), $vault, false /* autoload */);
        $readback = get_option(self::option_key($app_id), []);
        if (function_exists('sodium_memzero')) {
            sodium_memzero($key);
        }
        if (!is_array($readback) || ($readback[$alias] ?? null) !== $encoded) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $app_id is an internal post ID, $alias is a regex-validated identifier; exception is caught by the REST layer, not rendered to output
            throw new \RuntimeException(sprintf(
                'Secret_Vault::set(%s, %s) failed to persist — check DB write permissions and wp_options table state',
                $app_id,
                $alias,
            ));
        }
    }

    /**
     * Retrieve the decrypted value for ($app_id, $alias). Returns null when:
     *  - the app has no vault
     *  - the alias isn't set
     *  - base64 decode fails
     *  - decryption fails (e.g. salts changed, tampered ciphertext)
     */
    public static function get(string $app_id, string $alias): ?string {
        self::require_sodium();
        $vault = get_option(self::option_key($app_id), []);
        if (!is_array($vault) || !isset($vault[$alias]) || !is_string($vault[$alias])) {
            return null;
        }
        $blob = base64_decode($vault[$alias], true);
        if ($blob === false) return null;
        $nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($blob) < $nonce_len + 1) return null;
        $nonce      = substr($blob, 0, $nonce_len);
        $ciphertext = substr($blob, $nonce_len);

        $key       = self::derive_key();
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if (function_exists('sodium_memzero')) {
            sodium_memzero($key);
        }
        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Remove a single alias's stored value. No-op if absent.
     */
    public static function delete(string $app_id, string $alias): void {
        $vault = get_option(self::option_key($app_id), []);
        if (!is_array($vault) || !isset($vault[$alias])) return;
        unset($vault[$alias]);
        if ($vault === []) {
            delete_option(self::option_key($app_id));
        } else {
            update_option(self::option_key($app_id), $vault, false);
        }
    }

    /**
     * Purge the entire vault for an app — used by the uninstaller and the
     * "Clear all" admin action.
     */
    public static function delete_all(string $app_id): void {
        delete_option(self::option_key($app_id));
    }

    /**
     * Aliases that currently have a stored value for $app_id. Does NOT
     * decrypt — fast enough to call on every admin page render.
     *
     * @return string[]
     */
    public static function list_set_aliases(string $app_id): array {
        $vault = get_option(self::option_key($app_id), []);
        if (!is_array($vault)) return [];
        return array_values(array_filter(array_keys($vault), 'is_string'));
    }

    private static function require_sodium(): void {
        if (!self::is_available()) {
            throw new \RuntimeException(
                'Secret_Vault requires libsodium (sodium_crypto_secretbox). '
                . 'PHP 7.2+ ships it; check that the sodium extension is enabled.',
            );
        }
    }

    /**
     * Derive the secretbox key from wp_salt('auth') + AUTH_KEY. Returns a
     * SODIUM_CRYPTO_SECRETBOX_KEYBYTES-byte (32-byte) key. The key is
     * deterministic per-site — two sites with different wp-config.php salts
     * produce different keys, so a DB-only theft can't decrypt elsewhere.
     *
     * IMPORTANT: the `. AUTH_KEY` append is deliberate belt-and-suspenders
     * — wp_salt('auth') already mixes AUTH_KEY + AUTH_SALT internally on
     * modern WP, but the explicit append guards against a future WP change
     * that drops one of those salts from the wp_salt() derivation. Do NOT
     * "simplify" this away.
     */
    /**
     * Public accessor for the site-wide sodium secretbox key.
     *
     * Used by the async webhook queue (Task 11 of the cron+webhooks
     * plan) to encrypt buffered request bodies + headers at rest.
     * The key is per-site (mixed from wp_salt + AUTH_KEY), so a
     * DB-only theft can't decrypt elsewhere — same posture as the
     * per-app secret vault.
     *
     * Static accessor mirrors derive_key()'s contract: the cached
     * key bytes change only when AUTH_KEY rotates or wp_salt rotates;
     * downstream callers can safely call this once per request.
     */
    public static function encryption_key(): string {
        return self::derive_key();
    }

    private static function derive_key(): string {
        $material = wp_salt('auth');
        if (defined('AUTH_KEY') && is_string(AUTH_KEY)) {
            $material .= AUTH_KEY;
        }
        return sodium_crypto_generichash($material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private static function option_key(string $app_id): string {
        return 'dsgo_apps_secrets_' . $app_id;
    }
}
