<?php
namespace WP_Cognito_Sync;

class Admin {
    private $api;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_test_sync', array($this, 'handle_test_sync'));
        add_action('admin_post_full_sync', array($this, 'handle_full_sync'));
        add_action('admin_post_test_group_sync', array($this, 'handle_test_group_sync'));
        add_action('admin_post_full_group_sync', array($this, 'handle_full_group_sync'));
        add_action('admin_post_clear_sync_results', array($this, 'handle_clear_sync_results'));
        $this->api = new API();
    }

    public function add_admin_menu() {
        add_options_page(
            __('WordPress Cognito Sync Settings', 'wp-cognito-sync'),
            __('Cognito Sync', 'wp-cognito-sync'),
            'manage_options',
            'wp-cognito-sync',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('wp_cognito_sync', 'wp_cognito_sync_api_url');
        register_setting('wp_cognito_sync', 'wp_cognito_sync_api_key');
        register_setting('wp_cognito_sync', 'wp_cognito_sync_login_create');
        register_setting('wp_cognito_sync', 'wp_cognito_sync_groups', array(
            'type' => 'array',
            'default' => array(),
            'sanitize_callback' => array($this, 'sanitize_groups_option')
        ));

        if (isset($_POST['clear_logs']) && check_admin_referer('clear_logs')) {
            $this->api->clear_logs();
            wp_redirect(add_query_arg(['page' => 'wp-cognito-sync', 'logs-cleared' => '1'], admin_url('options-general.php')));
            exit;
        }

        if (isset($_POST['action']) && $_POST['action'] === 'toggle_group_sync' && check_admin_referer('cognito_group_sync')) {
            $group_id = intval($_POST['group_id']);
            $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
            $this->toggle_group_sync($group_id, $enabled);
        }
    }

    public function sanitize_groups_option($value) {
        if (!is_array($value)) {
            return array();
        }
        return array_values(array_filter($value));
    }

    public function handle_clear_sync_results() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('clear_sync_results');

        delete_transient('cognito_sync_results');
        delete_transient('cognito_sync_test_results');
        delete_transient('cognito_sync_progress');
        delete_transient('cognito_sync_group_results');
        delete_transient('cognito_sync_group_test_results');

        wp_redirect(add_query_arg(
            array(
                'page' => 'wp-cognito-sync',
                'tab' => 'sync',
                'results-cleared' => '1'
            ),
            admin_url('options-general.php')
        ));
        exit;
    }

    private function toggle_group_sync($group_id, $enabled) {
        $group_name = sanitize_text_field($_POST['group_id']);
        $synced_groups = get_option('wp_cognito_sync_groups', array());
        
        if (!is_array($synced_groups)) {
            $synced_groups = array();
        }
        
        if ($enabled) {
            if (!in_array($group_name, $synced_groups)) {
                $synced_groups[] = $group_name;
                $this->api->sync_group_create($group_name);
            }
        } else {
            $synced_groups = array_diff($synced_groups, array($group_name));
        }
        
        $synced_groups = array_values(array_filter($synced_groups));
        update_option('wp_cognito_sync_groups', $synced_groups);
        
        wp_redirect(add_query_arg(
            array(
                'page' => 'wp-cognito-sync',
                'tab' => 'groups',
                'updated' => '1'
            ), 
            admin_url('options-general.php')
        ));
        exit;
    }

    private function get_all_users($offset = 0, $limit = 50) {
        $args = array(
            'number' => $limit,
            'offset' => $offset,
            'fields' => 'all'
        );
        return get_users($args);
    }

    public function handle_test_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $stats = array(
            'total' => 0,
            'to_create' => 0,
            'to_update' => 0,
            'users' => array(),
            'timestamp' => current_time('mysql')
        );

        $offset = 0;
        $limit = 50;

        do {
            $users = $this->get_all_users($offset, $limit);
            
            foreach ($users as $user) {
                $stats['total']++;
                
                $cognito_id = get_user_meta($user->ID, 'cognito_user_id', true);
                if (empty($cognito_id)) {
                    $stats['to_create']++;
                    $stats['users'][] = array(
                        'id' => $user->ID,
                        'email' => $user->user_email,
                        'action' => 'create'
                    );
                } else {
                    $stats['to_update']++;
                    $stats['users'][] = array(
                        'id' => $user->ID,
                        'email' => $user->user_email,
                        'action' => 'update'
                    );
                }
            }
            
            $offset += $limit;
        } while (count($users) === $limit);

        set_transient('cognito_sync_test_results', $stats, HOUR_IN_SECONDS);
        
        wp_redirect(add_query_arg(
            array(
                'page' => 'wp-cognito-sync',
                'tab' => 'sync',
                'test-complete' => '1'
            ), 
            admin_url('options-general.php')
        ));
        exit;
    }

    public function handle_full_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $stats = array(
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => array(),
            'timestamp' => current_time('mysql')
        );

        $offset = 0;
        $limit = 20;

        do {
            $users = $this->get_all_users($offset, $limit);
            
            foreach ($users as $user) {
                $stats['total']++;
                
                try {
                    $cognito_id = get_user_meta($user->ID, 'cognito_user_id', true);
                    if (empty($cognito_id)) {
                        $result = $this->api->sync_user_create($user->ID);
                        if ($result) {
                            $stats['created']++;
                        } else {
                            $stats['failed']++;
                        }
                    } else {
                        $result = $this->api->sync_user_update($user->ID, null);
                        if ($result) {
                            $stats['updated']++;
                        } else {
                            $stats['failed']++;
                        }
                    }
                } catch (\Exception $e) {
                    $stats['failed']++;
                    $stats['errors'][] = sprintf(
                        'Error processing user %s (%d): %s',
                        $user->user_email,
                        $user->ID,
                        $e->getMessage()
                    );
                }

                $stats['progress'] = ($offset + $stats['total']) / (get_user_count());
                set_transient('cognito_sync_progress', $stats, HOUR_IN_SECONDS);
            }
            
            $offset += $limit;
        } while (count($users) === $limit);

        set_transient('cognito_sync_results', $stats, HOUR_IN_SECONDS);
        
        wp_redirect(add_query_arg(
            array(
                'page' => 'wp-cognito-sync',
                'tab' => 'sync',
                'sync-complete' => '1'
            ), 
            admin_url('options-general.php')
        ));
        exit;
    }

    public function handle_test_group_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $stats = array(
            'total' => 0,
            'to_create' => 0,
            'memberships_to_update' => 0,
            'groups' => array(),
            'timestamp' => current_time('mysql')
        );

        $synced_groups = get_option('wp_cognito_sync_groups', array());
        foreach ($synced_groups as $group_name) {
            $stats['total']++;
            $users_in_role = get_users(['role' => $group_name]);
            $member_count = count($users_in_role);

            $stats['groups'][] = array(
                'name' => "WP_{$group_name}",
                'action' => 'create',
                'members_to_update' => $member_count
            );

            $stats['to_create']++;
            $stats['memberships_to_update'] += $member_count;
        }

        set_transient('cognito_sync_group_test_results', $stats, HOUR_IN_SECONDS);

        wp_redirect(add_query_arg(
            array(
                'page' => 'wp-cognito-sync',
                'tab' => 'sync',
                'group-test-complete' => '1'
            ),
            admin_url('options-general.php')
        ));
        exit;
    }

    public function handle_full_group_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $stats = array(
            'total' => 0,
            'created' => 0,
            'memberships_updated' => 0,
            'failed' => 0,
            'errors' => array(),
            'timestamp' => current_time('mysql')
        );

        $synced_groups = get_option('wp_cognito_sync_groups', array());

        foreach ($synced_groups as $group_name) {
            $stats['total']++;

            try {
                $result = $this->api->sync_group_create($group_name);
                if ($result) {
                    $stats['created']++;

                    $users = get_users(['role' => $group_name]);

                    foreach ($users as $user) {
                        if ($this->api->sync_group_membership($user->ID, $group_name, 'add')) {
                            $stats['memberships_updated']++;
                        } else {
                            $stats['failed']++;
                        }
                    }
                } else {
                    $stats['failed']++;
                }
            } catch (\Exception $e) {
                $stats['failed']++;
                $stats['errors'][] = sprintf(
                    'Error processing group %s: %s',
                    $group_name,
                    $e->getMessage()
                );
            }
        }

        set_transient('cognito_sync_group_results', $stats, HOUR_IN_SECONDS);

        wp_redirect(add_query_arg(
            array(
                'page' => 'wp-cognito-sync',
                'tab' => 'sync',
                'group-sync-complete' => '1'
            ),
            admin_url('options-general.php')
        ));
        exit;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php settings_errors('wp_cognito_sync_messages'); ?>

            <h2 class="nav-tab-wrapper">
                <a href="?page=wp-cognito-sync&tab=settings" 
                   class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Settings', 'wp-cognito-sync'); ?>
                </a>
                <a href="?page=wp-cognito-sync&tab=sync" 
                   class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Sync Management', 'wp-cognito-sync'); ?>
                </a>
                <a href="?page=wp-cognito-sync&tab=logs" 
                   class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Logs', 'wp-cognito-sync'); ?>
                </a>
                <a href="?page=wp-cognito-sync&tab=groups" 
                   class="nav-tab <?php echo $active_tab === 'groups' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Group Management', 'wp-cognito-sync'); ?>
                </a>
            </h2>

            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'sync':
                        $this->render_sync_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    case 'groups':
                        $this->render_groups_tab();
                        break;
                    default:
                        $this->render_settings_tab();
                }
                ?>
            </div>
            <!-- Footer Section -->
            <div class="plugin-footer">
                <p>
                    <?php _e('WordPress Cognito Sync Plugin. Made with &#x2661 by Adam Scott.', 'wp-cognito-sync'); ?>
                    <a href="https://github.com/ascoarchitect/wordpress-cognito-sync" target="_blank">
                    <?php _e('https://github.com/ascoarchitect/wordpress-cognito-sync', 'wp-cognito-sync'); ?>
                    </a>
                </p>
            </div>
        </div>

        <style>
            .sync-management-container {
                margin-top: 20px;
            }
            .sync-actions {
                display: flex;
                gap: 20px;
                margin-bottom: 20px;
            }
            .sync-action-box {
                background: #fff;
                padding: 20px;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                flex: 1;
            }
            .progress-bar {
                width: 100%;
                height: 20px;
                background: #f0f0f1;
                border-radius: 10px;
                overflow: hidden;
                margin: 10px 0;
            }
            .progress-bar .progress {
                height: 100%;
                background: #2271b1;
                transition: width 0.3s ease;
            }
            .test-results, .sync-results {
                margin-top: 20px;
            }
            .sync-errors {
                margin-top: 20px;
                padding: 15px;
                background: #fff;
                border-left: 4px solid #dc3232;
            }
            .log-level {
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
            }
            .log-level-error {
                background-color: #dc3545;
                color: white;
            }
            .log-level-info {
                background-color: #17a2b8;
                color: white;
            }
            .logs-container {
                margin-top: 20px;
                max-height: 500px;
                overflow-y: auto;
            }
            .tab-content {
                margin-top: 20px;
            }
            .sync-section {
                margin-bottom: 40px;
                padding: 20px;
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .sync-section h3 {
                margin-top: 0;
            }
            .group-details {
                margin-top: 20px;
            }
            .sync-status {
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
            }
            .sync-status.enabled {
                background-color: #28a745;
                color: white;
            }
            .sync-status.disabled {
                background-color: #dc3545;
                color: white;
            }
            .sync-timestamp {
                color: #666;
                font-style: italic;
                margin-bottom: 15px;
            }
            .sync-actions-header {
                margin-bottom: 20px;
                padding: 10px;
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
            .plugin-footer {
            margin-top: 20px;
            padding: 10px;
            background: #f1f1f1;
            border-top: 1px solid #ccc;
            text-align: left;
            }
        </style>
        <?php
    }

    private function render_sync_tab() {
        $test_results = get_transient('cognito_sync_test_results');
        $sync_results = get_transient('cognito_sync_results');
        $sync_progress = get_transient('cognito_sync_progress');
        
        $group_test_results = get_transient('cognito_sync_group_test_results');
        $group_sync_results = get_transient('cognito_sync_group_results');

        ?>
        <div class="sync-management-container">
            <?php if ($test_results || $sync_results || $group_test_results || $group_sync_results): ?>
                <div class="sync-actions-header">
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('clear_sync_results'); ?>
                        <input type="hidden" name="action" value="clear_sync_results">
                        <button type="submit" class="button">
                            <?php _e('Clear All Results', 'wp-cognito-sync'); ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- User Sync Section -->
            <div class="sync-section">
                <h3><?php _e('User Synchronization', 'wp-cognito-sync'); ?></h3>
                <div class="sync-actions">
                    <div class="sync-action-box">
                        <h3><?php _e('Test Sync', 'wp-cognito-sync'); ?></h3>
                        <p><?php _e('This will analyze your WordPress users and show how many would be created or updated in Cognito.', 'wp-cognito-sync'); ?></p>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <?php wp_nonce_field('cognito_test_sync'); ?>
                            <input type="hidden" name="action" value="test_sync">
                            <?php submit_button(__('Run Test Sync', 'wp-cognito-sync'), 'secondary', 'submit', false); ?>
                        </form>
                    </div>

                    <div class="sync-action-box">
                        <h3><?php _e('Full Sync', 'wp-cognito-sync'); ?></h3>
                        <p><?php _e('This will synchronize all WordPress users with Cognito. This operation cannot be undone.', 'wp-cognito-sync'); ?></p>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <?php wp_nonce_field('cognito_full_sync'); ?>
                            <input type="hidden" name="action" value="full_sync">
                            <?php submit_button(__('Run Full Sync', 'wp-cognito-sync'), 'primary', 'submit', false); ?>
                        </form>
                    </div>
                </div>

                <?php if ($test_results): ?>
                    <div class="test-results">
                        <h3><?php _e('Test Results', 'wp-cognito-sync'); ?></h3>
                        <p class="sync-timestamp">
                            <?php printf(__('Generated on: %s', 'wp-cognito-sync'),
                                wp_date(get_option('date_format') . ' ' . get_option('time_format'),
                                strtotime($test_results['timestamp']))); ?>
                        </p>
                        <table class="widefat">
                            <tbody>
                                <tr>
                                    <th><?php _e('Total Users', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($test_results['total']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php _e('To Be Created', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($test_results['to_create']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php _e('To Be Updated', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($test_results['to_update']); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($sync_progress && !isset($_GET['sync-complete'])): ?>
                    <div class="sync-progress">
                        <h3><?php _e('Sync Progress', 'wp-cognito-sync'); ?></h3>
                        <div class="progress-bar">
                            <div class="progress" style="width: <?php echo esc_attr($sync_progress['progress'] * 100); ?>%"></div>
                        </div>
                        <p><?php printf(__('Processed %d users', 'wp-cognito-sync'), $sync_progress['total']); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($sync_results): ?>
                    <div class="sync-results">
                        <h3><?php _e('Sync Results', 'wp-cognito-sync'); ?></h3>
                        <p class="sync-timestamp">
                            <?php printf(__('Generated on: %s', 'wp-cognito-sync'),
                                wp_date(get_option('date_format') . ' ' . get_option('time_format'),
                                strtotime($sync_results['timestamp']))); ?>
                        </p>
                        <table class="widefat">
                            <tbody>
                                <tr>
                                    <th><?php _e('Total Processed', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($sync_results['total']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php _e('Created', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($sync_results['created']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php _e('Updated', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($sync_results['updated']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php _e('Failed', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($sync_results['failed']); ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <?php if (!empty($sync_results['errors'])): ?>
                            <div class="sync-errors">
                                <h4><?php _e('Errors', 'wp-cognito-sync'); ?></h4>
                                <ul>
                                    <?php foreach ($sync_results['errors'] as $error): ?>
                                        <li><?php echo esc_html($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Group Sync Section -->
            <div class="sync-section">
                <h3><?php _e('Group Synchronization', 'wp-cognito-sync'); ?></h3>
                <div class="sync-actions">
                    <div class="sync-action-box">
                        <h3><?php _e('Test Group Sync', 'wp-cognito-sync'); ?></h3>
                        <p><?php _e('This will analyze your sync-enabled WordPress roles and show which groups need to be created or updated in Cognito.', 'wp-cognito-sync'); ?></p>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <?php wp_nonce_field('cognito_test_group_sync'); ?>
                            <input type="hidden" name="action" value="test_group_sync">
                            <?php submit_button(__('Run Group Test Sync', 'wp-cognito-sync'), 'secondary', 'submit', false); ?>
                        </form>
                    </div>

                    <div class="sync-action-box">
                        <h3><?php _e('Full Group Sync', 'wp-cognito-sync'); ?></h3>
                        <p><?php _e('This will create any missing groups in Cognito and synchronize all group memberships for sync-enabled groups.', 'wp-cognito-sync'); ?></p>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <?php wp_nonce_field('cognito_full_group_sync'); ?>
                            <input type="hidden" name="action" value="full_group_sync">
                            <?php submit_button(__('Run Full Group Sync', 'wp-cognito-sync'), 'primary', 'submit', false); ?>
                        </form>
                    </div>
                </div>

                <?php if ($group_test_results): ?>
                    <div class="test-results">
                        <h3><?php _e('Group Test Results', 'wp-cognito-sync'); ?></h3>
                        <p class="sync-timestamp">
                            <?php printf(__('Generated on: %s', 'wp-cognito-sync'),
                                wp_date(get_option('date_format') . ' ' . get_option('time_format'),
                                strtotime($group_test_results['timestamp']))); ?>
                        </p>
                        <table class="widefat">
                            <tbody>
                                <tr>
                                    <th><?php _e('Total Sync-Enabled Groups', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($group_test_results['total']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php _e('Groups to Create', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($group_test_results['to_create']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php _e('Memberships to Update', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($group_test_results['memberships_to_update']); ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <?php if (!empty($group_test_results['groups'])): ?>
                            <div class="group-details">
                                <h4><?php _e('Group Details', 'wp-cognito-sync'); ?></h4>
                                <table class="widefat">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Group Name', 'wp-cognito-sync'); ?></th>
                                            <th><?php _e('Action Needed', 'wp-cognito-sync'); ?></th>
                                            <th><?php _e('Members to Update', 'wp-cognito-sync'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($group_test_results['groups'] as $group): ?>
                                            <tr>
                                                <td><?php echo esc_html($group['name']); ?></td>
                                                <td><?php echo esc_html($group['action']); ?></td>
                                                <td><?php echo esc_html($group['members_to_update']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($group_sync_results): ?>
                    <div class="sync-results">
                        <h3><?php _e('Group Sync Results', 'wp-cognito-sync'); ?></h3>
                        <p class="sync-timestamp">
                            <?php printf(__('Generated on: %s', 'wp-cognito-sync'),
                                wp_date(get_option('date_format') . ' ' . get_option('time_format'),
                                strtotime($group_sync_results['timestamp']))); ?>
                        </p>
                        <table class="widefat">
                            <tbody>
                                <tr>
                                    <th><?php _e('Groups Processed', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($group_sync_results['total']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php _e('Groups Created', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($group_sync_results['created']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php _e('Memberships Updated', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($group_sync_results['memberships_updated']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php _e('Failed Operations', 'wp-cognito-sync'); ?></th>
                                    <td><?php echo esc_html($group_sync_results['failed']); ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <?php if (!empty($group_sync_results['errors'])): ?>
                            <div class="sync-errors">
                                <h4><?php _e('Errors', 'wp-cognito-sync'); ?></h4>
                                <ul>
                                    <?php foreach ($group_sync_results['errors'] as $error): ?>
                                        <li><?php echo esc_html($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
     }

    private function render_settings_tab() {
        ?>
        <form action="options.php" method="post">
            <?php
            settings_fields('wp_cognito_sync');
            do_settings_sections('wp_cognito_sync');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_sync_api_url"><?php _e('API Gateway URL', 'wp-cognito-sync'); ?></label>
                    </th>
                    <td>
                        <input type="url" name="wp_cognito_sync_api_url"
                               value="<?php echo esc_attr(get_option('wp_cognito_sync_api_url')); ?>"
                               class="regular-text">
                        <p class="description"><?php _e('Enter the API Gateway URL without the /sync endpoint', 'wp-cognito-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="wp_cognito_sync_api_key"><?php _e('API Key', 'wp-cognito-sync'); ?></label>
                    </th>
                    <td>
                        <input type="password" name="wp_cognito_sync_api_key"
                               value="<?php echo esc_attr(get_option('wp_cognito_sync_api_key')); ?>"
                               class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Login Sync Settings', 'wp-cognito-sync'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="wp_cognito_sync_login_create"
                                   value="1"
                                   <?php checked(get_option('wp_cognito_sync_login_create'), 1); ?>>
                            <?php _e('Create Cognito accounts on login', 'wp-cognito-sync'); ?>
                        </label>
                        <p class="description">
                            <?php _e('If enabled, users without a Cognito account will have one created when they log in.', 'wp-cognito-sync'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_logs_tab() {
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('clear_logs'); ?>
            <input type="submit" name="clear_logs" class="button" value="<?php _e('Clear Logs', 'wp-cognito-sync'); ?>">
        </form>

        <div class="logs-container">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php _e('Time', 'wp-cognito-sync'); ?></th>
                        <th><?php _e('Level', 'wp-cognito-sync'); ?></th>
                        <th><?php _e('Message', 'wp-cognito-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->api->get_logs() as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log['timestamp']); ?></td>
                            <td>
                                <span class="log-level log-level-<?php echo esc_attr($log['level']); ?>">
                                    <?php echo esc_html($log['level']); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_groups_tab() {
        $synced_groups = get_option('wp_cognito_sync_groups', array());
        if (!is_array($synced_groups)) {
            $synced_groups = array();
            update_option('wp_cognito_sync_groups', $synced_groups);
        }
        
        $groups = get_editable_roles();
        ?>
        <div class="group-management-container">
            <h2><?php _e('Group Synchronization Management', 'wp-cognito-sync'); ?></h2>
            <p class="description">
                <?php _e('Select which WordPress roles should be synchronized with Cognito groups. Synchronized groups will be prefixed with "WP_" in Cognito.', 'wp-cognito-sync'); ?>
            </p>

            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php _e('WordPress Role', 'wp-cognito-sync'); ?></th>
                        <th><?php _e('Cognito Group Name', 'wp-cognito-sync'); ?></th>
                        <th><?php _e('Sync Status', 'wp-cognito-sync'); ?></th>
                        <th><?php _e('Actions', 'wp-cognito-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $role_name => $role_info): ?>
                        <tr>
                            <td><?php echo esc_html($role_info['name']); ?></td>
                            <td><?php echo 'WP_' . esc_html($role_name); ?></td>
                            <td>
                                <?php 
                                if (in_array($role_name, $synced_groups)) {
                                    echo '<span class="sync-status enabled">Enabled</span>';
                                } else {
                                    echo '<span class="sync-status disabled">Disabled</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                    <?php wp_nonce_field('cognito_group_sync'); ?>
                                    <input type="hidden" name="action" value="toggle_group_sync">
                                    <input type="hidden" name="group_id" value="<?php echo esc_attr($role_name); ?>">
                                    <input type="hidden" name="enabled" value="<?php echo in_array($role_name, $synced_groups) ? '0' : '1'; ?>">
                                    <button type="submit" class="button">
                                        <?php echo in_array($role_name, $synced_groups) ? 
                                            __('Disable Sync', 'wp-cognito-sync') : 
                                            __('Enable Sync', 'wp-cognito-sync'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

}