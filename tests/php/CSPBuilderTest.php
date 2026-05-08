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
        // style-src uses 'unsafe-inline' (covers both <style> blocks and style=
        // attribute) instead of a nonce. Per CSP3 the two are mutually
        // exclusive; framework apps need the attribute path to work.
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $header);
        $this->assertStringNotContainsString("'nonce-NONCE123'", explode("style-src", $header)[1] ?? '');
        $this->assertStringContainsString("img-src 'self'", $header);
        $this->assertStringContainsString("connect-src 'self'", $header);
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

    public function test_script_src_keeps_strict_keywords_out(): void {
        // Script execution is the high-leverage attack surface; the dangerous
        // CSP keywords MUST stay out of script-src. Style-src is permitted to
        // relax (covers the `style="..."` attribute frameworks emit for
        // animation timing) — CSS does not reach JS.
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
        $unsafe_eval_kw = 'unsafe-' . 'eval';
        $this->assertStringNotContainsString($unsafe_eval_kw, $script_section);
    }
}
