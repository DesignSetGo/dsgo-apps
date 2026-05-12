<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Bucket;
use DSGo_Apps\DisplayMode;
use DSGo_Apps\Manifest;
use DSGo_Apps\Permission;
use DSGo_Apps\Permissions;
use WP_UnitTestCase;

class PermissionsTest extends WP_UnitTestCase {

    public function test_required_permission_for_known_methods(): void {
        $this->assertSame(Permission::SiteInfo, Permissions::required('site.info'));
        $this->assertSame(Permission::Posts,    Permissions::required('posts.list'));
        $this->assertSame(Permission::Posts,    Permissions::required('posts.get'));
        $this->assertSame(Permission::Pages,    Permissions::required('pages.list'));
        $this->assertSame(Permission::Pages,    Permissions::required('pages.get'));
        $this->assertSame(Permission::User,     Permissions::required('user.current'));
        $this->assertSame(Permission::User,     Permissions::required('user.can'));
    }

    public function test_methods_with_no_permission_required(): void {
        $this->assertNull(Permissions::required('storage.app.get'));
        $this->assertNull(Permissions::required('storage.app.set'));
        $this->assertNull(Permissions::required('storage.user.get'));
        $this->assertNull(Permissions::required('storage.user.set'));
        $this->assertNull(Permissions::required('bridge.ping'));
        // media.upload is core/opt-out — no manifest permission required.
        $this->assertNull(Permissions::required('media.upload'));
    }

    public function test_unknown_method_throws(): void {
        $this->expectException(\InvalidArgumentException::class);
        Permissions::required('frob.nicate');
    }

    public function test_to_array_for_localize(): void {
        $arr = Permissions::to_array();
        $this->assertSame('site_info', $arr['site.info']);
        $this->assertSame('posts',     $arr['posts.list']);
        $this->assertNull($arr['storage.app.get']);
        $this->assertNull($arr['bridge.ping']);
    }

    // --- Bucket enum + active_for() (added 2026-05-09 for the bucket model) ---
    //
    // These tests construct Manifest objects directly (bypassing Manifest::validate())
    // because several of the manifest fields the bucket model detects (permissions.http,
    // permissions.write non-empty, scheduled.jobs, webhooks.endpoints, permissions.send,
    // commerce.endpoints) are gated by validators that ship with the corresponding
    // bridge-method specs. Bucket::active_for() reads via Manifest::raw_field() so the
    // bucket detection works regardless of which bridge specs have shipped their
    // typed validators yet.

    public function test_bucket_enum_values(): void {
        $this->assertSame('read_content',      Bucket::ReadContent->value);
        $this->assertSame('write_content',     Bucket::WriteContent->value);
        $this->assertSame('external_services', Bucket::ExternalServices->value);
        $this->assertSame('send_messages',     Bucket::SendMessages->value);
        $this->assertSame('ai',                Bucket::Ai->value);
        $this->assertSame('run_automatically', Bucket::RunAutomatically->value);
        $this->assertSame('commerce',          Bucket::Commerce->value);
    }

    public function test_bucket_active_for_returns_read_content_when_only_posts_declared(): void {
        $m = $this->minimal_manifest_with_raw(
            ['permissions' => ['read' => ['posts']]],
            permissions_read: [Permission::Posts],
        );
        $this->assertEquals([Bucket::ReadContent], Bucket::active_for($m));
    }

    public function test_bucket_active_for_combines_writes_and_reads(): void {
        $m = $this->minimal_manifest_with_raw(
            ['permissions' => ['read' => ['posts'], 'write' => ['post']]],
            permissions_read: [Permission::Posts],
        );
        $this->assertEquals(
            [Bucket::ReadContent, Bucket::WriteContent],
            Bucket::active_for($m),
        );
    }

    public function test_bucket_active_for_picks_up_external_services_from_http_array(): void {
        $m = $this->minimal_manifest_with_raw(
            ['permissions' => ['read' => [], 'http' => ['api.stripe.com']]],
        );
        $this->assertEquals([Bucket::ExternalServices], Bucket::active_for($m));
    }

    public function test_bucket_active_for_picks_up_send_messages_from_permissions_send_email(): void {
        $m = $this->minimal_manifest_with_raw(
            ['permissions' => ['send' => ['email']]],
        );
        $this->assertEquals([Bucket::SendMessages], Bucket::active_for($m));
    }

