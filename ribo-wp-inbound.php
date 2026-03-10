<?php
/*
Plugin Name: RIBO WP Inbound Forms
Description: WPForms-inspired drag-and-drop form builder (v1), shortcode embed, and reliable CRM lead sync with queue + retries.
Version: 2.1.1
Author: RIBO
*/

if (!defined('ABSPATH')) { exit; }

define('RIBO_WP_INBOUND_VERSION', '2.1.1');
define('RIBO_WP_INBOUND_SCHEMA_VERSION', 2);
define('RIBO_WP_INBOUND_DIR', plugin_dir_path(__FILE__));
define('RIBO_WP_INBOUND_URL', plugin_dir_url(__FILE__));


if (!function_exists('ribo_wrap_text_lines')) {
    function ribo_wrap_text_lines($text, $max_chars = 20) {
        $text = trim(wp_strip_all_tags((string) $text));
        if ($text === '') { return ''; }

        $words = preg_split('/\s+/', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if ($word === '') { continue; }

            if ($current === '') {
                while (function_exists('mb_strlen') && mb_strlen($word) > $max_chars || !function_exists('mb_strlen') && strlen($word) > $max_chars) {
                    if (function_exists('mb_substr')) {
                        $lines[] = mb_substr($word, 0, $max_chars);
                        $word = mb_substr($word, $max_chars);
                    } else {
                        $lines[] = substr($word, 0, $max_chars);
                        $word = substr($word, $max_chars);
                    }
                }
                $current = $word;
                continue;
            }

            $candidate = $current . ' ' . $word;
            $candidate_len = function_exists('mb_strlen') ? mb_strlen($candidate) : strlen($candidate);
            if ($candidate_len <= $max_chars) {
                $current = $candidate;
                continue;
            }

            $lines[] = $current;
            $current = '';

            while (function_exists('mb_strlen') && mb_strlen($word) > $max_chars || !function_exists('mb_strlen') && strlen($word) > $max_chars) {
                if (function_exists('mb_substr')) {
                    $lines[] = mb_substr($word, 0, $max_chars);
                    $word = mb_substr($word, $max_chars);
                } else {
                    $lines[] = substr($word, 0, $max_chars);
                    $word = substr($word, $max_chars);
                }
            }
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return implode("
", $lines);
    }
}

require_once RIBO_WP_INBOUND_DIR . 'includes/class-db.php';
require_once RIBO_WP_INBOUND_DIR . 'includes/class-logger.php';
require_once RIBO_WP_INBOUND_DIR . 'includes/class-api-client.php';
require_once RIBO_WP_INBOUND_DIR . 'includes/class-rest.php';
require_once RIBO_WP_INBOUND_DIR . 'includes/class-shortcode.php';
require_once RIBO_WP_INBOUND_DIR . 'includes/class-blocks.php';
require_once RIBO_WP_INBOUND_DIR . 'includes/class-widget.php';
require_once RIBO_WP_INBOUND_DIR . 'includes/class-cron.php';
require_once RIBO_WP_INBOUND_DIR . 'includes/class-elementor-loader.php';
require_once RIBO_WP_INBOUND_DIR . 'admin/class-admin-menu.php';

register_activation_hook(__FILE__, ['RIBO_DB', 'activate']);
register_deactivation_hook(__FILE__, ['RIBO_Cron', 'deactivate']);

add_action('plugins_loaded', function() {
    RIBO_DB::init();
    RIBO_DB::maybe_run_migrations();
    RIBO_Logger::init();
    RIBO_Cron::init();
    RIBO_REST::init();
    RIBO_Shortcode::init();
    RIBO_Blocks::init();
    if (class_exists('RIBO_Form_Widget')) {
        RIBO_Form_Widget::init();
    }
    if (RIBO_Elementor_Loader::is_elementor_active()) {
        RIBO_Elementor_Loader::init();
    }
    if (is_admin()) {
        RIBO_Admin_Menu::init();
    }
});

/**
 * Admin assets (Form Builder)
 */
add_action('admin_enqueue_scripts', function($hook){
    // Only load on our builder page
    if (!is_admin()) { return; }
    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

    // Global UI styles for all plugin pages
    if (strpos($page, 'ribo-crm') === 0) {
        wp_enqueue_style('ribo-app-css', RIBO_WP_INBOUND_URL . 'admin/assets/app.css', [], RIBO_WP_INBOUND_VERSION);
    }

    if ($page !== 'ribo-crm-builder') { return; }

    // Core WP jQuery UI helpers
    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_script('jquery-ui-draggable');
    wp_enqueue_script('jquery-ui-droppable');
    wp_enqueue_script('jquery-ui-resizable');

    wp_enqueue_style('ribo-builder-css', RIBO_WP_INBOUND_URL . 'admin/assets/builder.css', [], RIBO_WP_INBOUND_VERSION);
    wp_enqueue_script('ribo-builder-js', RIBO_WP_INBOUND_URL . 'admin/assets/builder.js', ['jquery','jquery-ui-sortable','jquery-ui-draggable','jquery-ui-droppable','jquery-ui-resizable'], RIBO_WP_INBOUND_VERSION, true);

    // Public form styling reused by Builder "Preview Mode".
    wp_enqueue_style('ribo-form-css-preview', RIBO_WP_INBOUND_URL . 'public/assets/form.css', [], RIBO_WP_INBOUND_VERSION);
}, 20);

/**
 * Settings registration
 */
add_action('admin_init', function(){
    // Store with autoload=no by default
    register_setting('ribo_settings', 'ribo_crm_api_key', ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
    register_setting('ribo_settings', 'ribo_retry_max_attempts', ['type'=>'integer','sanitize_callback'=>'absint','default'=>3]);
    register_setting('ribo_settings', 'ribo_cron_interval_minutes', ['type'=>'integer','sanitize_callback'=>'absint','default'=>5]);

    add_filter('pre_update_option_ribo_crm_api_key', function($new, $old) {
        // allow blank to keep old if user didn't intend to overwrite (but UI provides overwrite explicitly)
        return $new;
    }, 10, 2);

    // Ensure autoload=no
    add_filter('pre_update_option', function($value, $option, $old_value){
        if (in_array($option, ['ribo_crm_api_key','ribo_retry_max_attempts','ribo_cron_interval_minutes'], true)) {
            global $wpdb;
            $autoload = 'no';
            $existing = $wpdb->get_var($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name=%s", $option));
            if ($existing) {
                // wp will update value; autoload stays as-is. We'll force it after update below.
            } else {
                // add with autoload=no
                add_option($option, $value, '', $autoload);
                return $old_value; // prevent double-add; WordPress will then update old_value; safe
            }
        }
        return $value;
    }, 10, 3);

    add_action('updated_option', function($option, $old, $value){
        if (in_array($option, ['ribo_crm_api_key','ribo_retry_max_attempts','ribo_cron_interval_minutes'], true)) {
            global $wpdb;
            $wpdb->update($wpdb->options, ['autoload'=>'no'], ['option_name'=>$option]);
        }
    }, 10, 3);
});
