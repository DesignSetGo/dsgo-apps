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

        // The http_log table is created on activate(); ensure it exists
        // for tests that don't go through the activation hook so
        // Http_Proxy_Bridge::log() doesn't error with "table doesn't exist".
        \DSGo_Apps\Http_Proxy_Log::create_table();
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

    public function test_can_unknown_cap_returns_400(): void {
        // /can is restricted to a fixed allowlist so attackers can't enumerate
        // arbitrary third-party caps. Unknown strings are rejected outright.
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('GET', '/dsgo/v1/can');
        $req->set_query_params(['cap' => 'no_such_cap']);
        $resp = $this->server->dispatch($req);
        $this->assertSame(400, $resp->get_status());
        $this->assertSame('invalid_params', $resp->get_data()['code']);
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
            $this->assertSame(['page', 'block'], $manifest['display']['modes']);
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

    // --- help.method endpoint (GET /apps/<id>/help/methods/<method>) ----

    public function test_help_method_returns_method_doc_for_known_method(): void {
        $this->cleanup_bundle('help-test-app');
        wp_set_current_user($this->admin_id);
        try {
            // Install a minimal app so the route's app-exists check passes.
            $zip = $this->build_preview_zip('help-test-app', []);
            \DSGo_Apps\Installer::install($zip, $this->admin_id);

            $req  = new WP_REST_Request('GET', '/dsgo/v1/apps/help-test-app/help/methods/posts.list');
            $resp = $this->server->dispatch($req);
            $this->assertSame(200, $resp->get_status());
            $body = $resp->get_data();
            $this->assertStringContainsString('dsgo.posts.list', $body['signature']);
            $this->assertIsArray($body['errors']);
            $this->assertIsArray($body['examples']);
        } finally {
            @unlink($zip ?? '');
            $this->cleanup_bundle('help-test-app');
        }
    }

    public function test_help_method_returns_404_for_unknown_method(): void {
        $this->cleanup_bundle('help-test-app');
        wp_set_current_user($this->admin_id);
        try {
            $zip = $this->build_preview_zip('help-test-app', []);
            \DSGo_Apps\Installer::install($zip, $this->admin_id);

            $req  = new WP_REST_Request('GET', '/dsgo/v1/apps/help-test-app/help/methods/frob.nicate');
            $resp = $this->server->dispatch($req);
            $this->assertSame(404, $resp->get_status());
            $this->assertSame('not_found', $resp->get_data()['code']);
        } finally {
            @unlink($zip ?? '');
            $this->cleanup_bundle('help-test-app');
        }
    }

    public function test_help_method_returns_404_for_unknown_app(): void {
        wp_set_current_user($this->admin_id);
        $req  = new WP_REST_Request('GET', '/dsgo/v1/apps/never-installed/help/methods/posts.list');
        $resp = $this->server->dispatch($req);
        $this->assertSame(404, $resp->get_status());
        $this->assertSame('not_found', $resp->get_data()['code']);
    }

    public function test_help_method_works_for_method_outside_manifest_permissions(): void {
        // Key property: help.method is always-available — an app that doesn't
        // declare 'ai' in permissions.read can still look up ai.prompt's docs.
        $this->cleanup_bundle('help-test-app');
        wp_set_current_user($this->admin_id);
        try {
            // Install with NO permissions — minimal manifest.
            $zip = $this->build_preview_zip('help-test-app', []);
            \DSGo_Apps\Installer::install($zip, $this->admin_id);

            $req  = new WP_REST_Request('GET', '/dsgo/v1/apps/help-test-app/help/methods/ai.prompt');
            $resp = $this->server->dispatch($req);
            $this->assertSame(200, $resp->get_status());
            $this->assertStringContainsString('dsgo.ai.prompt', $resp->get_data()['signature']);
        } finally {
            @unlink($zip ?? '');
            $this->cleanup_bundle('help-test-app');
        }
    }

    // --- preview endpoint (POST /apps/preview) --------------------------

    public function test_preview_requires_admin(): void {
        wp_set_current_user($this->subscriber_id);
        $req = new WP_REST_Request('POST', '/dsgo/v1/apps/preview');
        $resp = $this->server->dispatch($req);
        $this->assertSame(403, $resp->get_status());
    }

    public function test_preview_returns_400_when_no_file(): void {
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('POST', '/dsgo/v1/apps/preview');
        $resp = $this->server->dispatch($req);
        $this->assertSame(400, $resp->get_status());
        $this->assertSame('missing_file', $resp->get_data()['code']);
    }

    public function test_preview_returns_bucket_payload_for_valid_zip(): void {
        $this->cleanup_bundle('preview-app');
        wp_set_current_user($this->admin_id);
        try {
            $zip = $this->build_preview_zip('preview-app', ['posts', 'ai']);
            $req = new WP_REST_Request('POST', '/dsgo/v1/apps/preview');
            $req->set_file_params([
                'bundle' => [
                    'name' => 'preview-app.zip', 'type' => 'application/zip',
                    'tmp_name' => $zip, 'error' => UPLOAD_ERR_OK, 'size' => filesize($zip),
                ],
            ]);
            $resp = $this->server->dispatch($req);
            $this->assertSame(200, $resp->get_status(), 'response: ' . wp_json_encode($resp->get_data()));
            $body = $resp->get_data();
            $this->assertSame('preview-app',         $body['app_id']);
            $this->assertSame('Preview App',         $body['name']);
            $this->assertFalse($body['is_update']);
            $this->assertNull($body['previously_approved']);
            $this->assertSame(['read_content', 'ai'], $body['buckets']);
            $this->assertSame([],                     $body['new_buckets']);
            $this->assertSame([],                     $body['removed_buckets']);
            $this->assertIsString($body['rendered_html']);
            // Active bucket rows are present in rendered_html.
            $this->assertStringContainsString('dsgo-bucket--read_content', $body['rendered_html']);
            $this->assertStringContainsString('dsgo-bucket--ai',           $body['rendered_html']);
        } finally {
            @unlink($zip ?? '');
            $this->cleanup_bundle('preview-app');
        }
    }

    public function test_preview_marks_new_buckets_on_update(): void {
        $this->cleanup_bundle('preview-app');
        wp_set_current_user($this->admin_id);
        try {
            // First install — establishes previously_approved=['read_content'].
            $zip1 = $this->build_preview_zip('preview-app', ['posts']);
            \DSGo_Apps\Installer::install($zip1, $this->admin_id);

            // Preview an update that adds AI.
            $zip2 = $this->build_preview_zip('preview-app', ['posts', 'ai']);
            $req  = new WP_REST_Request('POST', '/dsgo/v1/apps/preview');
            $req->set_file_params([
                'bundle' => [
                    'name' => 'preview-app.zip', 'type' => 'application/zip',
                    'tmp_name' => $zip2, 'error' => UPLOAD_ERR_OK, 'size' => filesize($zip2),
                ],
            ]);
            $resp = $this->server->dispatch($req);
            $this->assertSame(200, $resp->get_status());
            $body = $resp->get_data();
            $this->assertTrue($body['is_update']);
            $this->assertSame(['read_content'],         $body['previously_approved']);
            $this->assertSame(['ai'],                   $body['new_buckets']);
            $this->assertSame([],                       $body['removed_buckets']);
            // Rendered HTML marks the new bucket.
            $this->assertStringContainsString('dsgo-bucket--new', $body['rendered_html']);
        } finally {
            @unlink($zip1 ?? '');
            @unlink($zip2 ?? '');
            $this->cleanup_bundle('preview-app');
        }
    }

    public function test_preview_returns_422_for_invalid_manifest(): void {
        wp_set_current_user($this->admin_id);
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-bad-zip-');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        // Missing required fields → ManifestError → invalid_manifest → 422.
        $zip->addFromString('dsgo-app.json', '{"manifest_version":1}');
        $zip->addFromString('index.html', '<!doctype html><html/>');
        $zip->close();
        try {
            $req = new WP_REST_Request('POST', '/dsgo/v1/apps/preview');
            $req->set_file_params([
                'bundle' => [
                    'name' => 'bad.zip', 'type' => 'application/zip',
                    'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp),
                ],
            ]);
            $resp = $this->server->dispatch($req);
            $this->assertSame(422, $resp->get_status());
            $this->assertSame('invalid_manifest', $resp->get_data()['code']);
        } finally {
            @unlink($tmp);
        }
    }

    private function build_preview_zip(string $id, array $permissions_read): string {
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-zip-');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('dsgo-app.json', json_encode([
            'manifest_version' => 1,
            'id'               => $id,
            'name'             => 'Preview App',
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => $permissions_read, 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $zip->addFromString('index.html', '<!doctype html><html><head><title>x</title></head><body>x</body></html>');
        $zip->close();
        return $tmp;
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
        wp_set_current_user($this->admin_id);
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
        wp_set_current_user($this->admin_id);
        $req = new \WP_REST_Request('POST', '/dsgo/v1/apps/no-such/ai/prompt');
        $req->set_body_params(['messages' => [['role' => 'user', 'content' => 'hi']]]);
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(404, $resp->get_status());
    }

    public function test_ai_prompt_endpoint_401_when_anonymous(): void {
        // ai.prompt requires an authenticated WP session — billing-amplification guard.
        wp_set_current_user(0);
        $this->install_test_app_for_abilities('ai-app-anon', ['read' => ['ai']]);
        wp_set_current_user(0);
        $req = new \WP_REST_Request('POST', '/dsgo/v1/apps/ai-app-anon/ai/prompt');
        $req->set_body_params(['messages' => [['role' => 'user', 'content' => 'hi']]]);
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(401, $resp->get_status());
    }

    public function test_ai_prompt_endpoint_403_when_app_lacks_ai_permission(): void {
        wp_set_current_user($this->admin_id);
        $this->install_test_app_for_abilities('no-ai-perm', ['read' => ['posts']]);
        $req = new \WP_REST_Request('POST', '/dsgo/v1/apps/no-ai-perm/ai/prompt');
        $req->set_body_params(['messages' => [['role' => 'user', 'content' => 'hi']]]);
        $resp = rest_get_server()->dispatch($req);
        $this->assertSame(403, $resp->get_status());
        $this->assertSame('permission_denied', $resp->get_data()['code']);
    }

    public function test_ai_prompt_endpoint_503_when_no_connector(): void {
        wp_set_current_user($this->admin_id);
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
        $this->assertSame(['page', 'block'], $by_id['regular-app']['modes']);
    }

    public function test_list_apps_block_only_iframe_app_is_home_eligible(): void {
        $this->seed_app('block-only', [
            'display' => ['modes' => ['block'], 'default' => 'block'],
        ]);
        wp_set_current_user($this->admin_id);
        $resp = $this->server->dispatch(new WP_REST_Request('GET', '/dsgo/v1/apps'));
        $items = $resp->get_data();
        $this->assertTrue($items[0]['home_eligible']);
        $this->assertSame(['page', 'block'], $items[0]['modes']);
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

    public function test_set_site_home_accepts_block_only_iframe_app(): void {
        $this->seed_app('block-only', [
            'display' => ['modes' => ['block'], 'default' => 'block'],
        ]);
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('POST', '/dsgo/v1/site-home');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['app_id' => 'block-only']));
        $resp = $this->server->dispatch($req);
        $this->assertSame(200, $resp->get_status());
        $this->assertSame('block-only', $resp->get_data()['home_id']);
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

    // ===== shape_install_response: needs_secrets / secrets_url =====
    //
    // Drives the post-install redirect into the Secrets tab. Tested via
    // reflection rather than a full zip round-trip because the contract
    // is "given an InstallResult + the manifest in post meta, decide
    // whether to point the admin at the secrets tab" — there's no need
    // to relitigate the entire installer to verify it.

    public function test_install_response_signals_needs_secrets_when_required_unset(): void {
        $post_id = $this->seed_app('needs-vault', [
            'required_secrets' => ['STRIPE_SECRET'],
            'secrets'          => [
                ['alias' => 'STRIPE_SECRET', 'description' => 'Stripe secret key for charges API.'],
            ],
        ]);
        $shape = $this->invoke_shape_install_response(
            new \DSGo_Apps\InstallResult('needs-vault', $post_id, 'http://example.com/apps/needs-vault/'),
        );
        $this->assertTrue($shape['needs_secrets']);
        $this->assertNotNull($shape['secrets_url']);
        $this->assertStringContainsString('app_id=needs-vault', (string) $shape['secrets_url']);
        $this->assertStringContainsString('tab=secrets',        (string) $shape['secrets_url']);
        $this->assertStringContainsString('just_installed=1',   (string) $shape['secrets_url']);
    }

    public function test_install_response_clears_needs_secrets_when_vault_already_populated(): void {
        $post_id = $this->seed_app('vault-ready', [
            'required_secrets' => ['STRIPE_SECRET'],
            'secrets'          => [
                ['alias' => 'STRIPE_SECRET', 'description' => 'Stripe secret key for charges API.'],
            ],
        ]);
        // Simulate the admin having set the secret BEFORE re-installing
        // (e.g., a redeploy of the same slug; vault values survive update).
        \DSGo_Apps\Secret_Vault::set('vault-ready', 'STRIPE_SECRET', 'sk_test_already_there');
        try {
            $shape = $this->invoke_shape_install_response(
                new \DSGo_Apps\InstallResult('vault-ready', $post_id, 'http://example.com/apps/vault-ready/'),
            );
            $this->assertFalse($shape['needs_secrets']);
            $this->assertNull($shape['secrets_url']);
        } finally {
            \DSGo_Apps\Secret_Vault::delete_all('vault-ready');
        }
    }

    public function test_install_response_omits_redirect_when_no_required_secrets(): void {
        // An app with secrets[] but no required_secrets[] doesn't block on
        // install — the admin can run the app and set secrets later.
        $post_id = $this->seed_app('optional-secrets', [
            'secrets' => [
                ['alias' => 'OPTIONAL_KEY', 'description' => 'Optional API key for an enrichment feature.'],
            ],
        ]);
        $shape = $this->invoke_shape_install_response(
            new \DSGo_Apps\InstallResult('optional-secrets', $post_id, 'http://example.com/apps/optional-secrets/'),
        );
        $this->assertFalse($shape['needs_secrets']);
        $this->assertNull($shape['secrets_url']);
    }

    public function test_install_response_still_redirects_when_sodium_unavailable(): void {
        // Without sodium the vault can't decrypt — but we MUST still send
        // the admin to the Secrets tab so the sodium-unavailable notice
        // explains why the app is non-functional. Swallowing the redirect
        // would leave a broken app looking like a clean install.
        // We can't actually disable sodium at runtime in the test, so we
        // assert via the path that runs when the vault is empty: it must
        // produce needs_secrets=true regardless of sodium availability.
        $post_id = $this->seed_app('redirect-anyway', [
            'required_secrets' => ['SK'],
            'secrets'          => [['alias' => 'SK', 'description' => 'Some key.']],
        ]);
        \DSGo_Apps\Secret_Vault::delete_all('redirect-anyway');
        $shape = $this->invoke_shape_install_response(
            new \DSGo_Apps\InstallResult('redirect-anyway', $post_id, 'http://example.com/'),
        );
        $this->assertTrue($shape['needs_secrets'],
            'admins must be directed to the Secrets tab even on hosts without sodium — the tab surfaces the degraded UX');
    }

    private function invoke_shape_install_response(\DSGo_Apps\InstallResult $result): array {
        $ref = new \ReflectionMethod(\DSGo_Apps\RestApi::class, 'shape_install_response');
        $ref->setAccessible(true);
        $out = $ref->invoke(null, $result);
        $this->assertIsArray($out);
        return $out;
    }

    // ===== POST /apps/{app_id}/http/fetch =====

    public function tear_down_http_proxy(): void {
        \DSGo_Apps\Http_Proxy_Bridge::reset_for_tests();
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$wpdb->prefix}dsgo_apps_http_log");
    }

    private function seed_http_app(string $slug, array $http_allowlist, array $secrets = [], ?string $test_endpoint = null): int {
        $overrides = [
            'permissions' => ['http' => $http_allowlist],
            'secrets'     => $secrets,
        ];
        if ($test_endpoint !== null) {
            $overrides['http'] = ['test_endpoint' => $test_endpoint];
        }
        return $this->seed_app($slug, $overrides);
    }

    private function bypass_proxy_ssrf(): void {
        \DSGo_Apps\Http_Proxy_Bridge::set_dns_resolver_for_tests(fn () => ['93.184.215.14']);
    }

    public function test_http_fetch_returns_403_when_no_http_permission(): void {
        $this->seed_http_app('no-http', []);
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('POST', '/dsgo/v1/apps/no-http/http/fetch');
        $req->set_body_params(['url' => 'https://api.stripe.com/v1/charges']);
        $resp = $this->server->dispatch($req);
        $this->assertSame(403, $resp->get_status());
        $this->assertSame('http_permission_denied', $resp->get_data()['code']);
        $this->tear_down_http_proxy();
    }

    public function test_http_fetch_returns_404_for_unknown_app(): void {
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('POST', '/dsgo/v1/apps/no-such-app/http/fetch');
        $req->set_body_params(['url' => 'https://api.stripe.com/']);
        $resp = $this->server->dispatch($req);
        $this->assertSame(404, $resp->get_status());
    }

    public function test_http_fetch_returns_422_for_disallowed_host(): void {
        $this->seed_http_app('stripe-only', ['api.stripe.com']);
        wp_set_current_user($this->admin_id);
        $req = new WP_REST_Request('POST', '/dsgo/v1/apps/stripe-only/http/fetch');
        $req->set_body_params(['url' => 'https://api.notion.com/v1/pages']);
        $resp = $this->server->dispatch($req);
        $this->assertSame(422, $resp->get_status());
        $this->assertSame('http_host_not_allowed', $resp->get_data()['code']);
        $this->tear_down_http_proxy();
    }

    public function test_http_fetch_returns_200_with_mocked_transport(): void {
        $this->seed_http_app('stripe-ok', ['api.stripe.com']);
        wp_set_current_user($this->admin_id);
        \DSGo_Apps\Http_Proxy_Bridge::set_transport_factory_for_tests(static fn () => [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => '{"id":"ch_1","amount":100}',
            'headers'  => ['content-type' => 'application/json'],
        ]);
        $req = new WP_REST_Request('POST', '/dsgo/v1/apps/stripe-ok/http/fetch');
        $req->set_body_params([
            'url'     => 'https://api.stripe.com/v1/charges',
            'method'  => 'GET',
            'headers' => ['Accept' => 'application/json'],
        ]);
        $resp = $this->server->dispatch($req);
        $data = $resp->get_data();
        $this->assertSame(200, $resp->get_status());
        $this->assertTrue($data['ok']);
        $this->assertSame(200, $data['status']);
        $this->assertSame(['id' => 'ch_1', 'amount' => 100], $data['body']);
        $this->tear_down_http_proxy();
    }

    public function test_http_fetch_returns_429_after_rate_limit(): void {
        $this->seed_http_app('rate-test', ['api.stripe.com']);
        wp_set_current_user($this->admin_id);
        add_filter('dsgo_apps_http_rate_per_minute', static fn () => 1);
        \DSGo_Apps\Http_Proxy_Bridge::set_transport_factory_for_tests(static fn () => [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => '{}',
            'headers'  => ['content-type' => 'application/json'],
        ]);

        $req = new WP_REST_Request('POST', '/dsgo/v1/apps/rate-test/http/fetch');
        $req->set_body_params(['url' => 'https://api.stripe.com/v1/charges']);
        $this->server->dispatch($req);   // 1st: within budget

        $req2 = new WP_REST_Request('POST', '/dsgo/v1/apps/rate-test/http/fetch');
        $req2->set_body_params(['url' => 'https://api.stripe.com/v1/charges']);
        $resp = $this->server->dispatch($req2);
        $this->assertSame(429, $resp->get_status());
        $data = $resp->get_data();
        $this->assertSame('http_rate_limited', $data['code']);
        $this->assertGreaterThan(0, $data['retry_after_seconds']);

        remove_all_filters('dsgo_apps_http_rate_per_minute');
        $this->tear_down_http_proxy();
    }

    // ===== admin-ajax: Secrets tab =====

    public function test_ajax_secret_set_requires_manage_options(): void {
        $this->seed_http_app('vault-app', ['api.stripe.com'], [
            ['alias' => 'SK', 'description' => 'Stripe secret key for charges API.'],
        ]);
        wp_set_current_user($this->subscriber_id);
        $_POST = [
            'app_id' => 'vault-app',
            'alias'  => 'SK',
            'value'  => 'sk_test_xyz',
            'nonce'  => 'irrelevant',
        ];
        $_REQUEST = $_POST;
        $caught = $this->capture_ajax_json('dsgo_apps_secret_set');
        $this->assertFalse($caught['payload']['success']);
        $this->assertSame('forbidden', $caught['payload']['data']['code']);
    }

    public function test_ajax_secret_set_writes_to_vault(): void {
        $this->seed_http_app('vault-write', ['api.stripe.com'], [
            ['alias' => 'SK', 'description' => 'Stripe secret key for charges API.'],
        ]);
        wp_set_current_user($this->admin_id);
        $nonce = wp_create_nonce('dsgo_apps_secret_nonce_vault-write');
        $_POST = [
            'app_id' => 'vault-write',
            'alias'  => 'SK',
            'value'  => 'sk_test_persisted',
            'nonce'  => $nonce,
        ];
        $_REQUEST = $_POST;
        $caught = $this->capture_ajax_json('dsgo_apps_secret_set');
        $this->assertTrue($caught['payload']['success'] ?? false);
        $this->assertSame('sk_test_persisted', \DSGo_Apps\Secret_Vault::get('vault-write', 'SK'));
        \DSGo_Apps\Secret_Vault::delete_all('vault-write');
    }

    public function test_ajax_secret_set_rejects_undeclared_alias(): void {
        $this->seed_http_app('vault-strict', ['api.stripe.com'], [
            ['alias' => 'SK', 'description' => 'Stripe secret key for charges API.'],
        ]);
        wp_set_current_user($this->admin_id);
        $nonce = wp_create_nonce('dsgo_apps_secret_nonce_vault-strict');
        $_POST = [
            'app_id' => 'vault-strict',
            'alias'  => 'UNKNOWN_KEY',
            'value'  => 'leaked',
            'nonce'  => $nonce,
        ];
        $_REQUEST = $_POST;
        $caught = $this->capture_ajax_json('dsgo_apps_secret_set');
        $this->assertFalse($caught['payload']['success']);
        $this->assertSame('unknown_alias', $caught['payload']['data']['code']);
        // And the vault has nothing for that alias — defense against typos
        // leaking values that the proxy will never use.
        $this->assertNull(\DSGo_Apps\Secret_Vault::get('vault-strict', 'UNKNOWN_KEY'));
    }

    public function test_ajax_secret_clear_removes_value(): void {
        $this->seed_http_app('vault-clear', ['api.stripe.com'], [
            ['alias' => 'SK', 'description' => 'Stripe secret key for charges API.'],
        ]);
        \DSGo_Apps\Secret_Vault::set('vault-clear', 'SK', 'sk_to_delete');
        wp_set_current_user($this->admin_id);
        $nonce = wp_create_nonce('dsgo_apps_secret_nonce_vault-clear');
        $_POST = [
            'app_id' => 'vault-clear',
            'alias'  => 'SK',
            'nonce'  => $nonce,
        ];
        $_REQUEST = $_POST;
        $caught = $this->capture_ajax_json('dsgo_apps_secret_clear');
        $this->assertTrue($caught['payload']['success']);
        $this->assertNull(\DSGo_Apps\Secret_Vault::get('vault-clear', 'SK'));
    }

    public function test_ajax_http_test_returns_404_when_no_test_endpoint_declared(): void {
        $this->seed_http_app('no-endpoint', ['api.stripe.com']);   // no http.test_endpoint
        wp_set_current_user($this->admin_id);
        $nonce = wp_create_nonce('dsgo_apps_secret_nonce_no-endpoint');
        $_POST = ['app_id' => 'no-endpoint', 'nonce' => $nonce];
        $_REQUEST = $_POST;
        $caught = $this->capture_ajax_json('dsgo_apps_http_test');
        $this->assertFalse($caught['payload']['success']);
        $this->assertSame('no_test_endpoint', $caught['payload']['data']['code']);
    }

    public function test_ajax_http_test_invokes_bridge_against_declared_endpoint(): void {
        $this->seed_http_app('with-endpoint', ['api.stripe.com'], [], 'https://api.stripe.com/v1/charges');
        wp_set_current_user($this->admin_id);
        \DSGo_Apps\Http_Proxy_Bridge::set_transport_factory_for_tests(static fn () => [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => '{"ok":true}',
            'headers'  => ['content-type' => 'application/json'],
        ]);
        $nonce = wp_create_nonce('dsgo_apps_secret_nonce_with-endpoint');
        $_POST = ['app_id' => 'with-endpoint', 'nonce' => $nonce];
        $_REQUEST = $_POST;
        $caught = $this->capture_ajax_json('dsgo_apps_http_test');
        $this->assertTrue($caught['payload']['success']);
        $this->assertSame(200, $caught['payload']['data']['status']);
        $this->tear_down_http_proxy();
    }

    public function test_ajax_secret_set_rejects_bad_nonce(): void {
        $this->seed_http_app('vault-nonce', ['api.stripe.com'], [
            ['alias' => 'SK', 'description' => 'Stripe secret key for charges API.'],
        ]);
        wp_set_current_user($this->admin_id);
        $_POST = [
            'app_id' => 'vault-nonce',
            'alias'  => 'SK',
            'value'  => 'sk_test_xyz',
            'nonce'  => 'definitely-not-a-real-nonce',
        ];
        // check_ajax_referer dies with -1 on bad nonce; capture_ajax_json
        // converts WPDieException into a structured payload.
        $_REQUEST = $_POST;
        $caught = $this->capture_ajax_json('dsgo_apps_secret_set');
        // No secret was written even though the alias was valid.
        $this->assertNull(\DSGo_Apps\Secret_Vault::get('vault-nonce', 'SK'));
        // Some kind of failure (-1 die or json_error) — exact shape varies
        // between WP versions, so just assert "not success".
        $this->assertFalse($caught['payload']['success'] ?? false);
    }

    /**
     * Run an admin-ajax action and capture the JSON `wp_send_json_*`
     * payload. wp_send_json calls wp_die after echoing the body, and
     * by default wp_die exits — which kills the test process. We
     * register an ajax die handler that throws WPAjaxDieContinueException
     * (matches the WP_Ajax_UnitTestCase convention) and we ob_get_clean
     * INSIDE that handler so the buffered body is preserved on the
     * exception's message.
     *
     * @return array{payload:array<mixed>}
     */
    private function capture_ajax_json(string $action): array {
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }
        $captured = ['body' => ''];
        $die_handler = static function () use (&$captured): void {
            $captured['body'] = (string) ob_get_clean();
            throw new \WPAjaxDieContinueException('');
        };
        add_filter('wp_die_ajax_handler', static fn () => $die_handler);
        // wp_send_json sends a Content-Type header before echoing. The
        // implicit_flush=off pattern keeps the body inside the buffer
        // until our die handler grabs it.
        $prev      = ini_get('implicit_flush');
        ini_set('implicit_flush', '0');
        $start_lvl = ob_get_level();
        ob_start();
        try {
            do_action('wp_ajax_' . $action);
        } catch (\WPAjaxDieContinueException | \WPAjaxDieStopException $e) {
            // Body already captured by die handler; nothing to do.
        }
        // Drain any buffers we (or the handler) opened so PHPUnit doesn't
        // flag the test as "did not close its own output buffers" risky.
        while (ob_get_level() > $start_lvl) {
            $chunk = (string) ob_get_clean();
            if ($captured['body'] === '' && $chunk !== '') {
                $captured['body'] = $chunk;
            }
        }
        ini_set('implicit_flush', (string) $prev);
        remove_all_filters('wp_die_ajax_handler');
        $payload = json_decode($captured['body'], true);
        return ['payload' => is_array($payload) ? $payload : ['raw' => $captured['body']]];
    }
}
