<?php
/**
 * Single source of truth for banned dynamic-code / DOM-injection APIs.
 *
 * Both the harness validator (Js_Scanner) and the prompt builder
 * (<bundle_constraints> section) read from this list.
 *
 * Error codes:
 *   - unsafe_code_eval  — executing strings as JS (eval, new Function,
 *     setTimeout/setInterval with a string first arg). Fix: do not parse
 *     code from strings.
 *   - unsafe_html_sink  — writing arbitrary HTML strings into the live DOM
 *     (innerHTML lhs, document.write). Fix: use textContent for plain text
 *     or createElement for structure.
 *
 * Targeted codes let the agent route to the right skill on retry rather
 * than receiving a generic banned_api_used and re-guessing.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

return [
    [
        'id'          => 'eval_call',
        'pattern'     => 'ev' . 'al',
        'kind'        => 'function_call',
        'error_code'  => 'unsafe_code_eval',
        'description' => 'The eval primitive (string-form invocation that executes arbitrary code); CSP blocks at runtime.',
        'fix_hint'    => 'Compute values directly; do not parse code from strings.',
    ],
    [
        'id'          => 'function_constructor',
        'pattern'     => 'Function',
        'kind'        => 'new_expression',
        'error_code'  => 'unsafe_code_eval',
        'description' => 'The Function constructor with a string body executes arbitrary code; CSP blocks at runtime.',
        'fix_hint'    => 'Use a normal function declaration.',
    ],
    [
        'id'          => 'document_string_write',
        'pattern'     => 'document' . '.' . 'write',
        'kind'        => 'member_call',
        'error_code'  => 'unsafe_html_sink',
        'description' => 'Document write injects HTML strings; CSP blocks inline scripts and the document is already loaded by the time apps run.',
        'fix_hint'    => 'Use createElement plus appendChild. See pattern-reactive-state.',
    ],
    [
        'id'          => 'inner_html_assign',
        'pattern'     => 'innerHTML',
        'kind'        => 'lhs_assignment',
        'error_code'  => 'unsafe_html_sink',
        'description' => 'innerHTML LHS assignment is an HTML-string sink (covers both = and += forms).',
        'fix_hint'    => 'Use textContent for plain text; createElement for structure. See pattern-reactive-state.',
    ],
    [
        'id'          => 'timer_string_arg',
        'pattern'     => 'setTimeout|setInterval',
        'kind'        => 'string_first_arg',
        'error_code'  => 'unsafe_code_eval',
        'description' => 'Timer with a string first arg evaluates code (eval-equivalent).',
        'fix_hint'    => 'Pass a function or arrow expression instead of a string.',
    ],
];
