<?php
namespace WP_Cognito_Sync;

class User {
    private $api;

    public function __construct() {
        $this->api = new API();
        add_action('show_user_profile', array($this, 'add_cognito_id_field'));
        add_action('edit_user_profile', array($this, 'add_cognito_id_field'));
    }

    public function handle_user_login($user_login, $user) {
        error_log("WP Cognito Sync: Processing login for user {$user->ID}");

        // Check if login sync is enabled
        if (!get_option('wp_cognito_sync_login_create', false)) {
            error_log("WP Cognito Sync: Login sync disabled, skipping");
            return;
        }

        // Get Cognito ID
        $cognito_id = get_user_meta($user->ID, 'cognito_user_id', true);

        // If no Cognito ID exists and login create is enabled, create the user
        if (empty($cognito_id)) {
            error_log("WP Cognito Sync: No Cognito ID found, creating user");
            $this->api->sync_user_create($user->ID);
            return;
        }

        // Otherwise, perform a sync check
        $this->check_and_sync_user($user->ID);
    }

    private function check_and_sync_user($user_id) {
        error_log("WP Cognito Sync: Checking user sync status for user {$user_id}");
        
        $user = get_userdata($user_id);
        if (!$user) {
            error_log("WP Cognito Sync: Unable to get user data for ID {$user_id}");
            return;
        }

        // Get current WordPress data
        $wp_data = [
            'email' => $user->user_email,
            'firstName' => get_user_meta($user_id, 'first_name', true),
            'lastName' => get_user_meta($user_id, 'last_name', true),
            'wp_user_id' => $user_id
        ];

        // Send update request to ensure Cognito is in sync
        $this->api->sync_user_update($user_id, null);
    }

    public function add_cognito_id_field($user) {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }

        $cognito_id = get_user_meta($user->ID, 'cognito_user_id', true);
        ?>
        <h3><?php _e('Cognito Integration', 'wp-cognito-sync'); ?></h3>
        <table class="form-table">
            <tr>
                <th>
                    <label for="cognito_user_id"><?php _e('Cognito User ID', 'wp-cognito-sync'); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="cognito_user_id" 
                           id="cognito_user_id" 
                           value="<?php echo esc_attr($cognito_id); ?>" 
                           class="regular-text" 
                           readonly>
                    <p class="description">
                        <?php 
                        if (empty($cognito_id)) {
                            _e('No Cognito account linked', 'wp-cognito-sync');
                        } else {
                            _e('This user is linked to a Cognito account', 'wp-cognito-sync');
                        }
                        ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }
}