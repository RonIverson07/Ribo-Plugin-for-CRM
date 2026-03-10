<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Elementor widget for RIBO Inbound Forms.
 */
class RIBO_Elementor_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'ribo_form_widget';
    }

    public function get_title() {
        return __('RIBO Form', 'ribo-wp-inbound');
    }

    public function get_icon() {
        return 'eicon-form-horizontal';
    }

    public function get_categories() {
        return ['general'];
    }

    public function get_keywords() {
        return ['ribo', 'form', 'contact', 'crm', 'lead'];
    }

    protected function register_controls() {

        $this->start_controls_section(
            'section_content',
            [
                'label' => __('Content', 'ribo-wp-inbound'),
            ]
        );

        $this->add_control(
            'mode',
            [
                'label' => __('Widget Mode', 'ribo-wp-inbound'),
                'type' => \Elementor\Controls_Manager::SELECT,
                'default' => 'existing',
                'options' => [
                    'existing' => __('Use Existing Form', 'ribo-wp-inbound'),
                    'manual'   => __('Manual Form Builder', 'ribo-wp-inbound'),
                ],
            ]
        );

        $published_forms = $this->get_published_forms_options();

        $this->add_control(
            'form_id',
            [
                'label' => __('Select Form', 'ribo-wp-inbound'),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => $published_forms,
                'condition' => [
                    'mode' => 'existing',
                ],
            ]
        );

        // --- Manual Mode Controls ---

        $this->add_control(
            'manual_form_name',
            [
                'label' => __('Form Name', 'ribo-wp-inbound'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => __('New Elementor Form', 'ribo-wp-inbound'),
                'condition' => [
                    'mode' => 'manual',
                ],
            ]
        );

        $this->add_control(
            'manual_submit_text',
            [
                'label' => __('Submit Button Text', 'ribo-wp-inbound'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => __('Send', 'ribo-wp-inbound'),
                'condition' => [
                    'mode' => 'manual',
                ],
            ]
        );

        $repeater = new \Elementor\Repeater();

        $repeater->add_control(
            'field_label',
            [
                'label' => __('Label', 'ribo-wp-inbound'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => __('Label', 'ribo-wp-inbound'),
            ]
        );

        $repeater->add_control(
            'field_type',
            [
                'label' => __('Type', 'ribo-wp-inbound'),
                'type' => \Elementor\Controls_Manager::SELECT,
                'default' => 'text',
                'options' => [
                    'text'     => __('Text', 'ribo-wp-inbound'),
                    'email'    => __('Email', 'ribo-wp-inbound'),
                    'phone'    => __('Phone', 'ribo-wp-inbound'),
                    'textarea' => __('Textarea', 'ribo-wp-inbound'),
                    'dropdown' => __('Select', 'ribo-wp-inbound'),
                    'checkboxes' => __('Checkbox', 'ribo-wp-inbound'),
                    'radio'    => __('Radio', 'ribo-wp-inbound'),
                    'hidden'   => __('Hidden', 'ribo-wp-inbound'),
                ],
            ]
        );

        $repeater->add_control(
            'field_placeholder',
            [
                'label' => __('Placeholder', 'ribo-wp-inbound'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'condition' => [
                    'field_type!' => ['dropdown', 'checkboxes', 'hidden'],
                ],
            ]
        );

        $repeater->add_control(
            'field_required',
            [
                'label' => __('Required', 'ribo-wp-inbound'),
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'default' => '',
                'label_on' => __('Yes', 'ribo-wp-inbound'),
                'label_off' => __('No', 'ribo-wp-inbound'),
                'return_value' => 'yes',
                'condition' => [
                    'field_type!' => 'hidden',
                ],
            ]
        );

        $repeater->add_control(
            'field_width',
            [
                'label' => __('Width (1-12 columns)', 'ribo-wp-inbound'),
                'type' => \Elementor\Controls_Manager::NUMBER,
                'min' => 1,
                'max' => 12,
                'step' => 1,
                'default' => 12,
                'condition' => [
                    'field_type!' => 'hidden',
                ],
            ]
        );

        $repeater->add_control(
            'field_options',
            [
                'label' => __('Options (one per line)', 'ribo-wp-inbound'),
                'type' => \Elementor\Controls_Manager::TEXTAREA,
                'condition' => [
                    'field_type' => ['dropdown', 'checkboxes'],
                ],
            ]
        );

        $repeater->add_control(
            'payload_key',
            [
                'label' => __('CRM Mapping Key (Payload Key)', 'ribo-wp-inbound'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'description' => __('Specify which CRM field this data should map to (e.g., "name", "email", "phone", "message").', 'ribo-wp-inbound'),
            ]
        );

        $this->add_control(
            'manual_fields',
            [
                'label' => __('Form Fields', 'ribo-wp-inbound'),
                'type' => \Elementor\Controls_Manager::REPEATER,
                'fields' => $repeater->get_controls(),
                'default' => [
                    [
                        'field_label' => __('Name', 'ribo-wp-inbound'),
                        'field_type' => 'text',
                        'field_width' => 12,
                        'payload_key' => 'name',
                        'field_required' => 'yes',
                    ],
                    [
                        'field_label' => __('Email', 'ribo-wp-inbound'),
                        'field_type' => 'email',
                        'field_width' => 12,
                        'payload_key' => 'email',
                        'field_required' => 'yes',
                    ],
                    [
                        'field_label' => __('Message', 'ribo-wp-inbound'),
                        'field_type' => 'textarea',
                        'field_width' => 12,
                        'payload_key' => 'message',
                    ],
                ],
                'title_field' => '{{{ field_label }}} ({{{ field_type }}})',
                'condition' => [
                    'mode' => 'manual',
                ],
            ]
        );

        $this->add_control(
            'internal_ribo_form_id',
            [
                'label' => __('Internal RIBO Form ID', 'ribo-wp-inbound'),
                'type' => \Elementor\Controls_Manager::HIDDEN,
                'default' => '',
            ]
        );

        $this->end_controls_section();

        // --- Style Section ---
        $this->start_controls_section(
            'section_style',
            [
                'label' => __('Form Wrapper Style', 'ribo-wp-inbound'),
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'wrapper_max_width',
            [
                'label'      => __('Max Width', 'ribo-wp-inbound'),
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => ['px', '%', 'vw'],
                'range'      => [
                    'px' => ['min' => 200, 'max' => 2000],
                    '%'  => ['min' => 0, 'max' => 100],
                ],
                'default'    => [
                    'size' => 100,
                    'unit' => '%',
                ],
                'selectors'  => [
                    '{{WRAPPER}} .ribo-form-wrap' => 'max-width: {{SIZE}}{{UNIT}} !important; width: 100% !important;',
                ],
            ]
        );

        $this->add_responsive_control(
            'wrapper_alignment',
            [
                'label'     => __('Alignment', 'ribo-wp-inbound'),
                'type'      => \Elementor\Controls_Manager::CHOOSE,
                'options'   => [
                    'flex-start' => [
                        'title' => __('Left', 'ribo-wp-inbound'),
                        'icon'  => 'eicon-text-align-left',
                    ],
                    'center'     => [
                        'title' => __('Center', 'ribo-wp-inbound'),
                        'icon'  => 'eicon-text-align-center',
                    ],
                    'flex-end'   => [
                        'title' => __('Right', 'ribo-wp-inbound'),
                        'icon'  => 'eicon-text-align-right',
                    ],
                ],
                'default'   => 'center',
                'selectors' => [
                    '{{WRAPPER}} .elementor-widget-container' => 'display: flex; flex-direction: column; align-items: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_responsive_control(
            'wrapper_padding',
            [
                'label'      => __('Padding', 'ribo-wp-inbound'),
                'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', '%'],
                'selectors'  => [
                    '{{WRAPPER}} .ribo-form-wrap' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
                ],
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Background::get_type(),
            [
                'name'     => 'wrapper_background',
                'label'    => __('Background', 'ribo-wp-inbound'),
                'types'    => ['classic', 'gradient'],
                'selector' => '{{WRAPPER}} .ribo-form-wrap',
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Border::get_type(),
            [
                'name'     => 'wrapper_border',
                'label'    => __('Border', 'ribo-wp-inbound'),
                'selector' => '{{WRAPPER}} .ribo-form-wrap',
            ]
        );

        $this->add_responsive_control(
            'wrapper_border_radius',
            [
                'label'      => __('Border Radius', 'ribo-wp-inbound'),
                'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%'],
                'selectors'  => [
                    '{{WRAPPER}} .ribo-form-wrap' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
                ],
            ]
        );

        $this->end_controls_section();
    }

    private function get_published_forms_options() {
        $forms = RIBO_DB::list_forms();
        $options = ['' => __('Select form…', 'ribo-wp-inbound')];
        foreach ($forms as $f) {
            if ($f['status'] === 'published') {
                $options[$f['id']] = $f['name'] ?: $f['id'];
            }
        }
        return $options;
    }

    protected function render() {
        $settings  = $this->get_settings_for_display();
        $mode      = $settings['mode'] ?? 'existing';
        $is_editor = \Elementor\Plugin::$instance->editor->is_edit_mode();

        if ($is_editor) {
            // Enable Canva-style drag-and-resize!
            // Background is pointer-events:none so you can select the widget handles.
            // Fields are pointer-events:auto so you can move/resize them.
            echo '<style>
                .ribo-el-interactive { 
                    position: relative;
                    width: 100%;
                }
                .ribo-el-interactive .ribo-form-wrap {
                    pointer-events: none !important;
                }
                .ribo-el-interactive .ribo-field {
                    pointer-events: auto !important;
                }
            </style>';
            echo '<div class="ribo-el-interactive">';
        }

        if ($mode === 'existing') {
            $form_id = $settings['form_id'];
            if (!$form_id) {
                echo '<div class="elementor-alert elementor-alert-warning">' . __('Please select a RIBO form.', 'ribo-wp-inbound') . '</div>';
                if ($is_editor) echo '</div>';
                return;
            }
            echo do_shortcode('[ribo_form id="' . esc_attr($form_id) . '"]');
        } else {
            // Manual Mode
            $schema = $this->convert_settings_to_schema($settings);

            // Sync to DB so the canvas JS / submit endpoint can find it
            if ($is_editor || \Elementor\Plugin::$instance->preview->is_preview_mode()) {
                $this->sync_manual_form_to_db($schema, $settings);
            }

            echo RIBO_Shortcode::render_schema($schema);
        }

        if ($is_editor) {
            echo '</div>';
        }
    }

    /**
     * Build a schema from Elementor panel controls.
     * Preserves canvas_x / canvas_y / height from any previously
     * drag-positioned save in the DB so positions survive panel edits.
     */
    private function convert_settings_to_schema($settings) {
        $form_name   = !empty($settings['manual_form_name'])  ? $settings['manual_form_name']  : 'Elementor Form';
        $submit_text = !empty($settings['manual_submit_text']) ? $settings['manual_submit_text'] : 'Send';
        $fields_data = !empty($settings['manual_fields'])     ? $settings['manual_fields']      : [];
        $form_id     = $settings['internal_ribo_form_id'];

        if (empty($form_id)) {
            $form_id = 'el_form_' . $this->get_id();
        }

        // Load previously drag-saved positions from DB (field_id => settings array)
        $saved_positions = $this->load_saved_field_positions($form_id);

        $fields  = [];
        $mapping = [];

        foreach ($fields_data as $index => $f_item) {
            $f_id        = 'fld_el_' . $index;
            $type        = $f_item['field_type']       ?? 'text';
            $label       = $f_item['field_label']      ?? '';
            $required    = ($f_item['field_required']  === 'yes');
            $width       = (float)($f_item['field_width'] ?? 12);
            $ph          = $f_item['field_placeholder'] ?? '';
            $payload_key = trim($f_item['payload_key'] ?? '');

            $field_settings = ['placeholder' => $ph];

            if (in_array($type, ['dropdown', 'checkboxes', 'radio'], true)) {
                $options_text   = $f_item['field_options'] ?? '';
                $choices        = array_filter(array_map('trim', explode("\n", $options_text)));
                $field_settings['choices'] = array_values($choices);
            }

            // Restore drag positions if they were previously saved
            if (!empty($saved_positions[$f_id])) {
                $sp = $saved_positions[$f_id];
                foreach (['canvas_x', 'canvas_y', 'height', 'box_height'] as $pos_key) {
                    if (isset($sp[$pos_key]) && is_numeric($sp[$pos_key])) {
                        $field_settings[$pos_key] = (float) $sp[$pos_key];
                    }
                }
                // Also restore saved column width from drag if available
                if (isset($sp['_saved_width']) && is_numeric($sp['_saved_width'])) {
                    $width = (float) $sp['_saved_width'];
                }
            }

            $fields[] = [
                'id'       => $f_id,
                'type'     => $type,
                'label'    => $label,
                'required' => $required,
                'width'    => round($width, 1),
                'settings' => $field_settings,
            ];

            if ($payload_key) {
                $mapping[$f_id] = $payload_key;
            }
        }

        $schema = [
            'id'      => $form_id,
            'name'    => $form_name,
            'status'  => 'published',
            'version' => RIBO_WP_INBOUND_SCHEMA_VERSION,
            'fields'  => $fields,
            'ui'      => [
                'submit_text'  => $submit_text,
                'canvas_width' => 720,
            ],
            'mapping' => $mapping,
        ];

        return RIBO_DB::normalize_schema($schema);
    }

    /**
     * Load the saved per-field settings (position, height) from the DB schema
     * for this form so they can be reapplied when the panel controls change.
     *
     * @param  string $form_id
     * @return array  field_id => settings_array
     */
    private function load_saved_field_positions($form_id) {
        if (empty($form_id)) return [];
        $form = RIBO_DB::get_form($form_id);
        if (!$form) return [];
        $schema = json_decode($form['schema_json'], true);
        if (!is_array($schema) || empty($schema['fields'])) return [];

        $positions = [];
        foreach ($schema['fields'] as $f) {
            $fid = $f['id'] ?? '';
            if (!$fid) continue;
            $s = isset($f['settings']) && is_array($f['settings']) ? $f['settings'] : [];
            // Also carry the saved column width so drags survive panel label edits
            if (isset($f['width']) && is_numeric($f['width'])) {
                $s['_saved_width'] = (float) $f['width'];
            }
            $positions[$fid] = $s;
        }
        return $positions;
    }

    /**
     * Persist the manual form into the RIBO components system.
     */
    private function sync_manual_form_to_db($schema, $settings) {
        $form_id = $schema['id'];
        $name = $schema['name'];
        $status = $schema['status'];
        $version = $schema['version'];
        
        // Ensure name is unique and fits limits
        $name = RIBO_DB::make_unique_form_name($name, $form_id, 40);
        $schema['name'] = $name;

        $schema_json = wp_json_encode($schema, JSON_PRETTY_PRINT);
        
        RIBO_DB::upsert_form($form_id, $name, $schema_json, $status, $version);

        // If the form_id was just generated, we might want it to persist in the widget settings.
        // Elementor doesn't allow easy side-effect saving of controls during render,
        // but by using a stable generated ID or one derived from the widget instance ID, it works.
        // Let's use the widget ID if possible.
        // $this->get_id() is the unique widget instance ID.
    }
}
