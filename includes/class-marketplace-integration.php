<?php
if (!defined('ABSPATH')) exit;

class MMP_Marketplace_Integration {
    private static $instance = null;
    private $integrations = [];
    private $table_name;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'mmp_marketplaces';
        
        $this->integrations = [
            'trendyol' => [
                'name' => 'Trendyol',
                'class' => 'MMP_Trendyol_Integration',
                'settings' => [
                    'api_key' => 'text',
                    'api_secret' => 'password',
                    'supplier_id' => 'text',
                    'seller_id' => 'text'
                ]
            ],
            'hepsiburada' => [
                'name' => 'Hepsiburada',
                'class' => 'MMP_Hepsiburada_Integration',
                'settings' => [
                    'api_key' => 'text',
                    'api_secret' => 'password',
                    'merchant_id' => 'text'
                ]
            ],
            'n11' => [
                'name' => 'n11',
                'class' => 'MMP_N11_Integration',
                'settings' => [
                    'api_key' => 'text',
                    'api_secret' => 'password'
                ]
            ]
        ];
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_mmp_save_marketplace', [$this, 'ajax_save_marketplace']);
        add_action('wp_ajax_mmp_sync_marketplace', [$this, 'ajax_sync_marketplace']);
        add_action('mmp_hourly_sync_event', [$this, 'scheduled_marketplace_sync']);
        
        $this->load_integrations();
    }
    
    private function load_integrations() {
        foreach ($this->integrations as $integration_id => $integration) {
            if (class_exists($integration['class'])) {
                new $integration['class']();
            }
        }
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Marketplaces', 'multi-marketplace-manager'),
            __('Marketplaces', 'multi-marketplace-manager'),
            'manage_woocommerce',
            'mmp-marketplaces',
            [$this, 'render_marketplaces_page']
        );
    }
    
    public function render_marketplaces_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $marketplace_id = isset($_GET['marketplace_id']) ? absint($_GET['marketplace_id']) : 0;
        
        switch ($action) {
            case 'add':
            case 'edit':
                $marketplace = $marketplace_id ? $this->get_marketplace($marketplace_id) : null;
                $integration_options = $this->get_integration_options();
                include MMP_PLUGIN_PATH . 'templates/admin/marketplace-edit.php';
                break;
            case 'sync':
                $marketplace = $this->get_marketplace($marketplace_id);
                if ($marketplace) {
                    $this->sync_marketplace($marketplace);
                }
                wp_redirect(admin_url('admin.php?page=mmp-marketplaces'));
                exit;
            default:
                $marketplaces = $this->get_all_marketplaces();
                $integration_options = $this->get_integration_options();
                include MMP_PLUGIN_PATH . 'templates/admin/marketplaces-list.php';
        }
    }
    
    public function ajax_save_marketplace() {
        check_ajax_referer('mmp-save-marketplace', 'security');
        
        $marketplace_id = isset($_POST['marketplace_id']) ? absint($_POST['marketplace_id']) : 0;
        $integration_id = isset($_POST['integration_id']) ? sanitize_text_field($_POST['integration_id']) : '';
        $marketplace_name = isset($_POST['marketplace_name']) ? sanitize_text_field($_POST['marketplace_name']) : '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $settings = [];
        if (isset($this->integrations[$integration_id]['settings'])) {
            foreach ($this->integrations[$integration_id]['settings'] as $key => $type) {
                $settings[$key] = isset($_POST[$key]) ? sanitize_text_field($_POST[$key]) : '';
            }
        }
        
        if (empty($integration_id) || empty($marketplace_name)) {
            wp_send_json_error(['message' => __('Integration type and marketplace name are required', 'multi-marketplace-manager')]);
        }
        
        $marketplace_data = [
            'marketplace_name' => $marketplace_name,
            'integration_id' => $integration_id,
            'settings' => maybe_serialize($settings),
            'is_active' => $is_active
        ];
        
        $result = $marketplace_id ? $this->update_marketplace($marketplace_id, $marketplace_data) : $this->add_marketplace($marketplace_data);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => $marketplace_id ? __('Marketplace updated successfully', 'multi-marketplace-manager') : __('Marketplace added successfully', 'multi-marketplace-manager'),
            'redirect' => admin_url('admin.php?page=mmp-marketplaces')
        ]);
    }
    
    public function ajax_sync_marketplace() {
        check_ajax_referer('mmp-sync-marketplace', 'security');
        
        $marketplace_id = isset($_POST['marketplace_id']) ? absint($_POST['marketplace_id']) : 0;
        
        if (!$marketplace_id) {
            wp_send_json_error(['message' => __('Invalid marketplace ID', 'multi-marketplace-manager')]);
        }
        
        $marketplace = $this->get_marketplace($marketplace_id);
        
        if (!$marketplace) {
            wp_send_json_error(['message' => __('Marketplace not found', 'multi-marketplace-manager')]);
        }
        
        $result = $this->sync_marketplace($marketplace);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => __('Marketplace sync completed', 'multi-marketplace-manager'),
            'stats' => $result
        ]);
    }
    
    public function scheduled_marketplace_sync() {
        $marketplaces = $this->get_active_marketplaces();
        
        foreach ($marketplaces as $marketplace) {
            $this->sync_marketplace($marketplace);
        }
    }
    
    private function sync_marketplace($marketplace) {
        $integration_id = $marketplace->integration_id;
        
        if (!isset($this->integrations[$integration_id])) {
            return new WP_Error('invalid_integration', __('Invalid marketplace integration', 'multi-marketplace-manager'));
        }
        
        $class = $this->integrations[$integration_id]['class'];
        
        if (!class_exists($class)) {
            return new WP_Error('integration_not_found', __('Integration class not found', 'multi-marketplace-manager'));
        }
        
        $integration = new $class();
        
        if (!method_exists($integration, 'sync_products')) {
            return new WP_Error('invalid_method', __('Integration does not support product sync', 'multi-marketplace-manager'));
        }
        
        $settings = maybe_unserialize($marketplace->settings);
        $result = $integration->sync_products($settings);
        
        // Update last sync time
        $this->update_marketplace($marketplace->marketplace_id, [
            'last_sync' => current_time('mysql')
        ]);
        
        return $result;
    }
    
    private function get_integration_options() {
        $options = [];
        
        foreach ($this->integrations as $id => $integration) {
            $options[$id] = $integration['name'];
        }
        
        return $options;
    }
    
    private function get_all_marketplaces() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY marketplace_name ASC");
    }
    
    private function get_active_marketplaces() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table_name} WHERE is_active = 1 ORDER BY marketplace_name ASC");
    }
    
    private function get_marketplace($marketplace_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE marketplace_id = %d",
            $marketplace_id
        ));
    }
    
    private function add_marketplace($data) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'marketplace_name' => $data['marketplace_name'],
                'integration_id' => $data['integration_id'],
                'settings' => $data['settings'],
                'is_active' => $data['is_active'],
                'created_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%d', '%s']
        );
        
        if (!$result) {
            return new WP_Error('db_error', __('Could not add marketplace to database', 'multi-marketplace-manager'));
        }
        
        return $wpdb->insert_id;
    }
    
    private function update_marketplace($marketplace_id, $data) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            $data,
            ['marketplace_id' => $marketplace_id]
        );
        
        if ($result === false) {
            return new WP_Error('db_error', __('Could not update marketplace in database', 'multi-marketplace-manager'));
        }
        
        return true;
    }
}

