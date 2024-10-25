<?php
if (!defined('ABSPATH')) {
    exit;
}

// Add debug logging function if not already defined
if (!function_exists('frg_log')) {
    function frg_log($message, $data = null) {
        if (WP_DEBUG) {
            error_log('FRG: ' . $message);
            if ($data !== null) {
                error_log('FRG Data: ' . print_r($data, true));
            }
        }
    }
}

// Cache refresh function with enhanced logging
function frg_refresh_cache() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'frg_cache';
    $api_key = get_option('frg_api_key');
    $selected_albums = get_option('frg_selected_albums', array());

    frg_log('Starting cache refresh');
    frg_log('API Key exists:', !empty($api_key) ? 'Yes' : 'No');
    frg_log('Selected albums:', $selected_albums);

    // Verify cache table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    frg_log('Cache table exists:', $table_exists ? 'Yes' : 'No');

    if (!$table_exists) {
        frg_log('Creating cache table');
        frg_create_cache_table();
    }

    if (empty($api_key)) {
        frg_log('Error: No API key configured');
        return false;
    }

    if (empty($selected_albums)) {
        frg_log('Error: No albums selected');
        return false;
    }

    $oauth_token = get_option('frg_oauth_token');
    frg_log('OAuth token exists:', !empty($oauth_token) ? 'Yes' : 'No');

    foreach ($selected_albums as $album_id) {
        frg_log('Fetching photos for album:', $album_id);

        // Build the API URL with OAuth if available
        $url = "https://api.flickr.com/services/rest/";
        $params = array(
            'method' => 'flickr.photosets.getPhotos',
            'api_key' => $api_key,
            'photoset_id' => $album_id,
            'format' => 'json',
            'nojsoncallback' => 1,
            'extras' => 'url_l,owner'
        );

        if (!empty($oauth_token)) {
            $params['oauth_token'] = $oauth_token;
        }

        $request_url = add_query_arg($params, $url);
        frg_log('API Request URL:', $request_url);

        $response = wp_remote_get($request_url, array(
            'timeout' => 30,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            frg_log('API Request error:', $response->get_error_message());
            continue;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        frg_log('API Response code:', $response_code);
        frg_log('API Response body sample:', substr($response_body, 0, 500) . '...');

        if ($response_code !== 200) {
            frg_log('Error: Unexpected response code');
            continue;
        }

        $photo_data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            frg_log('Error: JSON decode failed:', json_last_error_msg());
            continue;
        }

        if (!isset($photo_data['photoset']['photo'])) {
            frg_log('Error: Invalid photo data format:', $photo_data);
            continue;
        }

        frg_log('Found ' . count($photo_data['photoset']['photo']) . ' photos in album');

        // Store in cache
        $result = $wpdb->replace(
            $table_name,
            array(
                'album_id' => $album_id,
                'photo_data' => $response_body,
                'last_updated' => current_time('mysql')
            ),
            array('%s', '%s', '%s')
        );

        if ($result === false) {
            frg_log('Database error:', $wpdb->last_error);
        } else {
            frg_log('Successfully cached photos for album:', $album_id);
        }
    }

    // Verify cache contents after refresh
    foreach ($selected_albums as $album_id) {
        $cached_data = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT photo_data FROM $table_name WHERE album_id = %s",
                $album_id
            )
        );

        if ($cached_data) {
            $data = json_decode($cached_data, true);
            $photo_count = isset($data['photoset']['photo']) ? count($data['photoset']['photo']) : 0;
            frg_log("Verification - Album $album_id has $photo_count photos in cache");
        } else {
            frg_log("Verification - No cached data found for album $album_id");
        }
    }
}

// Create cache table with logging
function frg_create_cache_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'frg_cache';

    frg_log('Creating cache table:', $table_name);

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        album_id varchar(50) NOT NULL,
        photo_data longtext NOT NULL,
        last_updated timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY album_id (album_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $result = dbDelta($sql);

    frg_log('Table creation result:', $result);

    // Verify table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    frg_log('Table exists after creation:', $table_exists ? 'Yes' : 'No');
}

// Handle cache clearing with logging
add_action('admin_post_frg_clear_cache', 'frg_handle_clear_cache');
function frg_handle_clear_cache() {
    check_admin_referer('frg_clear_cache');

    frg_log('Cache clear requested');

    global $wpdb;
    $table_name = $wpdb->prefix . 'frg_cache';

    $result = $wpdb->query("TRUNCATE TABLE $table_name");
    frg_log('Cache truncate result:', $result !== false ? 'Success' : 'Failed');

    // Immediately refresh the cache
    frg_refresh_cache();

    wp_safe_redirect(add_query_arg(
        array('page' => 'flickr-random-gallery', 'cache-cleared' => '1'),
        admin_url('options-general.php')
    ));
    exit;
}
?>
