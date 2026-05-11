<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Bucket;
use DSGo_Apps\Bucket_Renderer;
use DSGo_Apps\DisplayMode;
use DSGo_Apps\Manifest;
use DSGo_Apps\Permission;
use WP_UnitTestCase;

/**
 * Tests for Bucket_Renderer — the install-dialog row HTML for a single
 * permission bucket (label + justification + expandable detail panel).
 *
 * Wave A (ships with this plan): label rendering, justification fallback,
 * NEW-PERMISSION marking, escaping, and the three buckets whose underlying
 * fields are validated today (read_content, ai, external_services using raw
 * fixtures).
 *
 * Wave B (fixture-only — activates as later specs ship): write_content,
 * send_messages, run_automatically, commerce. The renderer reads via
 * Manifest::raw_field() so these tests pass today against raw fixtures
 * without going through Manifest::validate() (which would reject the
 * supplementary blocks).
 */
class BucketRendererTest extends WP_UnitTestCase {

    // --- Wave A: label + justification + escape + diff marker ----------

    public function test_render_row_includes_bucket_label_for_each_bucket(): void {
        foreach (Bucket::cases() as $bucket) {
            $m = $this->minimal_manifest();
            $html = Bucket_Renderer::render_row($bucket, $m, null);
            $this->assertNotEmpty(
                $html,
                "render_row produced empty output for {$bucket->value}",
            );
            // The bucket value (e.g. "read_content") must appear as a class or
            // data attribute so the install dialog JS can find the row.
            $this->assertStringContainsString(
                $bucket->value,
                $html,
                "render_row output for {$bucket->value} does not reference its bucket key",
            );
        }
    }

    public function test_render_row_uses_author_justification_when_provided(): void {
        $m = $this->manifest_with_raw([
            'permissions' => [
                'read'           => ['posts'],
                'write'          => [],
                'justifications' => [
                    'read_content' => 'Builds a searchable archive of recent posts.',
                ],
            ],
        ], permissions_read: [Permission::Posts]);

        $html = Bucket_Renderer::render_row(Bucket::ReadContent, $m, null);
        $this->assertStringContainsString(
            'Builds a searchable archive of recent posts.',
            $html,
        );
    }

    public function test_render_row_uses_default_copy_when_justification_absent(): void {
        $m = $this->manifest_with_raw(
            ['permissions' => ['read' => ['posts']]],
            permissions_read: [Permission::Posts],
        );
        $html = Bucket_Renderer::render_row(Bucket::ReadContent, $m, null);
        // Default copy from spec table.
        $this->assertStringContainsString(
            'Reads posts, pages, and user data from your site.',
            $html,
        );
    }

    public function test_render_row_has_default_copy_for_every_bucket(): void {
        // Each canonical bucket has default copy defined; no bucket falls
        // back to empty when justification is absent.
        $m = $this->minimal_manifest();
        foreach (Bucket::cases() as $bucket) {
            $html = Bucket_Renderer::render_row($bucket, $m, null);
            // Strip tags to test for any non-empty justification text.
            $text = trim(wp_strip_all_tags($html));
            $this->assertNotEmpty(
                $text,
                "Bucket {$bucket->value} renders no visible text without justification",
            );
        }
    }

    public function test_render_row_strips_script_tags_from_author_justification(): void {
        // wp_kses_post strips <script> tags — that's the XSS prevention.
        // Inner text content survives as plain text, which is harmless (the
        // browser won't execute "alert(...)" without script tags around it).
        // Belt-and-suspenders: the validator already rejects HTML in
        // justifications, but if a future caller bypasses validation we
        // still want the renderer to be safe.
        $m = $this->manifest_with_raw([
            'permissions' => [
                'read'           => ['posts'],
                'justifications' => [
                    'read_content' => '<script>alert("xss")</script>Legit reason text.',
                ],
            ],
        ], permissions_read: [Permission::Posts]);

        $html = Bucket_Renderer::render_row(Bucket::ReadContent, $m, null);
        $this->assertStringNotContainsString('<script>',  $html);
        $this->assertStringNotContainsString('</script>', $html);
        // The non-script content survives as text — that's expected.
        $this->assertStringContainsString('Legit reason text.', $html);
    }

