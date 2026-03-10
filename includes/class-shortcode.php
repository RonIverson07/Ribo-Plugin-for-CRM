<?php
if (!defined('ABSPATH')) { exit; }

class RIBO_Shortcode {
    public static function init() {
        add_shortcode('ribo_form', [__CLASS__, 'render']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
    }

    public static function register_assets() {
        wp_register_style('ribo-form-css', RIBO_WP_INBOUND_URL . 'public/assets/form.css', [], RIBO_WP_INBOUND_VERSION);
        wp_register_script('ribo-form-js', RIBO_WP_INBOUND_URL . 'public/assets/form.js', [], RIBO_WP_INBOUND_VERSION, true);
    }

    public static function render($atts) {
        $atts = shortcode_atts(['id' => ''], $atts, 'ribo_form');
        $form_id = sanitize_text_field($atts['id']);
        if (!$form_id) { return '<!-- ribo_form: missing id -->'; }

        $form = RIBO_DB::get_form($form_id);
        if (!$form || $form['status'] !== 'published') { return '<!-- ribo_form: not found -->'; }

        $schema = json_decode($form['schema_json'], true);
        if (!is_array($schema)) { return '<!-- ribo_form: invalid schema -->'; }
        $schema = RIBO_DB::normalize_schema($schema, [
            'id' => $form['id'],
            'name' => $form['name'],
            'status' => $form['status'],
        ]);

        $fields = isset($schema['fields']) && is_array($schema['fields']) ? $schema['fields'] : [];
        $ui = isset($schema['ui']) && is_array($schema['ui']) ? $schema['ui'] : [];
        $submit_text = isset($ui['submit_text']) ? sanitize_text_field($ui['submit_text']) : 'Send';
        $base_width = isset($ui['canvas_width']) ? floatval($ui['canvas_width']) : 0;
        if ($base_width < 320) { $base_width = 720; }
        $stage_height = isset($ui['canvas_height']) ? floatval($ui['canvas_height']) : 0;
        if ($stage_height < 240) { $stage_height = 240; }

        foreach ($fields as $layout_field) {
            $layout_type = sanitize_text_field($layout_field['type'] ?? 'text');
            if ($layout_type === 'hidden') { continue; }
            $layout_settings = isset($layout_field['settings']) && is_array($layout_field['settings']) ? $layout_field['settings'] : [];
            $layout_top = isset($layout_settings['canvas_y']) ? floatval($layout_settings['canvas_y']) : 0;
            if ($layout_top < 0) $layout_top = 0;
            $layout_height = 0;
            if (isset($layout_settings['height'])) {
                $layout_height = floatval($layout_settings['height']);
            } elseif (isset($layout_settings['box_height'])) {
                $layout_height = floatval($layout_settings['box_height']);
            }
            if ($layout_height <= 0) { $layout_height = 86; }
            $stage_height = max($stage_height, $layout_top + $layout_height + 12);
        }

        wp_enqueue_style('ribo-form-css');
        wp_enqueue_script('ribo-form-js');
        wp_localize_script('ribo-form-js', 'RIBO_FORM', [
            'restUrl' => esc_url_raw(rest_url('ribo/v1/forms/' . $form_id . '/submit')),
            'nonce' => wp_create_nonce('wp_rest')
        ]);

        ob_start();
        ?>
        <div class="ribo-form-wrap ribo-form-wrap--layout" data-form-id="<?php echo esc_attr($form_id); ?>" style="max-width:<?php echo esc_attr(round($base_width, 1)); ?>px;">
          <form class="ribo-form ribo-form--layout" novalidate>
            <input type="hidden" name="_page_url" value="<?php echo esc_url(get_permalink()); ?>">
            <div class="ribo-form-stage" style="min-height:<?php echo esc_attr(round($stage_height, 1)); ?>px;">
            <?php foreach ($fields as $f):
                $fid = sanitize_text_field($f['id'] ?? '');
                if (!$fid) continue;

                $type = sanitize_text_field($f['type'] ?? 'text');
                $label = sanitize_text_field($f['label'] ?? '');
                $required = !empty($f['required']);
                $settings = isset($f['settings']) && is_array($f['settings']) ? $f['settings'] : [];
                $ph = sanitize_text_field($settings['placeholder'] ?? '');
                $default_value = isset($settings['default_value']) ? sanitize_text_field($settings['default_value']) : '';

                if ($type === 'hidden') {
                    ?>
                    <input id="<?php echo esc_attr($fid); ?>" type="hidden" name="<?php echo esc_attr($fid); ?>" value="<?php echo esc_attr($default_value); ?>">
                    <?php
                    continue;
                }

                $width_units = isset($f['width']) ? floatval($f['width']) : 12.0;
                if ($width_units < 1) $width_units = 1;
                if ($width_units > 12) $width_units = 12;
                $width_units = round($width_units, 1);
                $width_px = max(120, ($width_units / 12.0) * $base_width);
                $width_pct = round(($width_px / max(1, $base_width)) * 100.0, 4);
                $left_px = isset($settings['canvas_x']) ? floatval($settings['canvas_x']) : 0;
                if ($left_px < 0) $left_px = 0;
                $left_pct = round(($left_px / max(1, $base_width)) * 100.0, 4);
                $top_px = isset($settings['canvas_y']) ? floatval($settings['canvas_y']) : 0;
                if ($top_px < 0) $top_px = 0;

                $height_px = 0;
                if (isset($settings['height'])) {
                    $height_px = floatval($settings['height']);
                } elseif (isset($settings['box_height'])) {
                    $height_px = floatval($settings['box_height']);
                }
                if ($height_px < 0) { $height_px = 0; }
                $field_box_height = $height_px > 0 ? $height_px : 86;
            ?>
              <div class="ribo-field" style="left:<?php echo esc_attr($left_pct); ?>%;top:<?php echo esc_attr(round($top_px, 1)); ?>px;width:<?php echo esc_attr($width_pct); ?>%;min-height:<?php echo esc_attr(round($field_box_height, 1)); ?>px;">
                <?php if ($label): ?>
                  <label for="<?php echo esc_attr($fid); ?>"><?php echo esc_html($label); ?><?php echo $required ? ' <span class="ribo-req">*</span>' : ''; ?></label>
                <?php endif; ?>

                <?php if ($type === 'textarea'): ?>
                  <textarea id="<?php echo esc_attr($fid); ?>" name="<?php echo esc_attr($fid); ?>" placeholder="<?php echo esc_attr($ph); ?>" <?php echo $required ? 'required' : ''; ?><?php echo $height_px ? ' style="height:' . esc_attr($height_px) . 'px;min-height:' . esc_attr($height_px) . 'px;"' : ''; ?>></textarea>
                <?php elseif ($type === 'dropdown'):
                    $choices = $settings['choices'] ?? [];
                    if (!is_array($choices)) $choices = [];
                ?>
                  <select id="<?php echo esc_attr($fid); ?>" name="<?php echo esc_attr($fid); ?>" <?php echo $required ? 'required' : ''; ?><?php echo $height_px ? ' style="height:' . esc_attr($height_px) . 'px;"' : ''; ?>>
                    <option value="">Select…</option>
                    <?php foreach ($choices as $c): $c = sanitize_text_field($c); ?>
                      <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif ($type === 'checkboxes'):
                    $choices = $settings['choices'] ?? [];
                    if (!is_array($choices)) $choices = [];
                ?>
                  <div class="ribo-choices"<?php echo $height_px ? ' style="max-height:' . esc_attr($height_px) . 'px;overflow:auto;"' : ''; ?>>
                    <?php foreach ($choices as $c): $c = sanitize_text_field($c); ?>
                      <label class="ribo-choice">
                        <input type="checkbox" name="<?php echo esc_attr($fid); ?>[]" value="<?php echo esc_attr($c); ?>">
                        <span><?php echo esc_html($c); ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                <?php elseif ($type === 'recaptcha'): ?>
                  <div class="ribo-recaptcha" style="padding:10px;border:1px dashed #dcdcde;border-radius:10px;background:#fbfbfb;color:#646970;">
                    reCAPTCHA placeholder (configure validation later)
                  </div>
                <?php else:
                    $html_type = ($type === 'email') ? 'email' : (($type === 'phone') ? 'tel' : (($type === 'number') ? 'number' : (($type === 'date') ? 'date' : 'text')));
                    if ($type === 'file') { $html_type = 'url'; }
                ?>
                  <input id="<?php echo esc_attr($fid); ?>" type="<?php echo esc_attr($html_type); ?>" name="<?php echo esc_attr($fid); ?>" placeholder="<?php echo esc_attr($ph); ?>" <?php echo $required ? 'required' : ''; ?><?php echo $height_px ? ' style="height:' . esc_attr($height_px) . 'px;"' : ''; ?>>
                <?php endif; ?>

                <div class="ribo-error" data-error-for="<?php echo esc_attr($fid); ?>"></div>
              </div>
            <?php endforeach; ?>
            </div>

            <button type="submit" class="ribo-submit"><?php echo esc_html($submit_text); ?></button>
            <div class="ribo-status" aria-live="polite"></div>
          </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
