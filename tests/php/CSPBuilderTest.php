<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\CSPBuilder;
use WP_UnitTestCase;

class CSPBuilderTest extends WP_UnitTestCase {

    public function test_builds_header_for_minimal_csp(): void {
        $csp = [
            'script_src' => ['self'],
            'style_src' => ['self'],
            'img_src' => ['self'],
            'connect_src' => ['self'],
        ];
        $header = CSPBuilder::build($csp, 'NONCE123');
        $this->assertStringContainsString("script-src 'self' 'nonce-NONCE123'", $header);
        // 'wasm-unsafe-eval' is appended unconditionally so WebAssembly
        // works without per-author opt-in. It is the narrow WASM token,
        // NOT the generic 'unsafe-eval'.
        $this->assertStringContainsString("'wasm-unsafe-eval'", $header);
        // style-src uses 'unsafe-inline' (covers both <style> blocks and style=
        // attribute) instead of a nonce. Per CSP3 the two are mutually
        // exclusive; framework apps need the attribute path to work.
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $header);
        $this->assertStringNotContainsString("'nonce-NONCE123'", explode("style-src", $header)[1] ?? '');
        $this->assertStringContainsString("img-src 'self'", $header);
        $this->assertStringContainsString("connect-src 'self'", $header);
        // worker-src lets bundles spawn Web Workers from their own assets.
        $this->assertStringContainsString("worker-src 'self'", $header);
        $this->assertStringContainsString("default-src 'none'", $header);
        $this->assertStringContainsString("base-uri 'none'", $header);
        $this->assertStringContainsString("frame-ancestors 'self'", $header);
        $this->assertStringContainsString("object-src 'none'", $header);
    }

    public function test_quotes_self_and_data_keywords(): void {
        $csp = [
            'script_src' => ['self'],
            'style_src' => ['self'],
            'img_src' => ['self', 'data:'],
            'connect_src' => ['self'],
        ];
        $header = CSPBuilder::build($csp, 'N');
        $this->assertStringContainsString("img-src 'self' data:", $header);
    }

    public function test_passes_through_https_origins(): void {
        $csp = [
            'script_src' => ['self'],
            'style_src' => ['self'],
            'img_src' => ['self'],
            'connect_src' => ['self', 'https://api.openai.com', 'https://example.com:8443'],
        ];
        $header = CSPBuilder::build($csp, 'N');
        $this->assertStringContainsString("connect-src 'self' https://api.openai.com https://example.com:8443", $header);
    }

    public function test_frame_src_defaults_to_none(): void {
        $csp = ['script_src' => ['self'], 'style_src' => ['self'], 'img_src' => ['self'], 'connect_src' => ['self']];
        $header = CSPBuilder::build($csp, 'N');
        $this->assertStringContainsString("frame-src 'none'", $header);
    }

    public function test_frame_src_lists_embed_origins(): void {
        $csp = ['script_src' => ['self'], 'style_src' => ['self'], 'img_src' => ['self'], 'connect_src' => ['self']];
        $header = CSPBuilder::build($csp, 'N', ['https://www.youtube.com', 'https://js.stripe.com']);
        $this->assertStringContainsString('frame-src https://www.youtube.com https://js.stripe.com', $header);
        $this->assertStringNotContainsString("frame-src 'none'", $header);
    }

    public function test_content_image_origins_includes_uploads_for_commerce_apps(): void {
        $manifest = \DSGo_Apps\Manifest::validate([
            'manifest_version' => 1, 'id' => 'shop-csp', 'name' => 'Shop',
            'version' => '0.1.0', 'entry' => 'index.html', 'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => ['commerce'], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
            'commerce' => ['providers' => ['woocommerce'], 'endpoints' => ['products']],
        ]);
        $origins = CSPBuilder::content_image_origins($manifest);
        $this->assertNotEmpty($origins, 'commerce apps must get uploads origin to render product images');
        $this->assertStringContainsString('/wp-content/uploads/', $origins[0]);
    }

    public function test_content_image_origins_includes_gravatar_for_user_reads(): void {
        $manifest = \DSGo_Apps\Manifest::validate([
            'manifest_version' => 1, 'id' => 'profile', 'name' => 'P',
            'version' => '0.1.0', 'entry' => 'index.html', 'isolation' => 'inline',
            'routes' => [['path' => '/', 'file' => 'index.html']],
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => ['user'], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self'], 'connect_src' => ['self'],
            ]],
        ]);
        $origins = CSPBuilder::content_image_origins($manifest);
        $this->assertContains('https://secure.gravatar.com', $origins);
    }

    public function test_content_image_origins_empty_when_no_relevant_permissions(): void {
        $manifest = \DSGo_Apps\Manifest::validate([
            'manifest_version' => 1, 'id' => 'static-app', 'name' => 'S',
            'version' => '0.1.0', 'entry' => 'index.html', 'isolation' => 'inline',
            'routes' => [['path' => '/', 'file' => 'index.html']],
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => [], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'csp' => [
                'script_src' => ['self'], 'style_src' => ['self'],
                'img_src' => ['self'], 'connect_src' => ['self'],
            ]],
        ]);
        $this->assertSame([], CSPBuilder::content_image_origins($manifest));
    }

    public function test_script_src_keeps_strict_keywords_out(): void {
        // Script execution is the high-leverage attack surface; the dangerous
        // CSP keywords MUST stay out of script-src. Style-src is permitted to
        // relax (covers the `style="..."` attribute frameworks emit for
        // animation timing) — CSS does not reach JS. The one exception is
        // 'wasm-unsafe-eval': it permits WebAssembly compile/instantiate ONLY,
        // not generic JS eval(), so it is allowed and asserted for elsewhere.
        $csp = [
            'script_src' => ['self'],
            'style_src' => ['self'],
            'img_src' => ['self'],
            'connect_src' => ['self'],
        ];
        $header = CSPBuilder::build($csp, 'N');
        $script_section = explode(';', $header)[1] ?? ''; // "script-src ..."
        $this->assertStringContainsString('script-src', $script_section);
        $this->assertStringNotContainsString('unsafe-inline', $script_section);
        // The generic eval grant is the quoted token "'unsafe-eval'". The
        // narrow "'wasm-unsafe-eval'" token contains the substring but is a
        // distinct, safe keyword — match the quoted form to tell them apart.
        $generic_eval_kw = "'unsafe-" . "eval'";
        $this->assertStringNotContainsString($generic_eval_kw, $script_section);
    }
}
