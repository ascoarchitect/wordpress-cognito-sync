<?php
namespace WP_Cognito_Sync;

class API {
    const LOG_OPTION = 'wp_cognito_sync_logs';
    const MAX_LOGS = 100;

    private function log_message($message, $level = 'info') {
        $logs = get_option(self::LOG_OPTION, []);
        
        // Add new log entry
        array_unshift($logs, [
            'timestamp' => current_time('mysql'),
            'message' => $message,
            'level' => $level
        ]);
        
        // Keep only the last X logs
        $logs = array_slice($logs, 0, self::MAX_LOGS);
        
        update_option(self::LOG_OPTION, $logs);
    }

    private function send_to_lambda($action, $user_data) {
        $api_url = rtrim(get_option('wp_cognito_sync_api_url'), '/') . '/sync';
        $api_key = get_option('wp_cognito_sync_api_key');

        if (empty($api_url) || empty($api_key)) {
            $this->log_message('API configuration missing', 'error');
            return false;
        }

        $payload = json_encode([
            'action' => $action,
            'user' => array_filter($user_data)
        ]);

        $this->log_message("Sending request to Lambda: {$action} - " . json_encode($user_data));

        $response = wp_remote_post($api_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key
            ],
            'body' => $payload,
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            $this->log_message('Request error: ' . $response->get_error_message(), 'error');
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);

        if ($http_code !== 200) {
            $this->log_message("API error: Status {$http_code} - {$body}", 'error');
            return false;
        }

        try {
            $decoded_response = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log_message('Invalid JSON response: ' . json_last_error_msg(), 'error');
                return false;
            }

            // Store Cognito User ID if present in response
            if (isset($decoded_response['result']['User']['Username'])) {
                $cognito_user_id = $decoded_response['result']['User']['Username'];
                update_user_meta($user_data['wp_user_id'], 'cognito_user_id', $cognito_user_id);
                $this->log_message("Updated Cognito User ID: {$cognito_user_id} for WordPress user: {$user_data['wp_user_id']}");
            }

