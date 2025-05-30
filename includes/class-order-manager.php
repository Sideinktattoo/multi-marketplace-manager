<?php
if (!defined('ABSPATH')) exit;

class MMP_Order_Manager {
    private static $instance = null;
    private $table_name;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'mmp_orders';
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('woocommerce_order_status_changed', [$this, 'order_status_changed'], 10, 3);
        add_action('mmp_hourly_sync_event', [$this, 'sync_marketplace_orders']);
        add_action('wp_ajax_mmp_update_tracking', [$this, 'ajax_update_tracking']);
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Marketplace Orders', 'multi-marketplace-manager'),
            __('Marketplace Orders', 'multi-marketplace-manager'),
            'manage_woocommerce',
            'mmp-orders',
            [$this, 'render_orders_page']
        );
    }
    
    public function render_orders_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        
        switch ($action) {
            case 'view':
                $order = $this->get_order($order_id);
                include MMP_PLUGIN_PATH . 'templates/admin/order-view.php';
                break;
            default:
                $orders = $this->get_all_orders();
                include MMP_PLUGIN_PATH . 'templates/admin/orders-list.php';
        }
    }
    
    public function order_status_changed($order_id, $old_status, $new_status) {
        $order = wc_get_order($order_id);
        $marketplace = $this->get_order_marketplace($order);
        
        if (!$marketplace) {
            return;
        }
        
        $this->update_order_status($order_id, $new_status);
        
        // Notify marketplace about status change
        $this->notify_marketplace($marketplace, $order_id, $new_status);
    }
    
    public function sync_marketplace_orders() {
        $marketplaces = MMP_Marketplace_Integration::instance()->get_active_marketplaces();
        
        foreach ($marketplaces as $marketplace) {
            $this->sync_orders_from_marketplace($marketplace);
        }
    }
    
    private function sync_orders_from_marketplace($marketplace) {
        $integration_id = $marketplace->integration_id;
        $settings = maybe_unserialize($marketplace->settings);
        
        switch ($integration_id) {
            case 'trendyol':
                $api = new MMP_Trendyol_API(
                    $settings['api_key'],
                    $settings['api_secret'],
                    $settings['supplier_id'],
                    $settings['seller_id']
                );
                $orders = $api->get_orders();
                break;
                
            case 'hepsiburada':
                $api = new MMP_Hepsiburada_API(
                    $settings['api_key'],
                    $settings['api_secret'],
                    $settings['merchant_id']
                );
                $orders = $api->get_orders();
                break;
                
            default:
                return;
        }
        
        foreach ($orders as $order_data) {
            $this->create_or_update_order($order_data, $marketplace->marketplace_id);
        }
    }
    
    private function create_or_update_order($order_data, $marketplace_id) {
        global $wpdb;
        
        // Check if order already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE marketplace_order_id = %s AND marketplace_id = %d",
            $order_data['order_id'],
            $marketplace_id
        ));
        
        $order_data = [
            'marketplace_id' => $marketplace_id,
            'marketplace_order_id' => $order_data['order_id'],
            'customer_name' => $order_data['customer_name'],
            'customer_email' => $order_data['customer_email'],
            'total_amount' => $order_data['total_amount'],
            'status' => $order_data['status'],
            'order_data' => maybe_serialize($order_data),
            'last_updated' => current_time('mysql')
        ];
        
        if ($existing) {
            $wpdb->update(
                $this->table_name,
                $order_data,
                ['order_id' => $existing->order_id]
            );
            $order_id = $existing->order_id;
        } else {
            $order_data['created_at'] = current_time('mysql');
            $wpdb->insert($this->table_name, $order_data);
            $order_id = $wpdb->insert_id;
            
            // Create WooCommerce order if needed
            $this->create_woocommerce_order($order_id, $order_data);
        }
        
        return $order_id;
    }
    
    private function create_woocommerce_order($mmp_order_id, $order_data) {
        $order_data = maybe_unserialize($order_data['order_data']);
        
        $order = wc_create_order([
            'customer_id' => $this->get_or_create_customer($order_data),
            'created_via' => 'marketplace_' . $order_data['marketplace']
        ]);
        
        foreach ($order_data['items'] as $item) {
            $product_id = wc_get_product_id_by_sku($item['sku']);
            
            if ($product_id) {
                $order->add_product(wc_get_product($product_id), $item['quantity']);
            }
        }
        
        // Set address
        $this->set_order_address($order, $order_data);
        
        // Set shipping method
        $this->set_shipping_method($order, $order_data);
        
        // Set payment method
        $order->set_payment_method('marketplace_' . $order_data['marketplace']);
        $order->set_payment_method_title(ucfirst($order_data['marketplace']));
        
        // Calculate totals
        $order->calculate_totals();
        
        // Update status
        $order->update_status($this->map_marketplace_status($order_data['status']));
        
        // Save MMP order reference
        update_post_meta($order->get_id(), '_mmp_order_id', $mmp_order_id);
        update_post_meta($order->get_id(), '_mmp_marketplace', $order_data['marketplace']);
        update_post_meta($order->get_id(), '_mmp_marketplace_order_id', $order_data['order_id']);
        
        return $order->get_id();
    }
    
    private function get_or_create_customer($order_data) {
        $customer_id = email_exists($order_data['customer_email']);
        
        if (!$customer_id) {
            $customer_id = wc_create_new_customer(
                $order_data['customer_email'],
                $order_data['customer_email'],
                wp_generate_password()
            );
            
            if (!is_wp_error($customer_id)) {
                wp_update_user([
                    'ID' => $customer_id,
                    'first_name' => $order_data['customer_name'],
                    'last_name' => ''
                ]);
            }
        }
        
        return $customer_id;
    }
    
    private function set_order_address($order, $order_data) {
        $address = [
            'first_name' => $order_data['customer_name'],
            'last_name' => '',
            'email' => $order_data['customer_email'],
            'phone' => $order_data['customer_phone'],
            'address_1' => $order_data['shipping_address']['address'],
            'address_2' => '',
            'city' => $order_data['shipping_address']['city'],
            'state' => $order_data['shipping_address']['state'],
            'postcode' => $order_data['shipping_address']['postcode'],
            'country' => $order_data['shipping_address']['country']
        ];
        
        $order->set_address($address, 'billing');
        $order->set_address($address, 'shipping');
    }
    
    private function set_shipping_method($order, $order_data) {
        $shipping = new WC_Order_Item_Shipping();
        $shipping->set_method_title($order_data['shipping_method']);
        $shipping->set_method_id('marketplace_' . sanitize_title($order_data['shipping_method']));
        $shipping->set_total($order_data['shipping_cost']);
        $order->add_item($shipping);
    }
    
    private function map_marketplace_status($marketplace_status) {
        $status_map = [
            'Created' => 'processing',
            'Approved' => 'processing',
            'Packed' => 'processing',
            'Shipped' => 'completed',
            'Delivered' => 'completed',
            'Cancelled' => 'cancelled',
            'Returned' => 'refunded'
        ];
        
        return $status_map[$marketplace_status] ?? 'pending';
    }
    
    private function notify_marketplace($marketplace, $order_id, $status) {
        $wc_order = wc_get_order($order_id);
        $marketplace_order_id = $wc_order->get_meta('_mmp_marketplace_order_id');
        
        if (!$marketplace_order_id) {
            return;
        }
        
        $integration_id = $marketplace->integration_id;
        $settings = maybe_unserialize($marketplace->settings);
        
        switch ($integration_id) {
            case 'trendyol':
                $api = new MMP_Trendyol_API(
                    $settings['api_key'],
                    $settings['api_secret'],
                    $settings['supplier_id'],
                    $settings['seller_id']
                );
                
                $api->update_order_status($marketplace_order_id, $status);
                break;
                
            case 'hepsiburada':
                $api = new MMP_Hepsiburada_API(
                    $settings['api_key'],
                    $settings['api_secret'],
                    $settings['merchant_id']
                );
                
                $api->update_order_status($marketplace_order_id, $status);
                break;
        }
    }
    
    public function ajax_update_tracking() {
        check_ajax_referer('mmp-update-tracking', 'security');
        
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $tracking_number = isset($_POST['tracking_number']) ? sanitize_text_field($_POST['tracking_number']) : '';
        $shipping_company = isset($_POST['shipping_company']) ? sanitize_text_field($_POST['shipping_company']) : '';
        
        if (!$order_id || empty($tracking_number)) {
            wp_send_json_error(['message' => __('Invalid parameters', 'multi-marketplace-manager')]);
        }
        
        $order = wc_get_order($order_id);
        $marketplace = $this->get_order_marketplace($order);
        
        if (!$marketplace) {
            wp_send_json_error(['message' => __('Not a marketplace order', 'multi-marketplace-manager')]);
        }
        
        // Update tracking info in WooCommerce
        $order->update_meta_data('_tracking_number', $tracking_number);
        $order->update_meta_data('_shipping_company', $shipping_company);
        $order->save();
        
        // Notify marketplace
        $integration_id = $marketplace->integration_id;
        $settings = maybe_unserialize($marketplace->settings);
        $marketplace_order_id = $order->get_meta('_mmp_marketplace_order_id');
        
        switch ($integration_id) {
            case 'trendyol':
                $api = new MMP_Trendyol_API(
                    $settings['api_key'],
                    $settings['api_secret'],
                    $settings['supplier_id'],
                    $settings['seller_id']
                );
                
                $result = $api->update_tracking($marketplace_order_id, $tracking_number, $shipping_company);
                break;
                
            case 'hepsiburada':
                $api = new MMP_Hepsiburada_API(
                    $settings['api_key'],
                    $settings['api_secret'],
                    $settings['merchant_id']
                );
                
                $result = $api->update_tracking($marketplace_order_id, $tracking_number, $shipping_company);
                break;
                
            default:
                $result = false;
        }
        
        if ($result) {
            wp_send_json_success(['message' => __('Tracking information updated successfully', 'multi-marketplace-manager')]);
        } else {
            wp_send_json_error(['message' => __('Failed to update tracking information', 'multi-marketplace-manager')]);
        }
    }
    
    private function get_order_marketplace($order) {
        $marketplace_id = $order->get_meta('_mmp_marketplace_id');
        
        if (!$marketplace_id) {
            return false;
        }
        
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mmp_marketplaces WHERE marketplace_id = %d",
            $marketplace_id
        ));
    }
    
    private function get_all_orders() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT o.*, m.marketplace_name 
            FROM {$this->table_name} o
            LEFT JOIN {$wpdb->prefix}mmp_marketplaces m ON o.marketplace_id = m.marketplace_id
            ORDER BY o.last_updated DESC
        ");
    }
    
    private function get_order($order_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare("
            SELECT o.*, m.marketplace_name 
            FROM {$this->table_name} o
            LEFT JOIN {$wpdb->prefix}mmp_marketplaces m ON o.marketplace_id = m.marketplace_id
            WHERE o.order_id = %d
        ", $order_id));
    }
    
    private function update_order_status($order_id, $status) {
        global $wpdb;
        
        $wpdb->update(
            $this->table_name,
            ['status' => $status, 'last_updated' => current_time('mysql')],
            ['order_id' => $order_id]
        );
    }
}
