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

    return sprintf(
        '<div class="flickr-random-gallery"
              data-columns="%d"
              data-count="%d"
              data-target="%s"
              style="display: grid; grid-template-columns: repeat(%d, 1fr); gap: 20px;">
            <div class="frg-loading">
                <div class="frg-spinner"></div>
                <p>Loading gallery...</p>
            </div>
         </div>',
        esc_attr($atts['columns']),
        esc_attr($atts['count']),
        esc_attr($atts['target']),
        esc_attr($atts['columns'])
    );
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

        if (empty($selected_albums)) {
            wp_send_json_error(['message' => 'No albums selected']);
            return;
        }

        $photos = [];
        foreach ($selected_albums as $album_id) {
            $photos_url = "https://www.flickr.com/services/rest/?" . http_build_query([
                    'method' => 'flickr.photosets.getPhotos',
                    'api_key' => $api_key,
                    'photoset_id' => $album_id,
                    'extras' => 'url_l,owner',
                    'format' => 'json',
                    'nojsoncallback' => 1
                ]);

            $response = wp_remote_get($photos_url);

            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body['photoset']['photo'])) {
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

        wp_send_json_success($photos);

    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'code' => $e->getCode()
        ]);
    }
}

add_action('wp_footer', 'frg_add_error_handling_script');
function frg_add_error_handling_script() {
    if (has_shortcode(get_the_content(), 'flickr_random_gallery')) {
        ?>
        <script>
            (function($) {
                $(document).ajaxError(function(event, jqXHR, settings, error) {
                    if (settings.url === frgAjax.ajaxurl && settings.data.includes('frg_load_photos')) {
                        console.error('Flickr Gallery AJAX Error:', error);
                        $('.flickr-random-gallery').html(
                            '<div class="frg-error">' +
                            '<p>Error loading gallery. Please try again later.</p>' +
                            '<button class="frg-retry-button">Retry</button>' +
                            '</div>'
                        );
                    }
                });

                $(document).on('click', '.frg-retry-button', function() {
                    var $gallery = $(this).closest('.flickr-random-gallery');
                    window.frgRefreshGallery($gallery);
                });
            })(jQuery);
        </script>
        <?php
    }
}

// Add to your plugin's activation hook
function frg_check_php_output_buffering() {
    $output_buffering = ini_get('output_buffering');
    if (!$output_buffering) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-warning">
                <p>Warning: PHP output buffering is disabled. This may cause issues with AJAX responses in the Flickr Random Gallery plugin. Please enable output_buffering in your PHP configuration for optimal performance.</p>
            </div>
            <?php
        });
    }
}
add_action('admin_init', 'frg_check_php_output_buffering');
?>
