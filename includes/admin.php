<?php
if (!defined('ABSPATH')) {
    exit;
}

// Add debug logging function
function frg_debug_log($message, $data = null)
{
    if (WP_DEBUG) {
        error_log('FRG Debug: ' . $message);
        if ($data !== null) {
            error_log('FRG Debug Data: ' . print_r($data, true));
        }
    }
}

// Add admin menu
add_action('admin_menu', 'frg_add_admin_menu');
function frg_add_admin_menu() {
    add_options_page(
        'Flickr Random Gallery Settings',
        'Flickr Gallery',
        'manage_options',
        'flickr-random-gallery',
        'frg_settings_page'
    );
}

// Handle OAuth callback
add_action('admin_init', 'frg_handle_oauth_callback');
function frg_handle_oauth_callback() {
    frg_debug_log('Starting OAuth callback handler');

    if (!isset($_GET['page']) || $_GET['page'] !== 'flickr-random-gallery') {
        return;
    }

    // Log current state
    frg_debug_log('Current tokens state:', array(
        'oauth_token' => get_option('frg_oauth_token'),
        'request_token' => get_option('frg_request_token'),
        'user_id' => get_option('frg_user_id')
    ));

    // Preserve API credentials when saving settings
    if (isset($_POST['option_page']) && $_POST['option_page'] === 'frg_settings_group') {
        frg_debug_log('Processing settings form submission');

        // Store current values
        $current_tokens = array(
            'oauth_token' => get_option('frg_oauth_token'),
            'oauth_token_secret' => get_option('frg_oauth_token_secret'),
            'request_token' => get_option('frg_request_token'),
            'request_token_secret' => get_option('frg_request_token_secret'),
            'user_id' => get_option('frg_user_id')
        );

        frg_debug_log('Current tokens before save:', $current_tokens);

        // Add filters to preserve values
        foreach ($current_tokens as $option_name => $value) {
            if ($value) {
                add_filter("pre_update_option_frg_$option_name", function($new_value) use ($value) {
                    return $new_value ?: $value;
                }, 10, 1);
            }
        }
    }

    // Handle OAuth callback
    if (isset($_GET['oauth_token'], $_GET['oauth_verifier'])) {
        frg_debug_log('Processing OAuth callback');

        $request_token = get_option('frg_request_token');
        $request_token_secret = get_option('frg_request_token_secret');

        frg_debug_log('OAuth callback data:', array(
            'callback_token' => $_GET['oauth_token'],
            'stored_request_token' => $request_token,
            'has_secret' => !empty($request_token_secret)
        ));

        if (!$request_token || !$request_token_secret) {
            frg_debug_log('Missing request tokens');
            add_settings_error(
                'frg_messages',
                'frg_oauth_error',
                'OAuth error: Missing request token. Token: ' . ($request_token ? 'Yes' : 'No') .
                ', Secret: ' . ($request_token_secret ? 'Yes' : 'No'),
                'error'
            );
            return;
        }

        // Get access token
        $result = frg_get_access_token($_GET['oauth_verifier'], $request_token, $request_token_secret);

        if (is_wp_error($result)) {
            frg_debug_log('Access token error:', $result->get_error_message());
            add_settings_error(
                'frg_messages',
                'frg_oauth_error',
                'OAuth error: ' . $result->get_error_message(),
                'error'
            );
            return;
        }

        frg_debug_log('Successfully obtained access token');
    }
}

