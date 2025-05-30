<?php
if (!defined('ABSPATH')) exit;

class MMP_Supplier_Manager {
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
        $this->table_name = $wpdb->prefix . 'mmp_suppliers';
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_mmp_import_supplier_products', [$this, 'ajax_import_products']);
        add_action('wp_ajax_mmp_save_supplier', [$this, 'ajax_save_supplier']);
        add_action('wp_ajax_mmp_delete_supplier', [$this, 'ajax_delete_supplier']);
        add_action('mmp_hourly_sync_event', [$this, 'scheduled_supplier_sync']);
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Suppliers', 'multi-marketplace-manager'),
            __('Suppliers', 'multi-marketplace-manager'),
            'manage_woocommerce',
            'mmp-suppliers',
            [$this, 'render_suppliers_page']
        );
    }
    
    public function render_suppliers_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $supplier_id = isset($_GET['supplier_id']) ? absint($_GET['supplier_id']) : 0;
        
        switch ($action) {
            case 'add':
            case 'edit':
                $supplier = $supplier_id ? $this->get_supplier($supplier_id) : null;
                include MMP_PLUGIN_PATH . 'templates/admin/supplier-edit.php';
                break;
            default:
                $suppliers = $this->get_all_suppliers();
                include MMP_PLUGIN_PATH . 'templates/admin/suppliers-list.php';
        }
    }
    
    public function ajax_import_products() {
        check_ajax_referer('mmp-import-products', 'security');
        
        $supplier_id = isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : 0;
        $markup = isset($_POST['markup']) ? floatval($_POST['markup']) : 0;
        
        if (!$supplier_id || $markup <= 0) {
            wp_send_json_error(['message' => __('Invalid parameters', 'multi-marketplace-manager')]);
        }
        
        $supplier = $this->get_supplier($supplier_id);
        if (!$supplier) {
            wp_send_json_error(['message' => __('Supplier not found', 'multi-marketplace-manager')]);
        }
        
        $result = $this->import_products_from_xml($supplier, $markup);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => sprintf(__('%d products imported successfully', 'multi-marketplace-manager'), $result['imported']),
            'stats' => $result
        ]);
    }
    
    public function ajax_save_supplier() {
        check_ajax_referer('mmp-save-supplier', 'security');
        
        $supplier_id = isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : 0;
        $supplier_data = [
            'supplier_name' => isset($_POST['supplier_name']) ? sanitize_text_field($_POST['supplier_name']) : '',
            'xml_url' => isset($_POST['xml_url']) ? esc_url_raw($_POST['xml_url']) : '',
            'default_markup' => isset($_POST['default_markup']) ? floatval($_POST['default_markup']) : 0,
            'auto_sync' => isset($_POST['auto_sync']) ? 1 : 0
        ];
        
        if (empty($supplier_data['supplier_name']) || empty($supplier_data['xml_url'])) {
            wp_send_json_error(['message' => __('Supplier name and XML URL are required', 'multi-marketplace-manager')]);
        }
        
        $result = $supplier_id ? $this->update_supplier($supplier_id, $supplier_data) : $this->add_supplier($supplier_data);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => $supplier_id ? __('Supplier updated successfully', 'multi-marketplace-manager') : __('Supplier added successfully', 'multi-marketplace-manager'),
            'redirect' => admin_url('admin.php?page=mmp-suppliers')
        ]);
    }
    
    public function ajax_delete_supplier() {
        check_ajax_referer('mmp-delete-supplier', 'security');
        
        $supplier_id = isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : 0;
        
        if (!$supplier_id) {
            wp_send_json_error(['message' => __('Invalid supplier ID', 'multi-marketplace-manager')]);
        }
        
        $result = $this->delete_supplier($supplier_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => __('Supplier deleted successfully', 'multi-marketplace-manager'),
            'redirect' => admin_url('admin.php?page=mmp-suppliers')
        ]);
    }
    
    public function scheduled_supplier_sync() {
        $suppliers = $this->get_suppliers_for_sync();
        
        foreach ($suppliers as $supplier) {
            $this->import_products_from_xml($supplier, $supplier->default_markup);
        }
    }
    
    private function import_products_from_xml($supplier, $markup) {
        $xml = $this->load_xml($supplier->xml_url);
        
        if (is_wp_error($xml)) {
            return $xml;
        }
        
        $imported = 0;
        $updated = 0;
        $failed = 0;
        
        foreach ($xml->product as $product) {
            $product_data = $this->prepare_product_data($product, $markup);
            $result = $this->create_or_update_product($product_data);
            
            if ($result === 'imported') {
                $imported++;
            } elseif ($result === 'updated') {
                $updated++;
            } else {
                $failed++;
            }
        }
        
        return [
            'imported' => $imported,
            'updated' => $updated,
            'failed' => $failed,
            'total' => $imported + $updated + $failed
        ];
    }
    
    private function load_xml($url) {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $xml = simplexml_load_string($body);
        
        if (!$xml) {
            return new WP_Error('invalid_xml', __('Invalid XML format', 'multi-marketplace-manager'));
        }
        
        return $xml;
    }
    
    private function prepare_product_data($product, $markup) {
        $cost_price = (float)$product->price;
        $selling_price = $cost_price * (1 + ($markup / 100));
        
        return [
            'name' => (string)$product->name,
            'description' => (string)$product->description,
            'short_description' => (string)$product->short_description,
            'sku' => (string)$product->code,
            'supplier_code' => (string)$product->code,
            'regular_price' => $selling_price,
            'cost_price' => $cost_price,
            'stock_quantity' => (int)$product->stock,
            'category' => (string)$product->category,
            'images' => $this->prepare_images($product->images),
            'attributes' => $this->prepare_attributes($product->attributes),
            'meta_data' => [
                '_supplier_id' => $supplier->supplier_id,
                '_supplier_name' => $supplier->supplier_name,
                '_cost_price' => $cost_price
            ]
        ];
    }
    
    private function prepare_images($images) {
        $prepared = [];
        
        foreach ($images->image as $image) {
            $prepared[] = [
                'url' => (string)$image,
                'alt' => ''
            ];
        }
        
        return $prepared;
    }
    
    private function prepare_attributes($attributes) {
        $prepared = [];
        
        foreach ($attributes->attribute as $attr) {
            $prepared[] = [
                'name' => (string)$attr->name,
                'value' => (string)$attr->value,
                'visible' => true,
                'variation' => false
            ];
        }
        
        return $prepared;
    }
    
    private function create_or_update_product($product_data) {
        $product_id = wc_get_product_id_by_sku($product_data['sku']);
        
        if ($product_id) {
            $this->update_product($product_id, $product_data);
            return 'updated';
        } else {
            $this->create_product($product_data);
            return 'imported';
        }
    }
    
    private function create_product($product_data) {
        $product = new WC_Product_Simple();
        
        $product->set_name($product_data['name']);
        $product->set_description($product_data['description']);
        $product->set_short_description($product_data['short_description']);
        $product->set_sku($product_data['sku']);
        $product->set_regular_price($product_data['regular_price']);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($product_data['stock_quantity']);
        $product->set_status('publish');
        
        // Set categories
        $category_ids = $this->get_or_create_categories($product_data['category']);
        if (!empty($category_ids)) {
            $product->set_category_ids($category_ids);
        }
        
        // Set attributes
        if (!empty($product_data['attributes'])) {
            $attributes = [];
            
            foreach ($product_data['attributes'] as $attribute) {
                $attr = new WC_Product_Attribute();
                $attr->set_name($attribute['name']);
                $attr->set_options([$attribute['value']]);
                $attr->set_visible($attribute['visible']);
                $attr->set_variation($attribute['variation']);
                $attributes[] = $attr;
            }
            
            $product->set_attributes($attributes);
        }
        
        // Save meta data
        foreach ($product_data['meta_data'] as $key => $value) {
            $product->update_meta_data($key, $value);
        }
        
        $product_id = $product->save();
        
        // Upload images
        if (!empty($product_data['images'])) {
            $this->upload_product_images($product_id, $product_data['images']);
        }
        
        // Save cost data
        $this->save_product_costs($product_id, $product_data);
        
        return $product_id;
    }
    
    private function update_product($product_id, $product_data) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return false;
        }
        
        $product->set_name($product_data['name']);
        $product->set_description($product_data['description']);
        $product->set_short_description($product_data['short_description']);
        $product->set_regular_price($product_data['regular_price']);
        $product->set_stock_quantity($product_data['stock_quantity']);
        
        // Update meta data
        foreach ($product_data['meta_data'] as $key => $value) {
            $product->update_meta_data($key, $value);
        }
        
        $product->save();
        
        // Update cost data
        $this->save_product_costs($product_id, $product_data);
        
        return true;
    }
    
    private function save_product_costs($product_id, $product_data) {
        global $wpdb;
        
        $cost_data = [
            'product_id' => $product_id,
            'supplier_cost' => $product_data['cost_price'],
            'markup_rate' => $markup,
            'calculated_price' => $product_data['regular_price']
        ];
        
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT cost_id FROM {$wpdb->prefix}mmp_product_costs WHERE product_id = %d",
            $product_id
        ));
        
        if ($existing) {
            $wpdb->update(
                "{$wpdb->prefix}mmp_product_costs",
                $cost_data,
                ['cost_id' => $existing->cost_id]
            );
        } else {
            $wpdb->insert(
                "{$wpdb->prefix}mmp_product_costs",
                $cost_data
            );
        }
    }
    
    private function get_or_create_categories($category_path) {
        $category_ids = [];
        $categories = explode('>', $category_path);
        
        foreach ($categories as $category_name) {
            $category_name = trim($category_name);
            if (empty($category_name)) continue;
            
            $term = term_exists($category_name, 'product_cat');
            
            if (!$term) {
                $term = wp_insert_term(
                    $category_name,
                    'product_cat',
                    ['slug' => sanitize_title($category_name)]
                );
            }
            
            if (!is_wp_error($term)) {
                $category_ids[] = $term['term_id'];
            }
        }
        
        return $category_ids;
    }
    
    private function upload_product_images($product_id, $images) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $image_ids = [];
        $first = true;
        
        foreach ($images as $image) {
            $image_id = media_sideload_image($image['url'], $product_id, $image['alt'], 'id');
            
            if (!is_wp_error($image_id)) {
                $image_ids[] = $image_id;
                
                if ($first) {
                    set_post_thumbnail($product_id, $image_id);
                    $first = false;
                }
            }
        }
        
        if (!empty($image_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $image_ids));
        }
    }
    
    private function get_all_suppliers() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY supplier_name ASC");
    }
    
    private function get_suppliers_for_sync() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table_name} WHERE auto_sync = 1 ORDER BY supplier_name ASC");
    }
    
    private function get_supplier($supplier_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE supplier_id = %d",
            $supplier_id
        ));
    }
    
    private function add_supplier($data) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'supplier_name' => $data['supplier_name'],
                'xml_url' => $data['xml_url'],
                'default_markup' => $data['default_markup'],
                'auto_sync' => $data['auto_sync']
            ],
            ['%s', '%s', '%f', '%d']
        );
        
        if (!$result) {
            return new WP_Error('db_error', __('Could not add supplier to database', 'multi-marketplace-manager'));
        }
        
        return $wpdb->insert_id;
    }
    
    private function update_supplier($supplier_id, $data) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            [
                'supplier_name' => $data['supplier_name'],
                'xml_url' => $data['xml_url'],
                'default_markup' => $data['default_markup'],
                'auto_sync' => $data['auto_sync']
            ],
            ['supplier_id' => $supplier_id],
            ['%s', '%s', '%f', '%d'],
            ['%d']
        );
        
        if ($result === false) {
            return new WP_Error('db_error', __('Could not update supplier in database', 'multi-marketplace-manager'));
        }
        
        return true;
    }
    
    private function delete_supplier($supplier_id) {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->table_name,
            ['supplier_id' => $supplier_id],
            ['%d']
        );
        
        if (!$result) {
            return new WP_Error('db_error', __('Could not delete supplier from database', 'multi-marketplace-manager'));
        }
        
        return true;
    }
}
