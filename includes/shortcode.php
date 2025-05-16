<?php
if (!defined('ABSPATH')) {
    exit;
}

// Register shortcode
add_shortcode('flickr_random_gallery', 'frg_display_gallery');
function frg_display_gallery($atts) {
    $atts = shortcode_atts(array(
        'columns' => 3,
        'count' => 9,
        'target' => '_blank'
    ), $atts);

    // Add a container div to wrap both gallery and button
    $html = '<div class="flickr-random-gallery-container">';

    // Gallery div remains the same
    $html .= '<div class="flickr-random-gallery"
        data-columns="' . esc_attr($atts['columns']) . '"
        data-count="' . esc_attr($atts['count']) . '"
        data-target="' . esc_attr($atts['target']) . '"
        style="display: grid; grid-template-columns: repeat(' . esc_attr($atts['columns']) . ', 1fr); gap: 20px;">';
    $html .= '</div>';

    // Add refresh button
    $html .= '<div class="gallery-refresh-container" style="text-align: center; margin-top: 20px;">
        <button class="gallery-refresh-button button loading" style="align-items: center; display: inline-flex; gap: 5px;">
            <span class="dashicons dashicons-image-rotate" style="height: auto; font-size: 16px;"></span>
            <span class="button-text">Loading Photos</span>
        </button>
    </div>';

    $html .= '</div>'; // Close container

    // Add styles once
    static $styles_added = false;
    if (!$styles_added) {
        $html .= '<style>
            .gallery-item .image-wrapper {
                overflow: hidden;
                position: relative;
            }
            .gallery-item .image-wrapper img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
                transform: scale(1);
                transition: transform 0.3s ease-in-out;
            }
            .gallery-item .image-wrapper:hover img {
                transform: scale(1.1);
            }
            .gallery-item .overlay {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                opacity: 0;
                transition: opacity 0.3s ease-in-out;
            }
            .gallery-item .image-wrapper:hover .overlay {
                opacity: 1;
            }
            .view-on-flickr {
                color: white;
                padding: 8px 16px;
                border: 2px solid white;
                border-radius: 4px;
                font-size: 14px;
            }
            .gallery-refresh-button {
                padding: 8px 16px;
                background: #0073aa;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                transition: background 0.2s ease;
            }
            .gallery-refresh-button .button-text {
                transition: opacity 0.2s ease;
            }
            .gallery-refresh-button.loading {
                opacity: 0.7;
                cursor: wait;
            }
            .gallery-refresh-button:hover {
                background: #006291;
            }
            .gallery-refresh-button.loading {
                opacity: 0.7;
                cursor: wait;
            }
            .gallery-refresh-button .dashicons {
                transition: transform 0.3s ease;
            }
            .gallery-refresh-button.loading .dashicons {
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
        </style>';
        $styles_added = true;
    }

    return $html;
}

