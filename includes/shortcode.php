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

        error_log('FRG: User ID from options: ' . $user_id);

        if (empty($selected_albums)) {
            wp_send_json_error(['message' => 'No albums selected']);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'frg_cache';
        $photos = [];

        foreach ($selected_albums as $album_id) {
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

            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                error_log('FRG: API Response: ' . print_r($body, true));

                if (!empty($body['photoset']['photo'])) {
                    foreach ($body['photoset']['photo'] as &$photo) {
                        $photo['album_id'] = $album_id;
                        $photo['owner'] = $album_owner;

                        error_log('FRG: Processing photo: ' . print_r($photo, true));
                    }
                    $photos = array_merge($photos, $body['photoset']['photo']);
                }
            }
        }

        if (empty($photos)) {
            wp_send_json_error(['message' => 'No photos found']);
            return;
        }

        // Shuffle and limit photos
        shuffle($photos);
        $count = isset($_GET['count']) ? absint($_GET['count']) : 9;
        $photos = array_slice($photos, 0, $count);

        error_log('FRG: Final photos array: ' . print_r($photos, true));
        wp_send_json_success($photos);

    } catch (Exception $e) {
        error_log('FRG Error: ' . $e->getMessage());
        wp_send_json_error([
            'message' => $e->getMessage(),
            'code' => $e->getCode()
        ]);
    }
}

// Rest of the error handling code remains the same...
?>
