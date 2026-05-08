<?php
declare(strict_types=1);

namespace DSGo_Apps\Tests;

use DSGo_Apps\Harness_Prompt;
use DSGo_Apps\Harness_Provider;

/**
 * Test double for Harness_Provider. Returns a queue of canned responses.
 *
 * Supports both the legacy generate() interface (used by HarnessGeneratorTest
 * for backwards-compat) and the new chat() interface used by the agent loop.
 *
 * For the legacy interface, each canned response is either:
 *   - array<string,mixed>  — an envelope (plan + files); the mock converts this
 *                            to a finish() tool call so the loop terminates.
 *   - WP_Error('parse_error', ...) — simulates a parse/provider error.
 *   - WP_Error other — bubbles up directly.
 *
 * HarnessValidationError scenarios (bad envelope content) are simulated by
 * returning the raw envelope as a finish() call; the generator's validator
 * will reject it and the mock's next response provides the corrected bundle.
 */
final class MockHarnessProvider extends Harness_Provider
{
    /** @var list<array<string,mixed>|\WP_Error> */
    public array $responses = [];
    public int $call_count = 0;
    /** @var list<Harness_Prompt> */
    public array $captured_prompts = [];

    public function __construct() { /* skip parent ctor — no callables needed */ }

    /**
     * Legacy interface — kept so old tests compile. Not called by the new generator.
     */
    public function generate(Harness_Prompt $prompt, array $envelope_schema, string $model = ''): array|\WP_Error
    {
        $this->captured_prompts[] = $prompt;
        $this->call_count++;
        if (empty($this->responses)) {
            return new \WP_Error('test_exhausted', 'MockHarnessProvider ran out of canned responses.');
        }
        return array_shift($this->responses);
    }

    /**
     * New chat() interface used by Harness_Generator's agent loop.
     *
     * Converts each canned response to the shape the loop expects:
     * - WP_Error  → returned directly
     * - array     → wrapped as a finish() tool call result. This triggers the
     *               generator to call $tools->dispatch('finish', args), which
     *               runs the validator. If the envelope is invalid, finish()
     *               returns ok=false and the loop moves on to the next response.
     *
     * call_count and captured_prompts are updated for compatibility with
     * HarnessGeneratorTest assertions.
     */
    public function chat(string $system, array $messages, array $tool_decls = [], string $model = ''): array|\WP_Error
    {
        $this->call_count++;

        // Capture a synthetic prompt so captured_prompts[0]->variable_suffix()
        // works in test_generate_passes_prior_files_to_prompt_builder.
        // Extract the user message content for the prompt's variable suffix.
        $variable_suffix = '';
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'user') {
                $variable_suffix = (string) ($msg['content'] ?? '');
                break;
            }
        }
        // Build a Harness_Prompt stand-in that the test can inspect.
        $this->captured_prompts[] = new Harness_Prompt($system, $variable_suffix);

        if (empty($this->responses)) {
            return new \WP_Error('test_exhausted', 'MockHarnessProvider ran out of canned responses.');
        }

        $response = array_shift($this->responses);

        // Propagate WP_Errors directly.
        if ($response instanceof \WP_Error) {
            return $response;
        }

        // Wrap an envelope array as a finish() tool call.
        $envelope = is_array($response) ? $response : [];
        return [
            'text'       => '',
            'tool_calls' => [
                [
                    'id'   => 'mock_finish_' . $this->call_count,
                    'name' => 'finish',
                    'args' => $envelope,
                ],
            ],
            'tokens_in'  => 50,
            'tokens_out' => 20,
        ];
    }
}