// AJAX handler for loading photos
add_action('wp_ajax_frg_load_photos', 'frg_ajax_load_photos');
add_action('wp_ajax_nopriv_frg_load_photos', 'frg_ajax_load_photos');
function frg_ajax_load_photos() {
    try {
        // Verify nonce
        check_ajax_referer('frg-gallery-nonce', 'nonce');

        $api_key = get_option('frg_api_key');
        $selected_albums = get_option('frg_selected_albums', array());
        $user_id = get_option('frg_user_id');
        
        // Check if we should force refresh (bypass cache)
        $force_refresh = isset($_GET['force_refresh']) ? filter_var($_GET['force_refresh'], FILTER_VALIDATE_BOOLEAN) : false;
        
        error_log('FRG: User ID from options: ' . $user_id);
        error_log('FRG: Force refresh: ' . ($force_refresh ? 'Yes' : 'No'));

        if (empty($selected_albums)) {
            wp_send_json_error(['message' => 'No albums selected']);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'frg_cache';
        $photos = [];
        $cache_hit = false;
        $cache_status = [];

        // Check if cache table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        if (!$table_exists) {
            error_log('FRG: Cache table does not exist, creating it');
            frg_create_cache_table();
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            error_log('FRG: Cache table created: ' . ($table_exists ? 'Yes' : 'No'));
        }

        // First try to get photos from cache if not forcing refresh and table exists
        if (!$force_refresh && $table_exists) {
            error_log('FRG: Checking cache for album data');
            
            foreach ($selected_albums as $album_id) {
                $cache_data = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT photo_data, last_updated FROM $table_name WHERE album_id = %s",
                        $album_id
                    )
                );
                
                if ($cache_data) {
                    // Check if cache is fresh (less than 24 hours old)
                    $cache_timestamp = strtotime($cache_data->last_updated);
                    $cache_age_hours = (current_time('timestamp') - $cache_timestamp) / HOUR_IN_SECONDS;
                    $cache_is_fresh = $cache_age_hours < 24;
                    
                    error_log('FRG: Cache found for album ' . $album_id . ', age: ' . $cache_age_hours . ' hours');
                    
                    if ($cache_is_fresh) {
                        $album_data = json_decode($cache_data->photo_data, true);
                        
                        if (!empty($album_data['photoset']['photo'])) {
                            // Add album_id and owner to each photo
                            foreach ($album_data['photoset']['photo'] as &$photo) {
                                $photo['album_id'] = $album_id;
                                // Use owner from API data or fallback to user_id
                                $photo['owner'] = isset($photo['owner']) ? $photo['owner'] : $user_id;
                            }
                            
                            // Merge photos from this album with our collection
                            $photos = array_merge($photos, $album_data['photoset']['photo']);
                            $cache_hit = true;
                            $cache_status[$album_id] = 'hit';
                            
                            error_log('FRG: Using cached data for album: ' . $album_id . ', found ' . count($album_data['photoset']['photo']) . ' photos');
                        }
                    } else {
                        error_log('FRG: Cache is stale for album: ' . $album_id);
                        $cache_status[$album_id] = 'stale';
                    }
                } else {
                    error_log('FRG: No cache found for album: ' . $album_id);
                    $cache_status[$album_id] = 'miss';
                }
            }
        } else if (!$table_exists) {
            error_log('FRG: Cache table does not exist, skipping cache check');
            foreach ($selected_albums as $album_id) {
                $cache_status[$album_id] = 'no table';
            }
        } else {
            error_log('FRG: Skipping cache due to force refresh');
            foreach ($selected_albums as $album_id) {
                $cache_status[$album_id] = 'bypassed';
            }
        }

        // If no valid cached data was found for any album, or force refresh is enabled,
        // fall back to API calls for albums without cache hits
        if (empty($photos) || $force_refresh) {
            error_log('FRG: Getting photos from API for albums without valid cache');
            
            if (empty($api_key)) {
                wp_send_json_error(['message' => 'API key not configured', 'debug' => 'API key missing']);
                return;
            }
            
            foreach ($selected_albums as $album_id) {
                // Skip if we already have cached data for this album and not forcing refresh
                if (!$force_refresh && isset($cache_status[$album_id]) && $cache_status[$album_id] === 'hit') {
                    continue;
                }
                
                // Get album owner info first
                $album_info_url = "https://www.flickr.com/services/rest/?" . http_build_query([
                    'method' => 'flickr.photosets.getInfo',
                    'api_key' => $api_key,
                    'photoset_id' => $album_id,
                    'format' => 'json',
                    'nojsoncallback' => 1
                ]);

                $album_response = wp_remote_get($album_info_url);
                $album_owner = $user_id; // Default to user_id

                if (is_wp_error($album_response)) {
                    error_log('FRG: API error getting album info: ' . $album_response->get_error_message());
                    $cache_status[$album_id] = 'api error';
                    continue;
                }

                if (!is_wp_error($album_response)) {
                    $album_data = json_decode(wp_remote_retrieve_body($album_response), true);
                    if (!empty($album_data['photoset']['owner'])) {
                        $album_owner = $album_data['photoset']['owner'];
                        error_log('FRG: Album owner from API: ' . $album_owner);
                    }
                }

                // Now get photos
                $photos_url = "https://www.flickr.com/services/rest/?" . http_build_query([
                    'method' => 'flickr.photosets.getPhotos',
                    'api_key' => $api_key,
                    'photoset_id' => $album_id,
                    'extras' => 'url_l,owner,path_alias,username',
                    'format' => 'json',
                    'nojsoncallback' => 1,
                    'user_id' => $album_owner
                ]);

                error_log('FRG: Fetching photos with URL: ' . $photos_url);

                $response = wp_remote_get($photos_url);

                if (is_wp_error($response)) {
                    error_log('FRG: API error getting photos: ' . $response->get_error_message());
                    $cache_status[$album_id] = 'api error';
                    continue;
                }

                if (!is_wp_error($response)) {
                    $body = wp_remote_retrieve_body($response);
                    $photo_data = json_decode($body, true);
                    
                    // Check for API error response
                    if (isset($photo_data['stat']) && $photo_data['stat'] === 'fail') {
                        error_log('FRG: Flickr API error: ' . json_encode($photo_data));
                        $cache_status[$album_id] = 'flickr error: ' . ($photo_data['message'] ?? 'Unknown error');
                        continue;
                    }
                    
                    error_log('FRG: API Response: ' . print_r($photo_data, true));

                    if (!empty($photo_data['photoset']['photo'])) {
                        foreach ($photo_data['photoset']['photo'] as &$photo) {
                            $photo['album_id'] = $album_id;
                            $photo['owner'] = $album_owner;
                            error_log('FRG: Processing photo: ' . print_r($photo, true));
                        }
                        $photos = array_merge($photos, $photo_data['photoset']['photo']);
                        
                        // Update cache for this album if table exists
                        if ($table_exists) {
                            $result = $wpdb->replace(
                            $table_name,
                            array(
                                'album_id' => $album_id,
                                'photo_data' => $body,
                                'last_updated' => current_time('mysql')
                            ),
                            array('%s', '%s', '%s')
                        );
                        
                        if ($result === false) {
                            error_log('FRG: Database error: ' . $wpdb->last_error);
                        } else {
                            error_log('FRG: Successfully cached photos for album: ' . $album_id);
                            $cache_status[$album_id] = 'updated';
                            }
                        }
                    } else {
                        error_log('FRG: No photos found in album response: ' . json_encode($photo_data));
                        $cache_status[$album_id] = 'no photos';
                    }
                }
            }
        }

        if (empty($photos)) {
            wp_send_json_error([
                'message' => 'No photos found',
                'debug' => [
                    'cache_status' => $cache_status,
                    'albums' => $selected_albums,
                    'table_exists' => $table_exists
                ]
            ]);
            return;
        }

        // Shuffle and limit photos
        shuffle($photos);
        $count = isset($_GET['count']) ? absint($_GET['count']) : 9;
        $photos = array_slice($photos, 0, $count);

        error_log('FRG: Final photos array: ' . print_r($photos, true));
        
        // Add indicator to show this was a reshuffle rather than a cache refresh
        $operation_type = $force_refresh ? 'api_refresh' : ($cache_hit ? 'cache_reshuffle' : 'initial_load');
        
        // Log the operation type
        error_log('FRG: Operation type: ' . $operation_type);
        
        // Include cache status and operation type in response
        wp_send_json_success([
            'photos' => $photos,
            'cache' => [
                'hit' => $cache_hit,
                'status' => $cache_status,
                'operation' => $operation_type
            ]
        ]);

    } catch (Exception $e) {
        error_log('FRG Error: ' . $e->getMessage());
        wp_send_json_error([
            'message' => $e->getMessage(),
            'code' => $e->getCode()
        ]);
    }
}

// Add a cache table repair function
add_action('wp_ajax_frg_repair_cache', 'frg_ajax_repair_cache');
function frg_ajax_repair_cache() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
        return;
    }
    
    check_ajax_referer('frg-repair-cache', 'nonce');
    
    // Recreate the cache table
    frg_create_cache_table();
    
    // Refresh all caches
    frg_refresh_cache();
    
    wp_send_json_success(['message' => 'Cache table repaired and refreshed']);
}

// Rest of the error handling code remains the same...
?>
