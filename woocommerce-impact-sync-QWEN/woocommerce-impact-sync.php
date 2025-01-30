<?php
/**
 * Plugin Name: WooCommerce Impact Sync
 * Description: Imports and synchronizes product catalogs from Impact Radius into WooCommerce.
 * Version: 2.0
 * Author: Your Name
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define constants
define('WIS_PLUGIN_FILE', __FILE__);
define('WIS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WIS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoload classes
spl_autoload_register(function ($class) {
    $namespace = 'WooCommerceImpactSync\\';
    if (strpos($class, $namespace) === 0) {
        $class_name = str_replace($namespace, '', $class);
        $file = WIS_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $class_name) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Initialize the plugin
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        \WooCommerceImpactSync\Plugin::init();
    } else {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>The WooCommerce Impact Sync plugin requires WooCommerce to be installed and active.</p></div>';
        });
    }
});

namespace WooCommerceImpactSync;

class Plugin {
    private static $instance = null;

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Load dependencies
        $this->load_dependencies();

        // Register hooks
        $this->register_hooks();
    }

    private function load_dependencies() {
        require_once WIS_PLUGIN_DIR . 'includes/class-admin.php';
        require_once WIS_PLUGIN_DIR . 'includes/class-api.php';
        require_once WIS_PLUGIN_DIR . 'includes/class-product-handler.php';
        require_once WIS_PLUGIN_DIR . 'includes/class-ajax-handler.php';
        require_once WIS_PLUGIN_DIR . 'includes/class-helper.php';
        require_once WIS_PLUGIN_DIR . 'includes/class-log.php';
    }

    private function register_hooks() {
        Admin::init();
        AjaxHandler::init();
    }
}