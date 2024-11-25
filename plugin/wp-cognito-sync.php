<?php
/**
 * Plugin Name: WordPress Cognito Sync
 * Plugin URI: https://mymarine.app
 * Description: Automatically synchronizes WordPress user and group operations with Amazon Cognito User Pools using AWS API Gateway and Lambda. Supports full and test synchronization options, detailed logs, and automatic account creation on user login.
 * Version: 1.4.0
 * Author: Adam Scott
 * Text Domain: wp-cognito-sync
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_COGNITO_SYNC_VERSION', '1.0.0');
define('WP_COGNITO_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_COGNITO_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'WP_Cognito_Sync\\';
    $base_dir = WP_COGNITO_SYNC_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Initialize the plugin
function wp_cognito_sync_init() {
    $plugin = new WP_Cognito_Sync\Plugin();
    $plugin->init();
}
add_action('plugins_loaded', 'wp_cognito_sync_init');

// Activation hook
register_activation_hook(__FILE__, function() {
    add_option('wp_cognito_sync_api_url', '');
    add_option('wp_cognito_sync_api_key', '');
    add_option('wp_cognito_sync_logs', []);
    add_option('wp_cognito_sync_login_create', false);
    add_option('wp_cognito_sync_groups', array());
});