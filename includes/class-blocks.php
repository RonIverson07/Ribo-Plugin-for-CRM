<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Gutenberg block support.
 *
 * Goal: allow non-technical users to add a form via the + (block inserter)
 * without relying on shortcodes.
 */
class RIBO_Blocks {
    public static function init() {
        // Only register if Gutenberg / blocks are available.
        if (!function_exists('register_block_type')) {
            return;
        }
        add_action('init', [__CLASS__, 'register_block']);
    }

    /**
     * Get published forms for dropdowns.
     */
    private static function get_published_forms() {
        $forms = RIBO_DB::list_forms();
        $out = [];
        foreach ($forms as $f) {
            if (!is_array($f)) { continue; }
            if (($f['status'] ?? '') !== 'published') { continue; }
            $out[] = [
                'id' => (string)($f['id'] ?? ''),
                'name' => (string)($f['name'] ?? ''),
            ];
        }
        return $out;
    }

    public static function register_block() {
        // Register editor script.
        wp_register_script(
            'ribo-block-editor',
            RIBO_WP_INBOUND_URL . 'admin/assets/block.js',
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n'],
            RIBO_WP_INBOUND_VERSION,
            true
        );

        // Provide forms list to the editor.
        wp_localize_script('ribo-block-editor', 'RIBO_BLOCKS', [
            'forms' => self::get_published_forms(),
            'labelSelect' => __('Select a form', 'ribo-wp-inbound'),
            'labelNoForms' => __('No published forms found. Publish a form first.', 'ribo-wp-inbound'),
            'labelSelected' => __('Selected form', 'ribo-wp-inbound'),
        ]);

        register_block_type('ribo-crm/form', [
            'api_version' => 2,
            'editor_script' => 'ribo-block-editor',
            'render_callback' => [__CLASS__, 'render_block'],
            'attributes' => [
                'formId' => [
                    'type' => 'string',
                    'default' => '',
                ],
            ],
        ]);
    }

    public static function render_block($attributes) {
        $form_id = isset($attributes['formId']) ? sanitize_text_field($attributes['formId']) : '';
        if (!$form_id) {
            return '<!-- ribo-crm/form: missing formId -->';
        }
        // Reuse the existing shortcode rendering so we don't introduce a new rendering path.
        return do_shortcode('[ribo_form id="' . esc_attr($form_id) . '"]');
    }
}
