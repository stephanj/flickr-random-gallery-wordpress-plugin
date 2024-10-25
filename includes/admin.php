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
    $api_key = get_option('frg_api_key');
    $api_secret = get_option('frg_api_secret');

    if (!$api_key || !$api_secret) {
        add_settings_error(
            'frg_messages',
            'frg_oauth_error',
            'Please save your API key and secret before connecting to Flickr.',
            'error'
        );
        return false;
    }

    // Clear any existing OAuth tokens
    delete_option('frg_request_token');
    delete_option('frg_request_token_secret');
    delete_option('frg_oauth_token');
    delete_option('frg_oauth_token_secret');

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

    // Set up the request args with increased timeout
    $args = array(
        'timeout' => 30, // Increase timeout to 30 seconds
        'httpversion' => '1.1',
        'sslverify' => true,
        'headers' => array(
            'Accept' => 'application/json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        )
    );

    // Make the request with extended error handling
    $response = wp_remote_get($url . '?' . http_build_query($params), $args);

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        $error_code = $response->get_error_code();

        // Log the error for debugging
        error_log(sprintf('Flickr OAuth Error: [%s] %s', $error_code, $error_message));

        // Provide more specific error message based on the error
        $user_message = 'OAuth error: ';
        if (strpos($error_message, 'Operation timed out') !== false) {
            $user_message .= 'Connection to Flickr timed out. Please try again or check your internet connection.';
        } elseif (strpos($error_message, 'Could not resolve host') !== false) {
            $user_message .= 'Could not connect to Flickr. Please check your internet connection.';
        } else {
            $user_message .= $error_message;
        }

        add_settings_error(
            'frg_messages',
            'frg_oauth_error',
            $user_message,
            'error'
        );
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        $error_message = sprintf(
            'Flickr returned an unexpected response (HTTP %s). Please try again later.',
            $response_code
        );
        add_settings_error(
            'frg_messages',
            'frg_oauth_error',
            $error_message,
            'error'
        );
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    parse_str($body, $request_token);

    if (isset($request_token['oauth_token']) && isset($request_token['oauth_token_secret'])) {
        update_option('frg_request_token', $request_token['oauth_token']);
        update_option('frg_request_token_secret', $request_token['oauth_token_secret']);

        return "https://www.flickr.com/services/oauth/authorize?oauth_token={$request_token['oauth_token']}&perms=read";
    }

    // If we get here, something went wrong with the response format
    add_settings_error(
        'frg_messages',
        'frg_oauth_error',
        'Invalid response received from Flickr. Please try again.',
        'error'
    );
    return false;
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
    global $wpdb;
    $table_name = $wpdb->prefix . 'frg_cache';

    // Check if we need to start OAuth
    if (isset($_POST['start_oauth']) && check_admin_referer('frg_start_oauth')) {
        $auth_url = frg_start_oauth();
        if ($auth_url) {
            wp_redirect($auth_url);
            exit;
        }
    }
    ?>
    <div class="wrap">
        <h2>Flickr Random Gallery Settings</h2>

        <?php settings_errors('frg_messages'); ?>

        <?php if (isset($_GET['cache-cleared'])): ?>
            <div class="notice notice-success is-dismissible">
                <p>Cache cleared successfully!</p>
            </div>
        <?php endif; ?>

        <!-- Two-column layout container -->
        <div class="frg-settings-container" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; max-width: 1200px; margin-top: 20px;">
            <!-- Column 1: API Settings Form -->
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
                        <!-- OAuth Connection Form -->
                        <div class="card" style="margin-top: 20px; padding: 10px 20px;">
                            <h3>Connect to Flickr</h3>
                            <p>Click the button below to connect your Flickr account and grant access to your photos:</p>
                            <form method="post">
                                <?php wp_nonce_field('frg_start_oauth'); ?>
                                <input type="submit" name="start_oauth" class="button button-primary" value="Connect to Flickr">
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Column 2: Getting Started Guide -->
            <div class="card" style="height: fit-content; padding: 10px 20px;">
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

        <?php if (get_option('frg_oauth_token')): ?>
            <!-- Album Selection -->
            <div style="margin-top: 30px;">
                <h3>Select Albums</h3>
                <div id="flickr-albums-list">
                    <?php frg_display_albums_list(); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Cache Management Section -->
        <div class="frg-cache-management">
            <!-- ... (rest of the cache management section remains the same) ... -->
        </div>
    </div>
    <?php
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

        <div class="frg-albums-grid" style="
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
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

// Add this button to your admin page
add_action('admin_footer', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'flickr-random-gallery') {
        ?>
        <div class="card" style="margin-top: 20px;">
            <h3>Diagnostic Tools</h3>
            <button id="run-diagnostics" class="button">Run Diagnostics</button>
            <div id="diagnostic-results" style="margin-top: 10px; padding: 10px; display: none;"></div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('#run-diagnostics').on('click', function() {
                    $.post(ajaxurl, {
                        action: 'frg_run_diagnostics',
                        nonce: '<?php echo wp_create_nonce('frg-diagnostics'); ?>'
                    }, function(response) {
                        if (response.success) {
                            const results = response.data;
                            let html = '<h4>Diagnostic Results:</h4>';
                            html += '<pre>' + JSON.stringify(results, null, 2) + '</pre>';
                            $('#diagnostic-results').html(html).show();
                        }
                    });
                });
            });
        </script>
        <?php
    }
});

// Add the AJAX handler
add_action('wp_ajax_frg_run_diagnostics', function() {
    check_admin_referer('frg-diagnostics', 'nonce');
    $results = frg_run_diagnostics();
    wp_send_json_success($results);
});
?>
