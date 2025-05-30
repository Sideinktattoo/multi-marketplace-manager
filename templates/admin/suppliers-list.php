<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('Suppliers', 'multi-marketplace-manager'); ?></h1>
    <a href="<?php echo admin_url('admin.php?page=mmp-suppliers&action=add'); ?>" class="page-title-action"><?php _e('Add New', 'multi-marketplace-manager'); ?></a>
    
    <hr class="wp-header-end">
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('ID', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Supplier Name', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('XML URL', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Default Markup', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Auto Sync', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Actions', 'multi-marketplace-manager'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($suppliers)) : ?>
                <tr>
                    <td colspan="6"><?php _e('No suppliers found.', 'multi-marketplace-manager'); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ($suppliers as $supplier) : ?>
                    <tr>
                        <td><?php echo $supplier->supplier_id; ?></td>
                        <td><?php echo esc_html($supplier->supplier_name); ?></td>
                        <td><?php echo esc_html($supplier->xml_url); ?></td>
                        <td><?php echo $supplier->default_markup; ?>%</td>
                        <td><?php echo $supplier->auto_sync ? __('Yes', 'multi-marketplace-manager') : __('No', 'multi-marketplace-manager'); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=mmp-suppliers&action=edit&supplier_id=' . $supplier->supplier_id); ?>" class="button"><?php _e('Edit', 'multi-marketplace-manager'); ?></a>
                            <a href="<?php echo admin_url('admin.php?page=mmp-suppliers&action=import&supplier_id=' . $supplier->supplier_id); ?>" class="button"><?php _e('Import Products', 'multi-marketplace-manager'); ?></a>
                            <button class="button button-danger mmp-delete-supplier" data-supplier-id="<?php echo $supplier->supplier_id; ?>"><?php _e('Delete', 'multi-marketplace-manager'); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
