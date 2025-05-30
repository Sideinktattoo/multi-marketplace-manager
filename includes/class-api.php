<?php
if (!defined('ABSPATH')) exit;

class MMP_API {
    protected $api_key;
    protected $api_secret;
    protected $base_url;
    
    public function __construct($api_key, $api_secret, $base_url) {
        $this->api_key = $api_key;
        $this->api_secret = $api_secret;
        $this->base_url = $base_url;
    }
    
    protected function request($endpoint, $method = 'GET', $data = []) {
        $url = $this->base_url . $endpoint;
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->api_key . ':' . $this->api_secret),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'timeout' => 30,
            'sslverify' => false
        ];
        
        if (!empty($data)) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', __('Invalid JSON response', 'multi-marketplace-manager'), $body);
        }
        
        if (wp_remote_retrieve_response_code($response) >= 400) {
            $error_message = $decoded['message'] ?? __('API request failed', 'multi-marketplace-manager');
            return new WP_Error('api_error', $error_message, $decoded);
        }
        
        return $decoded;
    }
}

class MMP_Trendyol_API extends MMP_API {
    private $supplier_id;
    private $seller_id;
    
    public function __construct($api_key, $api_secret, $supplier_id, $seller_id) {
        parent::__construct($api_key, $api_secret, 'https://api.trendyol.com/sapigw/');
        $this->supplier_id = $supplier_id;
        $this->seller_id = $seller_id;
    }
    
    public function get_products($page = 0, $size = 50) {
        $endpoint = "suppliers/{$this->supplier_id}/products?page=$page&size=$size";
        return $this->request($endpoint);
    }
    
    public function batch_update_products($products) {
        $endpoint = "suppliers/{$this->supplier_id}/v2/products";
        return $this->request($endpoint, 'POST', ['items' => $products]);
    }
    
    public function get_orders($page = 0, $size = 50, $status = 'Created') {
        $endpoint = "suppliers/{$this->supplier_id}/orders?status=$status&page=$page&size=$size";
        return $this->request($endpoint);
    }
    
    public function update_order_status($order_id, $status) {
        $endpoint = "suppliers/{$this->supplier_id}/orders/$order_id/status";
        return $this->request($endpoint, 'PUT', ['status' => $status]);
    }
    
    public function update_tracking($order_id, $tracking_number, $shipping_company) {
        $endpoint = "suppliers/{$this->supplier_id}/orders/$order_id/cargo";
        return $this->request($endpoint, 'PUT', [
            'trackingNumber' => $tracking_number,
            'shippingCompany' => $shipping_company
        ]);
    }
}

class MMP_Hepsiburada_API extends MMP_API {
    private $merchant_id;
    
    public function __construct($api_key, $api_secret, $merchant_id) {
        parent::__construct($api_key, $api_secret, 'https://mpop-sit.hepsiburada.com/');
        $this->merchant_id = $merchant_id;
    }
    
    public function get_products($page = 0, $size = 50) {
        $endpoint = "product/api/products/get-merchant-products?page=$page&size=$size";
        return $this->request($endpoint);
    }
    
    public function batch_update_products($products) {
        $endpoint = "product/api/products/import";
        return $this->request($endpoint, 'POST', $products);
    }
    
    public function get_orders($page = 0, $size = 50, $status = 'Approved') {
        $endpoint = "order/merchantid/{$this->merchant_id}?status=$status&page=$page&size=$size";
        return $this->request($endpoint);
    }
    
    public function update_order_status($order_id, $status) {
        $endpoint = "order/update-status";
        return $this->request($endpoint, 'POST', [
            'orderId' => $order_id,
            'status' => $status
        ]);
    }
    
    public function update_tracking($order_id, $tracking_number, $shipping_company) {
        $endpoint = "order/update-tracking-number";
        return $this->request($endpoint, 'POST', [
            'orderId' => $order_id,
            'trackingNumber' => $tracking_number,
            'shippingCompany' => $shipping_company
        ]);
    }
}