    public function test_bucket_active_for_does_not_emit_send_messages_for_email_in_permissions_read(): void {
        // Email moved from permissions.read to permissions.send. The bucket
        // model detects via permissions.send only — legacy 'email' in
        // permissions.read does NOT activate Send messages.
        $m = $this->minimal_manifest_with_raw(
            ['permissions' => ['read' => ['email']]],
        );
        $this->assertNotContains(Bucket::SendMessages, Bucket::active_for($m));
    }

    public function test_bucket_active_for_picks_up_ai_from_permissions_read(): void {
        $m = $this->minimal_manifest_with_raw(
            ['permissions' => ['read' => ['ai']]],
            permissions_read: [Permission::Ai],
        );
        $this->assertEquals([Bucket::Ai], Bucket::active_for($m));
    }

    public function test_bucket_active_for_picks_up_run_automatically_from_scheduled_jobs(): void {
        $m = $this->minimal_manifest_with_raw([
            'permissions' => ['run' => ['scheduled']],
            'scheduled'   => ['jobs' => [['id' => 'x', 'ability' => 'a/b', 'schedule' => 'daily']]],
        ]);
        $this->assertEquals([Bucket::RunAutomatically], Bucket::active_for($m));
    }

    public function test_bucket_active_for_picks_up_run_automatically_from_webhook_endpoints(): void {
        $m = $this->minimal_manifest_with_raw([
            'permissions' => ['run' => ['webhooks']],
            'webhooks'    => ['endpoints' => [['id' => 'stripe', 'ability' => 'a/b', 'auth' => []]]],
        ]);
        $this->assertEquals([Bucket::RunAutomatically], Bucket::active_for($m));
    }

    public function test_bucket_active_for_picks_up_commerce_from_endpoints(): void {
        $m = $this->minimal_manifest_with_raw(
            ['commerce' => ['endpoints' => ['products']]],
        );
        $this->assertEquals([Bucket::Commerce], Bucket::active_for($m));
    }

    public function test_bucket_active_for_does_not_emit_storage_bucket(): void {
        $m = $this->minimal_manifest_with_raw([]);
        $values = array_map(fn (Bucket $b) => $b->value, Bucket::active_for($m));
        $this->assertNotContains('storage', $values);
        $this->assertSame([], $values);
    }

    public function test_bucket_active_for_returns_dedup_set(): void {
        // Same bucket activated by two paths (e.g., posts in read + ai in read)
        // returns one entry per bucket, not duplicates.
        $m = $this->minimal_manifest_with_raw(
            ['permissions' => ['read' => ['posts', 'pages', 'user']]],
            permissions_read: [Permission::Posts, Permission::Pages, Permission::User],
        );
        $buckets = Bucket::active_for($m);
        $this->assertCount(1, $buckets);
        $this->assertSame(Bucket::ReadContent, $buckets[0]);
    }

    public function test_bucket_from_permission_maps_known_perms(): void {
        $this->assertSame(Bucket::ReadContent, Bucket::from_permission(Permission::Posts));
        $this->assertSame(Bucket::ReadContent, Bucket::from_permission(Permission::Pages));
        $this->assertSame(Bucket::ReadContent, Bucket::from_permission(Permission::User));
        $this->assertSame(Bucket::ReadContent, Bucket::from_permission(Permission::SiteInfo));
        $this->assertSame(Bucket::ReadContent, Bucket::from_permission(Permission::Abilities));
        $this->assertSame(Bucket::Ai,          Bucket::from_permission(Permission::Ai));
        $this->assertSame(Bucket::Commerce,    Bucket::from_permission(Permission::Commerce));
    }

    public function test_bucket_from_permission_returns_null_for_unmapped(): void {
        // Email is in permissions.send (not read) — no read-side bucket mapping.
        $this->assertNull(Bucket::from_permission(Permission::Email));
    }

    // --- Round-trip contract: active_for(Manifest) is fresh-validated only ---

    public function test_active_for_survives_round_trip_for_typed_buckets(): void {
        // ReadContent and Ai activate from typed Permission enum cases that
        // DO survive to_array() → from_array_unchecked() round-trip. This
        // test pins that contract.
        $raw = [
            'manifest_version' => 1,
            'id'               => 'sample-app',
            'name'             => 'Sample',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => ['posts', 'ai'], 'write' => []],
            'runtime'          => ['sandbox' => 'strict'],
        ];
        $fresh    = Manifest::validate($raw);
        $hydrated = Manifest::from_array_unchecked($fresh->to_array());

