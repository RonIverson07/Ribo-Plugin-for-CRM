<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Elementor integration loader.
 * Registers the widget and handles Elementor-specific initialization.
 */
class RIBO_Elementor_Loader {
    public static function init() {
        // Register widget
        add_action('elementor/widgets/register', [__CLASS__, 'register_widgets']);

        // Enqueue interactive canvas assets inside the Elementor PREVIEW iframe
        add_action('elementor/preview/enqueue_scripts', [__CLASS__, 'enqueue_preview_scripts']);
        add_action('elementor/preview/enqueue_scripts', [__CLASS__, 'enqueue_preview_styles']);
    }

    /**
     * @param \Elementor\Widgets_Manager $widgets_manager
     */
    public static function register_widgets($widgets_manager) {
        require_once RIBO_WP_INBOUND_DIR . 'includes/class-elementor-widget.php';
        $widgets_manager->register(new \RIBO_Elementor_Widget());
    }

    /**
     * Enqueue canvas scripts (jQuery UI drag/resize + RIBO canvas logic)
     * inside the Elementor preview iframe so fields are draggable/resizable.
     */
    public static function enqueue_preview_scripts() {
        // jQuery UI drag/resize helpers (WP ships them; we just declare dependencies)
        wp_enqueue_script('jquery-ui-draggable');
        wp_enqueue_script('jquery-ui-resizable');

        // Public form CSS so the preview looks exact
        wp_enqueue_style(
            'ribo-form-css-el-preview',
            RIBO_WP_INBOUND_URL . 'public/assets/form.css',
            [],
            RIBO_WP_INBOUND_VERSION
        );

        // Our interactive canvas script
        wp_enqueue_script(
            'ribo-el-canvas-js',
            RIBO_WP_INBOUND_URL . 'public/assets/elementor-canvas.js',
            ['jquery', 'jquery-ui-draggable', 'jquery-ui-resizable'],
            RIBO_WP_INBOUND_VERSION,
            true
        );

        // Pass REST config to the canvas script
        wp_localize_script('ribo-el-canvas-js', 'RIBO_EL_CANVAS', [
            'rest_base' => esc_url_raw(rest_url('ribo/v1/forms/')),
            'nonce'     => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Enqueue canvas / interactive styles.
     */
    public static function enqueue_preview_styles() {
        wp_enqueue_style(
            'ribo-el-canvas-css',
            RIBO_WP_INBOUND_URL . 'public/assets/elementor-canvas.css',
            [],
            RIBO_WP_INBOUND_VERSION
        );
    }

    /**
     * Check if Elementor is active and loaded.
     */
    public static function is_elementor_active() {
        return did_action('elementor/loaded');
    }
}
