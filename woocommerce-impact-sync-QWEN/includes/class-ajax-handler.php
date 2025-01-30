<?php
namespace WooCommerceImpactSync;

class AjaxHandler {
    public static function init() {
        add_action('wp_ajax_wis_get_merchants', [__CLASS__, 'get_merchants_handler']);
        add_action('wp_ajax_wis_process_batch', [__CLASS__, 'process_batch_handler']);
        add_action('wp_ajax_wis_remove_products', [__CLASS__, 'remove_products_handler']);
        add_action('wp_ajax_wis_stop_import', [__CLASS__, 'stop_import_handler']);
    }

    public static function get_merchants_handler() {
        check_ajax_referer('wis_ajax_nonce', 'security');

        $account_sid = IMPACT_RADIUS_ACCOUNT_SID;
        $auth_token = IMPACT_RADIUS_AUTH_TOKEN;
        $url = "https://api.impact.com/Mediapartners/$account_sid/Catalogs";
        $headers = [
            'Authorization' => 'Basic ' . base64_encode("$account_sid:$auth_token"),
            'Accept' => 'application/json',
        ];

        $response = wp_remote_get($url, ['headers' => $headers, 'timeout' => 60]);
        if (is_wp_error($response)) {
            Log::write('Failed to retrieve catalogs: ' . $response->get_error_message(), 'error');
            wp_send_json_error(['message' => 'Failed to retrieve catalogs.']);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::write('Invalid JSON response from catalogs API: ' . json_last_error_msg(), 'error');
            wp_send_json_error(['message' => 'Invalid response from API.']);
        }

        $catalogs = $body['Catalogs'] ?? [];
        if (empty($catalogs)) {
            Log::write('No catalogs found.', 'warning');
            wp_send_json_error(['message' => 'No catalogs found.']);
        }

        $html = '<ul>';
        foreach ($catalogs as $catalog) {
            $html .= '<li><button class="wis-select-catalog" data-catalog-id="' . esc_attr($catalog['Id']) . '">' . esc_html($catalog['Name']) . '</button></li>';
        }
        $html .= '</ul>';

        wp_send_json_success(['html' => $html]);
    }

    public static function process_batch_handler() {
        check_ajax_referer('wis_ajax_nonce', 'security');

        $catalog_id = get_transient('wis_current_catalog');
        if (!$catalog_id && !empty($_POST['catalog_id'])) {
            $catalog_id = sanitize_text_field($_POST['catalog_id']);
            set_transient('wis_current_catalog', $catalog_id, 6 * HOUR_IN_SECONDS);
        }

        if (!$catalog_id) {
            wp_send_json_error(['message' => 'Catalog session expired. Please reselect the merchant and try again.']);
        }

        $status = get_option('wis_import_status', [
            'current_page' => 1,
            'processed' => 0,
            'total_items' => 1,
        ]);

        $data = Api::fetch_catalog_page($catalog_id, $status['current_page']);
        if (isset($data['error'])) {
            delete_option('wis_import_status');
            delete_transient('wis_current_catalog');
            wp_send_json_error(['message' => 'API error: ' . $data['error']]);
        }

        $items = $data['Items'] ?? [];
        if (!empty($items)) {
            ProductHandler::process_items_batch($items);

            $status['current_page']++;
            $status['processed'] += count($items);
            update_option('wis_import_status', $status);

            wp_send_json_success([
                'progress' => ($status['processed'] / $status['total_items']) * 100,
                'current' => $status['processed'],
                'total' => $status['total_items'],
                'message' => "Processed {$status['processed']} of {$status['total_items']} items",
            ]);
        } else {
            delete_option('wis_import_status');
            delete_transient('wis_current_catalog');

            $api_skus = get_transient('wis_api_skus');
            $manufacturer = get_transient('wis_current_manufacturer');
            self::start_product_removal($manufacturer, $api_skus);

            wp_send_json_success(['complete' => true, 'message' => 'Import completed, starting cleanup!']);
        }
    }

    public static function remove_products_handler() {
        check_ajax_referer('wis_ajax_nonce', 'security');

        $params = get_transient('wis_remove_params');
        if (!$params) {
            wp_send_json_error(['complete' => true, 'message' => 'No removal parameters found']);
        }

        $args = [
            'post_type' => 'product',
            'posts_per_page' => 100,
            'offset' => $params['offset'],
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => 'pa_brand',
                    'field' => 'name',
                    'terms' => $params['manufacturer'],
                ],
            ],
        ];

        $query = new \WP_Query($args);
        if ($query->have_posts()) {
            global $products_removed;
            foreach ($query->posts as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product) continue;

                $sku = $product->get_sku();
                if (!in_array($sku, $params['api_skus']) && substr($sku, -2) !== '-P') {
                    wp_delete_post($product_id, true);
                    $products_removed++;
                    Log::write("Removed product with SKU: " . $sku, 'info');
                }
            }

            $params['offset'] += 100;
            set_transient('wis_remove_params', $params, HOUR_IN_SECONDS);

            $progress = $params['total_products'] > 0
                ? ($params['offset'] / $params['total_products']) * 100
                : 0;

            wp_send_json_success([
                'progress' => $progress,
                'current' => $params['offset'],
                'total' => $params['total_products'],
                'message' => "Removed {$params['offset']} products...",
            ]);
        } else {
            delete_transient('wis_remove_params');
            wp_send_json_success(['complete' => true, 'message' => 'Product cleanup completed!']);
        }
    }

    public static function stop_import_handler() {
        check_ajax_referer('wis_ajax_nonce', 'security');
        set_transient('wis_import_stopped', true, 300); // 5-minute expiration
        wp_send_json_success();
    }

    private static function start_product_removal($manufacturer, $api_skus) {
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'tax_query' => [
                [
                    'taxonomy' => 'pa_brand',
                    'field' => 'name',
                    'terms' => $manufacturer,
                ],
            ],
            'fields' => 'ids',
        ];

        $query = new \WP_Query($args);
        $total_products = count($query->posts);

        set_transient('wis_remove_params', [
            'manufacturer' => $manufacturer,
            'api_skus' => $api_skus,
            'offset' => 0,
            'total_products' => $total_products,
        ], HOUR_IN_SECONDS);

        wp_send_json_success([
            'stage' => 'removal',
            'message' => 'Starting product cleanup...',
            'total_products' => $total_products,
        ]);
    }
}