// Improved OAuth initialization
function frg_start_oauth() {
    error_log('FRG: Inside frg_start_oauth()');

    $api_key = get_option('frg_api_key');
    $api_secret = get_option('frg_api_secret');

    error_log('FRG: API Key exists: ' . ($api_key ? 'yes' : 'no'));
    error_log('FRG: API Secret exists: ' . ($api_secret ? 'yes' : 'no'));

    if (!$api_key || !$api_secret) {
        error_log('FRG: Missing API credentials');
        add_settings_error(
            'frg_messages',
            'frg_oauth_error',
            'Please save your API key and secret before connecting to Flickr.',
            'error'
        );
        return false;
    }

    $callback_url = admin_url('options-general.php?page=flickr-random-gallery');
    $url = "https://www.flickr.com/services/oauth/request_token";

    $params = array(
        'oauth_callback' => $callback_url,
        'oauth_consumer_key' => $api_key,
        'oauth_nonce' => md5(uniqid(rand(), true)),
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp' => time(),
        'oauth_version' => '1.0'
    );

    ksort($params);
    $base_string = 'GET&' . rawurlencode($url) . '&' . rawurlencode(http_build_query($params));
    $key = rawurlencode($api_secret) . '&';
    $signature = base64_encode(hash_hmac('sha1', $base_string, $key, true));
    $params['oauth_signature'] = $signature;

    error_log('FRG: Making request to Flickr');
    error_log('FRG: Request URL: ' . $url . '?' . http_build_query($params));

    $response = wp_remote_get($url . '?' . http_build_query($params), array(
        'timeout' => 30,
        'sslverify' => true,
        'headers' => array(
            'Accept' => 'application/json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        )
    ));

    if (is_wp_error($response)) {
        error_log('FRG: Request error: ' . $response->get_error_message());
        add_settings_error(
            'frg_messages',
            'frg_oauth_error',
            'Connection error: ' . $response->get_error_message(),
            'error'
        );
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    error_log('FRG: Response body: ' . $body);

    parse_str($body, $request_token);

    if (isset($request_token['oauth_token']) && isset($request_token['oauth_token_secret'])) {
        error_log('FRG: Got request token: ' . $request_token['oauth_token']);

        update_option('frg_request_token', $request_token['oauth_token']);
        update_option('frg_request_token_secret', $request_token['oauth_token_secret']);

        $auth_url = "https://www.flickr.com/services/oauth/authorize?oauth_token={$request_token['oauth_token']}&perms=read";
        error_log('FRG: Generated auth URL: ' . $auth_url);

        return $auth_url;
    }

    error_log('FRG: Failed to get request token. Response: ' . print_r($request_token, true));
    add_settings_error(
        'frg_messages',
        'frg_oauth_error',
        'Failed to get request token from Flickr',
        'error'
    );
    return false;
}

add_action('admin_notices', 'frg_admin_notices');
function frg_admin_notices() {
    if (isset($_GET['page']) && $_GET['page'] === 'flickr-random-gallery') {
        if (isset($_GET['error']) && $_GET['error'] === 'oauth_failed') {
            ?>
            <div class="notice notice-error is-dismissible">
                <p>Failed to connect to Flickr. Please check your API credentials and try again.</p>
            </div>
            <?php
        }
    }
}

// Also update the access token function with similar improvements
function frg_get_access_token($oauth_verifier, $request_token, $request_token_secret) {
    frg_debug_log('Getting access token');

    $api_secret = get_option('frg_api_secret');
    $url = "https://www.flickr.com/services/oauth/access_token";

    $params = array(
        'oauth_consumer_key' => get_option('frg_api_key'),
        'oauth_nonce' => md5(uniqid(rand(), true)),
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp' => time(),
        'oauth_token' => $request_token,
        'oauth_verifier' => $oauth_verifier,
        'oauth_version' => '1.0'
    );

    ksort($params);
    $base_string = 'GET&' . rawurlencode($url) . '&' . rawurlencode(http_build_query($params));
    $key = rawurlencode($api_secret) . '&' . rawurlencode($request_token_secret);
    $signature = base64_encode(hash_hmac('sha1', $base_string, $key, true));
    $params['oauth_signature'] = $signature;

    $args = array(
        'timeout' => 30, // Increase timeout to 30 seconds
        'httpversion' => '1.1',
        'sslverify' => true,
        'headers' => array(
            'Accept' => 'application/json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        )
    );

    $response = wp_remote_get($url . '?' . http_build_query($params), $args);

    if (is_wp_error($response)) {
        frg_debug_log('Access token error: ' . $response->get_error_message());
        return new WP_Error('request_failed', 'Failed to connect to Flickr: ' . $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    frg_debug_log('Access token response:', $body);

    parse_str($body, $access_token);

    if (isset($access_token['oauth_token']) && isset($access_token['oauth_token_secret'])) {
        update_option('frg_oauth_token', $access_token['oauth_token']);
        update_option('frg_oauth_token_secret', $access_token['oauth_token_secret']);
        update_option('frg_user_id', $access_token['user_nsid']);

        // Clean up request tokens after successful access token retrieval
        delete_option('frg_request_token');
        delete_option('frg_request_token_secret');

        frg_debug_log('Access token saved successfully');
        return true;
    }

    frg_debug_log('Invalid access token response');
    return new WP_Error('invalid_response', 'Invalid response from Flickr');
}

// Update the register_settings function to properly handle the albums array
add_action('admin_init', 'frg_register_settings');
function frg_register_settings() {
    frg_debug_log('Registering settings');

    // Register API key with proper sanitization and preservation
    register_setting('frg_settings_group', 'frg_api_key', array(
        'sanitize_callback' => function($value) {
            $existing = get_option('frg_api_key');
            return !empty($value) ? sanitize_text_field($value) : $existing;
        },
        'default' => ''
    ));

    // Register API secret with proper sanitization and preservation
    register_setting('frg_settings_group', 'frg_api_secret', array(
        'sanitize_callback' => function($value) {
            $existing = get_option('frg_api_secret');
            return !empty($value) ? sanitize_text_field($value) : $existing;
        },
        'default' => ''
    ));

    // Register OAuth token settings
    register_setting('frg_settings_group', 'frg_oauth_token', array(
        'sanitize_callback' => 'frg_preserve_existing_value',
        'default' => ''
    ));

    register_setting('frg_settings_group', 'frg_oauth_token_secret', array(
        'sanitize_callback' => 'frg_preserve_existing_value',
        'default' => ''
    ));

    register_setting('frg_settings_group', 'frg_user_id', array(
        'sanitize_callback' => 'frg_preserve_existing_value',
        'default' => ''
    ));

    register_setting('frg_settings_group', 'frg_selected_albums', array(
        'type' => 'array',
        'sanitize_callback' => 'frg_sanitize_album_array',
        'default' => array()
    ));
}

function frg_preserve_existing_value($value) {
    $option_name = current_filter();
    $option_name = str_replace('sanitize_option_', '', $option_name);
    $existing_value = get_option($option_name);
    return !empty($value) ? $value : $existing_value;
}

add_action('pre_update_option_frg_selected_albums', function($value, $old_value) {
    // Preserve API credentials
    add_filter('pre_update_option_frg_api_key', function($new, $old) {
        return !empty($new) ? $new : $old;
    }, 10, 2);

    add_filter('pre_update_option_frg_api_secret', function($new, $old) {
        return !empty($new) ? $new : $old;
    }, 10, 2);

    // Preserve OAuth tokens
    add_filter('pre_update_option_frg_oauth_token', function($new, $old) {
        return !empty($new) ? $new : $old;
    }, 10, 2);

    add_filter('pre_update_option_frg_oauth_token_secret', function($new, $old) {
        return !empty($new) ? $new : $old;
    }, 10, 2);

    return $value;
}, 10, 2);

function frg_sanitize_album_array($input) {
    // If no albums are selected, return an empty array
    if (empty($input)) {
        return array();
    }

    // If input is already an array, sanitize each value
    if (is_array($input)) {
        return array_map('sanitize_text_field', $input);
    }

    // If input is a string (shouldn't happen, but just in case)
    return array(sanitize_text_field($input));
}

// Preserve tokens when saving albums
function frg_preserve_tokens_on_album_save($value, $old_value) {
    frg_debug_log('Preserving tokens during album save');
    frg_debug_log('Current OAuth token:', get_option('frg_oauth_token'));
    frg_debug_log('Current request token:', get_option('frg_request_token'));

    // Preserve OAuth tokens
    add_filter('pre_update_option_frg_oauth_token', function($new, $old) {
        return $old ?: $new;
    }, 10, 2);

    add_filter('pre_update_option_frg_oauth_token_secret', function($new, $old) {
        return $old ?: $new;
    }, 10, 2);

    return $value;
}

// Settings page HTML
function frg_settings_page() {
    // Get current tab
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
    ?>
    <div class="wrap">
        <h1>Flickr Random Gallery</h1>

        <?php settings_errors('frg_messages'); ?>

        <!-- Tabs Navigation -->
        <nav class="nav-tab-wrapper">
            <a href="?page=flickr-random-gallery&tab=settings"
               class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-admin-settings" style="margin: 4px 8px 0 0;"></span>
                Settings
            </a>
            <?php if (get_option('frg_oauth_token')): ?>
                <a href="?page=flickr-random-gallery&tab=albums"
                   class="nav-tab <?php echo $current_tab === 'albums' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-format-gallery" style="margin: 4px 8px 0 0;"></span>
                    Albums
                </a>
            <?php endif; ?>
            <a href="?page=flickr-random-gallery&tab=cache"
               class="nav-tab <?php echo $current_tab === 'cache' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-database" style="margin: 4px 8px 0 0;"></span>
                Cache
            </a>
            <a href="?page=flickr-random-gallery&tab=docs"
               class="nav-tab <?php echo $current_tab === 'docs' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-book" style="margin: 4px 8px 0 0;"></span>
                Documentation
            </a>
        </nav>

        <div class="tab-content" style="margin-top: 20px;">
            <?php
            switch ($current_tab) {
                case 'settings':
                    frg_render_settings_tab();
                    break;
                case 'albums':
                    if (get_option('frg_oauth_token')) {
                        frg_render_albums_tab();
                    }
                    break;
                case 'cache':
                    frg_render_cache_tab();
                    break;
                case 'docs':
                    frg_render_docs_tab();
                    break;
            }
            ?>
        </div>
    </div>

    <style>
        .tab-content {
            background: #fff;
            padding: 20px;
            border: 1px solid #c3c4c7;
            border-top: none;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .nav-tab-wrapper {
            margin-bottom: 0;
        }
        .nav-tab {
            display: inline-flex;
            align-items: center;
        }
    </style>
    <?php
}

function frg_render_settings_tab() {
    ?>
    <div class="frg-settings-container" style="
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    ">
        <!-- API Settings Form -->
        <div class="frg-api-settings">
            <form method="post" action="options.php">
                <?php settings_fields('frg_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Flickr API Key</th>
                        <td>
                            <input type="text"
                                   name="frg_api_key"
                                   value="<?php echo esc_attr(get_option('frg_api_key')); ?>"
                                   class="regular-text"
                                   placeholder="Enter your Flickr API Key"/>
                            <p class="description">Your Flickr API Key (Required)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Flickr API Secret</th>
                        <td>
                            <input type="text"
                                   name="frg_api_secret"
                                   value="<?php echo esc_attr(get_option('frg_api_secret')); ?>"
                                   class="regular-text"
                                   placeholder="Enter your Flickr API Secret"/>
                            <p class="description">Your Flickr API Secret (Required)</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>

            <?php if (get_option('frg_api_key') && get_option('frg_api_secret')): ?>
                <?php if (!get_option('frg_oauth_token')): ?>
                    <div class="card" style="margin-top: 20px; padding: 10px 20px;">
                        <h3>Connect to Flickr</h3>
                        <p>Click the button below to connect your Flickr account and grant access to your photos:</p>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <?php wp_nonce_field('frg_start_oauth'); ?>
                            <input type="hidden" name="action" value="frg_start_oauth_process">
                            <button type="submit" name="start_oauth" class="button button-primary">Connect to Flickr</button>
                        </form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Getting Started Guide -->
        <div class="card" style="height: fit-content; padding: 20px;">
            <h3>
                <span class="dashicons dashicons-admin-network" style="font-size: 24px; margin-right: 10px;"></span>
                Getting Started with Flickr API
            </h3>
            <p>To use this plugin, you need Flickr API credentials. Here's how to get them:</p>
            <ol>
                <li>Click the button below to go to Flickr's App Garden</li>
                <li>Sign in to your Flickr account (or create one if needed)</li>
                <li>Click "Create an App" and choose "Apply for a Non-Commercial Key"</li>
                <li>Fill in the application form with your website details</li>
                <li>Copy the API Key and API Secret provided by Flickr</li>
                <li>Paste them in the fields on the left and click "Save Settings"</li>
                <li>After saving, click the "Connect to Flickr" button to authorize the plugin</li>
            </ol>
            <a href="https://www.flickr.com/services/apps/create/"
               target="_blank"
               class="button button-primary"
               style="margin: 10px 0;">
                <span class="dashicons dashicons-external" style="line-height: 1.4;"></span>
                Get Flickr API Keys
            </a>
        </div>
    </div>

    <!-- Developer Credit Footer -->
    <div class="frg-developer-credit">
        <p>
            Developed by <a href="https://github.com/stephanj" target="_blank">Stephan Janssen</a>
            using <a href="https://github.com/devoxx/DevoxxGenieIDEAPlugin" target="_blank" style="color: #2271b1;">@DevoxxGenie</a>
            | <a href="https://github.com/stephanj/flickr-random-gallery" target="_blank" style="color: #2271b1;">
                View on GitHub
            </a>
        </p>
    </div>
    <?php
}

function frg_render_albums_tab() {
    ?>
    <div id="flickr-albums-list">
        <?php frg_display_albums_list(); ?>
    </div>
    <?php
}

function frg_render_cache_tab() {
    ?>
    <div class="frg-cache-management">
        <?php frg_add_cache_management_section(); ?>
    </div>
    <?php
}

function frg_render_docs_tab() {
    ?>
    <div class="frg-shortcode-docs">
        <?php frg_add_shortcode_docs(); ?>
    </div>
    <?php
}

function frg_add_cache_management_section() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'frg_cache';

    // Get cache statistics
    $cache_stats = array(
        'total_entries' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
        'oldest_entry' => $wpdb->get_var("SELECT MIN(last_updated) FROM $table_name"),
        'newest_entry' => $wpdb->get_var("SELECT MAX(last_updated) FROM $table_name"),
        'total_albums' => count(get_option('frg_selected_albums', array()))
    );

    // Get cached albums with their last update time
    $cached_albums = $wpdb->get_results("
        SELECT album_id, last_updated,
        LENGTH(photo_data) as data_size
        FROM $table_name
        ORDER BY last_updated DESC
    ");

    ?>
    <div class="frg-cache-management" style="margin-top: 30px; padding: 20px;">
        <h2 style="margin-top: 0;">
            <span class="dashicons dashicons-database" style="font-size: 24px; margin-right: 10px;"></span>
            Cache Management
        </h2>

        <!-- Cache Statistics -->
        <div class="frg-cache-stats" style="
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        ">
            <div class="stat-card" style="background: #f0f6fc; padding: 15px; border-radius: 4px;">
                <h4 style="margin: 0;">Cached Albums</h4>
                <p style="font-size: 24px; margin: 10px 0;">
                    <?php echo esc_html($cache_stats['total_entries']); ?> / <?php echo esc_html($cache_stats['total_albums']); ?>
                </p>
            </div>

            <div class="stat-card" style="background: #f0f6fc; padding: 15px; border-radius: 4px;">
                <h4 style="margin: 0;">Last Cache Update</h4>
                <p style="font-size: 16px; margin: 10px 0;">
                    <?php
                    if ($cache_stats['newest_entry']) {
                        echo esc_html(human_time_diff(strtotime($cache_stats['newest_entry']), current_time('timestamp'))) . ' ago';
                    } else {
                        echo 'Never';
                    }
                    ?>
                </p>
            </div>

            <div class="stat-card" style="background: #f0f6fc; padding: 15px; border-radius: 4px;">
                <h4 style="margin: 0;">Cache Status</h4>
                <p style="font-size: 16px; margin: 10px 0;">
                    <?php
                    if ($cache_stats['total_entries'] === $cache_stats['total_albums']) {
                        echo '<span style="color: #00a32a;">✓ All albums cached</span>';
                    } else {
                        echo '<span style="color: #cc1818;">⚠ Cache incomplete</span>';
                    }
                    ?>
                </p>
            </div>
        </div>

        <!-- Cache Actions -->
        <div class="frg-cache-actions" style="margin: 20px 0; display: flex; gap: 10px;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                <?php wp_nonce_field('frg_clear_cache'); ?>
                <input type="hidden" name="action" value="frg_clear_cache">
                <button type="submit" class="button button-secondary">
                    <span class="dashicons dashicons-trash" style="margin: 4px 5px 0 -5px;"></span>
                    Clear Cache
                </button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                <?php wp_nonce_field('frg_refresh_cache'); ?>
                <input type="hidden" name="action" value="frg_refresh_cache">
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-update" style="margin: 4px 5px 0 -5px;"></span>
                    Refresh Cache Now
                </button>
            </form>
        </div>

        <!-- Cache Details Table -->
        <?php if (!empty($cached_albums)): ?>
            <h3>Cached Albums</h3>
            <table class="widefat" style="margin-top: 15px;">
                <thead>
                <tr>
                    <th>Album ID</th>
                    <th>Last Updated</th>
                    <th>Cache Size</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($cached_albums as $album): ?>
                    <tr>
                        <td><?php echo esc_html($album->album_id); ?></td>
                        <td>
                            <?php
                            $time_diff = human_time_diff(strtotime($album->last_updated), current_time('timestamp'));
                            echo esc_html($time_diff) . ' ago';
                            ?>
                        </td>
                        <td><?php echo esc_html(size_format(strlen($album->data_size), 2)); ?></td>
                        <td>
                            <?php
                            $age_hours = (current_time('timestamp') - strtotime($album->last_updated)) / HOUR_IN_SECONDS;
                            if ($age_hours < 24) {
                                echo '<span style="color: #00a32a;">✓ Fresh</span>';
                            } elseif ($age_hours < 48) {
                                echo '<span style="color: #dba617;">⚠ Aging</span>';
                            } else {
                                echo '<span style="color: #cc1818;">⚠ Stale</span>';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Cache Information -->
        <div class="frg-cache-info card" style="margin-top: 20px; padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1;">
            <h4 style="margin-top: 0;">ℹ️ About Caching</h4>
            <p>The plugin caches Flickr album data to improve performance and reduce API calls. Cache is automatically refreshed:</p>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li>Every 24 hours via WordPress cron</li>
                    <li>When you modify album selections</li>
                    <li>When you manually refresh the cache</li>
                </ul>
            </ul>
        </div>
    </div>

    <script>
        jQuery(document).ready(function($) {
            // Add loading spinner to refresh button when clicked
            $('form[action*="frg_refresh_cache"]').on('submit', function() {
                $(this).find('button').prop('disabled', true)
                    .html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Refreshing...');
            });

            // Confirm cache clearing
            $('form[action*="frg_clear_cache"]').on('submit', function(e) {
                if (!confirm('Are you sure you want to clear the cache? This will delete all cached photos and require re-downloading them on next gallery load.')) {
                    e.preventDefault();
                }
            });
        });
    </script>
    <?php
}

// Add the cache refresh action handler
add_action('admin_post_frg_refresh_cache', 'frg_handle_cache_refresh');
function frg_handle_cache_refresh() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('frg_refresh_cache');

    // Call the cache refresh function from cache.php
    frg_refresh_cache();

    // Redirect back to the settings page with a success message
    wp_safe_redirect(add_query_arg(
        array(
            'page' => 'flickr-random-gallery',
            'cache-refreshed' => '1'
        ),
        admin_url('options-general.php')
    ));
    exit;
}

// Add success message for cache refresh
add_action('admin_notices', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'flickr-random-gallery') {
        if (isset($_GET['cache-refreshed'])) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>Cache refreshed successfully!</p>
            </div>
            <?php
        }
    }
});

function frg_add_shortcode_docs() {
    ?>
    <div class="frg-shortcode-docs" style="margin-top: 30px; padding: 20px;">
        <h2 style="margin-top: 0;">
            <span class="dashicons dashicons-shortcode" style="font-size: 24px; margin-right: 10px;"></span>
            Using the Gallery Shortcode
        </h2>

        <p class="description">Use the shortcode below to display your Flickr gallery anywhere on your site:</p>

        <div class="frg-shortcode-example" style="
            background: #f0f0f1;
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
            font-family: monospace;
        ">
            [flickr_random_gallery]
        </div>

        <h3>Shortcode Options</h3>
        <table class="widefat" style="margin-top: 15px;">
            <thead>
            <tr>
                <th>Parameter</th>
                <th>Default</th>
                <th>Description</th>
                <th>Example</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><code>columns</code></td>
                <td>3</td>
                <td>Number of columns in the gallery grid</td>
                <td><code>[flickr_random_gallery columns="4"]</code></td>
            </tr>
            <tr>
                <td><code>count</code></td>
                <td>9</td>
                <td>Number of photos to display</td>
                <td><code>[flickr_random_gallery count="12"]</code></td>
            </tr>
            <tr>
                <td><code>target</code></td>
                <td>_blank</td>
                <td>Link target for photos (_blank, _self)</td>
                <td><code>[flickr_random_gallery target="_self"]</code></td>
            </tr>
            </tbody>
        </table>

        <h3 style="margin-top: 20px;">Example Usage</h3>
        <div class="frg-example-cards" style="
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 15px;
        ">
            <!-- Basic Example -->
            <div class="card" style="padding: 15px;">
                <h4>Basic Gallery</h4>
                <code>[flickr_random_gallery]</code>
                <p class="description">Displays 9 photos in a 3-column grid</p>
            </div>

            <!-- Custom Layout -->
            <div class="card" style="padding: 15px;">
                <h4>Custom Layout</h4>
                <code>[flickr_random_gallery columns="4" count="12"]</code>
                <p class="description">Shows 12 photos in a 4-column grid</p>
            </div>

            <!-- Custom Target -->
            <div class="card" style="padding: 15px;">
                <h4>Same Window Links</h4>
                <code>[flickr_random_gallery target="_self"]</code>
                <p class="description">Opens photos in the same window</p>
            </div>
        </div>

        <div class="frg-tips card" style="margin-top: 20px; padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1;">
            <h4 style="margin-top: 0;">💡 Tips</h4>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <li>You can use the shortcode in posts, pages, and widgets</li>
                <li>Multiple galleries can be added to the same page with different settings</li>
                <li>Photos are randomly selected from your chosen albums each time the page loads</li>
                <li>The gallery is responsive and will adjust to fit your theme's layout</li>
            </ul>
        </div>
    </div>
    <?php
}

add_action('admin_post_frg_start_oauth_process', 'frg_handle_oauth_start');
function frg_handle_oauth_start() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('frg_start_oauth');

    // Log the start of OAuth process
    error_log('FRG: Starting OAuth process');

    // Clear any existing OAuth tokens
    delete_option('frg_oauth_token');
    delete_option('frg_oauth_token_secret');
    delete_option('frg_request_token');
    delete_option('frg_request_token_secret');

    $auth_url = frg_start_oauth();

    if ($auth_url) {
        error_log('FRG: Redirecting to Flickr auth URL: ' . $auth_url);
        wp_redirect($auth_url);
        exit;
    } else {
        error_log('FRG: Failed to get auth URL');
        wp_redirect(add_query_arg(
            array(
                'page' => 'flickr-random-gallery',
                'error' => 'oauth_failed'
            ),
            admin_url('options-general.php')
        ));
        exit;
    }
}

// Display available albums list
function frg_display_albums_list() {
    frg_debug_log('Displaying albums list');

    $api_key = get_option('frg_api_key');
    $oauth_token = get_option('frg_oauth_token');
    $user_id = get_option('frg_user_id');

    frg_debug_log('Album list tokens:', array(
        'has_api_key' => !empty($api_key),
        'has_oauth_token' => !empty($oauth_token),
        'has_user_id' => !empty($user_id)
    ));

    $selected_albums = (array) get_option('frg_selected_albums', array());

    if (!$oauth_token || !$user_id) {
        echo '<div class="notice notice-error"><p>Authentication error. Please reconnect to Flickr.</p></div>';
        return;
    }

    $url = "https://api.flickr.com/services/rest/?method=flickr.photosets.getList" .
           "&api_key={$api_key}" .
           "&user_id={$user_id}" .
           "&format=json" .
           "&nojsoncallback=1";

    $response = wp_remote_get($url);

    if (is_wp_error($response)) {
        echo '<div class="notice notice-error"><p>Error fetching albums: ' . esc_html($response->get_error_message()) . '</p></div>';
        return;
    }

    $albums = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($albums['photosets']['photoset']) || empty($albums['photosets']['photoset'])) {
        echo '<div class="notice notice-warning"><p>No albums found in your Flickr account.</p></div>';
        return;
    }

    // Display summary of selected albums
    if (!empty($selected_albums)) {
        echo '<div class="frg-selected-summary card" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #2271b1;">';
        echo '<h3 style="margin-top: 0;">Currently Selected Albums</h3>';
        echo '<p>You have selected ' . count($selected_albums) . ' album(s) to display in your gallery:</p>';
        echo '<ul style="list-style-type: disc; margin-left: 20px;">';

        foreach ($albums['photosets']['photoset'] as $album) {
            if (in_array($album['id'], $selected_albums)) {
                echo '<li><strong>' . esc_html($album['title']['_content']) . '</strong> ';
                echo '<span class="description">(' . esc_html($album['photos']) . ' photos)</span></li>';
            }
        }

        echo '</ul>';
        echo '</div>';
    }

    // Start form for saving album selection
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('frg_settings_group'); ?>

        <div class="frg-albums-header" style="margin: 20px 0;">
            <h3 style="margin-bottom: 10px;">Available Albums</h3>
            <p class="description">Select the albums you want to include in your gallery:</p>
        </div>

        <div class="frg-submit-wrapper" style="margin-top: 20px;">
            <?php submit_button('Save Album Selection', 'primary', 'submit', false, ['style' => 'margin-right: 10px;']); ?>
            <span class="description">Changes will take effect after saving.</span>
        </div>

        <div class="frg-albums-grid" style="
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 5px;
            margin-top: 20px;
        ">
            <?php
            foreach ($albums['photosets']['photoset'] as $album) {
                $checked = in_array($album['id'], $selected_albums) ? 'checked' : '';
                ?>
                <div class="frg-album-item card" style="
                    padding: 15px;
                    background: <?php echo $checked ? '#f0f6fc' : '#fff'; ?>;
                    border: 1px solid #ddd;
                    border-left: 4px solid <?php echo $checked ? '#2271b1' : '#ddd'; ?>;
                    ">
                    <label style="display: block;">
                        <div style="display: flex; align-items: flex-start;">
                            <input type="checkbox"
                                   name="frg_selected_albums[]"
                                   value="<?php echo esc_attr($album['id']); ?>"
                                   style="margin-top: 4px;"
                                <?php echo $checked; ?>>
                            <div style="margin-left: 10px;">
                                <strong style="display: block; margin-bottom: 5px;">
                                    <?php echo esc_html($album['title']['_content']); ?>
                                </strong>
                                <span class="description">
                                    <?php echo esc_html($album['photos']); ?> photos
                                </span>
                            </div>
                        </div>
                    </label>
                </div>
                <?php
            }
            ?>
        </div>

        <div class="frg-submit-wrapper" style="margin-top: 20px;">
            <?php submit_button('Save Album Selection', 'primary', 'submit', false, ['style' => 'margin-right: 10px;']); ?>
            <span class="description">Changes will take effect after saving.</span>
        </div>
    </form>

    <script>
        jQuery(document).ready(function($) {
            // Add visual feedback when selecting albums
            $('.frg-album-item input[type="checkbox"]').on('change', function() {
                const card = $(this).closest('.frg-album-item');
                if (this.checked) {
                    card.css({
                        'background-color': '#f0f6fc',
                        'border-left-color': '#2271b1'
                    });
                } else {
                    card.css({
                        'background-color': '#fff',
                        'border-left-color': '#ddd'
                    });
                }
            });
        });
    </script>
    <?php
}

function frg_run_diagnostics() {
    $results = array();

    // Check API credentials
    $api_key = get_option('frg_api_key');
    $api_secret = get_option('frg_api_secret');
    $oauth_token = get_option('frg_oauth_token');
    $user_id = get_option('frg_user_id');

    $results['credentials'] = array(
        'api_key' => !empty($api_key) ? 'Present' : 'Missing',
        'api_secret' => !empty($api_secret) ? 'Present' : 'Missing',
        'oauth_token' => !empty($oauth_token) ? 'Present' : 'Missing',
        'user_id' => !empty($user_id) ? $user_id : 'Missing'
    );

    // Check selected albums
    $selected_albums = get_option('frg_selected_albums', array());
    $results['albums'] = array(
        'count' => count($selected_albums),
        'ids' => $selected_albums
    );

    // Test Flickr API connection
    if ($api_key && $oauth_token) {
        $test_url = 'https://api.flickr.com/services/rest/';
        $params = array(
            'method' => 'flickr.test.login',
            'api_key' => $api_key,
            'oauth_token' => $oauth_token,
            'format' => 'json',
            'nojsoncallback' => 1
        );

        $response = wp_remote_get(add_query_arg($params, $test_url));

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            $results['api_test'] = $data;
        } else {
            $results['api_test'] = array(
                'error' => $response->get_error_message()
            );
        }
    }

    return $results;
}
?>
