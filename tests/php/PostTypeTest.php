<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\PostType;
use WP_UnitTestCase;

class PostTypeTest extends WP_UnitTestCase {

    public function test_post_type_registered(): void {
        $this->assertTrue(post_type_exists(PostType::SLUG));
    }

    public function test_post_type_not_publicly_queryable(): void {
        $obj = get_post_type_object(PostType::SLUG);
        $this->assertFalse($obj->public);
        $this->assertFalse($obj->show_in_rest);
        $this->assertFalse($obj->publicly_queryable);
    }

    public function test_supports_only_title(): void {
        $this->assertTrue(post_type_supports(PostType::SLUG, 'title'));
        $this->assertFalse(post_type_supports(PostType::SLUG, 'editor'));
    }
}
