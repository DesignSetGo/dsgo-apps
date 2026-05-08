<?php
/**
 * Bridge layer for the WordPress AI Client.
 *
 * Wraps wp_ai_client_prompt() and WP_AI_Client_Ability_Function_Resolver into
 * a single dsgo.ai.prompt() handler that enforces the manifest's tool allow-list,
 * iteration cap, and wall-clock timeout. Returns a structured ok/err array;
 * REST translation lives in class-rest-api.php.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

final class AiBridge {

    /** @var \Closure|null Test-only factory override. */
    private static ?\Closure $factory_override = null;

    /**
     * Test seam: inject a fake builder + resolver. Closure must return:
     *   ['supports_ai' => bool, 'builder' => object, 'resolver_factory' => callable|null]
     */
    public static function set_factory_for_tests(?\Closure $factory): void {
        self::$factory_override = $factory;
    }

    public static function reset_factory_for_tests(): void {
        self::$factory_override = null;
    }

    /**
     * @param array{messages?:mixed,tools?:mixed,max_tokens?:mixed} $params
     * @return array{ok:bool,data?:array,code?:string,reason?:string,wp_error_code?:string,message?:string}
     */
    public static function prompt(Manifest $manifest, int $visitor_user_id, array $params): array {
        $messages = $params['messages'] ?? null;
        if (!is_array($messages)) {
            return ['ok' => false, 'code' => 'invalid_params', 'message' => '"messages" must be an array'];
        }
        foreach ($messages as $i => $m) {
            if (!is_array($m) || !isset($m['role'], $m['content'])
                || !in_array($m['role'], ['user', 'assistant', 'system'], true)
                || !is_string($m['content'])) {
                return ['ok' => false, 'code' => 'invalid_params',
                        'message' => sprintf('messages[%d] is malformed', $i)];
            }
        }

        $factory = self::$factory_override ?? [self::class, 'default_factory'];
        $deps = $factory($manifest);
        if (empty($deps['supports_ai'])) {
            return ['ok' => false, 'code' => 'ai_not_configured',
                    'message' => 'No AI provider is configured. Visit Settings → Connectors to set one up.'];
        }
        $builder = $deps['builder'];
        $resolver_factory = $deps['resolver_factory'] ?? null;

        $tools_param = $params['tools'] ?? [];
        $tool_names = self::resolve_tool_names($tools_param, $manifest);
        if ($tool_names === null) {
            return ['ok' => false, 'code' => 'permission_denied', 'reason' => 'tool_not_in_consumes',
                    'message' => 'one or more requested tools are not in abilities.consumes'];
        }
        if ($manifest->ai_max_tool_calls === 0) {
            $tool_names = [];
        }
        $tool_objects = self::resolve_tool_objects($tool_names);
        $resolver = $resolver_factory ? $resolver_factory($tool_objects) : null;

        $builder->with_messages($messages);
        if (!empty($tool_objects)) {
            $builder->using_abilities(...$tool_objects);
        }
        if (isset($params['max_tokens']) && is_int($params['max_tokens']) && $params['max_tokens'] > 0) {
            $builder->with_max_tokens($params['max_tokens']);
        }

        $start = microtime(true);
        $tool_calls = [];
        $message = $builder->generate_text_result();
        if (is_wp_error($message)) {
            return self::map_wp_error($message);
        }

        for ($i = 0; $i < $manifest->ai_max_tool_calls; $i++) {
            if ((microtime(true) - $start) > $manifest->ai_timeout_seconds) {
                self::log_telemetry($manifest->id, $start, 'ai_timeout', count($tool_calls));
                return ['ok' => false, 'code' => 'internal_error', 'reason' => 'ai_timeout',
                        'message' => sprintf('AI call exceeded %d seconds', $manifest->ai_timeout_seconds)];
            }
            if (!$resolver || !$resolver->has_ability_calls($message)) {
                break;
            }
            $response_message = $resolver->execute_abilities($message);
            foreach (self::extract_recorded_calls($message) as $call) {
                $tool_calls[] = $call;
            }
            $builder->with_message($message);
            $builder->with_message($response_message);
            $message = $builder->generate_text_result();
            if (is_wp_error($message)) {
                return self::map_wp_error($message);
            }
        }

        if ($resolver && $resolver->has_ability_calls($message)) {
            self::log_telemetry($manifest->id, $start, 'tool_call_cap_exceeded', count($tool_calls));
            return ['ok' => false, 'code' => 'internal_error', 'reason' => 'tool_call_cap_exceeded',
                    'message' => sprintf('AI exceeded %d tool calls', $manifest->ai_max_tool_calls)];
        }

        $text  = self::extract_text($message);
        $usage = self::extract_usage($message);
        self::log_telemetry($manifest->id, $start, 'success', count($tool_calls));
        return ['ok' => true, 'data' => [
            'content'    => $text,
            'usage'      => $usage,
            'tool_calls' => $tool_calls,
        ]];
    }

    /**
     * @return string[]|null  List of allowed tool names, or null if any requested
     * tool is not in abilities.consumes. Empty array means "no tools".
     */
    private static function resolve_tool_names(mixed $tools, Manifest $manifest): ?array {
        if ($tools === 'auto') {
            if (!function_exists('wp_get_abilities')) return [];
            $names = [];
            foreach (wp_get_abilities() as $ability) {
                $name = $ability->get_name();
                if (AbilitiesBridge::name_in_consumes($name, $manifest)) {
                    $names[] = $name;
                }
            }
            return $names;
        }
        if (!is_array($tools) || $tools === []) return [];
        foreach ($tools as $name) {
            if (!is_string($name) || !AbilitiesBridge::name_in_consumes($name, $manifest)) {
                return null;
            }
        }
        return $tools;
    }

    /** @return array<int, \WP_Ability> */
    private static function resolve_tool_objects(array $names): array {
        if (!function_exists('wp_get_ability') || !function_exists('wp_has_ability')) return [];
        $out = [];
        foreach ($names as $name) {
            if (!wp_has_ability($name)) continue;
            $ability = wp_get_ability($name);
            if ($ability instanceof \WP_Ability) {
                $out[] = $ability;
            }
        }
        return $out;
    }

    /**
     * Extract per-call records from the model's tool-call message.
     * In tests, the message carries a `calls` array with pre-recorded shape.
     * In production, walks the Message DTO from the bundled php-ai-client.
     *
     * @return array<int, array{name:string,args:array,result:array,duration_ms:int}>
     */
    private static function extract_recorded_calls($message): array {
        if (is_object($message) && property_exists($message, 'calls') && is_array($message->calls)) {
            $out = [];
            foreach ($message->calls as $call) {
                $out[] = [
                    'name'        => (string) ($call['name'] ?? 'unknown'),
                    'args'        => is_array($call['args'] ?? null) ? $call['args'] : [],
                    'result'      => is_array($call['result'] ?? null) ? $call['result'] : ['ok' => false, 'error' => 'unknown', 'code' => 'internal_error'],
                    'duration_ms' => (int) ($call['duration_ms'] ?? 0),
                ];
            }
            return $out;
        }
        return self::extract_recorded_calls_from_real_message($message);
    }

    /** Real-message path — walks Message DTO from the bundled php-ai-client. */
    private static function extract_recorded_calls_from_real_message($message): array {
        if (!is_object($message)) return [];
        $out = [];
        if (method_exists($message, 'getParts')) {
            foreach ($message->getParts() as $part) {
                if (method_exists($part, 'getFunctionCall')) {
                    $call = $part->getFunctionCall();
                    if ($call !== null) {
                        $out[] = [
                            'name'        => (string) (method_exists($call, 'getName') ? $call->getName() : 'unknown'),
                            'args'        => method_exists($call, 'getArgs') ? (array) $call->getArgs() : [],
                            'result'      => ['ok' => true, 'data' => null],
                            'duration_ms' => 0,
                        ];
                    }
                }
            }
        }
        return $out;
    }

    private static function extract_text($message): string {
        if (is_object($message) && property_exists($message, 'text')) {
            return (string) $message->text;
        }
        if (is_object($message) && method_exists($message, 'getParts')) {
            foreach ($message->getParts() as $part) {
                if (method_exists($part, 'getText') && $part->getText() !== null) {
                    return (string) $part->getText();
                }
            }
        }
        return '';
    }

    private static function extract_usage($message): array {
        if (is_object($message) && property_exists($message, 'usage') && is_array($message->usage)) {
            return $message->usage;
        }
        return ['input_tokens' => 0, 'output_tokens' => 0];
    }

    private static function map_wp_error(\WP_Error $err): array {
        $code = $err->get_error_code();
        if ($code === 'wp_ai_no_provider' || $code === 'ai_no_provider') {
            return ['ok' => false, 'code' => 'ai_not_configured',
                    'message' => $err->get_error_message(), 'wp_error_code' => $code];
        }
        return ['ok' => false, 'code' => 'internal_error',
                'message' => $err->get_error_message(), 'wp_error_code' => $code];
    }

    private static function default_factory(Manifest $manifest): array {
        $supports = function_exists('wp_supports_ai') ? wp_supports_ai() : false;
        $builder  = function_exists('wp_ai_client_prompt') ? wp_ai_client_prompt() : null;
        $resolver_factory = static function (array $abilities): ?object {
            if (!class_exists(\WP_AI_Client_Ability_Function_Resolver::class)) return null;
            return new \WP_AI_Client_Ability_Function_Resolver(...$abilities);
        };
        return [
            'supports_ai'      => $supports,
            'builder'          => $builder,
            'resolver_factory' => $resolver_factory,
        ];
    }

    private static function log_telemetry(string $app_id, float $start, string $outcome, int $tool_count): void {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) return;
        $duration_ms = (int) ((microtime(true) - $start) * 1000);
        error_log(sprintf(
            '[dsgo-ai] app=%s outcome=%s duration_ms=%d tool_calls=%d',
            $app_id, $outcome, $duration_ms, $tool_count,
        ));
    }
}
