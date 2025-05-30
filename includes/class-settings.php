<?php
if (!defined('ABSPATH')) exit;

class MMP_Settings {
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('MMP Settings', 'multi-marketplace-manager'),
            __('MMP Settings', 'multi-marketplace-manager'),
            'manage_woocommerce',
            'mmp-settings',
            [$this, 'render_settings_page']
        );
    }
    
    public function register_settings() {
        register_setting('mmp_general_settings', 'mmp_default_markup');
        register_setting('mmp_general_settings', 'mmp_auto_sync');
        register_setting('mmp_general_settings', 'mmp_sync_interval');
        
        register_setting('mmp_cost_settings', 'mmp_default_shipping_cost');
        register_setting('mmp_cost_settings', 'mmp_default_packaging_cost');
        register_setting('mmp_cost_settings', 'mmp_default_commission_rate');
        register_setting('mmp_cost_settings', 'mmp_default_tax_rate');
        register_setting('mmp_cost_settings', 'mmp_default_other_costs');
        
        add_settings_section(
            'mmp_general_section',
            __('General Settings', 'multi-marketplace-manager'),
            [$this, 'render_general_section'],
            'mmp-settings'
        );
        
        add_settings_field(
            'mmp_default_markup',
            __('Default Markup Percentage', 'multi-marketplace-manager'),
            [$this, 'render_default_markup_field'],
            'mmp-settings',
            'mmp_general_section'
        );
        
        add_settings_field(
            'mmp_auto_sync',
            __('Auto Sync Products', 'multi-marketplace-manager'),
            [$this, 'render_auto_sync_field'],
            'mmp-settings',
            'mmp_general_section'
        );
        
        add_settings_field(
            'mmp_sync_interval',
            __('Sync Interval', 'multi-marketplace-manager'),
            [$this, 'render_sync_interval_field'],
            'mmp-settings',
            'mmp_general_section'
        );
        
        add_settings_section(
            'mmp_cost_section',
            __('Default Cost Settings', 'multi-marketplace-manager'),
            [$this, 'render_cost_section'],
            'mmp-settings'
        );
        
        add_settings_field(
            'mmp_default_shipping_cost',
            __('Default Shipping Cost', 'multi-marketplace-manager'),
            [$this, 'render_default_shipping_cost_field'],
            'mmp-settings',
            'mmp_cost_section'
        );
        
        add_settings_field(
            'mmp_default_packaging_cost',
            __('Default Packaging Cost', 'multi-marketplace-manager'),
            [$this, 'render_default_packaging_cost_field'],
            'mmp-settings',
            'mmp_cost_section'
        );
        
        add_settings_field(
            'mmp_default_commission_rate',
            __('Default Commission Rate (%)', 'multi-marketplace-manager'),
            [$this, 'render_default_commission_rate_field'],
            'mmp-settings',
            'mmp_cost_section'
        );
        
        add_settings_field(
            'mmp_default_tax_rate',
            __('Default Tax Rate (%)', 'multi-marketplace-manager'),
            [$this, 'render_default_tax_rate_field'],
            'mmp-settings',
            'mmp_cost_section'
        );
        
        add_settings_field(
            'mmp_default_other_costs',
            __('Default Other Costs', 'multi-marketplace-manager'),
            [$this, 'render_default_other_costs_field'],
            'mmp-settings',
            'mmp_cost_section'
        );
    }
    
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Multi Marketplace Manager Settings', 'multi-marketplace-manager'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('mmp_general_settings');
                do_settings_sections('mmp-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    public function render_general_section() {
        echo '<p>' . __('Configure general settings for the Multi Marketplace Manager plugin.', 'multi-marketplace-manager') . '</p>';
    }
    
    public function render_cost_section() {
        echo '<p>' . __('Set default cost values that will be applied to new products.', 'multi-marketplace-manager') . '</p>';
    }
    
    public function render_default_markup_field() {
        $value = get_option('mmp_default_markup', 30);
        echo '<input type="number" step="0.01" min="0" name="mmp_default_markup" value="' . esc_attr($value) . '" class="small-text" /> %';
    }
    
    public function render_auto_sync_field() {
        $value = get_option('mmp_auto_sync', 1);
        echo '<label><input type="checkbox" name="mmp_auto_sync" value="1" ' . checked(1, $value, false) . ' /> ' . __('Enable automatic product sync', 'multi-marketplace-manager') . '</label>';
    }
    
    public function render_sync_interval_field() {
        $value = get_option('mmp_sync_interval', 'six_hours');
        $options = [
            'hourly' => __('Hourly', 'multi-marketplace-manager'),
            'six_hours' => __('Every 6 Hours', 'multi-marketplace-manager'),
            'twicedaily' => __('Twice Daily', 'multi-marketplace-manager'),
            'daily' => __('Daily', 'multi-marketplace-manager')
        ];
        
        echo '<select name="mmp_sync_interval">';
        foreach ($options as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($key, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }
    
    public function render_default_shipping_cost_field() {
        $value = get_option('mmp_default_shipping_cost', 10);
        echo get_woocommerce_currency_symbol() . ' <input type="number" step="0.01" min="0" name="mmp_default_shipping_cost" value="' . esc_attr($value) . '" class="small-text" />';
    }
    
    public function render_default_packaging_cost_field() {
        $value = get_option('mmp_default_packaging_cost', 5);
        echo get_woocommerce_currency_symbol() . ' <input type="number" step="0.01" min="0" name="mmp_default_packaging_cost" value="' . esc_attr($value) . '" class="small-text" />';
    }
    
    public function render_default_commission_rate_field() {
        $value = get_option('mmp_default_commission_rate', 15);
        echo '<input type="number" step="0.01" min="0" name="mmp_default_commission_rate" value="' . esc_attr($value) . '" class="small-text" /> %';
    }
    
    public function render_default_tax_rate_field() {
        $value = get_option('mmp_default_tax_rate', 18);
        echo '<input type="number" step="0.01" min="0" name="mmp_default_tax_rate" value="' . esc_attr($value) . '" class="small-text" /> %';
    }
    
    public function render_default_other_costs_field() {
        $value = get_option('mmp_default_other_costs', 2);
        echo get_woocommerce_currency_symbol() . ' <input type="number" step="0.01" min="0" name="mmp_default_other_costs" value="' . esc_attr($value) . '" class="small-text" />';
    }
}
