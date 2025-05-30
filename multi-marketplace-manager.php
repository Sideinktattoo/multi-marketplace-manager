<?php
/**
 * Plugin Name: Multi Marketplace Manager
 * Plugin URI: https://example.com/multi-marketplace-manager
 * Description: WooCommerce için çoklu tedarikçi ve pazar yeri entegrasyonu + kar/zarar hesaplama
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPLv2 or later
 * Text Domain: multi-marketplace-manager
 * Domain Path: /languages
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('MMP_VERSION', '1.0.0');
define('MMP_PLUGIN_FILE', __FILE__);
define('MMP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('MMP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MMP_CRON_INTERVAL', 'six_hours');

// Autoloader
spl_autoload_register(function ($class_name) {
    if (false !== strpos($class_name, 'MMP_')) {
        $classes_dir = MMP_PLUGIN_PATH . 'includes/';
        $class_file = 'class-' . strtolower(str_replace(['MMP_', '_'], ['', '-'], $class_name)) . '.php';
        require_once $classes_dir . $class_file;
    }
});

// Plugin main class
if (!class_exists('Multi_Marketplace_Manager')) {
    final class Multi_Marketplace_Manager {
        private static $instance = null;
        
        public static function instance() {
            if (is_null(self::$instance)) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        public function __construct() {
            $this->init_hooks();
            $this->includes();
            $this->init_components();
        }
        
        private function init_hooks() {
            register_activation_hook(MMP_PLUGIN_FILE, [$this, 'activate']);
            register_deactivation_hook(MMP_PLUGIN_FILE, [$this, 'deactivate']);
            
            add_action('plugins_loaded', [$this, 'init_plugin']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
            add_filter('cron_schedules', [$this, 'add_cron_schedule']);
        }
        
        public function activate() {
            if (!wp_next_scheduled('mmp_hourly_sync_event')) {
                wp_schedule_event(time(), MMP_CRON_INTERVAL, 'mmp_hourly_sync_event');
            }
            
            // Create necessary database tables
            $this->create_tables();
            
            // Set default options
            $this->set_default_options();
        }
        
        public function deactivate() {
            wp_clear_scheduled_hook('mmp_hourly_sync_event');
        }
        
        public function add_cron_schedule($schedules) {
            $schedules['six_hours'] = [
                'interval' => 6 * HOUR_IN_SECONDS,
                'display' => __('Every 6 Hours', 'multi-marketplace-manager')
            ];
            return $schedules;
        }
        
        private function create_tables() {
            global $wpdb;
            
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mmp_suppliers (
                supplier_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                supplier_name VARCHAR(255) NOT NULL,
                xml_url VARCHAR(255) NOT NULL,
                default_markup DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                auto_sync TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (supplier_id)
            ) $charset_collate;";
            
            $sql .= "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mmp_marketplaces (
                marketplace_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                marketplace_name VARCHAR(255) NOT NULL,
                api_key VARCHAR(255) DEFAULT NULL,
                api_secret VARCHAR(255) DEFAULT NULL,
                supplier_id VARCHAR(255) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                last_sync DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (marketplace_id)
            ) $charset_collate;";
            
            $sql .= "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mmp_product_costs (
                cost_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id BIGINT(20) UNSIGNED NOT NULL,
                supplier_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                packaging_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                other_costs DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                markup_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                calculated_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (cost_id),
                KEY product_id (product_id)
            ) $charset_collate;";
            
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
        
        private function set_default_options() {
            if (!get_option('mmp_general_settings')) {
                update_option('mmp_general_settings', [
                    'default_markup' => 30,
                    'auto_sync' => 1,
                    'sync_interval' => 'six_hours'
                ]);
            }
        }
        
        public function init_plugin() {
            if (!function_exists('WC')) {
                add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
                return;
            }
            
            load_plugin_textdomain('multi-marketplace-manager', false, dirname(plugin_basename(MMP_PLUGIN_FILE)) . '/languages');
        }
        
        public function includes() {
            require_once MMP_PLUGIN_PATH . 'includes/class-supplier-manager.php';
            require_once MMP_PLUGIN_PATH . 'includes/class-marketplace-integration.php';
            require_once MMP_PLUGIN_PATH . 'includes/class-price-calculator.php';
            require_once MMP_PLUGIN_PATH . 'includes/class-order-manager.php';
            require_once MMP_PLUGIN_PATH . 'includes/class-settings.php';
            require_once MMP_PLUGIN_PATH . 'includes/class-api.php';
            require_once MMP_PLUGIN_PATH . 'includes/class-reports.php';
        }
        
        public function init_components() {
            MMP_Supplier_Manager::instance();
            MMP_Marketplace_Integration::instance();
            MMP_Price_Calculator::instance();
            MMP_Order_Manager::instance();
            MMP_Settings::instance();
            MMP_Reports::instance();
        }
        
        public function enqueue_admin_assets($hook) {
            if (strpos($hook, 'mmp_') === false) {
                return;
            }
            
            wp_enqueue_style('mmp-admin', MMP_PLUGIN_URL . 'assets/css/admin.css', [], MMP_VERSION);
            
            wp_enqueue_script('mmp-admin', MMP_PLUGIN_URL . 'assets/js/admin.js', ['jquery', 'select2'], MMP_VERSION, true);
            
            wp_localize_script('mmp-admin', 'mmp_admin_params', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'import_nonce' => wp_create_nonce('mmp-import-products'),
                'sync_nonce' => wp_create_nonce('mmp-sync-products'),
                'i18n' => [
                    'importing' => __('Importing...', 'multi-marketplace-manager'),
                    'syncing' => __('Syncing...', 'multi-marketplace-manager'),
                    'complete' => __('Complete!', 'multi-marketplace-manager'),
                    'error' => __('Error!', 'multi-marketplace-manager')
                ]
            ]);
        }
        
        public function woocommerce_missing_notice() {
            ?>
            <div class="error notice">
                <p><?php 
                    printf(
                        __('%1$s requires %2$s to be installed and active. Please install WooCommerce to use Multi Marketplace Manager.', 'multi-marketplace-manager'),
                        '<strong>Multi Marketplace Manager</strong>',
                        '<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>'
                    ); 
                ?></p>
            </div>
            <?php
        }
    }
}

// Initialize the plugin
Multi_Marketplace_Manager::instance();
