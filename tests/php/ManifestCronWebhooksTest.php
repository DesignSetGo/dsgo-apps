<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Manifest;
use DSGo_Apps\ManifestError;
use WP_UnitTestCase;

/**
 * Tests for the cron + webhooks manifest extensions: `execute_php` on
 * abilities.publishes[], `permissions.run`, `scheduled.jobs[]`,
 * `webhooks.endpoints[]`. Each is shape-only at validate time —
 * class-existence and ability-name resolution run later (at ability
 * registration / cron dispatch / webhook handler time) so a manifest
 * can be validated before its companion plugin is installed.
 *
 * Lives in its own test class to keep the cron/webhook surface
 * coherent and to avoid bloating the already-large ManifestTest.
 */
final class ManifestCronWebhooksTest extends WP_UnitTestCase {

    // ===== Task 1: abilities.publishes[].execute_php =====

    public function test_execute_php_valid_entry_accepted(): void {
        $arr = $this->valid_iframe_manifest_with_ability([
            'execute_php' => ['class' => 'Acme\\Foo', 'method' => 'execute'],
        ]);
        $manifest = Manifest::validate($arr);
        $this->assertSame('Acme\\Foo', $manifest->abilities_publishes[0]['execute_php']['class']);
        $this->assertSame('execute',   $manifest->abilities_publishes[0]['execute_php']['method']);
    }

    public function test_execute_php_absent_ability_still_valid(): void {
        // No execute_php on the entry — the ability is still a valid
        // declaration (no companion plugin). The runtime will fail to
        // invoke it, but the manifest itself parses.
        $arr = $this->valid_iframe_manifest_with_ability([]);
        $manifest = Manifest::validate($arr);
        $this->assertArrayNotHasKey('execute_php', $manifest->abilities_publishes[0]);
    }

    public function test_execute_php_rejects_non_object(): void {
        $arr = $this->valid_iframe_manifest_with_ability(['execute_php' => 'Acme\\Foo::execute']);
        $this->expectExceptionMessage('execute_php_invalid');
        Manifest::validate($arr);
    }

    public function test_execute_php_rejects_missing_class(): void {
        $arr = $this->valid_iframe_manifest_with_ability([
            'execute_php' => ['method' => 'execute'],
        ]);
        $this->expectExceptionMessage('execute_php_invalid');
        Manifest::validate($arr);
    }

    public function test_execute_php_rejects_missing_method(): void {
        $arr = $this->valid_iframe_manifest_with_ability([
            'execute_php' => ['class' => 'Acme\\Foo'],
        ]);
        $this->expectExceptionMessage('execute_php_invalid');
        Manifest::validate($arr);
    }

    /** @dataProvider invalid_class_names */
    public function test_execute_php_class_name_regex_enforced(string $bad_class): void {
        $arr = $this->valid_iframe_manifest_with_ability([
            'execute_php' => ['class' => $bad_class, 'method' => 'execute'],
        ]);
        $this->expectExceptionMessage('execute_php_class_invalid');
        Manifest::validate($arr);
    }

    /** @return array<string, array{0:string}> */
    public static function invalid_class_names(): array {
        return [
            'leading digit' => ['123Foo'],
            'space inside'  => ['Foo Bar'],
            'empty string'  => [''],
            // Trailing backslash isn't a valid class name — guards against
            // a typo'd namespace separator slipping through.
            'trailing backslash' => ['Acme\\Foo\\'],
            // Hyphens / dashes are not allowed in PHP identifiers.
            'hyphen in name'     => ['Acme-Foo'],
        ];
    }

    public function test_execute_php_class_accepts_namespaced_class(): void {
        // Deeply namespaced names with single backslash separators are
        // the canonical PHP shape; they must round-trip cleanly.
        $arr = $this->valid_iframe_manifest_with_ability([
            'execute_php' => ['class' => 'Vendor\\Pkg\\Sub\\Handler_v2', 'method' => 'execute'],
        ]);
        $manifest = Manifest::validate($arr);
        $this->assertSame('Vendor\\Pkg\\Sub\\Handler_v2', $manifest->abilities_publishes[0]['execute_php']['class']);
    }

    /** @dataProvider invalid_method_names */
    public function test_execute_php_method_name_regex_enforced(string $bad_method): void {
        $arr = $this->valid_iframe_manifest_with_ability([
            'execute_php' => ['class' => 'Acme\\Foo', 'method' => $bad_method],
        ]);
        $this->expectExceptionMessage('execute_php_method_invalid');
        Manifest::validate($arr);
    }

    /** @return array<string, array{0:string}> */
    public static function invalid_method_names(): array {
        return [
            'leading digit' => ['123method'],
            'hyphen'        => ['my-method'],
            'empty string'  => [''],
            'space inside'  => ['my method'],
            'namespace separator' => ['Foo\\bar'],
        ];
    }

    // ===== helpers =====

    /**
     * Build a valid iframe-mode manifest with a single published ability.
     * `$extra` is shallow-merged into the ability entry — pass `[]` for
     * the no-extension baseline, or `['execute_php' => [...]]` to exercise
     * the new field.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function valid_iframe_manifest_with_ability(array $extra): array {
        return [
            'manifest_version' => 1,
            'id'               => 'sample',
            'name'             => 'Sample',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
            'abilities'        => [
                'publishes' => [
                    array_merge([
                        'name'        => 'sample/do-it',
                        'label'       => 'Do it',
                        'description' => 'A sample published ability for tests.',
                        'category'    => 'content',
                    ], $extra),
                ],
            ],
        ];
    }
}
