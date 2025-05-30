jQuery(document).ready(function($) {
    // Supplier Import
    $('.mmp-import-products').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var supplierId = button.data('supplier-id');
        var markup = $('#mmp-markup-' + supplierId).val();
        
        if (!markup || markup <= 0) {
            alert(mmp_admin_params.i18n.markup_required);
            return;
        }
        
        button.prop('disabled', true).text(mmp_admin_params.i18n.importing);
        
        $.post(ajaxurl, {
            action: 'mmp_import_supplier_products',
            supplier_id: supplierId,
            markup: markup,
            security: mmp_admin_params.import_nonce
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
            } else {
                alert(response.data.message);
            }
            
            button.prop('disabled', false).text(mmp_admin_params.i18n.import);
        });
    });
    
    // Marketplace Sync
    $('.mmp-sync-marketplace').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var marketplaceId = button.data('marketplace-id');
        
        button.prop('disabled', true).text(mmp_admin_params.i18n.syncing);
        
        $.post(ajaxurl, {
            action: 'mmp_sync_marketplace',
            marketplace_id: marketplaceId,
            security: mmp_admin_params.sync_nonce
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
                
                // Update last sync time
                button.closest('tr').find('td:nth-child(5)').text(mmp_admin_params.i18n.just_now);
            } else {
                alert(response.data.message);
            }
            
            button.prop('disabled', false).text(mmp_admin_params.i18n.sync);
        });
    });
    
    // Delete Supplier
    $('.mmp-delete-supplier').on('click', function() {
        if (!confirm(mmp_admin_params.i18n.confirm_delete)) {
            return;
        }
        
        var button = $(this);
        var supplierId = button.data('supplier-id');
        
        button.prop('disabled', true);
        
        $.post(ajaxurl, {
            action: 'mmp_delete_supplier',
            supplier_id: supplierId,
            security: mmp_admin_params.delete_nonce
        }, function(response) {
            if (response.success) {
                window.location.href = response.data.redirect;
            } else {
                alert(response.data.message);
                button.prop('disabled', false);
            }
        });
    });
    
    // Profit Report
    $('#mmp-profit-report-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var data = form.serialize();
        
        $.post(ajaxurl, {
            action: 'mmp_get_profit_report',
            data: data,
            security: mmp_admin_params.report_nonce
        }, function(response) {
            if (response.success) {
                updateProfitReport(response.data);
            } else {
                alert(response.data.message);
            }
        });
    });
    
    function updateProfitReport(data) {
        // Update summary cards
        $('#mmp-total-sales').text(data.totals.sales.toFixed(2));
        $('#mmp-total-cost').text(data.totals.cost.toFixed(2));
        $('#mmp-total-profit').text(data.totals.profit.toFixed(2));
        $('#mmp-profit-margin').text(data.totals.margin + '%');
        $('#mmp-order-count').text(data.count);
        
        // Update table
        var tbody = $('#mmp-profit-report-table tbody');
        tbody.empty();
        
        if (data.orders.length === 0) {
            tbody.append('<tr><td colspan="8">' + mmp_admin_params.i18n.no_orders + '</td></tr>');
            return;
        }
        
        $.each(data.orders, function(index, order) {
            var row = $('<tr>');
            
            row.append($('<td>').html('<a href="' + order.edit_link + '">#' + order.order_id + '</a>'));
            row.append($('<td>').text(order.date));
            row.append($('<td>').text(order.marketplace));
            row.append($('<td>').text(order.status));
            row.append($('<td>').text(order.total.toFixed(2)));
            row.append($('<td>').text(order.cost.toFixed(2)));
            row.append($('<td>').text(order.profit.toFixed(2)));
            row.append($('<td>').text(order.margin + '%'));
            
            tbody.append(row);
        });
    }
    
    // Date Range Toggle
    $('#mmp-date-range').on('change', function() {
        if ($(this).val() === 'custom') {
            $('#mmp-custom-date-range').show();
        } else {
            $('#mmp-custom-date-range').hide();
        }
    });
    
    // Datepicker
    $('.mmp-datepicker').datepicker({
        dateFormat: 'yy-mm-dd'
    });
    
    // Trigger initial report load
    $('#mmp-profit-report-form').trigger('submit');
});
