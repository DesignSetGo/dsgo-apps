<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\AiBridge;
use DSGo_Apps\Manifest;
use WP_UnitTestCase;

/**
 * Mock builder + resolver used by these tests. Records calls, returns scripted
 * responses. Production code uses the real WP_AI_Client_Prompt_Builder.
 */
class FakePromptBuilder {
    public array $messages = [];
    public array $abilities = [];
    public ?int $max_tokens = null;
    /** @var array<int, callable():mixed> */
    public array $generate_responses = [];
    private int $generate_idx = 0;

    public function with_messages(array $msgs): self { $this->messages = array_merge($this->messages, $msgs); return $this; }
    public function with_message($msg): self { $this->messages[] = $msg; return $this; }
    public function using_abilities(...$abilities): self { $this->abilities = $abilities; return $this; }
    public function with_max_tokens(int $n): self { $this->max_tokens = $n; return $this; }
    public function generate_text_result() {
        if ($this->generate_idx >= count($this->generate_responses)) {
            return new \WP_Error('test_no_more_responses', 'fake builder ran out of scripted responses');
        }
        $next = $this->generate_responses[$this->generate_idx++];
        return is_callable($next) ? $next() : $next;
    }
}

class FakeFunctionResolver {
    /** @var array<int,object> */
    public array $allowed_abilities;
    public array $execute_log = [];

    public function __construct(...$abilities) {
        $this->allowed_abilities = $abilities;
    }
    public function has_ability_calls($message): bool {
        return is_object($message) && property_exists($message, 'has_calls') && (bool) $message->has_calls;
    }
    public function execute_abilities($message): object {
        $this->execute_log[] = $message;
        return (object) ['type' => 'function_responses', 'parts' => $message->calls ?? []];
    }
}

class AiBridgeTest extends WP_UnitTestCase {

    public function tear_down(): void {
        AiBridge::reset_factory_for_tests();
        parent::tear_down();
    }

    private function manifest(array $overrides = []): Manifest {
        $base = [
            'manifest_version' => 1, 'id' => 'sample', 'name' => 'Sample',
            'version' => '0.1.0', 'entry' => 'index.html', 'isolation' => 'iframe',
            'display' => ['modes' => ['page'], 'default' => 'page'],
            'permissions' => ['read' => ['ai'], 'write' => []],
            'runtime' => ['sandbox' => 'strict', 'external_origins' => []],
        ];
        // Deep-merge for nested 'permissions' / 'abilities' / 'ai' overrides
        foreach ($overrides as $k => $v) { $base[$k] = $v; }
        return Manifest::validate($base);
    }

    public function test_returns_ai_not_configured_when_wp_supports_ai_false(): void {
        AiBridge::set_factory_for_tests(static fn () => [
            'supports_ai' => false,
            'builder'     => new FakePromptBuilder(),
            'resolver_factory' => null,
        ]);
        $result = AiBridge::prompt($this->manifest(), 0, ['messages' => [['role' => 'user', 'content' => 'hi']]]);
        $this->assertFalse($result['ok']);
        $this->assertSame('ai_not_configured', $result['code']);
    }

