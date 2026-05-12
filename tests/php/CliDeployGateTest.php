<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\RestApi;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use ZipArchive;

/**
 * The POST /dsgo/v1/apps (create_app) endpoint must require a Pro license
 * when the request authenticated via Application Password (the method the
 * @designsetgo/cli uses). Cookie-authenticated requests (wp-admin upload
 * importer) must continue to work unconditionally.
 */
final class CliDeployGateTest extends WP_UnitTestCase {

    protected WP_REST_Server $server;
    protected int $admin_id;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server   = $wp_rest_server;
        do_action('rest_api_init');

        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
    }

    public function tear_down(): void {
        remove_all_filters('dsgo_apps_pro_feature_enabled');
        // Reset the per-request AppPassword flag so it does not bleed into
        // subsequent tests in the same PHPUnit process.
        RestApi::reset_app_password_flag();
        parent::tear_down();
    }

    // ---- AppPassword + gate closed ----------------------------------------

    public function test_install_via_app_password_returns_402_when_gate_closed(): void {
        wp_set_current_user($this->admin_id);
        // Simulate AppPassword auth — fire the action that RestApi listens for.
        do_action('application_password_did_authenticate', get_userdata($this->admin_id), ['uuid' => 'test-uuid']);
        remove_all_filters('dsgo_apps_pro_feature_enabled');

        $request = new WP_REST_Request('POST', '/dsgo/v1/apps');
        $request->set_file_params(['bundle' => $this->build_bundle_param('gate-test-1')]);

        $response = $this->server->dispatch($request);

        $this->assertSame(402, $response->get_status());
        $this->assertSame('cli_requires_pro', $response->get_data()['code']);
    }

    public function test_install_via_app_password_402_response_includes_pricing_url(): void {
        wp_set_current_user($this->admin_id);
        do_action('application_password_did_authenticate', get_userdata($this->admin_id), ['uuid' => 'test-uuid']);
        remove_all_filters('dsgo_apps_pro_feature_enabled');

        $request = new WP_REST_Request('POST', '/dsgo/v1/apps');
        $request->set_file_params(['bundle' => $this->build_bundle_param('gate-test-2')]);

        $response = $this->server->dispatch($request);
        $data     = $response->get_data();

        $this->assertSame(402, $response->get_status());
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('https://designsetgo.dev/pricing', $data['data']['pricing_url']);
    }

    // ---- Cookie auth (wp-admin importer path) -- gate must not apply ------

    public function test_install_via_cookie_auth_is_unaffected_by_closed_gate(): void {
        wp_set_current_user($this->admin_id);
        // No AppPassword event fired; this is the cookie-auth path.
        remove_all_filters('dsgo_apps_pro_feature_enabled');

        $request = new WP_REST_Request('POST', '/dsgo/v1/apps');
        $request->set_file_params(['bundle' => $this->build_bundle_param('gate-test-3')]);

        $response = $this->server->dispatch($request);

        $this->assertNotSame(402, $response->get_status(), 'Cookie-authed install must not be blocked by the cli_deploy gate');
    }

    // ---- AppPassword + gate open (Pro license active) ---------------------

    public function test_install_via_app_password_succeeds_when_gate_open(): void {
        add_filter('dsgo_apps_pro_feature_enabled', static function (bool $en, string $f): bool {
            return $f === 'cli_deploy' ? true : $en;
        }, 10, 2);

        wp_set_current_user($this->admin_id);
        do_action('application_password_did_authenticate', get_userdata($this->admin_id), ['uuid' => 'test-uuid']);

        $request = new WP_REST_Request('POST', '/dsgo/v1/apps');
        $request->set_file_params(['bundle' => $this->build_bundle_param('gate-test-4')]);

        $response = $this->server->dispatch($request);

        $this->assertNotSame(402, $response->get_status(), 'AppPassword install should pass when cli_deploy gate is open');
        // The installer returns 201 on a new install; 200 would indicate the
        // guard passed even without a real zip — both confirm the gate is open.
        $this->assertContains($response->get_status(), [200, 201]);
    }

    // ---- Flag isolation between tests -------------------------------------

    public function test_app_password_flag_does_not_persist_across_requests(): void {
        // Confirm that tear_down resets the flag so this test sees it as clear.
        $this->assertFalse(RestApi::is_app_password_request());
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * Build a minimal valid bundle zip and return a $_FILES-style array for
     * set_file_params(). Matches the build_minimal_zip helper in InstallerCapTest.
     *
     * @return array{tmp_name:string,name:string,type:string,size:int,error:int}
     */
    private function build_bundle_param(string $id): array {
        $tmp = tempnam(sys_get_temp_dir(), 'dsgo-gate-');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('dsgo-app.json', (string) json_encode([
            'manifest_version' => 1,
            'id'               => $id,
            'name'             => $id,
            'version'          => '0.1.0',
            'entry'            => 'index.html',
            'isolation'        => 'iframe',
            'display'          => ['modes' => ['page'], 'default' => 'page'],
            'permissions'      => ['read' => [], 'write' => []],
            'runtime'          => ['sandbox' => 'strict', 'external_origins' => []],
        ]));
        $zip->addFromString('index.html', '<!doctype html><html><head><title>x</title></head><body>x</body></html>');
        $zip->close();
        return [
            'tmp_name' => $tmp,
            'name'     => $id . '.zip',
            'type'     => 'application/zip',
            'size'     => (int) filesize($tmp),
            'error'    => UPLOAD_ERR_OK,
        ];
    }
}
