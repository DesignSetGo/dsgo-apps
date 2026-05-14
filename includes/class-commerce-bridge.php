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
 * Session continuity: we don't manage a separate token store. WC's Store API
 * calls `wc_load_cart()` on entry, which initializes WC's session handler;
 * that handler issues the `wp_woocommerce_session_*` cookie on the response
 * once the cart has contents. The cookie is scoped to `home_url()`, so the
 * top-window navigation triggered by `checkout.open_hosted_page()` carries
 * it and `/checkout/` resolves the same cart. Logged-in visitors are keyed
 * off user id by WC; guests are keyed off the session cookie. We do not
 * generate or persist a Cart-Token ourselves — its only role is for
 * stateless client-driven storefronts, which isn't this code path.
 *
 * @package DSGo_Apps
 */

declare(strict_types=1);

namespace DSGo_Apps;

defined('ABSPATH') || exit;

final class CommerceBridge {

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
     * Invoke a commerce action and return the wire-format result array.
     *
     * Builds a BridgeResult internally and serializes it via to_array() at
     * the boundary — the emitted shape (`ok` + `data` on success; `ok`,
     * `code`, `message` plus optional `reason` / `wp_error_code` / `status`
     * on failure) is byte-identical to the inline arrays this method built
     * before.
     *
     * @param array<string,mixed> $params
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
        return self::invoke_result($action, $params, $manifest, $visitor_user_id)->to_array();
    }

    /**
     * Internal BridgeResult-returning core of invoke(). Kept separate so the
     * public method stays a thin to_array() boundary.
     *
     * @param array<string,mixed> $params
     */
    private static function invoke_result(string $action, array $params, Manifest $manifest, int $visitor_user_id): BridgeResult {
        $endpoint = self::endpoint_for_action($action);
        if ($endpoint === null) {
            return BridgeResult::error('unknown_method',
                sprintf('commerce action "%s" is not recognized', $action));
        }
        if (!self::endpoint_in_manifest($endpoint, $manifest)) {
            return BridgeResult::error('permission_denied',
                sprintf('commerce endpoint "%s" not in manifest commerce.endpoints', $endpoint),
                ['reason' => 'not_in_endpoints']);
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
            return BridgeResult::error('permission_denied',
                sprintf('site policy blocks commerce action "%s" for app "%s"', $action, $manifest->id),
                ['reason' => 'invoker_policy']);
        }

        // Provider gate — at least one configured provider must be active.
        $provider_active = false;
        foreach ($manifest->commerce_providers as $p) {
            if (self::provider_available($p)) { $provider_active = true; break; }
        }
        if (!$provider_active) {
            return BridgeResult::error('not_implemented',
                'no configured commerce provider is active on this site',
                ['reason' => 'no_provider_active']);
        }

        return match ($action) {
            'products.list'             => self::products_list($params, $manifest, $visitor_user_id),
            'products.get'              => self::products_get($params, $manifest, $visitor_user_id),
            'cart.get'                  => self::cart_get($params, $manifest, $visitor_user_id),
            'cart.add_item'             => self::cart_add_item($params, $manifest, $visitor_user_id),
            'cart.update_item'          => self::cart_update_item($params, $manifest, $visitor_user_id),
            'cart.remove_item'          => self::cart_remove_item($params, $manifest, $visitor_user_id),
            'checkout.open_hosted_page' => self::checkout_open_hosted_page($params, $manifest, $visitor_user_id),
            // endpoint_for_action() already rejects unrecognized actions, so
            // this arm is unreachable in practice — but an explicit default
            // keeps a future products.*/cart.*/checkout.* action that lands
            // before its handler from throwing UnhandledMatchError.
            default                     => BridgeResult::error('unknown_method',
                sprintf('commerce action "%s" is not recognized', $action)),
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

    private static function products_list(array $params, Manifest $manifest, int $visitor_user_id): BridgeResult {
        $query = self::filter_query_params($params, [
            'page', 'per_page', 'search', 'category', 'tag',
            'min_price', 'max_price', 'orderby', 'order', 'on_sale', 'featured',
            // type=variation + parent=<id> lets apps fetch the full priced
            // children of a variable product so the in-app variation picker
            // can show per-combo prices/stock without an N+1 of products.get.
            'type', 'parent', 'include', 'exclude', 'slug', 'sku', 'stock_status',
        ]);
        $resp = self::store_api_request('GET', '/wc/store/v1/products', $query, null, $manifest, $visitor_user_id);
        if (!$resp['ok']) return self::store_api_error_to_result($resp);
        $items = is_array($resp['data']) ? array_map([self::class, 'shape_product'], $resp['data']) : [];
        return BridgeResult::ok([
            'items'       => $items,
            'total'       => isset($resp['headers']['x-wp-total']) ? (int) $resp['headers']['x-wp-total'] : count($items),
            'total_pages' => isset($resp['headers']['x-wp-totalpages']) ? (int) $resp['headers']['x-wp-totalpages'] : 1,
        ]);
    }

    private static function products_get(array $params, Manifest $manifest, int $visitor_user_id): BridgeResult {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return BridgeResult::error('invalid_params', '"id" must be a positive integer');
        }
        $resp = self::store_api_request('GET', '/wc/store/v1/products/' . $id, [], null, $manifest, $visitor_user_id);
        if (!$resp['ok']) return self::store_api_error_to_result($resp);
        return BridgeResult::ok(self::shape_product($resp['data']));
    }

    // -----------------------------------------------------------------------
    // Cart (read + mutate)
    // -----------------------------------------------------------------------

    private static function cart_get(array $params, Manifest $manifest, int $visitor_user_id): BridgeResult {
        $resp = self::store_api_request('GET', '/wc/store/v1/cart', [], null, $manifest, $visitor_user_id);
        if (!$resp['ok']) return self::store_api_error_to_result($resp);
        return BridgeResult::ok(self::shape_cart($resp['data']));
    }

    private static function cart_add_item(array $params, Manifest $manifest, int $visitor_user_id): BridgeResult {
        $id  = isset($params['id']) ? (int) $params['id'] : 0;
        $qty = isset($params['quantity']) ? (int) $params['quantity'] : 1;
        if ($id <= 0) {
            return BridgeResult::error('invalid_params', '"id" must be a positive integer');
        }
        if ($qty <= 0 || $qty > 999) {
            return BridgeResult::error('invalid_params', '"quantity" must be 1-999');
        }
        $body = ['id' => $id, 'quantity' => $qty];
        if (isset($params['variation']) && is_array($params['variation'])) {
            $body['variation'] = array_values(array_filter($params['variation'], 'is_array'));
        }
        $resp = self::store_api_request('POST', '/wc/store/v1/cart/add-item', [], $body, $manifest, $visitor_user_id);
        if (!$resp['ok']) return self::store_api_error_to_result($resp);
        return BridgeResult::ok(self::shape_cart($resp['data']));
    }

    private static function cart_update_item(array $params, Manifest $manifest, int $visitor_user_id): BridgeResult {
        $key = isset($params['key']) && is_string($params['key']) ? $params['key'] : '';
        $qty = isset($params['quantity']) ? (int) $params['quantity'] : 0;
        if ($key === '') {
            return BridgeResult::error('invalid_params', '"key" is required');
        }
        if ($qty < 0 || $qty > 999) {
            return BridgeResult::error('invalid_params', '"quantity" must be 0-999');
        }
        $resp = self::store_api_request('POST', '/wc/store/v1/cart/update-item', [], ['key' => $key, 'quantity' => $qty], $manifest, $visitor_user_id);
        if (!$resp['ok']) return self::store_api_error_to_result($resp);
        return BridgeResult::ok(self::shape_cart($resp['data']));
    }

    private static function cart_remove_item(array $params, Manifest $manifest, int $visitor_user_id): BridgeResult {
        $key = isset($params['key']) && is_string($params['key']) ? $params['key'] : '';
        if ($key === '') {
            return BridgeResult::error('invalid_params', '"key" is required');
        }
        $resp = self::store_api_request('POST', '/wc/store/v1/cart/remove-item', [], ['key' => $key], $manifest, $visitor_user_id);
        if (!$resp['ok']) return self::store_api_error_to_result($resp);
        return BridgeResult::ok(self::shape_cart($resp['data']));
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

    private static function checkout_open_hosted_page(array $params, Manifest $manifest, int $visitor_user_id): BridgeResult {
        if (!self::provider_available('woocommerce')) {
            return BridgeResult::error('not_implemented', 'WooCommerce is not active');
        }
        // wc_get_checkout_url() returns the configured Checkout page URL.
        $url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '';
        if (!is_string($url) || $url === '') {
            return BridgeResult::error('not_implemented', 'WooCommerce Checkout page is not configured');
        }
        $return_to = isset($params['return_to']) && is_string($params['return_to']) ? $params['return_to'] : '';
        if ($return_to !== '') {
            // Only accept absolute paths on the same origin or full URLs whose
            // host matches home_url(). Anything else is dropped.
            $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
            $rt_host   = wp_parse_url($return_to, PHP_URL_HOST);
            $is_same   = $rt_host === null
                ? str_starts_with($return_to, '/')
                : ($rt_host === $home_host);
            if ($is_same) {
                $url = add_query_arg('dsgo_return_to', rawurlencode($return_to), $url);
            }
        }
        return BridgeResult::ok(['url' => $url]);
    }

    // -----------------------------------------------------------------------
    // Store API request — uses rest_do_request() so visitor caps + session
    // are honored without a real HTTP roundtrip. Cart continuity is provided
    // by WC's own session handler: wc_load_cart() (which the Store API
    // invokes on entry) initializes WC's session and sets the
    // wp_woocommerce_session_<hash> cookie on the response when the cart
    // has contents. That cookie carries through the subsequent top-window
    // navigation to /checkout/, so the same cart resolves there.
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
        // Defensive: wc_load_cart() bootstraps WC's session handler so the
        // Store API and the redirected /checkout/ page see the same cart.
        // The Store API invokes it on entry, but calling it explicitly first
        // ensures the session cookie is issued even on read-only paths
        // (products.list/get) — which is what binds the visitor's browser
        // to a cart-store key when they later add an item from a separate
        // request. Guarded so unit tests without WC don't fatal.
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        $req = new \WP_REST_Request($method, $path);
        foreach ($query as $k => $v) {
            $req->set_param($k, $v);
        }
        if ($body !== null) {
            $req->set_body_params($body);
            $req->set_header('Content-Type', 'application/json');
        }
        // WC's Store API requires a `Nonce` header on every cart-mutating
        // write (cart/add-item, cart/update-item, cart/remove-item, etc.)
        // to defeat cross-site CSRF. Inside an internal rest_do_request from
        // our already-authenticated bridge route, no browser-driven CSRF
        // path exists — but WC still rejects the call without the header.
        // Synthesize the nonce against the current user (or visitor=0) and
        // attach it; wp_verify_nonce on the WC side will accept it because
        // it was minted in the same session context. Without this, vanilla
        // guest add-to-cart returns 401 woocommerce_rest_missing_nonce →
        // surface as "sign in to continue" in the app UI.
        if ($method === 'POST' && str_starts_with($path, '/wc/store/v1/cart/')) {
            $req->set_header('Nonce', wp_create_nonce('wc_store_api'));
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
        return ['ok' => true, 'data' => $response->get_data(), 'headers' => $headers];
    }

    /**
     * Translate a `store_api_request()` failure array into a BridgeResult.
     *
     * `store_api_request()` is a pure-internal helper that still returns the
     * legacy assoc-array shape (it carries a `headers` field used only on the
     * success path). Its failure arrays look like
     * `['ok'=>false,'code'=>...,'message'=>...]` plus optional `status` and
     * `wp_error_code` keys; this lifts those into a BridgeResult so the
     * action handlers above can stay BridgeResult-native. The detail keys are
     * forwarded verbatim — including a literal `wp_error_code => null` when
     * present — so the serialized wire format is byte-identical to the old
     * direct `return $resp;`.
     *
     * @param array<string,mixed> $resp
     */
    private static function store_api_error_to_result(array $resp): BridgeResult {
        $details = [];
        if (array_key_exists('status', $resp)) {
            $details['status'] = $resp['status'];
        }
        if (array_key_exists('wp_error_code', $resp)) {
            $details['wp_error_code'] = $resp['wp_error_code'];
        }
        if (array_key_exists('reason', $resp)) {
            $details['reason'] = $resp['reason'];
        }
        return BridgeResult::error(
            (string) ($resp['code'] ?? 'internal_error'),
            (string) ($resp['message'] ?? ''),
            $details,
        );
    }

    // -----------------------------------------------------------------------
    // Response shapers — keep WC payloads small and consistent.
    // -----------------------------------------------------------------------

    /**
     * Field map for the `id|name|slug|link` shape shared by the `categories`
     * and `tags` blocks. Declared once so the two call sites can't drift.
     */
    private const TAXONOMY_REF_FIELDS = [
        'id'   => ['id',   'int'],
        'name' => ['name', 'string'],
        'slug' => ['slug', 'string'],
        'link' => ['link', 'string'],
    ];

    /**
     * Normalize a raw list of stdClass/array rows into a list of associative
     * arrays with a fixed, typed shape. Replaces the six near-identical
     * `foreach ... normalize_to_array ... build assoc array` loops that
     * shape_product() used to inline; the output is byte-identical.
     *
     * Each `$field_map` entry is `output_key => [source_key, type]` where
     * `type` is `'int'`, `'string'`, or `'bool'`. A nested list uses
     * `output_key => ['__list', source_key, $sub_field_map]` — the source
     * value is recursively run through shape_list() with the sub-map.
     *
     * @param mixed                                          $raw
     * @param array<string, array{0:string,1:string}|array{0:'__list',1:string,2:array<string,mixed>}> $field_map
     * @return array<int, array<string, mixed>>
     */
    private static function shape_list($raw, array $field_map): array {
        $out = [];
        foreach ((self::normalize_to_array($raw) ?: []) as $row) {
            $row = self::normalize_to_array($row);
            if (!is_array($row)) continue;
            $shaped = [];
            foreach ($field_map as $out_key => $spec) {
                if ($spec[0] === '__list') {
                    // ['__list', source_key, sub_field_map]
                    $shaped[$out_key] = self::shape_list($row[$spec[1]] ?? [], $spec[2]);
                    continue;
                }
                [$src_key, $type] = $spec;
                $value = $row[$src_key] ?? null;
                $shaped[$out_key] = match ($type) {
                    'int'    => (int) ($value ?? 0),
                    'bool'   => (bool) ($value ?? false),
                    default  => (string) ($value ?? ''),
                };
            }
            $out[] = $shaped;
        }
        return $out;
    }

    /**
     * @param mixed $raw
     */
    private static function shape_product($raw): array {
        // WC's Store API returns nested fields (images, prices, attributes,
        // etc.) as stdClass objects. Normalize to associative arrays so
        // is_array() / array-key access work uniformly across versions.
        $raw = self::normalize_to_array($raw);
        if (!is_array($raw)) return [];
        $images = self::shape_list($raw['images'] ?? [], [
            'id'        => ['id',        'int'],
            'src'       => ['src',       'string'],
            'thumbnail' => ['thumbnail', 'string'],
            'alt'       => ['alt',       'string'],
        ]);
        // Attributes drive the variation picker on the parent product. Each
        // entry includes its terms so apps can render selectors without
        // pulling taxonomy data over a second channel.
        $attributes = self::shape_list($raw['attributes'] ?? [], [
            'id'             => ['id',             'int'],
            'name'           => ['name',           'string'],
            'taxonomy'       => ['taxonomy',       'string'],
            'has_variations' => ['has_variations', 'bool'],
            'terms'          => ['__list', 'terms', [
                'id'   => ['id',   'int'],
                'name' => ['name', 'string'],
                'slug' => ['slug', 'string'],
            ]],
        ]);
        // Variations is a lightweight ref list ({id, attributes:[{name,value}]}).
        // To get per-variation prices/stock, apps call products.list with
        // type=variation&parent=<id> — that's the canonical Store API path.
        $variations = self::shape_list($raw['variations'] ?? [], [
            'id'         => ['id', 'int'],
            'attributes' => ['__list', 'attributes', [
                'name'  => ['name',  'string'],
                'value' => ['value', 'string'],
            ]],
        ]);
        $categories = self::shape_list($raw['categories'] ?? [], self::TAXONOMY_REF_FIELDS);
        $tags       = self::shape_list($raw['tags'] ?? [], self::TAXONOMY_REF_FIELDS);
        $quantity_limits = self::normalize_to_array($raw['quantity_limits'] ?? null);
        $shaped_quantity_limits = is_array($quantity_limits) ? [
            'minimum'      => isset($quantity_limits['minimum']) ? (int) $quantity_limits['minimum'] : 1,
            'maximum'      => isset($quantity_limits['maximum']) ? (int) $quantity_limits['maximum'] : 999,
            'multiple_of'  => isset($quantity_limits['multiple_of']) ? (int) $quantity_limits['multiple_of'] : 1,
            'editable'     => isset($quantity_limits['editable']) ? (bool) $quantity_limits['editable'] : true,
        ] : null;
        return [
            'id'              => (int) ($raw['id'] ?? 0),
            'parent_id'       => (int) ($raw['parent'] ?? 0),
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
            'low_stock_remaining' => isset($raw['low_stock_remaining']) ? (int) $raw['low_stock_remaining'] : null,
            'sold_individually' => (bool) ($raw['sold_individually'] ?? false),
            'images'          => $images,
            'type'            => (string) ($raw['type'] ?? 'simple'),
            'has_options'     => (bool) ($raw['has_options'] ?? false),
            'attributes'      => $attributes,
            'variations'      => $variations,
            'categories'      => $categories,
            'tags'            => $tags,
            'average_rating'  => (string) ($raw['average_rating'] ?? ''),
            'review_count'    => (int) ($raw['review_count'] ?? 0),
            'quantity_limits' => $shaped_quantity_limits,
            'add_to_cart'     => is_array($raw['add_to_cart'] ?? null) ? $raw['add_to_cart'] : (is_object($raw['add_to_cart'] ?? null) ? (array) $raw['add_to_cart'] : null),
        ];
    }

    /**
     * @param mixed $prices
     */
    private static function shape_price($prices): array {
        $prices = self::normalize_to_array($prices);
        if (!is_array($prices)) return ['amount' => '', 'currency' => '', 'min' => null, 'max' => null];
        $price_range = self::normalize_to_array($prices['price_range'] ?? null);
        return [
            'amount'    => (string) ($prices['price'] ?? ''),
            'regular'   => (string) ($prices['regular_price'] ?? ''),
            'sale'      => (string) ($prices['sale_price'] ?? ''),
            'currency'  => (string) ($prices['currency_code'] ?? ''),
            'min'       => is_array($price_range) && isset($price_range['min_amount']) ? (string) $price_range['min_amount'] : null,
            'max'       => is_array($price_range) && isset($price_range['max_amount']) ? (string) $price_range['max_amount'] : null,
            'minor_unit' => isset($prices['currency_minor_unit']) ? (int) $prices['currency_minor_unit'] : 2,
        ];
    }

    /**
     * Cast stdClass (or nested stdClass) to associative arrays. WC Store API
     * responses contain a mix of arrays and objects depending on the field;
     * normalizing once at the bridge boundary keeps the rest of the shape
     * code free of `is_object` / `(array)` ceremony.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_to_array($value) {
        if (is_object($value)) {
            $value = (array) $value;
        }
        return $value;
    }

    /**
     * @param mixed $raw
     */
    private static function shape_cart($raw): array {
        $raw = self::normalize_to_array($raw);
        if (!is_array($raw)) return [];
        $items = [];
        foreach (($raw['items'] ?? []) as $item) {
            $item = self::normalize_to_array($item);
            if (!is_array($item)) continue;
            $first_image = self::normalize_to_array($item['images'][0] ?? null);
            $img = is_array($first_image)
                ? (string) ($first_image['thumbnail'] ?? $first_image['src'] ?? '')
                : '';
            $item_totals = self::normalize_to_array($item['totals'] ?? null);
            $items[] = [
                'key'       => (string) ($item['key'] ?? ''),
                'id'        => (int) ($item['id'] ?? 0),
                'name'      => (string) ($item['name'] ?? ''),
                'quantity'  => (int) ($item['quantity'] ?? 0),
                'permalink' => (string) ($item['permalink'] ?? ''),
                'image'     => $img,
                'totals'    => is_array($item_totals) ? $item_totals : null,
            ];
        }
        $totals = self::normalize_to_array($raw['totals'] ?? null);
        if (!is_array($totals)) $totals = [];
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
