<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\PostType;
use DSGo_Apps\Storage;
use DSGo_Apps\StorageError;
use WP_UnitTestCase;

class StorageTest extends WP_UnitTestCase {

    private int $app_post_id;

    public function set_up(): void {
        parent::set_up();
        $this->app_post_id = $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'sample',
        ]);
    }

    public function test_app_set_and_get_roundtrip(): void {
        Storage::app_set($this->app_post_id, 'theme', ['mode' => 'dark']);
        $this->assertSame(['mode' => 'dark'], Storage::app_get($this->app_post_id, 'theme'));
    }

    public function test_app_get_unknown_returns_null(): void {
        $this->assertNull(Storage::app_get($this->app_post_id, 'never-set'));
    }

    public function test_user_set_requires_user(): void {
        $this->expectException(StorageError::class);
        $this->expectExceptionMessage('not_authenticated');
        Storage::user_set($this->app_post_id, 0, 'k', 'v');
    }

    public function test_user_set_and_get_roundtrip(): void {
        $uid = $this->factory->user->create();
        Storage::user_set($this->app_post_id, $uid, 'pref', 42);
        $this->assertSame(42, Storage::user_get($this->app_post_id, $uid, 'pref'));
    }

    public function test_user_get_anon_returns_null(): void {
        $this->assertNull(Storage::user_get($this->app_post_id, 0, 'k'));
    }

    public function test_invalid_key_rejected(): void {
        $this->expectException(StorageError::class);
        $this->expectExceptionMessage('invalid_params');
        Storage::app_set($this->app_post_id, 'has spaces', 'v');
    }

    public function test_quota_enforced(): void {
        $big = str_repeat('x', 200_000);
        Storage::app_set($this->app_post_id, 'a', $big);
        $this->expectException(StorageError::class);
        $this->expectExceptionMessage('payload_too_large');
        Storage::app_set($this->app_post_id, 'b', $big);
    }
}
