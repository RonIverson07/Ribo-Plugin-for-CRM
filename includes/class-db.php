<?php
if (!defined('ABSPATH')) { exit; }

class RIBO_DB {
    public static function init() {}

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $forms = $wpdb->prefix . 'ribo_forms';
        $pending = $wpdb->prefix . 'ribo_pending_submissions';
        $logs = $wpdb->prefix . 'ribo_logs';

        $sql1 = "CREATE TABLE $forms (
            id varchar(64) NOT NULL,
            name varchar(190) NOT NULL,
            schema_json longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            version int NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE $pending (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id varchar(64) NOT NULL,
            submission_id char(36) NOT NULL,
            payload_json longtext NOT NULL,
            attempts int NOT NULL DEFAULT 0,
            last_error text NULL,
            next_retry_at datetime NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY submission_id (submission_id),
            KEY status_next_retry (status, next_retry_at)
        ) $charset_collate;";

        $sql3 = "CREATE TABLE $logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL,
            event_type varchar(50) NOT NULL,
            form_id varchar(64) NULL,
            submission_id char(36) NULL,
            message text NOT NULL,
            context_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY level (level),
            KEY event_type (event_type),
            KEY form_id (form_id),
            KEY submission_id (submission_id)
        ) $charset_collate;";

        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);

        // Ensure cron is scheduled
        if (class_exists('RIBO_Cron')) {
            RIBO_Cron::schedule();
        }

        update_option('ribo_wp_inbound_plugin_version', RIBO_WP_INBOUND_VERSION, false);
    }



    public static function maybe_run_migrations() {
        $stored_version = get_option('ribo_wp_inbound_plugin_version', '0');

        if (!version_compare((string) $stored_version, RIBO_WP_INBOUND_VERSION, '<')) {
            return;
        }

        self::migrate_forms_schema();
        update_option('ribo_wp_inbound_plugin_version', RIBO_WP_INBOUND_VERSION, false);
    }

    public static function normalize_schema($schema, $defaults = []) {
        $defaults = is_array($defaults) ? $defaults : [];
        $schema = is_array($schema) ? $schema : [];

        $form_id = isset($defaults['id']) ? (string) $defaults['id'] : (isset($schema['id']) ? (string) $schema['id'] : '');
        $form_name = isset($defaults['name']) ? (string) $defaults['name'] : (isset($schema['name']) ? (string) $schema['name'] : 'Untitled Form');
        $status = isset($defaults['status']) ? (string) $defaults['status'] : (isset($schema['status']) ? (string) $schema['status'] : 'draft');

        $schema['id'] = $form_id ?: (isset($schema['id']) ? (string) $schema['id'] : '');
        $schema['name'] = $form_name ?: 'Untitled Form';
        $schema['status'] = in_array($status, ['draft', 'published'], true) ? $status : 'draft';
        $schema['version'] = max((int) RIBO_WP_INBOUND_SCHEMA_VERSION, isset($schema['version']) ? (int) $schema['version'] : 1);
        $schema['ui'] = (isset($schema['ui']) && is_array($schema['ui'])) ? $schema['ui'] : ['submit_text' => 'Send'];
        if (empty($schema['ui']['submit_text'])) {
            $schema['ui']['submit_text'] = 'Send';
        }
        $schema['mapping'] = (isset($schema['mapping']) && is_array($schema['mapping'])) ? $schema['mapping'] : [];
        $schema['fields'] = (isset($schema['fields']) && is_array($schema['fields'])) ? array_values($schema['fields']) : [];

        $base_width = isset($schema['ui']['canvas_width']) && is_numeric($schema['ui']['canvas_width']) ? (float) $schema['ui']['canvas_width'] : 720.0;
        if ($base_width < 320.0) {
            $base_width = 720.0;
        }
        $auto_x = 0.0;
        $auto_y = 0.0;
        $row_height = 0.0;
        $gap = 12.0;
        $legacy_non_zero_x = false;
        $legacy_large_x = false;

        foreach ($schema['fields'] as $legacy_field) {
            $legacy_settings = isset($legacy_field['settings']) && is_array($legacy_field['settings']) ? $legacy_field['settings'] : [];
            if (isset($legacy_settings['canvas_x']) && is_numeric($legacy_settings['canvas_x'])) {
                $legacy_x = (float) $legacy_settings['canvas_x'];
                if ($legacy_x > 0.0) {
                    $legacy_non_zero_x = true;
                }
                if ($legacy_x > 12.5) {
                    $legacy_large_x = true;
                }
            }
        }
        $positions_look_like_legacy_units = $legacy_non_zero_x && !$legacy_large_x;

        foreach ($schema['fields'] as $index => $field) {
            $field = is_array($field) ? $field : [];
            if (empty($field['id'])) {
                $field['id'] = 'fld_' . substr(md5(uniqid('fld', true)), 0, 8);
            }

            $width = isset($field['width']) ? (float) $field['width'] : 12.0;
            if ($width < 1 || $width > 12) {
                $width = 12.0;
            }
            $field['width'] = round($width, 1);

            $field['settings'] = (isset($field['settings']) && is_array($field['settings'])) ? $field['settings'] : [];

            $height = 0.0;
            if (isset($field['settings']['height'])) {
                $height = (float) $field['settings']['height'];
            } elseif (isset($field['settings']['box_height'])) {
                $height = (float) $field['settings']['box_height'];
            }
            if ($height > 0) {
                $height = round($height, 1);
                $field['settings']['height'] = $height;
                $field['settings']['box_height'] = $height;
            } else {
                unset($field['settings']['height'], $field['settings']['box_height']);
                $height = 86.0;
            }

            $has_x = isset($field['settings']['canvas_x']) && is_numeric($field['settings']['canvas_x']);
            $has_y = isset($field['settings']['canvas_y']) && is_numeric($field['settings']['canvas_y']);
            if ($has_x && $has_y && !$positions_look_like_legacy_units) {
                $field['settings']['canvas_x'] = round(max(0, (float) $field['settings']['canvas_x']), 1);
                $field['settings']['canvas_y'] = round(max(0, (float) $field['settings']['canvas_y']), 1);
            } else {
                $field_width_px = max(120.0, (($field['width'] / 12.0) * $base_width));
                if ($auto_x > 0 && ($auto_x + $field_width_px) > $base_width) {
                    $auto_x = 0.0;
                    $auto_y += $row_height + $gap;
                    $row_height = 0.0;
                }
                $field['settings']['canvas_x'] = round($auto_x, 1);
                $field['settings']['canvas_y'] = round($auto_y, 1);
                $auto_x += $field_width_px + $gap;
                $row_height = max($row_height, $height);
            }

            $schema['fields'][$index] = $field;
        }

        if (!isset($schema['ui']['canvas_height']) || !is_numeric($schema['ui']['canvas_height'])) {
            $max_bottom = 240.0;
            foreach ($schema['fields'] as $field) {
                $settings = isset($field['settings']) && is_array($field['settings']) ? $field['settings'] : [];
                $top = isset($settings['canvas_y']) ? (float) $settings['canvas_y'] : 0.0;
                $height = isset($settings['height']) ? (float) $settings['height'] : (isset($settings['box_height']) ? (float) $settings['box_height'] : 86.0);
                if ($height <= 0) { $height = 86.0; }
                $max_bottom = max($max_bottom, $top + $height + 24.0);
            }
            $schema['ui']['canvas_height'] = round($max_bottom, 1);
        } else {
            $schema['ui']['canvas_height'] = round(max(240.0, (float) $schema['ui']['canvas_height']), 1);
        }

        return $schema;
    }

    public static function migrate_forms_schema() {
        $forms = self::list_forms();
        if (empty($forms)) {
            return;
        }

        foreach ($forms as $form) {
            $schema = json_decode($form['schema_json'], true);
            $normalized = self::normalize_schema($schema, [
                'id' => $form['id'],
                'name' => $form['name'],
                'status' => $form['status'],
            ]);
            $normalized_json = wp_json_encode($normalized, JSON_PRETTY_PRINT);
            if ($normalized_json !== $form['schema_json'] || (int) $form['version'] < (int) $normalized['version']) {
                self::upsert_form($form['id'], $form['name'], $normalized_json, $form['status'], (int) $normalized['version']);
            }
        }
    }

    public static function now_mysql() {
        return current_time('mysql', 1);
    }

    public static function get_form($id) {
        global $wpdb;
        $forms = $wpdb->prefix . 'ribo_forms';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $forms WHERE id=%s", $id), ARRAY_A);
    }

    public static function list_forms() {
        global $wpdb;
        $forms = $wpdb->prefix . 'ribo_forms';
        return $wpdb->get_results("SELECT * FROM $forms ORDER BY updated_at DESC", ARRAY_A);
    }

    public static function get_form_by_name($name, $exclude_id = '') {
        global $wpdb;
        $forms = $wpdb->prefix . 'ribo_forms';
        if ($exclude_id) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM $forms WHERE name=%s AND id!=%s LIMIT 1", $name, $exclude_id), ARRAY_A);
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $forms WHERE name=%s LIMIT 1", $name), ARRAY_A);
    }

    public static function make_unique_form_name($name, $exclude_id = '', $max_length = 40) {
        $name = trim((string) $name);
        if ($name === '') {
            $name = 'Untitled Form';
        }

        $base_name = mb_substr($name, 0, $max_length);
        $candidate = $base_name;
        $counter = 1;

        while (self::get_form_by_name($candidate, $exclude_id)) {
            $suffix = ' (' . $counter . ')';
            $trimmed_base = mb_substr($base_name, 0, max(1, $max_length - mb_strlen($suffix)));
            $candidate = $trimmed_base . $suffix;
            $counter++;
        }

        return $candidate;
    }

    public static function upsert_form($id, $name, $schema_json, $status, $version) {
        global $wpdb;
        $forms = $wpdb->prefix . 'ribo_forms';
        $existing = self::get_form($id);
        $now = self::now_mysql();
        if ($existing) {
            $wpdb->update($forms, [
                'name' => $name,
                'schema_json' => $schema_json,
                'status' => $status,
                'version' => (int)$version,
                'updated_at' => $now,
            ], ['id' => $id]);
        } else {
            $wpdb->insert($forms, [
                'id' => $id,
                'name' => $name,
                'schema_json' => $schema_json,
                'status' => $status,
                'version' => (int)$version,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public static function delete_form($id) {
        global $wpdb;
        $forms = $wpdb->prefix . 'ribo_forms';
        $wpdb->delete($forms, ['id' => $id]);
    }

    public static function enqueue_pending($form_id, $submission_id, $payload_json, $attempts, $last_error, $next_retry_at, $status) {
        global $wpdb;
        $pending = $wpdb->prefix . 'ribo_pending_submissions';
        $now = self::now_mysql();
        $wpdb->insert($pending, [
            'form_id' => $form_id,
            'submission_id' => $submission_id,
            'payload_json' => $payload_json,
            'attempts' => (int)$attempts,
            'last_error' => $last_error,
            'next_retry_at' => $next_retry_at,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $wpdb->insert_id;
    }

    public static function list_pending($limit = 200) {
        global $wpdb;
        $pending = $wpdb->prefix . 'ribo_pending_submissions';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $pending ORDER BY created_at DESC LIMIT %d", (int)$limit), ARRAY_A);
    }

    public static function get_due_pending($limit = 20) {
        global $wpdb;
        $pending = $wpdb->prefix . 'ribo_pending_submissions';
        $now = self::now_mysql();
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $pending WHERE status='pending' AND (next_retry_at IS NULL OR next_retry_at <= %s) ORDER BY next_retry_at ASC, id ASC LIMIT %d", $now, (int)$limit),
            ARRAY_A
        );
    }

    public static function update_pending($id, $data) {
        global $wpdb;
        $pending = $wpdb->prefix . 'ribo_pending_submissions';
        $data['updated_at'] = self::now_mysql();
        $wpdb->update($pending, $data, ['id' => (int)$id]);
    }

    public static function delete_pending($id) {
        global $wpdb;
        $pending = $wpdb->prefix . 'ribo_pending_submissions';
        $wpdb->delete($pending, ['id' => (int)$id]);
    }
}
