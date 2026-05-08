<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Rewrite;
use WP_UnitTestCase;

class RewriteTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        global $wp_rewrite;
        Rewrite::register();
        $wp_rewrite->set_permalink_structure('/%postname%/');
        $wp_rewrite->flush_rules(false);
    }

    public function test_query_var_registered(): void {
        global $wp;
        $this->assertContains(Rewrite::QUERY_VAR, $wp->public_query_vars);
    }

    public function test_rewrite_rule_added(): void {
        global $wp_rewrite;
        $rules = $wp_rewrite->wp_rewrite_rules();
        $found = false;
        foreach ($rules as $pattern => $target) {
            if (str_contains($target, Rewrite::QUERY_VAR)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'expected a rewrite rule mapping to dsgo_app query var');
    }

    public function test_rewrite_rule_uses_default_apps_prefix(): void {
        global $wp_rewrite;
        $rules   = $wp_rewrite->wp_rewrite_rules();
        $matched = false;
        foreach ($rules as $pattern => $_target) {
            if (str_starts_with($pattern, '^apps/')) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue($matched, 'expected default rewrite rule to start with ^apps/');
    }

    public function test_rewrite_rule_uses_configured_prefix(): void {
        update_option(\DSGo_Apps\Settings::OPTION_URL_PREFIX, 'mini');
        try {
            global $wp_rewrite;
            // Re-register and flush so the new prefix is appended for this request.
            // The static rule registry accumulates across calls in a single PHP
            // process — production avoids that because each request hits a fresh
            // PHP_init — so we only assert the new rule is present, not that the
            // old one is gone.
            Rewrite::register();
            $wp_rewrite->flush_rules(false);
            $rules  = $wp_rewrite->wp_rewrite_rules();
            $custom = false;
            foreach ($rules as $pattern => $_target) {
                if (str_starts_with($pattern, '^mini/')) {
                    $custom = true;
                    break;
                }
            }
            $this->assertTrue($custom, 'expected rewrite rule to start with ^mini/ after option update');
        } finally {
            delete_option(\DSGo_Apps\Settings::OPTION_URL_PREFIX);
            Rewrite::register();
            global $wp_rewrite;
            $wp_rewrite->flush_rules(false);
        }
    }

    public function test_empty_prefix_registers_root_capturing_rule(): void {
        update_option(\DSGo_Apps\Settings::OPTION_URL_PREFIX, '');
        try {
            global $wp_rewrite;
            Rewrite::register();
            $wp_rewrite->flush_rules(false);
            $rules = $wp_rewrite->wp_rewrite_rules();

            $expected_pattern = '^([^/]+)(?:/(.+))?/?$';
            $this->assertArrayHasKey(
                $expected_pattern,
                $rules,
                'expected empty-prefix mode to register the root-capturing rewrite rule',
            );
            $this->assertStringContainsString(Rewrite::QUERY_VAR, $rules[$expected_pattern]);
        } finally {
            delete_option(\DSGo_Apps\Settings::OPTION_URL_PREFIX);
            Rewrite::register();
            global $wp_rewrite;
            $wp_rewrite->flush_rules(false);
        }
    }

    public function test_empty_prefix_does_not_shadow_real_pages(): void {
        // With prefix='' our rule registers at 'bottom' so WP's own page
        // resolution still wins for slugs that map to a real page. Create a
        // page named 'about' and confirm it resolves to the page, not an app.
        $page_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'post_title'  => 'About',
            'post_name'   => 'about',
        ]);
        update_option(\DSGo_Apps\Settings::OPTION_URL_PREFIX, '');
        try {
            global $wp_rewrite;
            Rewrite::register();
            $wp_rewrite->flush_rules(false);

            $this->go_to(home_url('/about/'));
            $this->assertTrue(is_page('about'), 'real page should win over empty-prefix app rule');
            $this->assertSame('', (string) get_query_var(Rewrite::QUERY_VAR));
        } finally {
            wp_delete_post($page_id, true);
            delete_option(\DSGo_Apps\Settings::OPTION_URL_PREFIX);
            Rewrite::register();
            global $wp_rewrite;
            $wp_rewrite->flush_rules(false);
        }
    }
}
