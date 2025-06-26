<?php
namespace WP_Cognito_Sync;

class Plugin {
    private $admin;
    private $api;
    private $user;

    public function init() {
        try {
            $this->admin = new Admin();
            $this->api = new API();
            $this->user = new User();

            // Existing hooks
            add_action('user_register', array($this->api, 'sync_user_create'), 10, 1);
            add_action('profile_update', array($this->api, 'sync_user_update'), 10, 2);
            add_action('delete_user', array($this->api, 'sync_user_delete'), 10, 1);

            // Login hook
            add_action('wp_login', array($this->user, 'handle_user_login'), 10, 2);
        } catch (Exception $e) {
            error_log('WP Cognito Sync Plugin initialization error: ' . $e->getMessage());
        }
    }
}
