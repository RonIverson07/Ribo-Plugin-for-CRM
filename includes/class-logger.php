<?php
if (!defined('ABSPATH')) { exit; }

class RIBO_Logger {
    public static function init() {}

    public static function log($level, $event_type, $message, $context = [], $form_id = null, $submission_id = null) {
        global $wpdb;
        $logs = $wpdb->prefix . 'ribo_logs';
        $wpdb->insert($logs, [
            'level' => sanitize_text_field($level),
            'event_type' => sanitize_text_field($event_type),
            'form_id' => $form_id ? sanitize_text_field($form_id) : null,
            'submission_id' => $submission_id ? sanitize_text_field($submission_id) : null,
            'message' => wp_kses_post($message),
            'context_json' => !empty($context) ? wp_json_encode($context) : null,
            'created_at' => current_time('mysql', 1)
        ]);
    }

    /**
     * List logs with optional filters.
     * Filters: level, event_type, form_id, date_from (Y-m-d), date_to (Y-m-d)
     */
    public static function list_logs($limit = 200, $filters = []) {
        global $wpdb;
        $logs = $wpdb->prefix . 'ribo_logs';

        $where = [];
        $params = [];

        if (!empty($filters['level'])) {
            $where[] = 'level = %s';
            $params[] = sanitize_text_field($filters['level']);
        }
        if (!empty($filters['event_type'])) {
            $where[] = 'event_type = %s';
            $params[] = sanitize_text_field($filters['event_type']);
        }
        if (!empty($filters['form_id'])) {
            $where[] = 'form_id = %s';
            $params[] = sanitize_text_field($filters['form_id']);
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $params[] = sanitize_text_field($filters['date_from']) . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $params[] = sanitize_text_field($filters['date_to']) . ' 23:59:59';
        }

        $sql = "SELECT * FROM $logs";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT %d';
        $params[] = (int)$limit;

        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    public static function clear_logs() {
        global $wpdb;
        $logs = $wpdb->prefix . 'ribo_logs';
        $wpdb->query("TRUNCATE TABLE $logs");
    }
}
