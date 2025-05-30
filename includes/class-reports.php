<?php
if (!defined('ABSPATH')) exit;

class MMP_Reports {
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_mmp_get_profit_report', [$this, 'ajax_get_profit_report']);
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Profit Reports', 'multi-marketplace-manager'),
            __('Profit Reports', 'multi-marketplace-manager'),
            'manage_woocommerce',
            'mmp-reports',
            [$this, 'render_reports_page']
        );
    }
    
    public function render_reports_page() {
        $report_type = isset($_GET['report']) ? sanitize_text_field($_GET['report']) : 'profit';
        
        switch ($report_type) {
            case 'marketplace':
                include MMP_PLUGIN_PATH . 'templates/admin/report-marketplace.php';
                break;
            case 'supplier':
                include MMP_PLUGIN_PATH . 'templates/admin/report-supplier.php';
                break;
            case 'product':
                include MMP_PLUGIN_PATH . 'templates/admin/report-product.php';
                break;
            default:
                include MMP_PLUGIN_PATH . 'templates/admin/report-profit.php';
        }
    }
    
    public function ajax_get_profit_report() {
        check_ajax_referer('mmp-profit-report', 'security');
        
        $date_range = isset($_POST['date_range']) ? sanitize_text_field($_POST['date_range']) : 'month';
        $marketplace_id = isset($_POST['marketplace_id']) ? absint($_POST['marketplace_id']) : 0;
        $supplier_id = isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : 0;
        
        $data = $this->generate_profit_report($date_range, $marketplace_id, $supplier_id);
        
        wp_send_json_success($data);
    }
    
    private function generate_profit_report($date_range, $marketplace_id = 0, $supplier_id = 0) {
        global $wpdb;
        
        $date_where = '';
        $marketplace_where = '';
        $supplier_where = '';
        
        // Set date range
        switch ($date_range) {
            case 'day':
                $date_where = "AND DATE(o.date_created) = CURDATE()";
                break;
            case 'week':
                $date_where = "AND o.date_created >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $date_where = "AND o.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
                break;
            case 'year':
                $date_where = "AND o.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
                break;
            default:
                $date_where = "";
        }
        
        // Set marketplace filter
        if ($marketplace_id > 0) {
            $marketplace_where = $wpdb->prepare("AND om.meta_value = %d", $marketplace_id);
        }
        
        // Set supplier filter
        if ($supplier_id > 0) {
            $supplier_where = $wpdb->prepare("AND pm.meta_key = '_supplier_id' AND pm.meta_value = %d", $supplier_id);
        }
        
        // Get orders data
        $orders = $wpdb->get_results("
            SELECT 
                o.ID as order_id,
                o.date_created,
                o.status,
                MAX(CASE WHEN om.meta_key = '_order_total' THEN om.meta_value ELSE 0 END) as total,
                MAX(CASE WHEN om.meta_key = '_mmp_marketplace_id' THEN om.meta_value ELSE 0 END) as marketplace_id,
                MAX(CASE WHEN om.meta_key = '_mmp_marketplace' THEN om.meta_value ELSE '' END) as marketplace_name
            FROM 
                {$wpdb->posts} o
                INNER JOIN {$wpdb->postmeta} om ON o.ID = om.post_id
                INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON o.ID = oi.order_id
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
                INNER JOIN {$wpdb->postmeta} pm ON oim.meta_value = pm.post_id
            WHERE 
                o.post_type = 'shop_order'
                AND o.post_status IN ('wc-completed', 'wc-processing')
                {$date_where}
                {$marketplace_where}
                {$supplier_where}
            GROUP BY 
                o.ID
            ORDER BY 
                o.date_created DESC
        ");
        
        // Get profit data for each order
        $report_data = [];
        $total_sales = 0;
        $total_cost = 0;
        $total_profit = 0;
        
        foreach ($orders as $order) {
            $order_profit = $this->calculate_order_profit($order->order_id);
            
            $report_data[] = [
                'order_id' => $order->order_id,
                'date' => $order->date_created,
                'status' => $order->status,
                'marketplace' => $order->marketplace_name,
                'total' => $order->total,
                'cost' => $order_profit['total_cost'],
                'profit' => $order_profit['profit'],
                'margin' => $order_profit['margin']
            ];
            
            $total_sales += $order->total;
            $total_cost += $order_profit['total_cost'];
            $total_profit += $order_profit['profit'];
        }
        
        // Calculate totals
        $total_margin = $total_sales > 0 ? ($total_profit / $total_sales) * 100 : 0;
        
        return [
            'orders' => $report_data,
            'totals' => [
                'sales' => $total_sales,
                'cost' => $total_cost,
                'profit' => $total_profit,
                'margin' => round($total_margin, 2)
            ],
            'count' => count($orders)
        ];
    }
    
    private function calculate_order_profit($order_id) {
        global $wpdb;
        
        $order = wc_get_order($order_id);
        $items = $order->get_items();
        
        $total_cost = 0;
        $total_profit = 0;
        
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            $quantity = $item->get_quantity();
            
            $cost = $wpdb->get_var($wpdb->prepare(
                "SELECT supplier_cost FROM {$wpdb->prefix}mmp_product_costs WHERE product_id = %d",
                $product_id
            ));
            
            if (!$cost) {
                $cost = get_post_meta($product_id, '_supplier_cost', true) ?: 0;
            }
            
            $item_cost = $cost * $quantity;
            $item_total = $item->get_total();
            $item_profit = $item_total - $item_cost;
            
            $total_cost += $item_cost;
            $total_profit += $item_profit;
        }
        
        // Add shipping cost if it's not already included in product costs
        $shipping_cost = $order->get_shipping_total();
        $total_cost += $shipping_cost;
        $total_profit -= $shipping_cost;
        
        // Calculate margin
        $order_total = $order->get_total();
        $margin = $order_total > 0 ? ($total_profit / $order_total) * 100 : 0;
        
        return [
            'total_cost' => $total_cost,
            'profit' => $total_profit,
            'margin' => round($margin, 2)
        ];
    }
}
