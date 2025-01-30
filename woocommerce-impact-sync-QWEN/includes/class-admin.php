<?php
namespace WooCommerceImpactSync;

class Admin {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    }

    public static function register_admin_menu() {
        add_menu_page(
            'WooCommerce Impact Sync',
            'Impact Sync',
            'manage_options',
            'woocommerce-impact-sync',
            [__CLASS__, 'render_admin_page'],
            'dashicons-update',
            25
        );
    }

    public static function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_woocommerce-impact-sync') {
            return;
        }
        wp_enqueue_style('wis-admin-css', WIS_PLUGIN_URL . 'assets/css/admin.css');
    }

    public static function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>WooCommerce Impact Sync</h1>
            <div id="wis-admin-container">
                <button id="wis-get-merchants" class="button button-primary">Get Merchants</button>
                <div id="wis-merchants-list"></div>
                <div id="wis-progress-container" style="display:none;">
                    <progress id="wis-progress-bar" value="0" max="100"></progress>
                    <p id="wis-status-message"></p>
                </div>
                <button id="wis-stop-import" class="button button-secondary" style="display:none;">Stop Import</button>
            </div>
        </div>
        <?php
    }
}