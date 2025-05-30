<?php
if (!defined('ABSPATH')) exit;

class MMP_Price_Calculator {
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
        $this->table_name = $wpdb->prefix . 'mmp_product_costs';
        
        add_action('woocommerce_product_options_pricing', [$this, 'add_cost_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_cost_data']);
        add_filter('woocommerce_product_get_price', [$this, 'calculate_dynamic_price'], 10, 2);
        add_filter('woocommerce_product_variation_get_price', [$this, 'calculate_dynamic_price'], 10, 2);
        add_action('mmp_after_product_import', [$this, 'update_cost_data'], 10, 2);
    }
    
    public function add_cost_fields() {
        global $post;
        
        echo '<div class="options_group">';
        
        woocommerce_wp_text_input([
            'id' => '_supplier_cost',
            'label' => __('Supplier Cost (₺)', 'multi-marketplace-manager'),
            'description' => __('The cost of the product from the supplier', 'multi-marketplace-manager'),
            'data_type' => 'price',
            'value' => get_post_meta($post->ID, '_supplier_cost', true)
        ]);
        
        woocommerce_wp_text_input([
            'id' => '_shipping_cost',
            'label' => __('Shipping Cost (₺)', 'multi-marketplace-manager'),
            'description' => __('Estimated shipping cost per unit', 'multi-marketplace-manager'),
            'data_type' => 'price',
            'value' => get_post_meta($post->ID, '_shipping_cost', true)
        ]);
        
        woocommerce_wp_text_input([
            'id' => '_packaging_cost',
            'label' => __('Packaging Cost (₺)', 'multi-marketplace-manager'),
            'description' => __('Estimated packaging cost per unit', 'multi-marketplace-manager'),
            'data_type' => 'price',
            'value' => get_post_meta($post->ID, '_packaging_cost', true)
        ]);
        
        woocommerce_wp_text_input([
            'id' => '_commission_rate',
            'label' => __('Commission Rate (%)', 'multi-marketplace-manager'),
            'description' => __('Marketplace commission percentage', 'multi-marketplace-manager'),
            'type' => 'number',
            'custom_attributes' => [
                'step' => '0.01',
                'min' => '0'
            ],
            'value' => get_post_meta($post->ID, '_commission_rate', true)
        ]);
        
        woocommerce_wp_text_input([
            'id' => '_tax_rate',
            'label' => __('Tax Rate (%)', 'multi-marketplace-manager'),
            'description' => __('Tax percentage to be applied', 'multi-marketplace-manager'),
            'type' => 'number',
            'custom_attributes' => [
                'step' => '0.01',
                'min' => '0'
            ],
            'value' => get_post_meta($post->ID, '_tax_rate', true)
        ]);
        
        woocommerce_wp_text_input([
            'id' => '_other_costs',
            'label' => __('Other Costs (₺)', 'multi-marketplace-manager'),
            'description' => __('Any additional costs per unit', 'multi-marketplace-manager'),
            'data_type' => 'price',
            'value' => get_post_meta($post->ID, '_other_costs', true)
        ]);
        
        woocommerce_wp_text_input([
            'id' => '_markup_rate',
            'label' => __('Markup Rate (%)', 'multi-marketplace-manager'),
            'description' => __('Desired profit margin percentage', 'multi-marketplace-manager'),
            'type' => 'number',
            'custom_attributes' => [
                'step' => '0.01',
                'min' => '0'
            ],
            'value' => get_post_meta($post->ID, '_markup_rate', true)
        ]);
        
        echo '</div>';
        
        // Show calculated price
        $this->show_calculated_price($post->ID);
    }
    
    private function show_calculated_price($product_id) {
        $price = $this->calculate_price($product_id);
        
        echo '<div class="options_group">';
        echo '<p class="form-field">';
        echo '<label>' . __('Calculated Price', 'multi-marketplace-manager') . '</label>';
        echo '<span class="calculated-price">' . wc_price($price) . '</span>';
        echo '</p>';
        echo '</div>';
        
        // JavaScript to update calculated price on the fly
        ?>
        <script>
        jQuery(document).ready(function($) {
            var costFields = ['_supplier_cost', '_shipping_cost', '_packaging_cost', '_commission_rate', '_tax_rate', '_other_costs', '_markup_rate'];
            
            function updateCalculatedPrice() {
                var data = {
                    action: 'mmp_calculate_price',
                    product_id: <?php echo $product_id; ?>,
                    security: '<?php echo wp_create_nonce("mmp-calculate-price"); ?>'
                };
                
                costFields.forEach(function(field) {
                    data[field] = $('#' + field).val();
                });
                
                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        $('.calculated-price').html(response.data.formatted_price);
                    }
                });
            }
            
            costFields.forEach(function(field) {
                $('#' + field).on('change keyup', updateCalculatedPrice);
            });
        });
        </script>
        <?php
    }
    
    public function save_cost_data($product_id) {
        $fields = [
            '_supplier_cost' => 'float',
            '_shipping_cost' => 'float',
            '_packaging_cost' => 'float',
            '_commission_rate' => 'float',
            '_tax_rate' => 'float',
            '_other_costs' => 'float',
            '_markup_rate' => 'float'
        ];
        
        foreach ($fields as $field => $type) {
            if (isset($_POST[$field])) {
                $value = $type === 'float' ? floatval($_POST[$field]) : sanitize_text_field($_POST[$field]);
                update_post_meta($product_id, $field, $value);
            }
        }
        
        // Save calculated price to database
        $this->update_cost_data_in_db($product_id);
    }
    
    public function calculate_dynamic_price($price, $product) {
        // Only calculate if no price is set manually
        if ($price > 0) {
            return $price;
        }
        
        $calculated_price = $this->calculate_price($product->get_id());
        return $calculated_price > 0 ? $calculated_price : $price;
    }
    
    public function calculate_price($product_id) {
        $cost_data = $this->get_cost_data($product_id);
        
        if (empty($cost_data)) {
            return 0;
        }
        
        // Calculate total cost
        $total_cost = $cost_data['supplier_cost'] 
                    + $cost_data['shipping_cost'] 
                    + $cost_data['packaging_cost'] 
                    + $cost_data['other_costs'];
        
        // Add commission if selling on marketplace
        if ($cost_data['commission_rate'] > 0) {
            $commission = $total_cost * ($cost_data['commission_rate'] / 100);
            $total_cost += $commission;
        }
        
        // Add tax
        if ($cost_data['tax_rate'] > 0) {
            $tax = $total_cost * ($cost_data['tax_rate'] / 100);
            $total_cost += $tax;
        }
        
        // Apply markup
        if ($cost_data['markup_rate'] > 0) {
            $total_cost = $total_cost * (1 + ($cost_data['markup_rate'] / 100));
        }
        
        return round($total_cost, 2);
    }
    
    public function update_cost_data($product_id, $product_data) {
        global $wpdb;
        
        $cost_data = [
            'product_id' => $product_id,
            'supplier_cost' => $product_data['cost_price'],
            'markup_rate' => $product_data['markup'],
            'calculated_price' => $product_data['regular_price']
        ];
        
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT cost_id FROM {$this->table_name} WHERE product_id = %d",
            $product_id
        ));
        
        if ($existing) {
            $wpdb->update(
                $this->table_name,
                $cost_data,
                ['cost_id' => $existing->cost_id]
            );
        } else {
            $wpdb->insert(
                $this->table_name,
                $cost_data
            );
        }
        
        // Update post meta
        update_post_meta($product_id, '_supplier_cost', $product_data['cost_price']);
        update_post_meta($product_id, '_markup_rate', $product_data['markup']);
    }
    
    private function update_cost_data_in_db($product_id) {
        global $wpdb;
        
        $cost_data = [
            'product_id' => $product_id,
            'supplier_cost' => get_post_meta($product_id, '_supplier_cost', true) ?: 0,
            'shipping_cost' => get_post_meta($product_id, '_shipping_cost', true) ?: 0,
            'packaging_cost' => get_post_meta($product_id, '_packaging_cost', true) ?: 0,
            'commission_rate' => get_post_meta($product_id, '_commission_rate', true) ?: 0,
            'tax_rate' => get_post_meta($product_id, '_tax_rate', true) ?: 0,
            'other_costs' => get_post_meta($product_id, '_other_costs', true) ?: 0,
            'markup_rate' => get_post_meta($product_id, '_markup_rate', true) ?: 0,
            'calculated_price' => $this->calculate_price($product_id)
        ];
        
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT cost_id FROM {$this->table_name} WHERE product_id = %d",
            $product_id
        ));
        
        if ($existing) {
            $wpdb->update(
                $this->table_name,
                $cost_data,
                ['cost_id' => $existing->cost_id]
            );
        } else {
            $wpdb->insert(
                $this->table_name,
                $cost_data
            );
        }
    }
    
    private function get_cost_data($product_id) {
        global $wpdb;
        
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE product_id = %d",
            $product_id
        ), ARRAY_A);
        
        if (!$data) {
            $data = [
                'supplier_cost' => get_post_meta($product_id, '_supplier_cost', true) ?: 0,
                'shipping_cost' => get_post_meta($product_id, '_shipping_cost', true) ?: 0,
                'packaging_cost' => get_post_meta($product_id, '_packaging_cost', true) ?: 0,
                'commission_rate' => get_post_meta($product_id, '_commission_rate', true) ?: 0,
                'tax_rate' => get_post_meta($product_id, '_tax_rate', true) ?: 0,
                'other_costs' => get_post_meta($product_id, '_other_costs', true) ?: 0,
                'markup_rate' => get_post_meta($product_id, '_markup_rate', true) ?: 0
            ];
        }
        
        return $data;
    }
    
    public function ajax_calculate_price() {
        check_ajax_referer('mmp-calculate-price', 'security');
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        
        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product ID', 'multi-marketplace-manager')]);
        }
        
        // Update meta values if provided
        $fields = [
            '_supplier_cost',
            '_shipping_cost',
            '_packaging_cost',
            '_commission_rate',
            '_tax_rate',
            '_other_costs',
            '_markup_rate'
        ];
        
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $value = floatval($_POST[$field]);
                update_post_meta($product_id, $field, $value);
            }
        }
        
        // Calculate new price
        $price = $this->calculate_price($product_id);
        
        wp_send_json_success([
            'price' => $price,
            'formatted_price' => wc_price($price)
        ]);
    }
}