        $fresh_buckets    = array_map(fn (Bucket $b) => $b->value, Bucket::active_for($fresh));
        $hydrated_buckets = array_map(fn (Bucket $b) => $b->value, Bucket::active_for($hydrated));

        $this->assertSame(['read_content', 'ai'], $fresh_buckets);
        $this->assertSame($fresh_buckets, $hydrated_buckets,
            'Typed buckets (ReadContent/Ai) must survive round-trip');
    }

    public function test_active_for_documents_round_trip_gap_for_supplementary_buckets(): void {
        // WriteContent, ExternalServices, SendMessages activate from
        // supplementary blocks (permissions.write/http/send) that are NOT
        // serialized by to_array(). Hydrating drops them — documented gap.
        //
        // RunAutomatically activates from scheduled.jobs + webhooks.endpoints.
        // to_array() NOW serializes both blocks into the stored array, so
        // RunAutomatically SURVIVES the round-trip and is no longer a gap.
        //
        // NOTE: We construct the fresh Manifest directly (bypassing validate)
        // because today's validator rejects permissions.write non-empty.
        // When the writes spec ships and lifts that rejection, this test
        // should be revisited — at that point the writes spec is responsible
        // for ALSO updating to_array() to serialize permissions.write, and
        // the write_content assertion below should flip to assertContains.
        //
        // Commerce is intentionally OMITTED from this test: the Commerce
        // bucket can activate either via Permission::Commerce in
        // permissions.read (typed, round-trip safe) OR via commerce.endpoints
        // raw (supplementary, NOT round-trip safe via raw — though to_array
        // does serialize the typed commerce_endpoints when populated). That
        // ambiguity belongs in its own dedicated test if/when it matters;
        // not muddying this one.
        $raw_with_supplementary = [
            'permissions' => [
                'read'  => ['posts'],
                'write' => ['post'],
                'http'  => ['api.stripe.com'],
                'send'  => ['email'],
            ],
            'scheduled'   => ['jobs' => [['id' => 'x', 'ability' => 'a/b', 'schedule' => 'daily']]],
            'webhooks'    => ['endpoints' => [['id' => 'stripe']]],
        ];
        $fresh = $this->minimal_manifest_with_raw(
            $raw_with_supplementary,
            permissions_read: [Permission::Posts],
        );
        $hydrated = Manifest::from_array_unchecked($fresh->to_array());

        $fresh_buckets    = array_map(fn (Bucket $b) => $b->value, Bucket::active_for($fresh));
        $hydrated_buckets = array_map(fn (Bucket $b) => $b->value, Bucket::active_for($hydrated));

        // Fresh: every bucket the supplementary blocks activate is present.
        $this->assertContains('write_content',     $fresh_buckets);
        $this->assertContains('external_services', $fresh_buckets);
        $this->assertContains('send_messages',     $fresh_buckets);
        $this->assertContains('run_automatically', $fresh_buckets);

        // Hydrated: permissions.write/http/send still gap — those three buckets drop.
        $this->assertNotContains('write_content',     $hydrated_buckets);
        $this->assertNotContains('external_services', $hydrated_buckets);
        $this->assertNotContains('send_messages',     $hydrated_buckets);
        // run_automatically now survives: to_array() serializes scheduled.jobs
        // and webhooks.endpoints, so hydration restores the RunAutomatically bucket.
        $this->assertContains('run_automatically', $hydrated_buckets);
    }

    public function test_active_for_raw_is_round_trip_safe_when_caller_keeps_raw(): void {
        // The documented escape hatch: callers that retain the original raw
        // payload should use active_for_raw() instead of active_for(Manifest).
        // It works regardless of round-trip status.
        $raw = [
            'permissions' => ['read' => ['posts'], 'http' => ['api.stripe.com']],
        ];
        $perms_read = ['posts'];
        $buckets = array_map(fn (Bucket $b) => $b->value, Bucket::active_for_raw($raw, $perms_read));
        $this->assertSame(['read_content', 'external_services'], $buckets);
    }

    /**
     * Construct a Manifest directly with the given raw array, bypassing
     * Manifest::validate(). Permissions::read can be passed typed via the
     * second arg if the test wants the Permission enum cases populated;
     * defaults to empty.
     *
     * @param array<string, mixed> $raw
     * @param Permission[] $permissions_read
     */
    private function minimal_manifest_with_raw(array $raw, array $permissions_read = []): Manifest {
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
