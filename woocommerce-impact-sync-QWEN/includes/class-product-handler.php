<?php
namespace WooCommerceImpactSync;

class ProductHandler {
    public static function process_items_batch($items) {
        global $products_added, $products_updated;
        wp_defer_term_counting(true);
        wp_defer_comment_counting(true);
        wc_set_time_limit(0);

        $processed_parents = [];
        foreach ($items as $item) {
            // Determine if parent or child and process accordingly
            if (isset($item['IsParent']) && ($item['IsParent'] === true || $item['IsParent'] === 'true')) {
                self::update_external_wc_product($item);
                $processed_parents[] = $item['CatalogItemId'];
            } else {
                // Handle child items and create parents if missing
                if (empty($item['ParentSku'])) {
                    self::update_external_wc_product($item);
                } else {
                    if (!in_array($item['ParentSku'], $processed_parents)) {
                        self::create_placeholder_parent($item, []);
                        $processed_parents[] = $item['ParentSku'];
                    }
                    self::update_external_wc_product($item);
                }
            }
        }

        wp_defer_term_counting(false);
        wp_defer_comment_counting(false);
    }

    public static function create_placeholder_parent($item, $parent_skus) {
        global $products_added, $products_updated;
        $parent_sku = $item['ParentSku'];
        $product_id = wc_get_product_id_by_sku($parent_sku . '-P');

        if (!$product_id) {
            $product = new \WC_Product_Variable();
            $products_added++;
        } else {
            $product = wc_get_product($product_id);
            if (!$product) {
                Log::write("Corrupted product data for SKU: " . $parent_sku . '-P', 'error');
                return;
            }
            $products_updated++;
        }

        // Generate parent name
        $generated_name = self::generate_parent_name($item['Name']);
        $product->set_name($generated_name);
        $product->set_sku($parent_sku . '-P');
        $product->set_description($item['Description'] ?? '');

        // Handle attributes
        $attributes = [];
        if (isset($item['Size'])) {
            $size_attr = Helper::get_or_create_attribute('Size');
            if ($size_attr) {
                $attribute = new \WC_Product_Attribute();
                $attribute->set_name($size_attr);
                $attribute->set_options([$item['Size']]);
                $attribute->set_visible(true);
                $attribute->set_variation(true);
                $attributes[] = $attribute;
            }
        }
        if (isset($item['Color'])) {
            $color_attr = Helper::get_or_create_attribute('Color');
            if ($color_attr) {
                $attribute = new \WC_Product_Attribute();
                $attribute->set_name($color_attr);
                $attribute->set_options([$item['Color']]);
                $attribute->set_visible(true);
                $attribute->set_variation(true);
                $attributes[] = $attribute;
            }
        }
        if (isset($item['Manufacturer'])) {
            $brand_attr = Helper::get_or_create_attribute('Brand');
            if ($brand_attr) {
                $attribute = new \WC_Product_Attribute();
                $attribute->set_name($brand_attr);
                $attribute->set_options([$item['Manufacturer']]);
                $attribute->set_visible(true);
                $attribute->set_variation(false);
                $attributes[] = $attribute;
            }
        }
        $product->set_attributes($attributes);

        // Handle images
        if (!$product->get_image_id() && isset($item['ImageUrl'])) {
            $image_id = Helper::download_image($item['ImageUrl'], $product->get_id());
            if ($image_id) {
                $product->set_image_id($image_id);
                Log::write("Set parent product image from variation: " . $item['ImageUrl'], 'info');
            }
        }

        $product->save();
    }

