<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Manifest;
use WP_UnitTestCase;

/**
 * Tests for Task 3 of the cron + webhooks plan: `webhooks.endpoints[]`
 * manifest validation. Covers permission gating, endpoint cap + duplicate
 * detection, the ability cross-reference (must exist + carry execute_php),
 * auth-block shape (hmac-sha256 vs bearer, scheme enum, secret-alias
 * cross-ref to top-level `secrets[]`), and rate-limit / async /
 * idempotency-header opt-in fields.
 *
 * Permission/scheduled-side tests live in `ManifestScheduledTest`.
 */
final class ManifestWebhooksValidationTest extends WP_UnitTestCase {

    public function test_webhooks_block_requires_run_permission(): void {
        $arr = $this->webhook_manifest([$this->valid_endpoint('stripe-events')]);
        unset($arr['permissions']['run']);
        $this->expectExceptionMessage('run_webhooks_not_permitted');
        Manifest::validate($arr);
    }

    public function test_webhooks_too_many_at_11(): void {
        $endpoints = [];
        for ($i = 1; $i <= 11; $i++) {
            $endpoints[] = $this->valid_endpoint("ep-$i");
        }
        $arr = $this->webhook_manifest($endpoints);
        $this->expectExceptionMessage('webhooks_too_many');
        Manifest::validate($arr);
    }

    public function test_webhooks_10_accepted(): void {
        $endpoints = [];
        for ($i = 1; $i <= 10; $i++) {
            $endpoints[] = $this->valid_endpoint("ep-$i");
        }
        $manifest = Manifest::validate($this->webhook_manifest($endpoints));
        $this->assertCount(10, $manifest->webhook_endpoints());
    }

    public function test_webhooks_duplicate_id_rejected(): void {
        $arr = $this->webhook_manifest([
            $this->valid_endpoint('stripe-events'),
            $this->valid_endpoint('stripe-events'),
        ]);
        $this->expectExceptionMessage('webhooks_duplicate_id');
        Manifest::validate($arr);
    }

    public function test_webhook_ability_not_found(): void {
        $endpoint = $this->valid_endpoint('stripe-events');
        $endpoint['ability'] = 'sample/missing';
        $arr = $this->webhook_manifest([$endpoint]);
        $this->expectExceptionMessage('webhook_ability_not_found');
        Manifest::validate($arr);
    }

    public function test_webhook_ability_without_execute_php_rejected(): void {
        $arr = $this->base_manifest();
        $arr['abilities']['publishes'] = [
            [
                'name'        => 'sample/do-it',
                'label'       => 'Do it',
                'description' => 'A sample published ability without php.',
                'category'    => 'content',
            ],
            [
                'name'        => 'sample/with-php',
                'label'       => 'With php',
                'description' => 'A second ability carrying execute_php.',
                'category'    => 'content',
                'execute_php' => ['class' => 'Acme\\Foo', 'method' => 'execute'],
            ],
        ];
        $arr['permissions']['run'] = ['webhooks'];
        $arr['secrets'] = [['alias' => 'STRIPE', 'description' => 'Stripe signing secret (test).']];
        $arr['webhooks'] = ['endpoints' => [[
            'id'      => 'stripe-events',
            'ability' => 'sample/do-it',
            'auth'    => ['type' => 'hmac-sha256', 'scheme' => 'stripe', 'secret_alias' => 'STRIPE'],
        ]]];
        $this->expectExceptionMessage('webhook_ability_not_php_callable');
        Manifest::validate($arr);
    }

    public function test_webhook_auth_missing(): void {
        $endpoint = $this->valid_endpoint('stripe-events');
        unset($endpoint['auth']);
        $arr = $this->webhook_manifest([$endpoint]);
        $this->expectExceptionMessage('webhook_auth_missing');
        Manifest::validate($arr);
    }

    public function test_webhook_auth_unknown_type(): void {
        $endpoint = $this->valid_endpoint('stripe-events');
        $endpoint['auth']['type'] = 'basic';
        $arr = $this->webhook_manifest([$endpoint]);
        $this->expectExceptionMessage('webhook_auth_unknown_type');
        Manifest::validate($arr);
    }

    public function test_webhook_auth_hmac_unknown_scheme(): void {
        $endpoint = $this->valid_endpoint('stripe-events');
        $endpoint['auth']['scheme'] = 'mailgun';
        $arr = $this->webhook_manifest([$endpoint]);
        $this->expectExceptionMessage('webhook_auth_unknown_scheme');
        Manifest::validate($arr);
    }

    /** @dataProvider valid_hmac_schemes */
    public function test_webhook_auth_hmac_known_schemes_accepted(string $scheme): void {
        $endpoint = $this->valid_endpoint('events');
        $endpoint['auth']['scheme'] = $scheme;
        $manifest = Manifest::validate($this->webhook_manifest([$endpoint]));
        $this->assertSame($scheme, $manifest->webhook_endpoints()[0]['auth']['scheme']);
    }

    /** @return array<string, array{0:string}> */
    public static function valid_hmac_schemes(): array {
        return [
            'stripe'  => ['stripe'],
            'github'  => ['github'],
            'slack'   => ['slack'],
            'generic' => ['generic'],
        ];
    }

