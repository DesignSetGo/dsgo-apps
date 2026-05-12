<?php
/**
 * Hooks into the DesignSetGo Blocks plugin's llms.txt + llms-full.txt
 * generator to advertise this site's DSGo Apps developer surface.
 *
 * Sibling plugin coupling is one-way: this class only fires when Blocks
 * is installed (the filter is registered unconditionally; Blocks just
 * never apply_filters() if it isn't running). When Blocks isn't
 * installed there is nothing to render against, so this is a no-op.
 *
 * Content is sourced from AiContextPack so the in-admin "Build with AI"
 * panel and the public /llms.txt advertise the same brief.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class LlmsTxtIntegration {

    public static function register(): void {
        add_filter('designsetgo_llms_txt_extra_sections', [self::class, 'append_section'], 10, 2);
    }

    /**
     * Filter callback. Appends the DSGo Apps developer-reference
     * section to /llms.txt or /llms-full.txt.
     *
     * @param mixed  $sections  Existing sections (string[] from Blocks).
     * @param string $variant   'summary' (llms.txt) or 'full' (llms-full.txt).
     * @return array<int,string>
     */
    public static function append_section($sections, string $variant): array {
        $out = is_array($sections) ? array_values(array_filter($sections, 'is_string')) : [];

        if ($variant === 'full') {
            $out[] = AiContextPack::llms_section_full();
        } else {
            $out[] = AiContextPack::llms_section_summary();
        }
        return $out;
    }
}
