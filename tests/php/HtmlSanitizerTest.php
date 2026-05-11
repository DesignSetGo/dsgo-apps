<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\HtmlSanitizer;
use DSGo_Apps\HtmlSanitizerError;
use WP_UnitTestCase;

class HtmlSanitizerTest extends WP_UnitTestCase {

    public function test_strips_event_handlers(): void {
        $html = '<button onclick="alert(1)">x</button>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringContainsString('<button', $out);
    }

    public function test_strips_iframe(): void {
        $html = '<p>before</p><iframe src="x"></iframe><p>after</p>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringNotContainsString('<iframe', $out);
    }

    public function test_strips_object_embed_base(): void {
        foreach (['<object data="x"></object>', '<embed src="x">', '<base href="x">'] as $bad) {
            $out = HtmlSanitizer::sanitize($bad, ['nonce' => 'NONCE']);
            $this->assertSame('', trim($out), "must strip: $bad");
        }
    }

    public function test_allows_inline_script_for_runtime_nonce_stamping(): void {
        // Frameworks (Astro / Next / Vite) emit inline <script type="module">
        // blocks for hydration, view transitions, etc. The renderer's
        // stamp_nonce_on_existing_tags adds the per-request nonce; CSP then
        // permits execution. Without a stamped nonce these are blocked at
        // the browser, so leaving them in is safe.
        $html = '<script>console.log(1)</script>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringContainsString('<script>', $out);
    }

    public function test_rejects_inline_script_with_mismatched_nonce(): void {
        // An author who hand-wrote a non-matching nonce would silently fail
        // CSP at render time — louder failure here.
        $html = '<script nonce="WRONG">console.log(1)</script>';
        $this->expectException(HtmlSanitizerError::class);
        $this->expectExceptionMessage('match the per-request nonce');
        HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
    }

    public function test_allows_bundled_script_with_nonce(): void {
        $html = '<script src="./assets/app.js" nonce="NONCE"></script>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringContainsString('<script src="./assets/app.js"', $out);
        $this->assertStringContainsString('nonce="NONCE"', $out);
    }

    public function test_rejects_remote_script_src(): void {
        $html = '<script src="https://evil.com/x.js" nonce="NONCE"></script>';
        $this->expectException(HtmlSanitizerError::class);
        $this->expectExceptionMessage('script src');
        HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
    }

    public function test_allows_bundle_script_without_nonce_for_runtime_stamping(): void {
        // Authors don't know the per-request nonce when bundling — the inline
        // renderer's stamp_nonce_on_existing_tags adds one before serving.
        $html = '<script src="./app.js"></script>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringContainsString('<script src="./app.js"', $out);
    }

    public function test_allows_bare_relative_bundle_script(): void {
        // Bundlers like esbuild / rollup typically emit `src="main.js"`
        // (no leading `./`) — this is bundle-local and must be accepted.
        $html = '<script src="main.js"></script>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringContainsString('<script src="main.js"', $out);
    }

    public function test_rejects_script_src_with_mismatched_nonce(): void {
        $html = '<script src="./app.js" nonce="WRONG"></script>';
        $this->expectException(HtmlSanitizerError::class);
        $this->expectExceptionMessage('match the per-request nonce');
        HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
    }

    public function test_rejects_protocol_relative_script(): void {
        $html = '<script src="//cdn.example.com/x.js"></script>';
        $this->expectException(HtmlSanitizerError::class);
        $this->expectExceptionMessage('script src');
        HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
    }

    public function test_allows_application_json_script_no_nonce(): void {
        $html = '<script type="application/json" id="data">{"x":1}</script>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringContainsString('application/json', $out);
        $this->assertStringContainsString('"x":1', $out);
    }

    public function test_allows_speculationrules_no_nonce(): void {
        $html = '<script type="speculationrules">{}</script>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringContainsString('speculationrules', $out);
    }

    public function test_strips_javascript_protocol_in_href(): void {
        $html = '<a href="javascript:alert(1)">x</a>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function test_allows_https_https_only(): void {
        $html = '<a href="https://example.com">x</a><a href="http://example.com">y</a>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringContainsString('https://example.com', $out);
        $this->assertStringNotContainsString('http://example.com', $out);
    }

    public function test_allows_data_uri_in_img_only(): void {
        $img = '<img src="data:image/png;base64,iVBOR...">';
        $a = '<a href="data:text/html,evil">x</a>';
        $img_out = HtmlSanitizer::sanitize($img, ['nonce' => 'NONCE']);
        $a_out = HtmlSanitizer::sanitize($a, ['nonce' => 'NONCE']);
        $this->assertStringContainsString('data:image/png', $img_out);
        $this->assertStringNotContainsString('data:', $a_out);
    }

    public function test_allows_link_stylesheet_bundle_relative(): void {
        $html = '<link rel="stylesheet" href="./assets/styles.css">';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringContainsString('<link rel="stylesheet"', $out);
    }

    public function test_rejects_link_stylesheet_remote(): void {
        $html = '<link rel="stylesheet" href="https://cdn.example.com/x.css">';
        $this->expectException(HtmlSanitizerError::class);
        HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
    }

    public function test_allows_inline_style_tag_with_nonce(): void {
        $html = '<style nonce="NONCE">.x { color: red; }</style>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringContainsString('color: red', $out);
    }

    public function test_strips_svg_script(): void {
        $html = '<svg><script>alert(1)</script></svg>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringNotContainsString('alert', $out);
    }

