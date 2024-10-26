<?php
/*
Plugin Name: Flickr Random Gallery
Description: Display random photos from selected Flickr albums using a shortcode with async loading
Version: 1.3
Author: Stephan Janssen
*/

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('FRG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FRG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FRG_VERSION', '1.3');

// Include required files
require_once FRG_PLUGIN_DIR . 'includes/admin.php';
require_once FRG_PLUGIN_DIR . 'includes/cache.php';
require_once FRG_PLUGIN_DIR . 'includes/shortcode.php';

// Register activation hook
register_activation_hook(__FILE__, 'frg_activate_plugin');
function frg_activate_plugin() {
    // Create cache table
    require_once FRG_PLUGIN_DIR . 'includes/cache.php';
    frg_create_cache_table();

    // Schedule cache refresh
    if (!wp_next_scheduled('frg_daily_cache_refresh')) {
        wp_schedule_event(time(), 'daily', 'frg_daily_cache_refresh');
    }
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'frg_deactivate_plugin');
function frg_deactivate_plugin() {
    wp_clear_scheduled_hook('frg_daily_cache_refresh');
}

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', 'frg_enqueue_scripts');
function frg_enqueue_scripts() {
    wp_enqueue_style('frg-gallery', FRG_PLUGIN_URL . 'css/gallery_v3.css', array(), FRG_VERSION);
    wp_enqueue_script('frg-gallery', FRG_PLUGIN_URL . 'js/gallery_v8.js', array('jquery'), FRG_VERSION, true);
    wp_localize_script('frg-gallery', 'frgAjax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('frg-gallery-nonce')
    ));
}

// Admin styles - only load on plugin admin pages
function frg_enqueue_admin_scripts($hook) {
    if (strpos($hook, 'flickr-random-gallery') !== false) {
        wp_enqueue_style('frg-admin', FRG_PLUGIN_URL . 'css/admin_v3.css', array(), FRG_VERSION);
    }
}
add_action('admin_enqueue_scripts', 'frg_enqueue_admin_scripts');