    public static function update_external_wc_product($item) {
        global $products_added, $products_updated;

        // Parent/Child Logic
        $is_api_parent = isset($item['IsParent']) && ($item['IsParent'] === true || $item['IsParent'] === 'true');
        $trueparent_sku = isset($item['ParentSku']) ? $item['ParentSku'] . '-P' : null;
        $parent_id = $trueparent_sku ? wc_get_product_id_by_sku($trueparent_sku) : null;

        // Case 1: This item is a CHILD (or APIParent converted to a variation)
        if ($parent_id || $is_api_parent) {
            // APIParent becomes a variation of TrueParent
            if ($is_api_parent) {
                $trueparent_sku = $item['CatalogItemId'] . '-P';
                $parent_id = wc_get_product_id_by_sku($trueparent_sku);
                $item['ParentSku'] = $trueparent_sku; // Force APIParent to act as a child
            }

            // Handle as variation
            $variation_id = wc_get_product_id_by_sku($item['CatalogItemId']);
            if (!$variation_id) {
                $variation = new \WC_Product_Variation();
                $products_added++;
            } else {
                $variation = wc_get_product($variation_id);
                $products_updated++;
            }

            // Set variation properties
            $variation->set_parent_id($parent_id);
            $variation->set_sku($item['CatalogItemId']);

            // Set attributes (Size/Color)
            $variation_attributes = [];
            if (isset($item['Size'])) {
                $size_attr = Helper::get_or_create_attribute('Size');
                $variation_attributes[$size_attr] = $item['Size'];
            }
            if (isset($item['Color'])) {
                $color_attr = Helper::get_or_create_attribute('Color');
                $variation_attributes[$color_attr] = $item['Color'];
            }
            $variation->set_attributes($variation_attributes);

            // Set external metadata
            $variation->update_meta_data('_wcev_external_url', $item['Url']);
            $variation->update_meta_data('_wcev_external_sku', $item['CatalogItemId']);
            $stock_status = (strtolower($item['StockAvailability'] ?? '') === 'instock') ? 'instock' : 'outofstock';
            $variation->set_stock_status($stock_status);

            // Handle images
            if (isset($item['ImageUrl'])) {
                $image_id = Helper::download_image($item['ImageUrl'], $variation->get_id());
                if ($image_id) {
                    $variation->set_image_id($image_id);
                }
            }

            $variation->save();

        // Case 2: This is a TRUE PARENT (variable product)
        } elseif (isset($item['IsParent']) && $item['IsParent']) {
            // Handled in process_items_batch() during the first pass

        // Case 3: STANDALONE SIMPLE PRODUCT (no parent/child)
        } else {
            $product_id = wc_get_product_id_by_sku($item['CatalogItemId']);
            if (!$product_id) {
                $product = new \WC_Product();
                $products_added++;
            } else {
                $product = wc_get_product($product_id);
                $products_updated++;
            }

            // Basic details
            $product->set_name($item['Name']);
            $product->set_description($item['Description'] ?? '');
            $product->set_sku($item['CatalogItemId']);

            // External product metadata
            $product->update_meta_data('_wcev_external_url', $item['Url']);
            $product->update_meta_data('_wcev_external_status', true);
            $manufacturer = $item['Manufacturer'] ?? '';
            $button_text = $manufacturer ? "Buy now at {$manufacturer}" : "Buy now";
            $product->update_meta_data('_wcev_external_add_to_cart_text', $button_text);

            // Stock status
            $stock_status = (strtolower($item['StockAvailability'] ?? '') === 'instock') ? 'instock' : 'outofstock';
            $product->set_stock_status($stock_status);

            // Attributes
            $attributes = [];
            if (isset($item['Size'])) {
                $size_attr = Helper::get_or_create_attribute('Size');
                $attribute = new \WC_Product_Attribute();
                $attribute->set_name($size_attr);
                $attribute->set_options([$item['Size']]);
                $attribute->set_visible(true);
                $attributes[] = $attribute;
            }
            if (isset($item['Color'])) {
                $color_attr = Helper::get_or_create_attribute('Color');
                $attribute = new \WC_Product_Attribute();
                $attribute->set_name($color_attr);
                $attribute->set_options([$item['Color']]);
                $attribute->set_visible(true);
                $attributes[] = $attribute;
            }
            $product->set_attributes($attributes);

            // Images
            if (isset($item['ImageUrl'])) {
                $image_id = Helper::download_image($item['ImageUrl'], $product->get_id());
                if ($image_id) {
                    $product->set_image_id($image_id);
                }
            }
            if (isset($item['AdditionalImageUrls']) && is_array($item['AdditionalImageUrls'])) {
                $gallery = [];
                foreach ($item['AdditionalImageUrls'] as $url) {
                    $image_id = Helper::download_image($url, $product->get_id());
                    if ($image_id) {
                        $gallery[] = $image_id;
                    }
                }
                $product->set_gallery_image_ids($gallery);
            }

            $product->save();
        }
    }

    public static function generate_parent_name($variation_name) {
        $words_to_remove = ['Twin', 'Full', 'Queen', 'King', 'Cal King', 'Twin XL', 'Standard', 'Plush', 'Medium', 'Firm', 'High Loft', 'Low Loft', 'Medium-Firm'];
        $pattern = '/\b(' . implode('|', array_map('preg_quote', $words_to_remove)) . ')\b/iu';
        $generated_name = trim(preg_replace($pattern, '', $variation_name));
        $generated_name = preg_replace('/\s+/', ' ', $generated_name);
        if (empty($generated_name)) {
                public static function generate_parent_name($variation_name) {
        $words_to_remove = ['Twin', 'Full', 'Queen', 'King', 'Cal King', 'Twin XL', 'Standard', 'Plush', 'Medium', 'Firm', 'High Loft', 'Low Loft', 'Medium-Firm'];
        $pattern = '/\b(' . implode('|', array_map('preg_quote', $words_to_remove)) . ')\b/iu';
        $generated_name = trim(preg_replace($pattern, '', $variation_name));
        $generated_name = preg_replace('/\s+/', ' ', $generated_name);
        if (empty($generated_name)) {
            return $variation_name;
        }
        return $generated_name;
    }
}