    public function test_webhook_auth_secret_not_declared(): void {
        $endpoint = $this->valid_endpoint('stripe-events');
        $endpoint['auth']['secret_alias'] = 'NOT_DECLARED';
        $arr = $this->webhook_manifest([$endpoint]);
        $this->expectExceptionMessage('webhook_auth_secret_not_declared');
        Manifest::validate($arr);
    }

    public function test_webhook_bearer_auth_accepted(): void {
        $arr = $this->webhook_manifest([[
            'id'      => 'bearer-events',
            'ability' => 'sample/do-it',
            'auth'    => ['type' => 'bearer', 'secret_alias' => 'STRIPE'],
        ]]);
        $manifest = Manifest::validate($arr);
        $this->assertSame('bearer', $manifest->webhook_endpoints()[0]['auth']['type']);
    }

    public function test_webhook_bearer_rejects_scheme_field(): void {
        // `scheme` is only meaningful for hmac-sha256. Surface a clear error
        // so authors don't think they can mix shapes.
        $arr = $this->webhook_manifest([[
            'id'      => 'bearer-events',
            'ability' => 'sample/do-it',
            'auth'    => ['type' => 'bearer', 'scheme' => 'stripe', 'secret_alias' => 'STRIPE'],
        ]]);
        $this->expectExceptionMessage('webhook_auth_scheme_not_applicable');
        Manifest::validate($arr);
    }

    public function test_webhook_rate_limit_0_rejected(): void {
        $endpoint = $this->valid_endpoint('stripe-events');
        $endpoint['rate_limit_per_minute'] = 0;
        $arr = $this->webhook_manifest([$endpoint]);
        $this->expectExceptionMessage('webhook_rate_limit_invalid');
        Manifest::validate($arr);
    }

    public function test_webhook_rate_limit_601_rejected(): void {
        $endpoint = $this->valid_endpoint('stripe-events');
        $endpoint['rate_limit_per_minute'] = 601;
        $arr = $this->webhook_manifest([$endpoint]);
        $this->expectExceptionMessage('webhook_rate_limit_invalid');
        Manifest::validate($arr);
    }

    public function test_webhook_rate_limit_600_accepted(): void {
        $endpoint = $this->valid_endpoint('stripe-events');
        $endpoint['rate_limit_per_minute'] = 600;
        $manifest = Manifest::validate($this->webhook_manifest([$endpoint]));
        $this->assertSame(600, $manifest->webhook_endpoints()[0]['rate_limit_per_minute']);
    }

    public function test_webhook_async_true_accepted(): void {
        $endpoint = $this->valid_endpoint('stripe-events');
        $endpoint['async'] = true;
        $manifest = Manifest::validate($this->webhook_manifest([$endpoint]));
        $this->assertTrue($manifest->webhook_endpoints()[0]['async']);
    }

    public function test_webhook_idempotency_header_string_accepted(): void {
        $endpoint = $this->valid_endpoint('stripe-events');
        $endpoint['idempotency_header'] = 'Stripe-Event-Id';
        $manifest = Manifest::validate($this->webhook_manifest([$endpoint]));
        $this->assertSame('Stripe-Event-Id', $manifest->webhook_endpoints()[0]['idempotency_header']);
    }

    public function test_webhook_idempotency_header_non_string_rejected(): void {
        $endpoint = $this->valid_endpoint('stripe-events');
        $endpoint['idempotency_header'] = 123;
        $arr = $this->webhook_manifest([$endpoint]);
        $this->expectExceptionMessage('webhook_idempotency_header_invalid');
        Manifest::validate($arr);
    }

    public function test_webhook_endpoints_typed_accessor_round_trip(): void {
        $endpoints = [
            $this->valid_endpoint('a'),
            $this->valid_endpoint('b'),
        ];
        $manifest = Manifest::validate($this->webhook_manifest($endpoints));
        $this->assertCount(2, $manifest->webhook_endpoints());
        $this->assertSame('a', $manifest->webhook_endpoints()[0]['id']);
        $this->assertSame('b', $manifest->webhook_endpoints()[1]['id']);
    }

    // ===== helpers =====

    /** @return array<string, mixed> */
    private function base_manifest(): array {
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
            'abilities'        => ['publishes' => []],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $endpoints
     * @return array<string, mixed>
     */
    private function webhook_manifest(array $endpoints): array {
        $arr = $this->base_manifest();
        $arr['abilities']['publishes'][] = [
            'name'        => 'sample/do-it',
            'label'       => 'Do it',
            'description' => 'A sample published ability carrying execute_php.',
            'category'    => 'content',
            'execute_php' => ['class' => 'Acme\\Foo', 'method' => 'execute'],
        ];
        $arr['permissions']['run'] = ['webhooks'];
        $arr['secrets'] = [['alias' => 'STRIPE', 'description' => 'Stripe signing secret (test).']];
        $arr['webhooks'] = ['endpoints' => $endpoints];
        return $arr;
    }

    /** @return array<string, mixed> */
    private function valid_endpoint(string $id): array {
        return [
            'id'      => $id,
            'ability' => 'sample/do-it',
            'auth'    => [
                'type'         => 'hmac-sha256',
                'scheme'       => 'stripe',
                'secret_alias' => 'STRIPE',
            ],
        ];
    }
}
