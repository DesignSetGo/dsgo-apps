<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

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
}
