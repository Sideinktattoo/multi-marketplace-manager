<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('Marketplaces', 'multi-marketplace-manager'); ?></h1>
    <a href="<?php echo admin_url('admin.php?page=mmp-marketplaces&action=add'); ?>" class="page-title-action"><?php _e('Add New', 'multi-marketplace-manager'); ?></a>
    
    <hr class="wp-header-end">
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('ID', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Marketplace', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Integration', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Status', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Last Sync', 'multi-marketplace-manager'); ?></th>
                <th><?php _e('Actions', 'multi-marketplace-manager'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($marketplaces)) : ?>
                <tr>
                    <td colspan="6"><?php _e('No marketplaces found.', 'multi-marketplace-manager'); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ($marketplaces as $marketplace) : ?>
                    <tr>
                        <td><?php echo $marketplace->marketplace_id; ?></td>
                        <td><?php echo esc_html($marketplace->marketplace_name); ?></td>
                        <td><?php echo esc_html($integration_options[$marketplace->integration_id] ?? $marketplace->integration_id); ?></td>
                        <td><?php echo $marketplace->is_active ? __('Active', 'multi-marketplace-manager') : __('Inactive', 'multi-marketplace-manager'); ?></td>
                        <td><?php echo $marketplace->last_sync ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($marketplace->last_sync)) : __('Never', 'multi-marketplace-manager'); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=mmp-marketplaces&action=edit&marketplace_id=' . $marketplace->marketplace_id); ?>" class="button"><?php _e('Edit', 'multi-marketplace-manager'); ?></a>
                            <a href="<?php echo admin_url('admin.php?page=mmp-marketplaces&action=sync&marketplace_id=' . $marketplace->marketplace_id); ?>" class="button"><?php _e('Sync Now', 'multi-marketplace-manager'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
