<?php
/**
 * Bridge layer for commerce providers (WooCommerce in v1).
 *
 * Apps declare which commerce surface they may touch via `commerce.endpoints`
 * (products | cart | checkout) in the manifest. This class enforces those
 * patterns, applies the `dsgo_apps_can_invoke_commerce` filter, and proxies to
 * the WooCommerce Store API (`/wp-json/wc/store/v1/*`) under the visitor's
 * auth context.
 *
 * Cart-Token persistence: the Store API issues a Cart-Token on first cart
 * touch; we stash it in a per-(app, visitor) transient so the cart survives
 * across requests for guest visitors. Logged-in visitors don't need the token
 * (cart is keyed off user id) but we forward it when present anyway.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

final class CommerceBridge {

    private const TRANSIENT_PREFIX = 'dsgo_apps_cart_token_';
    /** Cart token TTL — Store API tokens default to 48h. Match that. */
    private const TRANSIENT_TTL    = 48 * HOUR_IN_SECONDS;

    /**
     * Whether the configured provider is available on this site.
     */
    public static function provider_available(string $provider): bool {
        return match ($provider) {
            'woocommerce' => class_exists('WooCommerce') || function_exists('WC'),
            default       => false,
        };
    }

    public static function endpoint_in_manifest(string $endpoint, Manifest $manifest): bool {
        return in_array($endpoint, $manifest->commerce_endpoints, true);
    }

    /**
     * @param array{action:string, params:array<string,mixed>} $request
     * @return array{
     *   ok:bool,
     *   data?:mixed,
     *   code?:string,
     *   reason?:string,
     *   message?:string,
     *   wp_error_code?:string,
     *   status?:int,
     * }
     */
    public static function invoke(string $action, array $params, Manifest $manifest, int $visitor_user_id): array {
        $endpoint = self::endpoint_for_action($action);
        if ($endpoint === null) {
            return [
                'ok'      => false,
                'code'    => 'unknown_method',
                'message' => sprintf('commerce action "%s" is not recognized', $action),
            ];
        }
        if (!self::endpoint_in_manifest($endpoint, $manifest)) {
            return [
                'ok'      => false,
                'code'    => 'permission_denied',
                'reason'  => 'not_in_endpoints',
                'message' => sprintf('commerce endpoint "%s" not in manifest commerce.endpoints', $endpoint),
            ];
        }
        // Site policy hook — same shape as dsgo_apps_can_invoke_ability.
        $allowed = apply_filters(
            'dsgo_apps_can_invoke_commerce',
            true,
            $action,
            $params,
            $manifest->id,
            $visitor_user_id,
        );
        if (!$allowed) {
            return [
                'ok'      => false,
                'code'    => 'permission_denied',
                'reason'  => 'invoker_policy',
                'message' => sprintf('site policy blocks commerce action "%s" for app "%s"', $action, $manifest->id),
            ];
        }

        // Provider gate — at least one configured provider must be active.
        $provider_active = false;
        foreach ($manifest->commerce_providers as $p) {
            if (self::provider_available($p)) { $provider_active = true; break; }
        }
        if (!$provider_active) {
            return [
                'ok'      => false,
                'code'    => 'not_implemented',
                'reason'  => 'no_provider_active',
                'message' => 'no configured commerce provider is active on this site',
            ];
        }

        return match ($action) {
            'products.list'             => self::products_list($params, $manifest, $visitor_user_id),
            'products.get'              => self::products_get($params, $manifest, $visitor_user_id),
            'cart.get'                  => self::cart_get($params, $manifest, $visitor_user_id),
            'cart.add_item'             => self::cart_add_item($params, $manifest, $visitor_user_id),
            'cart.update_item'          => self::cart_update_item($params, $manifest, $visitor_user_id),
            'cart.remove_item'          => self::cart_remove_item($params, $manifest, $visitor_user_id),
            'checkout.open_hosted_page' => self::checkout_open_hosted_page($params, $manifest, $visitor_user_id),
        };
    }

    /**
     * Map an action name to the manifest endpoint it requires.
     */
    public static function endpoint_for_action(string $action): ?string {
        if (str_starts_with($action, 'products.'))  return 'products';
        if (str_starts_with($action, 'cart.'))      return 'cart';
        if (str_starts_with($action, 'checkout.'))  return 'checkout';
        return null;
    }

    // -----------------------------------------------------------------------
    // Products
    // -----------------------------------------------------------------------

    private static function products_list(array $params, Manifest $manifest, int $visitor_user_id): array {
        $query = self::filter_query_params($params, [
            'page', 'per_page', 'search', 'category', 'tag',
            'min_price', 'max_price', 'orderby', 'order', 'on_sale', 'featured',
        ]);
        $resp = self::store_api_request('GET', '/wc/store/v1/products', $query, null, $manifest, $visitor_user_id);
        if (!$resp['ok']) return $resp;
        $items = is_array($resp['data']) ? array_map([self::class, 'shape_product'], $resp['data']) : [];
        return [
            'ok'   => true,
            'data' => [
                'items'       => $items,
                'total'       => isset($resp['headers']['x-wp-total']) ? (int) $resp['headers']['x-wp-total'] : count($items),
                'total_pages' => isset($resp['headers']['x-wp-totalpages']) ? (int) $resp['headers']['x-wp-totalpages'] : 1,
            ],
        ];
    }

    private static function products_get(array $params, Manifest $manifest, int $visitor_user_id): array {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return ['ok' => false, 'code' => 'invalid_params', 'message' => '"id" must be a positive integer'];
        }
        $resp = self::store_api_request('GET', '/wc/store/v1/products/' . $id, [], null, $manifest, $visitor_user_id);
        if (!$resp['ok']) return $resp;
        return ['ok' => true, 'data' => self::shape_product($resp['data'])];
    }

    // -----------------------------------------------------------------------
    // Cart (read + mutate)
    // -----------------------------------------------------------------------

    private static function cart_get(array $params, Manifest $manifest, int $visitor_user_id): array {
        $resp = self::store_api_request('GET', '/wc/store/v1/cart', [], null, $manifest, $visitor_user_id);
        if (!$resp['ok']) return $resp;
        return ['ok' => true, 'data' => self::shape_cart($resp['data'])];
    }

    private static function cart_add_item(array $params, Manifest $manifest, int $visitor_user_id): array {
        $id  = isset($params['id']) ? (int) $params['id'] : 0;
        $qty = isset($params['quantity']) ? (int) $params['quantity'] : 1;
        if ($id <= 0) {
            return ['ok' => false, 'code' => 'invalid_params', 'message' => '"id" must be a positive integer'];
        }
        if ($qty <= 0 || $qty > 999) {
            return ['ok' => false, 'code' => 'invalid_params', 'message' => '"quantity" must be 1-999'];
        }
        $body = ['id' => $id, 'quantity' => $qty];
        if (isset($params['variation']) && is_array($params['variation'])) {
            $body['variation'] = array_values(array_filter($params['variation'], 'is_array'));
        }
        $resp = self::store_api_request('POST', '/wc/store/v1/cart/add-item', [], $body, $manifest, $visitor_user_id);
        if (!$resp['ok']) return $resp;
        return ['ok' => true, 'data' => self::shape_cart($resp['data'])];
    }

    private static function cart_update_item(array $params, Manifest $manifest, int $visitor_user_id): array {
        $key = isset($params['key']) && is_string($params['key']) ? $params['key'] : '';
        $qty = isset($params['quantity']) ? (int) $params['quantity'] : 0;
        if ($key === '') {
            return ['ok' => false, 'code' => 'invalid_params', 'message' => '"key" is required'];
        }
        if ($qty < 0 || $qty > 999) {
            return ['ok' => false, 'code' => 'invalid_params', 'message' => '"quantity" must be 0-999'];
        }
        $resp = self::store_api_request('POST', '/wc/store/v1/cart/update-item', [], ['key' => $key, 'quantity' => $qty], $manifest, $visitor_user_id);
        if (!$resp['ok']) return $resp;
        return ['ok' => true, 'data' => self::shape_cart($resp['data'])];
    }

    private static function cart_remove_item(array $params, Manifest $manifest, int $visitor_user_id): array {
        $key = isset($params['key']) && is_string($params['key']) ? $params['key'] : '';
        if ($key === '') {
            return ['ok' => false, 'code' => 'invalid_params', 'message' => '"key" is required'];
        }
        $resp = self::store_api_request('POST', '/wc/store/v1/cart/remove-item', [], ['key' => $key], $manifest, $visitor_user_id);
        if (!$resp['ok']) return $resp;
        return ['ok' => true, 'data' => self::shape_cart($resp['data'])];
    }

    // -----------------------------------------------------------------------
    // Checkout — v1 only supports hosted-page handoff.
    //
    // The bridge can't safely render the WC Blocks checkout inside a
    // sandboxed iframe (it needs cookies, nonces, and full DOM access). v1
    // returns the canonical /checkout/ URL with the cart token still bound
    // to the visitor's session; the client uses it to navigate the top
    // window. WC Blocks checkout then loads with the visitor's cart intact.
    // -----------------------------------------------------------------------

    private static function checkout_open_hosted_page(array $params, Manifest $manifest, int $visitor_user_id): array {
        if (!self::provider_available('woocommerce')) {
            return ['ok' => false, 'code' => 'not_implemented', 'message' => 'WooCommerce is not active'];
        }
        // wc_get_checkout_url() returns the configured Checkout page URL.
        $url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '';
        if (!is_string($url) || $url === '') {
            return ['ok' => false, 'code' => 'not_implemented', 'message' => 'WooCommerce Checkout page is not configured'];
        }
        $return_to = isset($params['return_to']) && is_string($params['return_to']) ? $params['return_to'] : '';
        if ($return_to !== '') {
            // Only accept absolute paths on the same origin or full URLs whose
            // host matches home_url(). Anything else is dropped.
            $home_host = parse_url(home_url(), PHP_URL_HOST);
            $rt_host   = parse_url($return_to, PHP_URL_HOST);
            $is_same   = $rt_host === null
                ? str_starts_with($return_to, '/')
                : ($rt_host === $home_host);
            if ($is_same) {
                $url = add_query_arg('dsgo_return_to', rawurlencode($return_to), $url);
            }
        }
        return [
            'ok'   => true,
            'data' => ['url' => $url],
        ];
    }

    // -----------------------------------------------------------------------
    // Cart-Token persistence
    // -----------------------------------------------------------------------

    private static function cart_token_key(string $app_id, int $visitor_user_id): string {
        if ($visitor_user_id > 0) {
            return self::TRANSIENT_PREFIX . $app_id . '_u' . $visitor_user_id;
        }
        // Guest visitor — bind to the WP session cookie so two concurrent
        // anonymous browsers each get their own cart. Falls back to a
        // per-app shared key on environments without a session cookie
        // (e.g. PHPUnit), which is fine because tests run isolated.
        $session = $_COOKIE['wp_woocommerce_session_' . COOKIEHASH] ?? '';
        if (!is_string($session) || $session === '') {
            $session = $_COOKIE['PHPSESSID'] ?? '';
        }
        $hash = is_string($session) && $session !== '' ? substr(md5($session), 0, 16) : 'anon';
        return self::TRANSIENT_PREFIX . $app_id . '_g_' . $hash;
    }

    private static function load_cart_token(string $app_id, int $visitor_user_id): string {
        $val = get_transient(self::cart_token_key($app_id, $visitor_user_id));
        return is_string($val) ? $val : '';
    }

    private static function save_cart_token(string $app_id, int $visitor_user_id, string $token): void {
        if ($token === '') return;
        set_transient(self::cart_token_key($app_id, $visitor_user_id), $token, self::TRANSIENT_TTL);
    }

    // -----------------------------------------------------------------------
    // Store API request — uses rest_do_request() so visitor caps + session
    // are honored without a real HTTP roundtrip. Forwards Cart-Token from
    // our session store; captures it back from the response for the next
    // call.
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed>      $query
     * @param array<string,mixed>|null $body
     * @return array{ok:bool, data?:mixed, headers?:array<string,string>, code?:string, message?:string, status?:int, wp_error_code?:string}
     */
    private static function store_api_request(string $method, string $path, array $query, ?array $body, Manifest $manifest, int $visitor_user_id): array {
        if (!function_exists('rest_do_request')) {
            return ['ok' => false, 'code' => 'not_implemented', 'message' => 'WordPress REST API unavailable'];
        }
        $req = new \WP_REST_Request($method, $path);
        foreach ($query as $k => $v) {
            $req->set_param($k, $v);
        }
        if ($body !== null) {
            $req->set_body_params($body);
            $req->set_header('Content-Type', 'application/json');
        }
        $token = self::load_cart_token($manifest->id, $visitor_user_id);
        if ($token !== '') {
            $req->set_header('Cart-Token', $token);
        }
        $response = rest_do_request($req);
        if ($response->is_error()) {
            $err = $response->as_error();
            $code = $err->get_error_code();
            $status = (int) ($response->get_status() ?: 500);
            $bridge_code = match (true) {
                $status === 401 => 'not_authenticated',
                $status === 403 => 'permission_denied',
                $status === 404 => 'not_found',
                $status === 429 => 'rate_limited',
                $status === 422,
                $status === 400 => 'invalid_params',
                default         => 'internal_error',
            };
            return [
                'ok'           => false,
                'code'         => $bridge_code,
                'status'       => $status,
                'message'      => $err->get_error_message(),
                'wp_error_code' => is_string($code) ? $code : null,
            ];
        }
        $headers_raw = $response->get_headers();
        $headers = [];
        if (is_array($headers_raw)) {
            foreach ($headers_raw as $hk => $hv) {
                $headers[strtolower((string) $hk)] = is_array($hv) ? (string) reset($hv) : (string) $hv;
            }
        }
        // Capture refreshed Cart-Token for the next request.
        if (!empty($headers['cart-token'])) {
            self::save_cart_token($manifest->id, $visitor_user_id, $headers['cart-token']);
        }
        return ['ok' => true, 'data' => $response->get_data(), 'headers' => $headers];
    }

    // -----------------------------------------------------------------------
    // Response shapers — keep WC payloads small and consistent.
    // -----------------------------------------------------------------------

    /**
     * @param mixed $raw
     */
    private static function shape_product($raw): array {
        if (!is_array($raw)) return [];
        $images = [];
        foreach (($raw['images'] ?? []) as $img) {
            if (!is_array($img)) continue;
            $images[] = [
                'id'  => (int) ($img['id'] ?? 0),
                'src' => (string) ($img['src'] ?? ''),
                'alt' => (string) ($img['alt'] ?? ''),
            ];
        }
        return [
            'id'              => (int) ($raw['id'] ?? 0),
            'name'            => (string) ($raw['name'] ?? ''),
            'slug'            => (string) ($raw['slug'] ?? ''),
            'permalink'       => (string) ($raw['permalink'] ?? ''),
            'description'     => (string) ($raw['description'] ?? ''),
            'short_description' => (string) ($raw['short_description'] ?? ''),
            'sku'             => (string) ($raw['sku'] ?? ''),
            'price'           => self::shape_price($raw['prices'] ?? null),
            'on_sale'         => (bool) ($raw['on_sale'] ?? false),
            'is_in_stock'     => (bool) ($raw['is_in_stock'] ?? true),
            'is_purchasable'  => (bool) ($raw['is_purchasable'] ?? true),
            'images'          => $images,
            'type'            => (string) ($raw['type'] ?? 'simple'),
            'has_options'     => (bool) ($raw['has_options'] ?? false),
            'add_to_cart'     => is_array($raw['add_to_cart'] ?? null) ? $raw['add_to_cart'] : null,
        ];
    }

    /**
     * @param mixed $prices
     */
    private static function shape_price($prices): array {
        if (!is_array($prices)) return ['amount' => '', 'currency' => '', 'min' => null, 'max' => null];
        return [
            'amount'    => (string) ($prices['price'] ?? ''),
            'regular'   => (string) ($prices['regular_price'] ?? ''),
            'sale'      => (string) ($prices['sale_price'] ?? ''),
            'currency'  => (string) ($prices['currency_code'] ?? ''),
            'min'       => isset($prices['price_range']['min_amount']) ? (string) $prices['price_range']['min_amount'] : null,
            'max'       => isset($prices['price_range']['max_amount']) ? (string) $prices['price_range']['max_amount'] : null,
            'minor_unit' => isset($prices['currency_minor_unit']) ? (int) $prices['currency_minor_unit'] : 2,
        ];
    }

    /**
     * @param mixed $raw
     */
    private static function shape_cart($raw): array {
        if (!is_array($raw)) return [];
        $items = [];
        foreach (($raw['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $img = is_array($item['images'] ?? null) && isset($item['images'][0]) && is_array($item['images'][0])
                ? (string) ($item['images'][0]['thumbnail'] ?? $item['images'][0]['src'] ?? '')
                : '';
            $items[] = [
                'key'       => (string) ($item['key'] ?? ''),
                'id'        => (int) ($item['id'] ?? 0),
                'name'      => (string) ($item['name'] ?? ''),
                'quantity'  => (int) ($item['quantity'] ?? 0),
                'permalink' => (string) ($item['permalink'] ?? ''),
                'image'     => $img,
                'totals'    => is_array($item['totals'] ?? null) ? $item['totals'] : null,
            ];
        }
        $totals = is_array($raw['totals'] ?? null) ? $raw['totals'] : [];
        return [
            'items'       => $items,
            'items_count' => (int) ($raw['items_count'] ?? 0),
            'items_weight' => (float) ($raw['items_weight'] ?? 0.0),
            'totals'      => [
                'total_items'    => (string) ($totals['total_items'] ?? ''),
                'total_price'    => (string) ($totals['total_price'] ?? ''),
                'currency_code'  => (string) ($totals['currency_code'] ?? ''),
                'currency_minor_unit' => isset($totals['currency_minor_unit']) ? (int) $totals['currency_minor_unit'] : 2,
            ],
            'needs_shipping' => (bool) ($raw['needs_shipping'] ?? false),
            'needs_payment'  => (bool) ($raw['needs_payment'] ?? false),
        ];
    }

    /**
     * Subset $params to a known-good list of keys.
     *
     * @param array<string,mixed> $params
     * @param string[] $allowed
     * @return array<string,mixed>
     */
    private static function filter_query_params(array $params, array $allowed): array {
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $params)) {
                $out[$key] = $params[$key];
            }
        }
        return $out;
    }
}