    public function test_strips_style_attr_url_javascript(): void {
        $html = '<div style="background: url(javascript:alert(1));">x</div>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function test_remote_script_accepted_when_origin_in_script_src(): void {
        // Author declared `csp.script_src: [..., "https://cdn.tailwindcss.com"]`,
        // so a `<script src="https://cdn.tailwindcss.com/...">` survives sanitize.
        $html = '<script src="https://cdn.tailwindcss.com/3.4.0"></script>';
        $out = HtmlSanitizer::sanitize($html, [
            'nonce'          => 'NONCE',
            'script_origins' => ['self', 'https://cdn.tailwindcss.com'],
        ]);
        $this->assertStringContainsString('cdn.tailwindcss.com', $out);
    }

    public function test_remote_script_rejected_when_origin_not_in_script_src(): void {
        $html = '<script src="https://evil.example/x.js"></script>';
        $this->expectException(HtmlSanitizerError::class);
        $this->expectExceptionMessage('script src');
        HtmlSanitizer::sanitize($html, [
            'nonce'          => 'NONCE',
            'script_origins' => ['self', 'https://cdn.tailwindcss.com'],
        ]);
    }

    public function test_iframes_dropped_when_no_embeds_declared(): void {
        $html = '<iframe src="https://www.youtube.com/embed/x" sandbox="allow-scripts"></iframe>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringNotContainsString('<iframe', $out);
    }

    public function test_iframe_kept_when_origin_in_embeds_allowlist_and_has_sandbox(): void {
        $html = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" sandbox="allow-scripts allow-same-origin"></iframe>';
        $out = HtmlSanitizer::sanitize($html, [
            'nonce'         => 'NONCE',
            'embed_origins' => ['https://www.youtube.com'],
        ]);
        $this->assertStringContainsString('<iframe', $out);
        $this->assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $out);
        $this->assertStringContainsString('sandbox=', $out);
    }

    public function test_iframe_dropped_when_origin_not_in_embeds_allowlist(): void {
        $html = '<iframe src="https://evil.example/x" sandbox="allow-scripts"></iframe>';
        $out = HtmlSanitizer::sanitize($html, [
            'nonce'         => 'NONCE',
            'embed_origins' => ['https://www.youtube.com'],
        ]);
        $this->assertStringNotContainsString('<iframe', $out);
    }

    public function test_iframe_keeps_origin_and_injects_default_sandbox_when_missing(): void {
        // WordPress oEmbed (YouTube, Vimeo, etc.) emits unsandboxed iframes that
        // authors cannot easily edit. When the origin is on the manifest's
        // embeds allowlist, inject a safe default sandbox instead of dropping
        // the iframe — the manifest already vouches for the origin.
        $html = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" allowfullscreen></iframe>';
        $out = HtmlSanitizer::sanitize($html, [
            'nonce'         => 'NONCE',
            'embed_origins' => ['https://www.youtube.com'],
        ]);
        $this->assertStringContainsString('<iframe', $out);
        $this->assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $out);
        $this->assertStringContainsString('sandbox="allow-scripts allow-same-origin', $out);
        // Top-frame navigation must NOT be granted by the default.
        $this->assertStringNotContainsString('allow-top-navigation', $out);
    }

    public function test_iframe_dropped_when_origin_missing_even_if_sandbox_absent(): void {
        // Origin check runs before the sandbox-default injection — an
        // un-allowlisted iframe is still dropped regardless of sandbox state.
        $html = '<iframe src="https://evil.example/x"></iframe>';
        $out = HtmlSanitizer::sanitize($html, [
            'nonce'         => 'NONCE',
            'embed_origins' => ['https://www.youtube.com'],
        ]);
        $this->assertStringNotContainsString('<iframe', $out);
    }

    public function test_passes_through_normal_content(): void {
        $html = '<h1>Hello</h1><p>World <a href="https://example.com">link</a></p>';
        $out = HtmlSanitizer::sanitize($html, ['nonce' => 'NONCE']);
        $this->assertStringContainsString('<h1>Hello</h1>', $out);
        $this->assertStringContainsString('https://example.com', $out);
    }

    public function test_root_mount_accepts_site_absolute_script_src(): void {
        $html = '<script src="/_astro/foo.js"></script>';
        $out = HtmlSanitizer::sanitize($html, [
            'nonce'             => 'NONCE',
            'allow_root_paths'  => true,
            'allow_url_prefix'  => null,
        ]);
        $this->assertStringContainsString('/_astro/foo.js', $out);
    }

    public function test_prefixed_mount_rejects_bare_site_absolute_script(): void {
        $this->expectException(HtmlSanitizerError::class);
        HtmlSanitizer::sanitize('<script src="/_astro/foo.js"></script>', [
            'nonce' => 'NONCE',
        ]);
    }

    public function test_custom_url_prefix_accepted_as_bundle_relative(): void {
        $out = HtmlSanitizer::sanitize('<script src="/mini/loyalty/app.js"></script>', [
            'nonce'            => 'NONCE',
            'allow_url_prefix' => '/mini/loyalty',
        ]);
        $this->assertStringContainsString('/mini/loyalty/app.js', $out);
    }

    public function test_root_mount_still_rejects_wp_internal_paths(): void {
        $this->expectException(HtmlSanitizerError::class);
        HtmlSanitizer::sanitize('<script src="/wp-admin/admin.php"></script>', [
            'nonce'            => 'NONCE',
            'allow_root_paths' => true,
        ]);
    }
}
