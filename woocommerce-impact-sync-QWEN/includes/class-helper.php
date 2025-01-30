<?php
namespace WooCommerceImpactSync;

class Helper {
    /**
     * Sanitize an image URL by removing query strings.
     *
     * @param string $url The image URL to sanitize.
     * @return string The sanitized URL.
     */
    public static function sanitize_image_url($url) {
        return preg_replace('/\?.*/', '', $url);
    }

    /**
     * Download an image and return its attachment ID.
     *
     * @param string $url The image URL to download.
     * @param int $post_id Optional. The post ID to attach the image to.
     * @return int The attachment ID of the downloaded image, or 0 on failure.
     */
    public static function download_image($url, $post_id = 0) {
        $url = self::sanitize_image_url($url);
        if (empty($url)) {
            return 0;
        }

        // Check if the image already exists in the media library
        $existing_id = attachment_url_to_postid($url);
        if ($existing_id) {
            Log::write("Image already exists in media library {$url}, id: " . $existing_id, 'info');
            return $existing_id;
        }

        // Download the image
        $media = media_sideload_image($url, $post_id, '', 'id');
        if (is_wp_error($media)) {
            Log::write("Failed to download image {$url}: " . $media->get_error_message(), 'error');
            return 0;
        }

        Log::write("Downloaded image {$url}, id: " . $media, 'info');
        return $media;
    }

    /**
     * Get or create a WooCommerce attribute.
     *
     * @param string $attribute_name The name of the attribute to get or create.
     * @return string|false The taxonomy name of the attribute, or false on failure.
     */
    public static function get_or_create_attribute($attribute_name) {
        static $attribute_cache = [];
        if (isset($attribute_cache[$attribute_name])) {
            return $attribute_cache[$attribute_name];
        }

        $taxonomy = 'pa_' . sanitize_title($attribute_name);
        if (!taxonomy_exists($taxonomy)) {
            $attribute_id = wc_create_attribute([
                'name' => $attribute_name,
                'slug' => sanitize_title($attribute_name),
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false,
            ]);

            if (is_wp_error($attribute_id)) {
                Log::write("Failed to create attribute {$attribute_name}: " . $attribute_id->get_error_message(), 'error');
                return false;
            }

            // Register the taxonomy
            register_taxonomy(
                $taxonomy,
                apply_filters('woocommerce_taxonomy_objects_' . $taxonomy, ['product']),
                apply_filters('woocommerce_taxonomy_args_' . $taxonomy, [
                    'labels' => [
                        'name' => $attribute_name,
                    ],
                    'hierarchical' => true,
                    'show_ui' => false,
                    'query_var' => true,
                    'rewrite' => false,
                ])
            );

            Log::write("Created attribute {$attribute_name} with taxonomy {$taxonomy}", 'info');
        }

        $attribute_cache[$attribute_name] = $taxonomy;
        return $taxonomy;
    }

    /**
     * Generate a parent product name by removing specific keywords.
     *
     * @param string $variation_name The variation name to process.
     * @return string The generated parent name.
     */
    public static function generate_parent_name($variation_name) {
        $words_to_remove = [
            'Twin', 'Full', 'Queen', 'King', 'Cal King', 'Twin XL', 'Standard',
            'Plush', 'Medium', 'Firm', 'High Loft', 'Low Loft', 'Medium-Firm',
        ];
        $pattern = '/\b(' . implode('|', array_map('preg_quote', $words_to_remove)) . ')\b/iu';
        $generated_name = trim(preg_replace($pattern, '', $variation_name));
        $generated_name = preg_replace('/\s+/', ' ', $generated_name);

        if (empty($generated_name)) {
            return $variation_name;
        }

        return $generated_name;
    }
}