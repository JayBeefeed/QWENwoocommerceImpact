<?php
namespace WooCommerceImpactSync;

class Api {
    public static function fetch_catalog_page($catalog_id, $page) {
        $account_sid = IMPACT_RADIUS_ACCOUNT_SID;
        $auth_token = IMPACT_RADIUS_AUTH_TOKEN;
        $url = "https://api.impact.com/Mediapartners/$account_sid/Catalogs/$catalog_id/Items?page=$page";
        $headers = [
            'Authorization' => 'Basic ' . base64_encode("$account_sid:$auth_token"),
            'Accept' => 'application/json',
        ];

        $response = wp_remote_get($url, ['headers' => $headers, 'timeout' => 30]);
        if (is_wp_error($response)) {
            Log::write("Failed to retrieve catalog items (page $page): " . $response->get_error_message(), 'error');
            return ['error' => 'Failed to retrieve catalog items.'];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::write("Invalid JSON response from catalog API (page $page): " . json_last_error_msg(), 'error');
            return ['error' => 'Invalid JSON response from API.'];
        }

        return $data;
    }
}