// Trendyol Integration
class MMP_Trendyol_Integration {
    public function __construct() {
        add_filter('mmp_marketplace_integrations', [$this, 'register_integration']);
    }
    
    public function register_integration($integrations) {
        $integrations['trendyol'] = [
            'name' => 'Trendyol',
            'class' => __CLASS__,
            'settings' => [
                'api_key' => 'text',
                'api_secret' => 'password',
                'supplier_id' => 'text',
                'seller_id' => 'text'
            ]
        ];
        return $integrations;
    }
    
    public function sync_products($settings) {
        if (empty($settings['api_key']) || empty($settings['api_secret'])) {
            return new WP_Error('missing_credentials', __('Trendyol API credentials are missing', 'multi-marketplace-manager'));
        }
        
        // Implement Trendyol API integration
        $api = new MMP_Trendyol_API(
            $settings['api_key'],
            $settings['api_secret'],
            $settings['supplier_id'],
            $settings['seller_id']
        );
        
        // Get products from WooCommerce
        $products = $this->get_products_to_sync();
        
        // Send products to Trendyol
        $result = $api->batch_update_products($products);
        
        return [
            'sent' => count($products),
            'success' => $result['success_count'],
            'failed' => $result['failed_count']
        ];
    }
    
    private function get_products_to_sync() {
        $args = [
            'limit' => -1,
            'return' => 'objects',
            'meta_key' => '_sync_trendyol',
            'meta_value' => 'yes'
        ];
        
        $products = wc_get_products($args);
        $prepared_products = [];
        
        foreach ($products as $product) {
            $prepared_products[] = $this->prepare_product_for_trendyol($product);
        }
        
        return $prepared_products;
    }
    
    private function prepare_product_for_trendyol($product) {
        // Get product cost data
        $cost_data = $this->get_product_cost_data($product->get_id());
        
        return [
            'barcode' => $product->get_sku(),
            'title' => $product->get_name(),
            'productMainId' => $product->get_sku(),
            'brandId' => $this->get_brand_id($product),
            'categoryId' => $this->get_category_id($product),
            'quantity' => $product->get_stock_quantity(),
            'stockCode' => $product->get_sku(),
            'dimensionalWeight' => $this->calculate_dimensional_weight($product),
            'description' => $product->get_description(),
            'currencyType' => 'TRY',
            'listPrice' => $product->get_regular_price(),
            'salePrice' => $product->get_sale_price() ?: $product->get_regular_price(),
            'cargoCompanyId' => 1, // Default cargo company
            'images' => $this->get_product_images($product),
            'attributes' => $this->get_product_attributes($product),
            'vatRate' => $this->get_vat_rate($product),
            'shipmentAddressId' => 1, // Default shipment address
            'supplierId' => get_option('mmp_trendyol_supplier_id')
        ];
    }
    
    // ... Diğer Trendyol'a özel metodlar ...
}

// Hepsiburada Integration
class MMP_Hepsiburada_Integration {
    // Benzer şekilde Hepsiburada entegrasyonu
}

// n11 Integration
class MMP_N11_Integration {
    // Benzer şekilde n11 entegrasyonu
}
