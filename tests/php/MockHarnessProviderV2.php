<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Harness_Prompt;
use DSGo_Apps\Harness_Provider;

/**
 * Test double for Harness_Provider that supports both the legacy generate()
 * interface (used by HarnessGeneratorTest) and the new chat() interface
 * (used by HarnessLoopTest / HarnessCostCapTest).
 *
 * For chat(), each queued response should be an array with keys:
 *   text (string), tool_calls (array), tokens_in (int), tokens_out (int)
 * or a WP_Error for error scenarios.
 */
final class MockHarnessProviderV2 extends Harness_Provider
{
    /** @var list<array<string,mixed>|\WP_Error> */
    public array $chat_responses = [];
    public int $chat_call_count  = 0;

    /** @var list<array{system:string,messages:array,tool_decls:array,model:string}> */
    public array $captured_chat_calls = [];

    /** @var list<array<string,mixed>|\WP_Error> (for legacy generate()) */
    public array $responses = [];
    public int $call_count = 0;
    /** @var list<Harness_Prompt> */
    public array $captured_prompts = [];

    public function __construct() { /* skip parent ctor */ }

    public function generate(Harness_Prompt $prompt, array $envelope_schema, string $model = ''): array|\WP_Error
    {
        $this->captured_prompts[] = $prompt;
        $this->call_count++;
        if (empty($this->responses)) {
            return new \WP_Error('test_exhausted', 'MockHarnessProviderV2::generate ran out of responses.');
        }
        return array_shift($this->responses);
    }

    public function chat(string $system, array $messages, array $tool_decls = [], string $model = ''): array|\WP_Error
    {
        $this->captured_chat_calls[] = [
            'system'     => $system,
            'messages'   => $messages,
            'tool_decls' => $tool_decls,
            'model'      => $model,
        ];
        $this->chat_call_count++;
        if (empty($this->chat_responses)) {
            return new \WP_Error('test_exhausted', 'MockHarnessProviderV2::chat ran out of responses.');
        }
        return array_shift($this->chat_responses);
    }
}
