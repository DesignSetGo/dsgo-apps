<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Secret_Vault;
use WP_UnitTestCase;

/**
 * Tests for Secret_Vault — sodium-encrypted per-app secret storage. Each
 * alias's value is independently encrypted with a fresh nonce so reading
 * one plaintext doesn't compromise others.
 *
 * The vault key is derived per-call from wp_salt('auth') + AUTH_KEY via
 * sodium_crypto_generichash; values live in `wp_options` keyed
 * `dsgo_apps_secrets_<app_id>` with autoload=no.
 */
final class SecretVaultTest extends WP_UnitTestCase {

    private const APP_ID = 'vault-test-app';

    public function tear_down(): void {
        Secret_Vault::delete_all(self::APP_ID);
        parent::tear_down();
    }

    public function test_sodium_is_available_on_test_env(): void {
        // PHP 7.2+ ships sodium; the vault refuses to operate without it.
        $this->assertTrue(Secret_Vault::is_available());
    }

    public function test_round_trip_encrypt_decrypt(): void {
        Secret_Vault::set(self::APP_ID, 'STRIPE_SECRET', 'sk_test_abc123');
        $this->assertSame('sk_test_abc123', Secret_Vault::get(self::APP_ID, 'STRIPE_SECRET'));
    }

    public function test_round_trip_preserves_special_chars(): void {
        // The secret value can include any printable bytes — quotes, equals,
        // newlines (some webhook auth schemes use multi-line secrets).
        $value = "sk_live_a-b/c=d\"e\nline2";
        Secret_Vault::set(self::APP_ID, 'TRICKY', $value);
        $this->assertSame($value, Secret_Vault::get(self::APP_ID, 'TRICKY'));
    }

    public function test_get_unknown_alias_returns_null(): void {
        $this->assertNull(Secret_Vault::get(self::APP_ID, 'NEVER_SET'));
    }

    public function test_get_unknown_app_returns_null(): void {
        $this->assertNull(Secret_Vault::get('no-such-app', 'WHATEVER'));
    }

    public function test_set_overwrites_existing_value(): void {
        Secret_Vault::set(self::APP_ID, 'STRIPE_SECRET', 'first_value');
        Secret_Vault::set(self::APP_ID, 'STRIPE_SECRET', 'second_value');
        $this->assertSame('second_value', Secret_Vault::get(self::APP_ID, 'STRIPE_SECRET'));
    }

    public function test_each_alias_uses_a_distinct_nonce(): void {
        // Belt-and-suspenders: storing the same plaintext under two aliases
        // must produce distinct ciphertexts (different nonces).
        Secret_Vault::set(self::APP_ID, 'A', 'same_value');
        Secret_Vault::set(self::APP_ID, 'B', 'same_value');
        $vault = get_option('dsgo_apps_secrets_' . self::APP_ID, []);
        $this->assertNotSame($vault['A'], $vault['B']);
    }

    public function test_delete_removes_single_alias(): void {
        Secret_Vault::set(self::APP_ID, 'A', 'val-a');
        Secret_Vault::set(self::APP_ID, 'B', 'val-b');
        Secret_Vault::delete(self::APP_ID, 'A');
        $this->assertNull(Secret_Vault::get(self::APP_ID, 'A'));
        $this->assertSame('val-b', Secret_Vault::get(self::APP_ID, 'B'));
    }

    public function test_delete_all_purges_every_alias(): void {
        Secret_Vault::set(self::APP_ID, 'A', 'val-a');
        Secret_Vault::set(self::APP_ID, 'B', 'val-b');
        Secret_Vault::delete_all(self::APP_ID);
        $this->assertNull(Secret_Vault::get(self::APP_ID, 'A'));
        $this->assertNull(Secret_Vault::get(self::APP_ID, 'B'));
    }

    public function test_list_set_aliases_returns_only_configured_keys(): void {
        Secret_Vault::set(self::APP_ID, 'A', 'val-a');
        Secret_Vault::set(self::APP_ID, 'B', 'val-b');
        $aliases = Secret_Vault::list_set_aliases(self::APP_ID);
        sort($aliases);
        $this->assertSame(['A', 'B'], $aliases);
    }

    public function test_list_set_aliases_does_not_decrypt(): void {
        // Smoke test: list-set should be cheap; we can't easily assert
        // "didn't decrypt" but we can at least confirm it works with values
        // that, if decryption ran, would be present in the result. Since
        // the public API doesn't expose decrypted values from list, this
        // is structural.
        Secret_Vault::set(self::APP_ID, 'A', 'secret_value');
        $aliases = Secret_Vault::list_set_aliases(self::APP_ID);
        $this->assertNotContains('secret_value', $aliases);
    }

    public function test_set_rejects_value_exceeding_size_cap(): void {
        // 64KB cap — anything larger is almost certainly a bug (real API
        // tokens are well under 1KB; the cap guards against pathological
        // input clogging wp_options).
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('65536-byte limit');
        // Strictly over the cap.
        Secret_Vault::set(self::APP_ID, 'BIG_VALUE', str_repeat('a', 65537));
    }

    public function test_get_returns_null_for_tampered_ciphertext(): void {
        // Sodium's secretbox is authenticated — flipping one byte of the
        // stored ciphertext should produce a decryption failure surfaced as
        // null (NOT an exception, NOT a partial result). This is the
        // structural defense against an attacker who has DB write access
        // but doesn't have the wp-config salts.
        Secret_Vault::set(self::APP_ID, 'TAMPER_TEST', 'sk_test_original');
        $vault = get_option('dsgo_apps_secrets_' . self::APP_ID, []);
        $original = $vault['TAMPER_TEST'];
        // Decode, flip one byte deep in the ciphertext (past the nonce),
        // re-encode, store. This produces a valid base64 string whose
        // decrypted form fails the Poly1305 MAC check.
        $blob = base64_decode($original, true);
        $blob[24] = chr((ord($blob[24]) ^ 0x01) & 0xff);   // flip a bit
        $vault['TAMPER_TEST'] = base64_encode($blob);
        update_option('dsgo_apps_secrets_' . self::APP_ID, $vault, false);

        $this->assertNull(Secret_Vault::get(self::APP_ID, 'TAMPER_TEST'));
    }

    public function test_list_set_aliases_returns_empty_after_delete_all(): void {
        Secret_Vault::set(self::APP_ID, 'A', 'val-a');
        Secret_Vault::set(self::APP_ID, 'B', 'val-b');
        Secret_Vault::delete_all(self::APP_ID);
        $this->assertSame([], Secret_Vault::list_set_aliases(self::APP_ID));
    }

    public function test_option_is_not_autoloaded(): void {
        // Vault values shouldn't be loaded on every page load — they're
        // only needed when admin reads them or the http proxy substitutes.
        // Verify autoload=no by checking wp_options directly.
        Secret_Vault::set(self::APP_ID, 'X', 'val');
        global $wpdb;
        $autoload = $wpdb->get_var($wpdb->prepare(
            "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
            'dsgo_apps_secrets_' . self::APP_ID,
        ));
        // WP 6.6+ uses 'on'/'off'; older versions use 'yes'/'no'. Either way
        // the negative is what matters.
        $this->assertNotSame('yes', $autoload);
        $this->assertNotSame('on',  $autoload);
    }
}
