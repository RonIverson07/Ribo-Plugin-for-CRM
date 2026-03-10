<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Classic WP Widget support (Appearance -> Widgets).
 *
 * This is useful for themes still using widget areas (sidebars/footers),
 * and also satisfies "plugin must appear as a widget" requirements.
 */
if (class_exists('WP_Widget')):

class RIBO_Form_Widget extends WP_Widget {
    public static function init() {
        add_action('widgets_init', function() {
            register_widget(__CLASS__);
        });
    }

    public function __construct() {
        parent::__construct(
            'ribo_form_widget',
            __('RIBO Form', 'ribo-wp-inbound'),
            ['description' => __('Embed a RIBO form in a widget area.', 'ribo-wp-inbound')]
        );
    }

    private function get_published_forms() {
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

    public function widget($args, $instance) {
        $title = isset($instance['title']) ? (string)$instance['title'] : '';
        $form_id = isset($instance['form_id']) ? (string)$instance['form_id'] : '';

        echo $args['before_widget'];
        if ($title) {
            echo $args['before_title'] . apply_filters('widget_title', $title) . $args['after_title'];
        }
        if ($form_id) {
            echo do_shortcode('[ribo_form id="' . esc_attr($form_id) . '"]');
        } else {
            // Keep it quiet on the frontend.
            echo '<!-- RIBO Form Widget: missing form selection -->';
        }
        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = isset($instance['title']) ? esc_attr((string)$instance['title']) : '';
        $form_id = isset($instance['form_id']) ? esc_attr((string)$instance['form_id']) : '';
        $forms = $this->get_published_forms();
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title (optional):', 'ribo-wp-inbound'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo $title; ?>" />
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('form_id')); ?>"><?php esc_html_e('Form:', 'ribo-wp-inbound'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('form_id')); ?>" name="<?php echo esc_attr($this->get_field_name('form_id')); ?>">
                <option value=""><?php esc_html_e('Select…', 'ribo-wp-inbound'); ?></option>
                <?php foreach ($forms as $f):
                    $id = esc_attr($f['id']);
                    $name = esc_html($f['name'] ?: $f['id']);
                ?>
                    <option value="<?php echo $id; ?>" <?php selected($form_id, $id); ?>><?php echo $name; ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($forms)): ?>
                <small style="display:block;margin-top:6px;opacity:0.8;">
                    <?php esc_html_e('No published forms found. Publish a form first.', 'ribo-wp-inbound'); ?>
                </small>
            <?php endif; ?>
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = sanitize_text_field($new_instance['title'] ?? '');
        $instance['form_id'] = sanitize_text_field($new_instance['form_id'] ?? '');
        return $instance;
    }
}

endif;
