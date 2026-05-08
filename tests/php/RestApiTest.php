<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\PostType;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

class RestApiTest extends WP_UnitTestCase {

    protected WP_REST_Server $server;
    protected int $admin_id;
    protected int $subscriber_id;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init');

        $this->admin_id      = $this->factory->user->create(['role' => 'administrator']);
        $this->subscriber_id = $this->factory->user->create(['role' => 'subscriber']);
    }

    public function test_list_apps_requires_admin(): void {
        wp_set_current_user($this->subscriber_id);
        $req = new WP_REST_Request('GET', '/dsgo/v1/apps');
        $resp = $this->server->dispatch($req);
        $this->assertSame(403, $resp->get_status());
    }

    public function test_list_apps_returns_installed(): void {
        $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'app-a',
            'post_title'  => 'App A',
        ]);
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('GET', '/dsgo/v1/apps');
        $resp = $this->server->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $items = $resp->get_data();
        $this->assertCount(1, $items);
        $this->assertSame('app-a', $items[0]['id']);
    }

    public function test_delete_app_requires_admin(): void {
        $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'doomed',
        ]);
        wp_set_current_user($this->subscriber_id);
        $req = new WP_REST_Request('DELETE', '/dsgo/v1/apps/doomed');
        $resp = $this->server->dispatch($req);
        $this->assertSame(403, $resp->get_status());
    }

    public function test_delete_app_removes_post(): void {
        $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'doomed',
        ]);
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('DELETE', '/dsgo/v1/apps/doomed');
        $resp = $this->server->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $this->assertNull(get_page_by_path('doomed', OBJECT, PostType::SLUG));
    }

    public function test_can_returns_boolean_for_logged_in_user(): void {
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('GET', '/dsgo/v1/can');
        $req->set_query_params(['cap' => 'manage_options']);
        $resp = $this->server->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $this->assertTrue($resp->get_data()['can']);
    }

    public function test_can_unknown_cap_returns_false(): void {
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('GET', '/dsgo/v1/can');
        $req->set_query_params(['cap' => 'no_such_cap']);
        $resp = $this->server->dispatch($req);
        $this->assertFalse($resp->get_data()['can']);
    }

    /** Mint the per-(user, app) storage nonce that `permit_storage` requires. */
    private function app_nonce_for(int $user_id, string $app_id): string {
        return wp_create_nonce(\DSGo_Apps\RestApi::app_nonce_action($user_id, $app_id));
    }

    public function test_storage_app_roundtrip(): void {
        $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'app-a',
        ]);
        wp_set_current_user($this->admin_id);
        $nonce = $this->app_nonce_for($this->admin_id, 'app-a');

        $put = new WP_REST_Request('PUT', '/dsgo/v1/apps/app-a/storage/app/theme');
        $put->set_header('X-DSGo-App-Nonce', $nonce);
        $put->set_body_params(['value' => ['mode' => 'dark']]);
        $resp = $this->server->dispatch($put);
        $this->assertSame(200, $resp->get_status());

        $get = new WP_REST_Request('GET', '/dsgo/v1/apps/app-a/storage/app/theme');
        $get->set_header('X-DSGo-App-Nonce', $nonce);
        $resp = $this->server->dispatch($get);
        $this->assertSame(['mode' => 'dark'], $resp->get_data()['value']);
    }

    public function test_storage_user_set_requires_login(): void {
        $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'app-a',
        ]);
        wp_set_current_user(0);
        $req = new WP_REST_Request('PUT', '/dsgo/v1/apps/app-a/storage/user/pref');
        $req->set_header('X-DSGo-App-Nonce', $this->app_nonce_for(0, 'app-a'));
        $req->set_body_params(['value' => 1]);
        $resp = $this->server->dispatch($req);
        $this->assertSame(401, $resp->get_status());
        $this->assertSame('not_authenticated', $resp->get_data()['code']);
    }

    public function test_storage_rejects_missing_app_nonce(): void {
        // The bridge sends `X-DSGo-App-Nonce` on every storage call. A direct
        // fetch from a malicious app's bundle won't have it — endpoint 403s.
        $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'app-a',
        ]);
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('GET', '/dsgo/v1/apps/app-a/storage/app/theme');
        $resp = $this->server->dispatch($req);
        $this->assertSame(403, $resp->get_status());
        $this->assertSame('rest_forbidden', $resp->get_data()['code']);
    }

    public function test_storage_rejects_other_apps_nonce(): void {
        // App A's nonce on app B's URL — verifier fails because the action ID
        // baked into A's nonce (dsgo_app_<user>_app-a) doesn't match what
        // permit_storage expects (dsgo_app_<user>_app-b).
        $this->factory->post->create(['post_type' => PostType::SLUG, 'post_status' => 'publish', 'post_name' => 'app-a']);
        $this->factory->post->create(['post_type' => PostType::SLUG, 'post_status' => 'publish', 'post_name' => 'app-b']);
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('GET', '/dsgo/v1/apps/app-b/storage/app/secret');
        $req->set_header('X-DSGo-App-Nonce', $this->app_nonce_for($this->admin_id, 'app-a'));
        $resp = $this->server->dispatch($req);
        $this->assertSame(403, $resp->get_status());
        $this->assertSame('rest_forbidden', $resp->get_data()['code']);
    }

    public function test_site_info_returns_required_fields_for_anonymous(): void {
        // The bridge spec promises `language` always, `admin_email` only for
        // users with manage_options. Anonymous visitors should get the public
        // shape without an admin_email field.
        wp_set_current_user(0);
        update_option('blogname', 'Anon Site');
        update_option('blogdescription', 'A description');
        $req  = new WP_REST_Request('GET', '/dsgo/v1/site-info');
        $resp = $this->server->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $data = $resp->get_data();
        $this->assertSame('Anon Site', $data['title']);
        $this->assertSame('A description', $data['description']);
        $this->assertArrayHasKey('language', $data);
        $this->assertArrayHasKey('date_format', $data);
        $this->assertArrayHasKey('time_format', $data);
        $this->assertArrayNotHasKey('admin_email', $data);
        // Language must be BCP 47 (hyphenated), not WP's underscore form.
        $this->assertStringNotContainsString('_', $data['language']);
    }

    public function test_site_info_includes_admin_email_for_admins(): void {
        wp_set_current_user($this->admin_id);
        update_option('admin_email', 'admin@example.test');
        $req  = new WP_REST_Request('GET', '/dsgo/v1/site-info');
        $resp = $this->server->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $this->assertSame('admin@example.test', $resp->get_data()['admin_email']);
    }

    public function test_site_info_omits_admin_email_for_subscribers(): void {
        wp_set_current_user($this->subscriber_id);
        $req  = new WP_REST_Request('GET', '/dsgo/v1/site-info');
        $resp = $this->server->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $this->assertArrayNotHasKey('admin_email', $resp->get_data());
    }

    public function test_storage_user_set_response_message_has_no_doubled_code_prefix(): void {
        // Regression: the 0.1 wire response was `{code: 'not_authenticated',
        // message: 'not_authenticated: ...'}` because StorageError's PHP
        // message includes the code prefix and the REST handler used
        // getMessage() verbatim. The client BridgeRequestError then prepended
        // the code again, producing "not_authenticated: not_authenticated:
        // ..." in `e.message`. The fix is to send `bare_message` so the
        // wire body's `message` is just the human description.
        $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'app-a',
        ]);
        wp_set_current_user(0);
        $req = new WP_REST_Request('PUT', '/dsgo/v1/apps/app-a/storage/user/pref');
        $req->set_header('X-DSGo-App-Nonce', $this->app_nonce_for(0, 'app-a'));
        $req->set_body_params(['value' => 1]);
        $resp = $this->server->dispatch($req);
        $this->assertSame(401, $resp->get_status());
        $body = $resp->get_data();
        $this->assertSame('not_authenticated', $body['code']);
        $this->assertStringStartsNotWith('not_authenticated:', $body['message']);
        $this->assertSame('user.set requires a logged-in user', $body['message']);
    }

    public function test_storage_user_get_anonymous_returns_null_silently(): void {
        $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => 'app-a',
        ]);
        wp_set_current_user(0);
        $req = new WP_REST_Request('GET', '/dsgo/v1/apps/app-a/storage/user/pref');
        $req->set_header('X-DSGo-App-Nonce', $this->app_nonce_for(0, 'app-a'));
        $resp = $this->server->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $this->assertNull($resp->get_data()['value']);
    }

    public function test_import_html_requires_admin(): void {
        wp_set_current_user($this->subscriber_id);
        $req = $this->build_import_html_request('<!doctype html><x/>', ['id' => 'art-x']);
        $resp = $this->server->dispatch($req);
        $this->assertSame(403, $resp->get_status());
    }

    public function test_import_html_rejects_anonymous(): void {
        wp_set_current_user(0);
        $req = $this->build_import_html_request('<!doctype html><x/>', ['id' => 'art-x']);
        $resp = $this->server->dispatch($req);
        $this->assertSame(401, $resp->get_status());
    }

    public function test_import_html_happy_path_creates_post(): void {
        $this->cleanup_bundle('imported-app');
        try {
            wp_set_current_user($this->admin_id);
            $req = $this->build_import_html_request(
                '<!doctype html><html><head><title>Hello</title></head><body><h1>imported</h1></body></html>',
                ['id' => 'imported-app', 'name' => 'Imported'],
            );
            $resp = $this->server->dispatch($req);
            $this->assertSame(201, $resp->get_status());
            $body = $resp->get_data();
            $this->assertSame('imported-app', $body['id']);
            $this->assertNotEmpty($body['url']);
            $post = get_page_by_path('imported-app', OBJECT, PostType::SLUG);
            $this->assertNotNull($post);
            $this->assertSame('Imported', $post->post_title);
            $manifest = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
            $this->assertSame('iframe', $manifest['isolation']);
        } finally {
            $this->cleanup_bundle('imported-app');
        }
    }

    public function test_import_html_maps_invalid_id_to_422(): void {
        wp_set_current_user($this->admin_id);
        $req = $this->build_import_html_request('<!doctype html><x/>', ['id' => 'BAD ID']);
        $resp = $this->server->dispatch($req);
        $this->assertSame(422, $resp->get_status());
        $this->assertSame('invalid_id', $resp->get_data()['code']);
    }

    public function test_import_html_maps_empty_body_to_422(): void {
        wp_set_current_user($this->admin_id);
        $req = $this->build_import_html_request('', ['id' => 'art-empty']);
        $resp = $this->server->dispatch($req);
        $this->assertSame(422, $resp->get_status());
        $this->assertSame('empty_html', $resp->get_data()['code']);
    }

    public function test_import_html_missing_file_returns_400(): void {
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('POST', '/dsgo/v1/apps/import-html');
        $req->set_body_params(['id' => 'art-no-file']);
        $resp = $this->server->dispatch($req);
        $this->assertSame(400, $resp->get_status());
        $this->assertSame('missing_file', $resp->get_data()['code']);
    }

    public function test_import_html_missing_id_returns_400(): void {
        wp_set_current_user($this->admin_id);
        $req = $this->build_import_html_request('<!doctype html><x/>', []);
        $resp = $this->server->dispatch($req);
        $this->assertSame(400, $resp->get_status());
        $this->assertSame('missing_field', $resp->get_data()['code']);
    }

    /** Build a multipart-style REST request: body params + a file in $_FILES shape. */
    private function build_import_html_request(string $html, array $params): WP_REST_Request {
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-html-');
        file_put_contents($tmp, $html);
        $req = new WP_REST_Request('POST', '/dsgo/v1/apps/import-html');
        $req->set_body_params($params);
        $req->set_file_params([
            'file' => [
                'name'     => 'artifact.html',
                'type'     => 'text/html',
                'tmp_name' => $tmp,
                'error'    => UPLOAD_ERR_OK,
                'size'     => strlen($html),
            ],
        ]);
        return $req;
    }

    // --- install-starter endpoint ---------------------------------------

    public function test_install_starter_requires_admin(): void {
        wp_set_current_user($this->subscriber_id);
        $req = new WP_REST_Request('POST', '/dsgo/v1/apps/install-starter');
        $resp = $this->server->dispatch($req);
        $this->assertSame(403, $resp->get_status());
    }

    public function test_install_starter_rejects_anonymous(): void {
        wp_set_current_user(0);
        $req = new WP_REST_Request('POST', '/dsgo/v1/apps/install-starter');
        $resp = $this->server->dispatch($req);
        $this->assertSame(401, $resp->get_status());
    }

    public function test_install_starter_happy_path_creates_post(): void {
        $this->cleanup_bundle('dsgo-starter');
        try {
            wp_set_current_user($this->admin_id);
            $req = new WP_REST_Request('POST', '/dsgo/v1/apps/install-starter');
            $resp = $this->server->dispatch($req);
            $this->assertSame(201, $resp->get_status(), 'response: ' . wp_json_encode($resp->get_data()));
            $body = $resp->get_data();
            $this->assertSame('dsgo-starter', $body['id']);
            $this->assertNotEmpty($body['url']);
            $post = get_page_by_path('dsgo-starter', OBJECT, PostType::SLUG);
            $this->assertNotNull($post);
            $this->assertSame('DSGo Starter', $post->post_title);
            $manifest = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
            $this->assertSame('inline', $manifest['isolation']);
            $this->assertCount(9, $manifest['routes']);
            $this->assertContains('site_info', $manifest['permissions']['read']);
            $this->assertContains('email',     $manifest['permissions']['read']);
        } finally {
            $this->cleanup_bundle('dsgo-starter');
        }
    }

    public function test_install_starter_is_idempotent(): void {
        $this->cleanup_bundle('dsgo-starter');
        try {
            wp_set_current_user($this->admin_id);
            $req = new WP_REST_Request('POST', '/dsgo/v1/apps/install-starter');
            $first = $this->server->dispatch($req);
            $this->assertSame(201, $first->get_status());
            $second = $this->server->dispatch(new WP_REST_Request('POST', '/dsgo/v1/apps/install-starter'));
            $this->assertSame(201, $second->get_status());
            $this->assertSame($first->get_data()['post_id'], $second->get_data()['post_id']);
        } finally {
            $this->cleanup_bundle('dsgo-starter');
        }
    }

    private function cleanup_bundle(string $id): void {
        $post = get_page_by_path($id, OBJECT, PostType::SLUG);
        if ($post) {
            wp_delete_post($post->ID, true);
        }
        \DSGo_Apps\Bundle::recursive_delete(\DSGo_Apps\Bundle::dir_for($id));
    }

    // --- abilities endpoints --------------------------------------------

    public function test_abilities_list_endpoint_returns_descriptors(): void {
        $this->install_test_app_for_abilities('abilities-app', ['read' => ['abilities']], ['consumes' => ['test/*']]);
        $this->register_test_ability('test/x');

        $req = new \WP_REST_Request('GET', '/dsgo/v1/apps/abilities-app/abilities');
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $data = $resp->get_data();
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('test/x', $data[0]['name']);
    }

    public function test_abilities_list_endpoint_returns_empty_when_no_match(): void {
        $this->install_test_app_for_abilities('abilities-empty', ['read' => ['abilities']], ['consumes' => []]);
        $req = new \WP_REST_Request('GET', '/dsgo/v1/apps/abilities-empty/abilities');
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $this->assertSame([], $resp->get_data());
    }

    public function test_abilities_invoke_endpoint_returns_data(): void {
        $this->install_test_app_for_abilities('abilities-invoke', ['read' => ['abilities']], ['consumes' => ['test/*']]);
        $this->register_test_ability('test/echo', static fn ($input) => ['echoed' => $input], [
            'input_schema' => ['type' => 'object'],
        ]);

        $req = new \WP_REST_Request('POST', '/dsgo/v1/apps/abilities-invoke/abilities/test/echo');
        $req->set_body_params(['args' => ['hello' => 'world']]);
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $this->assertSame(['echoed' => ['hello' => 'world']], $resp->get_data());
    }

    public function test_abilities_invoke_endpoint_maps_unmatched_to_403(): void {
        $this->install_test_app_for_abilities('abilities-denied', ['read' => ['abilities']], ['consumes' => ['other/x']]);
        $this->register_test_ability('test/x');

        $req = new \WP_REST_Request('POST', '/dsgo/v1/apps/abilities-denied/abilities/test/x');
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(403, $resp->get_status());
        $err = $resp->get_data();
        $this->assertSame('permission_denied', $err['code']);
    }

    public function test_abilities_endpoint_404_for_unknown_app(): void {
        $req = new \WP_REST_Request('GET', '/dsgo/v1/apps/no-such-app/abilities');
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(404, $resp->get_status());
    }

    public function test_abilities_list_endpoint_403_when_app_lacks_permission(): void {
        $this->install_test_app_for_abilities('no-abilities-perm', ['read' => ['posts']]);
        $req = new \WP_REST_Request('GET', '/dsgo/v1/apps/no-abilities-perm/abilities');
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(403, $resp->get_status());
        $this->assertSame('permission_denied', $resp->get_data()['code']);
    }

    // --- helpers --------------------------------------------------------

    private array $abilities_test_registered = [];

    private function install_test_app_for_abilities(string $id, array $permissions, ?array $abilities = null): int {
        $manifest = [
            'manifest_version' => 1, 'id' => $id, 'name' => $id, 'version' => '0.1.0',
            'entry' => 'index.html', 'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => array_merge(['read' => [], 'write' => []], $permissions),
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
        ];
        if ($abilities !== null) {
            $manifest['abilities'] = $abilities;
        }
        $post_id = $this->factory->post->create([
            'post_type' => \DSGo_Apps\PostType::SLUG,
            'post_status' => 'publish',
            'post_name' => $id,
            'post_title' => $id,
        ]);
        update_post_meta($post_id, 'dsgo_apps_manifest', $manifest);
        return $post_id;
    }

    /**
     * Register a test ability. Mirrors the test helper from AbilitiesBridgeTest:
     * - the wp_abilities_api_init action context must be active for register
     * - the 'test' category must exist
     */
    private function register_test_ability(string $name, ?callable $execute = null, array $extra = []): void {
        global $wp_current_filter;
        // Ensure the 'test' category is registered — check live registry, not a stale flag.
        if (function_exists('wp_register_ability_category') &&
            (!function_exists('wp_has_ability_category') || !wp_has_ability_category('test'))) {
            $wp_current_filter[] = 'wp_abilities_api_categories_init';
            try {
                wp_register_ability_category('test', ['label' => 'Test', 'description' => 'Test ability category']);
            } finally {
                array_pop($wp_current_filter);
            }
        }
        $wp_current_filter[] = 'wp_abilities_api_init';
        try {
            wp_register_ability($name, array_merge([
                'label'              => $name,
                'description'        => 'test ability ' . $name,
                'category'           => 'test',
                'execute_callback'   => $execute ?? static fn () => null,
                'permission_callback' => static fn () => true,
            ], $extra));
        } finally {
            array_pop($wp_current_filter);
        }
        $this->abilities_test_registered[] = $name;
    }

    public function tear_down(): void {
        foreach ($this->abilities_test_registered as $name) {
            if (function_exists('wp_unregister_ability')) {
                wp_unregister_ability($name);
            }
        }
        $this->abilities_test_registered = [];
        parent::tear_down();
    }

    // --- ai/prompt endpoint ---------------------------------------------

    public function test_ai_prompt_endpoint_returns_text(): void {
        $this->install_test_app_for_abilities('ai-app', ['read' => ['ai']]);
        \DSGo_Apps\AiBridge::set_factory_for_tests(static fn () => [
            'supports_ai' => true,
            'builder'     => new class {
                public array $messages = [];
                public function with_messages(array $m): self { $this->messages = array_merge($this->messages, $m); return $this; }
                public function with_message($m): self { $this->messages[] = $m; return $this; }
                public function using_abilities(...$a): self { return $this; }
                public function with_max_tokens(int $n): self { return $this; }
                public function generate_text_result() {
                    return (object) ['type' => 'text', 'text' => 'hi from AI', 'usage' => ['input_tokens' => 5, 'output_tokens' => 3]];
                }
            },
            'resolver_factory' => static fn (array $a) => new class { public function has_ability_calls($m): bool { return false; } public function execute_abilities($m): object { return (object)[]; } },
        ]);

        $req = new \WP_REST_Request('POST', '/dsgo/v1/apps/ai-app/ai/prompt');
        $req->set_body_params(['messages' => [['role' => 'user', 'content' => 'hi']]]);
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $data = $resp->get_data();
        $this->assertSame('hi from AI', $data['content']);
        $this->assertSame([], $data['tool_calls']);
        \DSGo_Apps\AiBridge::reset_factory_for_tests();
    }

    public function test_ai_prompt_endpoint_404_for_unknown_app(): void {
        $req = new \WP_REST_Request('POST', '/dsgo/v1/apps/no-such/ai/prompt');
        $req->set_body_params(['messages' => [['role' => 'user', 'content' => 'hi']]]);
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(404, $resp->get_status());
    }

    public function test_ai_prompt_endpoint_403_when_app_lacks_ai_permission(): void {
        $this->install_test_app_for_abilities('no-ai-perm', ['read' => ['posts']]);
        $req = new \WP_REST_Request('POST', '/dsgo/v1/apps/no-ai-perm/ai/prompt');
        $req->set_body_params(['messages' => [['role' => 'user', 'content' => 'hi']]]);
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(403, $resp->get_status());
        $this->assertSame('permission_denied', $resp->get_data()['code']);
    }

    public function test_ai_prompt_endpoint_503_when_no_connector(): void {
        $this->install_test_app_for_abilities('no-connector', ['read' => ['ai']]);
        \DSGo_Apps\AiBridge::set_factory_for_tests(static fn () => [
            'supports_ai' => false, 'builder' => null, 'resolver_factory' => null,
        ]);
        $req = new \WP_REST_Request('POST', '/dsgo/v1/apps/no-connector/ai/prompt');
        $req->set_body_params(['messages' => [['role' => 'user', 'content' => 'hi']]]);
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(503, $resp->get_status());
        $this->assertSame('ai_not_configured', $resp->get_data()['code']);
        \DSGo_Apps\AiBridge::reset_factory_for_tests();
    }

    public function test_delete_app_unregisters_published_abilities(): void {
        $this->install_test_app_for_abilities('publish-delete', ['read' => []]);
        update_post_meta(
            get_page_by_path('publish-delete', OBJECT, \DSGo_Apps\PostType::SLUG)->ID,
            'dsgo_apps_manifest',
            [
                'manifest_version' => 1, 'id' => 'publish-delete', 'name' => 'P', 'version' => '0.1.0',
                'entry' => 'index.html', 'isolation' => 'iframe',
                'display' => ['modes' => ['page'], 'default' => 'page'],
                'permissions' => ['read' => [], 'write' => []],
                'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
                'abilities' => ['publishes' => [
                    ['name' => 'publish-delete/x', 'label' => 'X', 'description' => 'd',
                     'category' => 'content', 'annotations' => [], 'timeout_seconds' => 30],
                ]],
            ],
        );
        $manifest = \DSGo_Apps\Manifest::validate(get_post_meta(
            get_page_by_path('publish-delete', OBJECT, \DSGo_Apps\PostType::SLUG)->ID,
            'dsgo_apps_manifest', true,
        ));
        \DSGo_Apps\AbilitiesPublisher::register_for_app($manifest);
        $this->assertTrue(wp_has_ability('publish-delete/x'));

        wp_set_current_user($this->factory->user->create(['role' => 'administrator']));
        $req = new \WP_REST_Request('DELETE', '/dsgo/v1/apps/publish-delete');
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(200, $resp->get_status());

        $this->assertFalse(wp_has_ability('publish-delete/x'));
    }

    /**
     * Seed an installed-app post with the meta keys list_apps and the
     * site-home REST handler depend on. Mirrors the shape Installer writes
     * (manifest array + dsgo_apps_mount_mode + dsgo_apps_isolation) without
     * unzipping a real bundle.
     */
    private function seed_app(string $slug, array $manifest_overrides = [], string $mount_mode = 'prefixed'): int {
        $post_id = $this->factory->post->create([
            'post_type'   => PostType::SLUG,
            'post_status' => 'publish',
            'post_name'   => $slug,
            'post_title'  => ucfirst(str_replace('-', ' ', $slug)),
        ]);
        $manifest = array_replace_recursive([
            'manifest_version' => 1,
            'id'               => $slug,
            'name'             => $slug,
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
            'mount'            => ['mode' => $mount_mode],
        ], $manifest_overrides);
        update_post_meta($post_id, 'dsgo_apps_manifest', $manifest);
        update_post_meta($post_id, 'dsgo_apps_mount_mode', $mount_mode);
        update_post_meta($post_id, 'dsgo_apps_isolation', $manifest['isolation']);
        \DSGo_Apps\Settings::refresh_root_app_id();
        return (int) $post_id;
    }

    public function test_list_apps_marks_root_app_as_site_home(): void {
        $this->seed_app('regular-app');
        $this->seed_app('home-app', [], 'root');

        wp_set_current_user($this->admin_id);
        $resp = $this->server->dispatch(new WP_REST_Request('GET', '/dsgo/v1/apps'));
        $items = $resp->get_data();
        $by_id = array_column($items, null, 'id');

        $this->assertTrue($by_id['home-app']['is_site_home']);
        $this->assertTrue($by_id['home-app']['home_eligible']);
        $this->assertSame(home_url('/'), $by_id['home-app']['url']);

        $this->assertFalse($by_id['regular-app']['is_site_home']);
        $this->assertTrue($by_id['regular-app']['home_eligible']);
    }

    public function test_list_apps_block_only_app_is_not_home_eligible(): void {
        $this->seed_app('block-only', [
            'display' => ['modes' => ['block'], 'default' => 'block'],
        ]);
        wp_set_current_user($this->admin_id);
        $resp = $this->server->dispatch(new WP_REST_Request('GET', '/dsgo/v1/apps'));
        $items = $resp->get_data();
        $this->assertFalse($items[0]['home_eligible']);
        $this->assertFalse($items[0]['is_site_home']);
    }

    public function test_set_site_home_requires_admin(): void {
        $this->seed_app('candidate');
        wp_set_current_user($this->subscriber_id);
        $req = new WP_REST_Request('POST', '/dsgo/v1/site-home');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['app_id' => 'candidate']));
        $resp = $this->server->dispatch($req);
        $this->assertSame(403, $resp->get_status());
    }

    public function test_set_site_home_promotes_app(): void {
        $this->seed_app('promote-me');
        wp_set_current_user($this->admin_id);

        $req = new WP_REST_Request('POST', '/dsgo/v1/site-home');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['app_id' => 'promote-me']));
        $resp = $this->server->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $this->assertSame('promote-me', $resp->get_data()['home_id']);

        $post = get_page_by_path('promote-me', OBJECT, PostType::SLUG);
        $this->assertSame('root', get_post_meta($post->ID, 'dsgo_apps_mount_mode', true));
        $manifest = get_post_meta($post->ID, 'dsgo_apps_manifest', true);
        $this->assertSame('root', $manifest['mount']['mode']);
        $this->assertSame('promote-me', \DSGo_Apps\Settings::get_root_app_id());
    }

    public function test_set_site_home_replaces_existing_home(): void {
        $this->seed_app('old-home', [], 'root');
        $this->seed_app('new-home');
        wp_set_current_user($this->admin_id);

        $req = new WP_REST_Request('POST', '/dsgo/v1/site-home');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['app_id' => 'new-home']));
        $resp = $this->server->dispatch($req);
        $this->assertSame(200, $resp->get_status());

        $old_post = get_page_by_path('old-home', OBJECT, PostType::SLUG);
        $new_post = get_page_by_path('new-home', OBJECT, PostType::SLUG);
        $this->assertSame('prefixed', get_post_meta($old_post->ID, 'dsgo_apps_mount_mode', true));
        $this->assertSame('root',     get_post_meta($new_post->ID, 'dsgo_apps_mount_mode', true));
        $this->assertSame('new-home', \DSGo_Apps\Settings::get_root_app_id());
    }

    public function test_set_site_home_demotes_when_app_id_null(): void {
        $this->seed_app('current-home', [], 'root');
        wp_set_current_user($this->admin_id);

        $req = new WP_REST_Request('POST', '/dsgo/v1/site-home');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['app_id' => null]));
        $resp = $this->server->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $this->assertNull($resp->get_data()['home_id']);

        $post = get_page_by_path('current-home', OBJECT, PostType::SLUG);
        $this->assertSame('prefixed', get_post_meta($post->ID, 'dsgo_apps_mount_mode', true));
        $this->assertNull(\DSGo_Apps\Settings::get_root_app_id());
    }

    public function test_set_site_home_rejects_unknown_app(): void {
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('POST', '/dsgo/v1/site-home');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['app_id' => 'no-such-app']));
        $resp = $this->server->dispatch($req);
        $this->assertSame(404, $resp->get_status());
        $this->assertSame('not_found', $resp->get_data()['code']);
    }

    public function test_set_site_home_rejects_app_without_page_mode(): void {
        $this->seed_app('block-only', [
            'display' => ['modes' => ['block'], 'default' => 'block'],
        ]);
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('POST', '/dsgo/v1/site-home');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['app_id' => 'block-only']));
        $resp = $this->server->dispatch($req);
        $this->assertSame(422, $resp->get_status());
        $this->assertSame('not_eligible', $resp->get_data()['code']);
    }

    public function test_set_site_home_rejects_invalid_app_id_shape(): void {
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('POST', '/dsgo/v1/site-home');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['app_id' => 'Bad ID!']));
        $resp = $this->server->dispatch($req);
        $this->assertSame(400, $resp->get_status());
        $this->assertSame('invalid_app_id', $resp->get_data()['code']);
    }

    public function test_set_site_home_repromote_is_noop(): void {
        $this->seed_app('already-home', [], 'root');
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('POST', '/dsgo/v1/site-home');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['app_id' => 'already-home']));
        $resp = $this->server->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $this->assertSame('already-home', \DSGo_Apps\Settings::get_root_app_id());
    }
}