    public function test_render_row_strips_event_handler_attributes(): void {
        // onclick=, onerror= and other event handler attributes should be
        // stripped by wp_kses_post even when they're attached to safe tags.
        $m = $this->manifest_with_raw([
            'permissions' => [
                'read'           => ['posts'],
                'justifications' => [
                    'read_content' => '<span onclick="alert(1)">click me</span>',
                ],
            ],
        ], permissions_read: [Permission::Posts]);

        $html = Bucket_Renderer::render_row(Bucket::ReadContent, $m, null);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    public function test_render_row_marks_new_permission_when_not_previously_approved(): void {
        $m = $this->manifest_with_raw(
            ['permissions' => ['read' => ['ai']]],
            permissions_read: [Permission::Ai],
        );
        // Previously approved set: only read_content. AI is new.
        $html = Bucket_Renderer::render_row(Bucket::Ai, $m, ['read_content']);
        $this->assertMatchesRegularExpression(
            '/dsgo-bucket--new|new-permission/',
            $html,
            'New bucket should carry a "new permission" marker class',
        );
    }

    public function test_render_row_does_not_mark_new_when_previously_approved(): void {
        $m = $this->manifest_with_raw(
            ['permissions' => ['read' => ['ai']]],
            permissions_read: [Permission::Ai],
        );
        $html = Bucket_Renderer::render_row(Bucket::Ai, $m, ['ai', 'read_content']);
        $this->assertDoesNotMatchRegularExpression(
            '/dsgo-bucket--new|new-permission/',
            $html,
        );
    }

    public function test_render_row_does_not_mark_new_on_first_install(): void {
        // null $previously_approved = first install; nothing is "new" because
        // every bucket is first-time.
        $m = $this->manifest_with_raw(
            ['permissions' => ['read' => ['ai']]],
            permissions_read: [Permission::Ai],
        );
        $html = Bucket_Renderer::render_row(Bucket::Ai, $m, null);
        $this->assertDoesNotMatchRegularExpression(
            '/dsgo-bucket--new|new-permission/',
            $html,
        );
    }

    // --- Wave A details: read_content, ai, external_services -----------

    public function test_render_read_content_details_lists_permission_strings(): void {
        $m = $this->manifest_with_raw(
            ['permissions' => ['read' => ['posts', 'pages', 'user']]],
            permissions_read: [Permission::Posts, Permission::Pages, Permission::User],
        );
        $html = Bucket_Renderer::render_row(Bucket::ReadContent, $m, null);
        $this->assertStringContainsString('posts', $html);
        $this->assertStringContainsString('pages', $html);
        $this->assertStringContainsString('user',  $html);
    }

    public function test_render_ai_details_lists_abilities_consumes_patterns(): void {
        $m = $this->manifest_with_raw([
            'permissions' => ['read' => ['ai', 'abilities']],
            'abilities'   => ['consumes' => ['yoast/analyze-page-seo', 'rank-math/*']],
        ], permissions_read: [Permission::Ai, Permission::Abilities]);

        $html = Bucket_Renderer::render_row(Bucket::Ai, $m, null);
        $this->assertStringContainsString('yoast/analyze-page-seo', $html);
        $this->assertStringContainsString('rank-math/*',            $html);
    }

    public function test_render_external_services_details_lists_hostnames_and_secrets(): void {
        $m = $this->manifest_with_raw([
            'permissions' => ['http' => ['api.stripe.com', '*.notion.com']],
            'secrets'     => [
                ['alias' => 'STRIPE_SECRET', 'description' => 'Stripe secret key'],
                ['alias' => 'NOTION_TOKEN',  'description' => 'Notion integration token'],
            ],
        ]);
        $html = Bucket_Renderer::render_row(Bucket::ExternalServices, $m, null);
        $this->assertStringContainsString('api.stripe.com', $html);
        $this->assertStringContainsString('*.notion.com',   $html);
        $this->assertStringContainsString('STRIPE_SECRET',  $html);
        $this->assertStringContainsString('NOTION_TOKEN',   $html);
    }

    // --- Wave B: stubs that exercise the renderer machinery via raw -----

    public function test_render_write_content_details_lists_declared_post_types(): void {
        $m = $this->manifest_with_raw([
            'permissions' => ['write' => ['post', 'page', 'product']],
        ]);
        $html = Bucket_Renderer::render_row(Bucket::WriteContent, $m, null);
        $this->assertStringContainsString('post',    $html);
        $this->assertStringContainsString('page',    $html);
        $this->assertStringContainsString('product', $html);
    }

    public function test_render_send_messages_details_handles_anonymous_with_empty_recipients(): void {
        // Validator rejects allow_anonymous: true + empty recipients, but the
        // renderer is the public surface — should never produce a heading
        // with no list under it if a future caller bypasses validation.
        $m = $this->manifest_with_raw([
            'permissions' => ['send' => ['email']],
            'email'       => [
                'allow_anonymous'      => true,
                'anonymous_recipients' => [],
            ],
        ]);
        $html = Bucket_Renderer::render_row(Bucket::SendMessages, $m, null);
        // Should NOT render the "restricted to:" heading without a list under it.
        $this->assertStringNotContainsString('restricted to:', $html);
        // Should explain that anonymous is enabled but empty.
        $this->assertStringContainsString('Anonymous email enabled', $html);
    }

    public function test_render_send_messages_details_lists_recipient_aliases_when_anonymous(): void {
        $m = $this->manifest_with_raw([
            'permissions' => ['send' => ['email']],
            'email'       => [
                'allow_anonymous'      => true,
                'anonymous_recipients' => ['admin', '*@example.com'],
            ],
        ]);
        $html = Bucket_Renderer::render_row(Bucket::SendMessages, $m, null);
        $this->assertStringContainsString('admin',          $html);
        $this->assertStringContainsString('*@example.com',  $html);
    }

    public function test_render_run_automatically_details_lists_schedules_and_webhook_endpoints(): void {
        $m = $this->manifest_with_raw([
            'permissions' => ['run' => ['scheduled', 'webhooks']],
            'scheduled'   => ['jobs' => [
                ['id' => 'daily-digest', 'ability' => 'a/b', 'schedule' => 'daily'],
            ]],
            'webhooks'    => ['endpoints' => [
                ['id' => 'stripe', 'ability' => 'a/c', 'auth' => []],
            ]],
        ]);
        $html = Bucket_Renderer::render_row(Bucket::RunAutomatically, $m, null);
        $this->assertStringContainsString('daily-digest', $html);
        $this->assertStringContainsString('stripe',       $html);
    }

    public function test_render_commerce_details_lists_endpoints(): void {
        $m = $this->manifest_with_raw([
            'commerce' => ['endpoints' => ['products', 'cart', 'checkout']],
        ]);
        $html = Bucket_Renderer::render_row(Bucket::Commerce, $m, null);
        $this->assertStringContainsString('products', $html);
        $this->assertStringContainsString('cart',     $html);
        $this->assertStringContainsString('checkout', $html);
    }

    // --- Helpers --------------------------------------------------------

    private function minimal_manifest(): Manifest {
        return $this->manifest_with_raw([], permissions_read: []);
    }

    /**
     * @param array<string, mixed> $raw
     * @param Permission[] $permissions_read
     */
    private function manifest_with_raw(array $raw, array $permissions_read = []): Manifest {
        return new Manifest(
            id: 'sample',
            name: 'Sample',
            description: null,
            version: '0.1.0',
            author: null,
            entry: 'index.html',
            display_modes: [DisplayMode::Page],
            display_default: DisplayMode::Page,
            display_icon: null,
            permissions_read: $permissions_read,
            external_origins: [],
            raw: $raw,
        );
    }
}