            return $decoded_response;
        } catch (\Exception $e) {
            $this->log_message('Failed to decode response: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    public function sync_user_create($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            $this->log_message("Unable to get user data for ID {$user_id}", 'error');
            return false;
        }

        $user_data = [
            'wp_user_id' => $user_id,
            'email' => $user->user_email,
            'username' => $user->user_login,
            'firstName' => get_user_meta($user_id, 'first_name', true),
            'lastName' => get_user_meta($user_id, 'last_name', true),
            'wp_memberrank' => get_user_meta($user_id, 'wpuef_cid_c6', true),
            'wp_membercategory' => get_user_meta($user_id, 'wpuef_cid_c10', true)
        ];

        $this->log_message("Creating user in Cognito: {$user->user_email}");
        $result = $this->send_to_lambda('create', $user_data);

        if ($result) {
            $this->sync_user_groups($user_id);
        }

        return $result;
    }

    // Modify existing sync_user_update to include group sync
    public function sync_user_update($user_id, $old_user_data) {
        $user = get_userdata($user_id);
        if (!$user) {
            $this->log_message("Unable to get user data for ID {$user_id}", 'error');
            return false;
        }

        $cognito_user_id = get_user_meta($user_id, 'cognito_user_id', true);
        $user_data = [
            'wp_user_id' => $user_id,
            'email' => $user->user_email,
            'username' => $user->user_login,
            'firstName' => get_user_meta($user_id, 'first_name', true),
            'lastName' => get_user_meta($user_id, 'last_name', true),
            'wp_memberrank' => get_user_meta($user_id, 'wpuef_cid_c6', true),
            'wp_membercategory' => get_user_meta($user_id, 'wpuef_cid_c10', true),
            'cognito_user_id' => $cognito_user_id
        ];

        // If no Cognito ID exists, create the user instead
        if (empty($cognito_user_id)) {
            $this->log_message("No Cognito ID found for user {$user_id}, creating new Cognito user");
            return $this->sync_user_create($user_id);
        }

        $this->log_message("Updating user in Cognito: {$user->user_email}");
        $result = $this->send_to_lambda('update', $user_data);

        if ($result) {
            $this->sync_user_groups($user_id);
        }

        return $result;
    }

    public function sync_user_delete($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            $this->log_message("Unable to get user data for ID {$user_id}", 'error');
            return false;
        }

        $cognito_user_id = get_user_meta($user_id, 'cognito_user_id', true);
        if (empty($cognito_user_id)) {
            $this->log_message("No Cognito ID found for user {$user_id}, skipping delete");
            return false;
        }

        $user_data = [
            'wp_user_id' => $user_id,
            'email' => $user->user_email,
            'username' => $user->user_login,
            'cognito_user_id' => $cognito_user_id
        ];

        $this->log_message("Deleting user from Cognito: {$user->user_email}");
        return $this->send_to_lambda('delete', $user_data);
    }

    public function get_logs($limit = 50) {
        return array_slice(get_option(self::LOG_OPTION, []), 0, $limit);
    }

    public function clear_logs() {
        update_option(self::LOG_OPTION, []);
    }

    public function sync_group_create($group_name) {
        $this->log_message("Creating group in Cognito: WP_{$group_name}");
    
        // Note: structure matches what the Lambda expects
        $data = [
            'action' => 'create_group',
            'group' => [
                'name' => "WP_{$group_name}",
                'description' => "WordPress role: {$group_name}"
            ]
        ];
    
        $response = wp_remote_post(rtrim(get_option('wp_cognito_sync_api_url'), '/') . '/sync', [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => get_option('wp_cognito_sync_api_key')
            ],
            'body' => json_encode($data),
            'timeout' => 15
        ]);
    
        if (is_wp_error($response)) {
            $this->log_message('Group creation error: ' . $response->get_error_message(), 'error');
            return false;
        }
    
        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
    
        if ($http_code !== 200) {
            $this->log_message("Group creation API error: Status {$http_code} - {$body}", 'error');
            return false;
        }
    
        try {
            $decoded_response = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log_message('Invalid JSON response from group creation: ' . json_last_error_msg(), 'error');
                return false;
            }
            return $decoded_response;
        } catch (\Exception $e) {
            $this->log_message('Failed to decode group creation response: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    public function sync_group_membership($user_id, $group_name, $action = 'add') {
        $cognito_id = get_user_meta($user_id, 'cognito_user_id', true);
        if (empty($cognito_id)) {
            $this->log_message("No Cognito ID found for user {$user_id}, skipping group sync", 'error');
            return false;
        }
    
        $this->log_message("Syncing group membership for user {$user_id} in group WP_{$group_name}: {$action}");
    
        $data = [
            'action' => 'update_group_membership',
            'group' => [
                'name' => "WP_{$group_name}",
                'user_id' => $cognito_id,
                'operation' => $action // 'add' or 'remove'
            ]
        ];
    
        $response = wp_remote_post(rtrim(get_option('wp_cognito_sync_api_url'), '/') . '/sync', [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => get_option('wp_cognito_sync_api_key')
            ],
            'body' => json_encode($data),
            'timeout' => 15
        ]);
    
        if (is_wp_error($response)) {
            $this->log_message('Group membership sync error: ' . $response->get_error_message(), 'error');
            return false;
        }
    
        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
    
        if ($http_code !== 200) {
            $this->log_message("Group membership sync API error: Status {$http_code} - {$body}", 'error');
            return false;
        }
    
        try {
            $decoded_response = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log_message('Invalid JSON response from group membership sync: ' . json_last_error_msg(), 'error');
                return false;
            }
            return $decoded_response;
        } catch (\Exception $e) {
            $this->log_message('Failed to decode group membership sync response: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    public function sync_user_groups($user_id) {
        $synced_groups = get_option('wp_cognito_sync_groups', array());
        $user = get_user_by('id', $user_id);
        
        if (!$user) {
            $this->log_message("Unable to find user {$user_id} for group sync", 'error');
            return false;
        }
    
        $success = true;
        foreach ($synced_groups as $group_name) {
            if (in_array($group_name, $user->roles)) {
                if (!$this->sync_group_membership($user_id, $group_name, 'add')) {
                    $success = false;
                }
            } else {
                if (!$this->sync_group_membership($user_id, $group_name, 'remove')) {
                    $success = false;
                }
            }
        }
    
        return $success;
    }
}