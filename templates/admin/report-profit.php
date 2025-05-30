<div class="wrap">
    <h1><?php _e('Profit Report', 'multi-marketplace-manager'); ?></h1>
    
    <div class="mmp-report-filters">
        <form id="mmp-profit-report-form">
            <select name="date_range" id="mmp-date-range">
                <option value="day"><?php _e('Today', 'multi-marketplace-manager'); ?></option>
                <option value="week"><?php _e('Last 7 Days', 'multi-marketplace-manager'); ?></option>
                <option value="month" selected><?php _e('Last 30 Days', 'multi-marketplace-manager'); ?></option>
                <option value="year"><?php _e('Last Year', 'multi-marketplace-manager'); ?></option>
                <option value="custom"><?php _e('Custom Range', 'multi-marketplace-manager'); ?></option>
            </select>
            
            <div id="mmp-custom-date-range" style="display: none;">
                <input type="text" name="start_date" class="mmp-datepicker" placeholder="<?php _e('Start Date', 'multi-marketplace-manager'); ?>">
                <input type="text" name="end_date" class="mmp-datepicker" placeholder="<?php _e('End Date', 'multi-marketplace-manager'); ?>">
            </div>
            
            <select name="marketplace_id" id="mmp-marketplace-filter">
                <option value="0"><?php _e('All Marketplaces', 'multi-marketplace-manager'); ?></option>
                <?php foreach ($marketplaces as $marketplace) : ?>
                    <option value="<?php echo $marketplace->marketplace_id; ?>"><?php echo esc_html($marketplace->marketplace_name); ?></option>
                <?php endforeach; ?>
            </select>
            
            <select name="supplier_id" id="mmp-supplier-filter">
                <option value="0"><?php _e('All Suppliers', 'multi-marketplace-manager'); ?></option>
                <?php foreach ($suppliers as $supplier) : ?>
                    <option value="<?php echo $supplier->supplier_id; ?>"><?php echo esc_html($supplier->supplier_name); ?></option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit" class="button button-primary"><?php _e('Apply Filters', 'multi-marketplace-manager'); ?></button>
        </form>
    </div>
    
    <div class="mmp-report-summary">
        <div class="mmp-summary-card">
            <h3><?php _e('Total Sales', 'multi-marketplace-manager'); ?></h3>
            <div class="mmp-summary-value" id="mmp-total-sales">-</div>
        </div>
        
        <div class="mmp-summary-card">
            <h3><?php _e('Total Cost', 'multi-marketplace-manager'); ?></h3>
            <div class="mmp-summary-value" id="mmp-total-cost">-</div>
        </div>
        
        <div class="mmp-summary-card">
            <h3><?php _e('Total Profit', 'multi-marketplace-manager'); ?></h3>
            <div class="mmp-summary-value" id="mmp-total-profit">-</div>
        </div>
        
        <div class="mmp-summary-card">
            <h3><?php _e('Profit Margin', 'multi-marketplace-manager'); ?></h3>
            <div class="mmp-summary-value" id="mmp-profit-margin">-</div>
        </div>
        
        <div class="mmp-summary-card">
            <h3><?php _e('Order Count', 'multi-marketplace-manager'); ?></h3>
            <div class="mmp-summary-value" id="mmp-order-count">-</div>
        </div>
    </div>
    
    <table class="wp-list-table widefat fixed striped" id="mmp-profit-report-table">
        <thead>
            <tr>
                <th><?php _e('Order', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Date', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Marketplace', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Status', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Total', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Cost', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Profit', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Margin', 'multi-marketplace-manager'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="8"><?php _e('Loading report data...', 'multi-marketplace-manager'); ?></td>
            </tr>
        </tbody>
    </table>
</div>