    public function test_plain_completion_returns_text(): void {
        $b = new FakePromptBuilder();
        $b->generate_responses[] = (object) [
            'type'    => 'text',
            'text'    => 'hello world',
            'usage'   => ['input_tokens' => 3, 'output_tokens' => 2],
        ];
        AiBridge::set_factory_for_tests(static fn () => [
            'supports_ai' => true, 'builder' => $b, 'resolver_factory' => static fn ($a) => new FakeFunctionResolver(...$a),
        ]);

        $result = AiBridge::prompt($this->manifest(), 0, [
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame('hello world', $result['data']['content']);
        $this->assertSame([], $result['data']['tool_calls']);
        $this->assertSame(['input_tokens' => 3, 'output_tokens' => 2], $result['data']['usage']);
    }

    public function test_tools_omitted_treated_as_no_tools(): void {
        $b = new FakePromptBuilder();
        $b->generate_responses[] = (object) ['type' => 'text', 'text' => 'ok', 'usage' => []];
        AiBridge::set_factory_for_tests(static fn () => [
            'supports_ai' => true, 'builder' => $b, 'resolver_factory' => static fn ($a) => new FakeFunctionResolver(...$a),
        ]);

        AiBridge::prompt($this->manifest([
            'permissions' => ['read' => ['ai', 'abilities'], 'write' => []],
            'abilities' => ['consumes' => ['test/*']],
        ]),
            0, ['messages' => [['role' => 'user', 'content' => 'hi']]]);
        $this->assertSame([], $b->abilities, 'omitted tools should not expand to consumes list');
    }

    public function test_tools_in_explicit_list_must_match_consumes(): void {
        AiBridge::set_factory_for_tests(static fn () => [
            'supports_ai' => true, 'builder' => new FakePromptBuilder(), 'resolver_factory' => static fn ($a) => new FakeFunctionResolver(...$a),
        ]);
        $manifest = $this->manifest([
            'permissions' => ['read' => ['ai', 'abilities'], 'write' => []],
            'abilities' => ['consumes' => ['test/allowed']],
        ]);
        $result = AiBridge::prompt($manifest, 0, [
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'tools'    => ['test/forbidden'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('permission_denied', $result['code']);
        $this->assertSame('tool_not_in_consumes', $result['reason']);
    }

    public function test_max_tool_calls_zero_disables_tools_even_with_auto(): void {
        $b = new FakePromptBuilder();
        $b->generate_responses[] = (object) ['type' => 'text', 'text' => 'ok', 'usage' => []];
        AiBridge::set_factory_for_tests(static fn () => [
            'supports_ai' => true, 'builder' => $b, 'resolver_factory' => static fn ($a) => new FakeFunctionResolver(...$a),
        ]);
        $manifest = $this->manifest([
            'permissions' => ['read' => ['ai', 'abilities'], 'write' => []],
            'abilities' => ['consumes' => ['test/*']],
            'ai' => ['max_tool_calls' => 0],
        ]);
        AiBridge::prompt($manifest, 0, [
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'tools'    => 'auto',
        ]);
        $this->assertSame([], $b->abilities);
    }

    public function test_tool_loop_iterates_then_returns_text(): void {
        $b = new FakePromptBuilder();
        $b->generate_responses[] = (object) [
            'type' => 'tool_calls',
            'has_calls' => true,
            'calls' => [['name' => 'test/echo', 'args' => ['x' => 1], 'result' => ['ok' => true, 'data' => ['echoed' => 1]]]],
        ];
        $b->generate_responses[] = (object) ['type' => 'text', 'text' => 'done', 'usage' => []];
        AiBridge::set_factory_for_tests(static fn () => [
            'supports_ai' => true, 'builder' => $b, 'resolver_factory' => static fn ($a) => new FakeFunctionResolver(...$a),
        ]);
        $manifest = $this->manifest([
            'permissions' => ['read' => ['ai', 'abilities'], 'write' => []],
            'abilities' => ['consumes' => ['test/*']],
        ]);
        $result = AiBridge::prompt($manifest, 0, [
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'tools'    => 'auto',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame('done', $result['data']['content']);
        $this->assertCount(1, $result['data']['tool_calls']);
        $this->assertSame('test/echo', $result['data']['tool_calls'][0]['name']);
        $this->assertTrue($result['data']['tool_calls'][0]['result']['ok']);
    }

    public function test_iteration_cap_exceeded_returns_internal_error(): void {
        $b = new FakePromptBuilder();
        for ($i = 0; $i < 8; $i++) {
            $b->generate_responses[] = (object) [
                'type' => 'tool_calls', 'has_calls' => true,
                'calls' => [['name' => 'test/x', 'args' => [], 'result' => ['ok' => true, 'data' => null]]],
            ];
        }
        AiBridge::set_factory_for_tests(static fn () => [
            'supports_ai' => true, 'builder' => $b, 'resolver_factory' => static fn ($a) => new FakeFunctionResolver(...$a),
        ]);
        $manifest = $this->manifest([
            'permissions' => ['read' => ['ai', 'abilities'], 'write' => []],
            'abilities' => ['consumes' => ['test/*']],
            'ai' => ['max_tool_calls' => 2],
        ]);
        $result = AiBridge::prompt($manifest, 0, [
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'tools'    => 'auto',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('internal_error', $result['code']);
        $this->assertSame('tool_call_cap_exceeded', $result['reason']);
    }

    public function test_wp_error_from_first_turn_maps_to_internal_error(): void {
        $b = new FakePromptBuilder();
        $b->generate_responses[] = static fn () => new \WP_Error('provider_down', 'transport failed');
        AiBridge::set_factory_for_tests(static fn () => [
            'supports_ai' => true, 'builder' => $b, 'resolver_factory' => static fn ($a) => new FakeFunctionResolver(...$a),
        ]);
        $result = AiBridge::prompt($this->manifest(), 0, [
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('internal_error', $result['code']);
        $this->assertSame('provider_down', $result['wp_error_code']);
    }

    public function test_invalid_messages_returns_invalid_params(): void {
        AiBridge::set_factory_for_tests(static fn () => [
            'supports_ai' => true, 'builder' => new FakePromptBuilder(), 'resolver_factory' => static fn ($a) => new FakeFunctionResolver(...$a),
        ]);
        $result = AiBridge::prompt($this->manifest(), 0, ['messages' => 'not-an-array']);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_params', $result['code']);
    }
}
