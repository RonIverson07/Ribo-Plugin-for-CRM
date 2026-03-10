<?php
if (!defined('ABSPATH')) { exit; }

class RIBO_Admin_Menu {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_ribo_test_connection', [__CLASS__, 'handle_test_connection']);
        add_action('admin_post_ribo_quick_connect', [__CLASS__, 'handle_quick_connect']);
        add_action('admin_post_ribo_save_form', [__CLASS__, 'handle_save_form']);
        add_action('admin_post_ribo_delete_form', [__CLASS__, 'handle_delete_form']);
        add_action('admin_post_ribo_duplicate_form', [__CLASS__, 'handle_duplicate_form']);
        add_action('admin_post_ribo_export_form', [__CLASS__, 'handle_export_form']);
        add_action('admin_post_ribo_import_form', [__CLASS__, 'handle_import_form']);
        add_action('admin_post_ribo_save_mapping', [__CLASS__, 'handle_save_mapping']);
        add_action('admin_post_ribo_clear_logs', [__CLASS__, 'handle_clear_logs']);
        add_action('admin_post_ribo_export_logs_csv', [__CLASS__, 'handle_export_logs_csv']);
    }

    public static function menu() {
        add_menu_page('RIBO CRM', 'RIBO CRM', 'manage_options', 'ribo-crm', [__CLASS__, 'page_connection'], 'dashicons-feedback', 56);
        add_submenu_page('ribo-crm', 'Connection', 'Connection', 'manage_options', 'ribo-crm', [__CLASS__, 'page_connection']);
        add_submenu_page('ribo-crm', 'Forms', 'Forms', 'manage_options', 'ribo-crm-forms', [__CLASS__, 'page_forms']);
        add_submenu_page('ribo-crm', 'Form Builder', 'Form Builder', 'manage_options', 'ribo-crm-builder', [__CLASS__, 'page_builder']);
        add_submenu_page('ribo-crm', 'Field Mapping', 'Field Mapping', 'manage_options', 'ribo-crm-mapping', [__CLASS__, 'page_mapping']);
        add_submenu_page('ribo-crm', 'Pending Sync', 'Pending Sync', 'manage_options', 'ribo-crm-pending', [__CLASS__, 'page_pending']);
        add_submenu_page('ribo-crm', 'Logs', 'Logs', 'manage_options', 'ribo-crm-logs', [__CLASS__, 'page_logs']);
    }

    private static function render($file, $vars = []) {
        extract($vars);
        include RIBO_WP_INBOUND_DIR . 'admin/pages/partials/header.php';
        include RIBO_WP_INBOUND_DIR . 'admin/pages/' . $file;
        include RIBO_WP_INBOUND_DIR . 'admin/pages/partials/footer.php';
    }

    public static function page_connection() { self::render('connection.php'); }
    public static function page_forms() { self::render('forms.php'); }
    public static function page_builder() { self::render('builder.php'); }
    public static function page_mapping() { self::render('mapping.php'); }
    public static function page_pending() { self::render('pending-sync.php'); }
    public static function page_logs() { self::render('logs.php'); }

    public static function handle_test_connection() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('ribo_test_connection');

        $res = RIBO_Api_Client::test_connection();
        update_option('ribo_last_test_time', current_time('mysql'), false);
        update_option('ribo_last_test_code', (int)$res['code'], false);
        update_option('ribo_last_test_error', (string)$res['error'], false);

        RIBO_Logger::log($res['ok'] ? 'info' : 'error', 'connection_test', $res['ok'] ? 'Connection OK' : 'Connection failed', $res);

        wp_safe_redirect(admin_url('admin.php?page=ribo-crm'));
        exit;
    }


    public static function handle_quick_connect() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('ribo_quick_connect');

        $key  = isset($_POST['ribo_crm_api_key']) ? trim((string)wp_unslash($_POST['ribo_crm_api_key'])) : '';

        $retry = isset($_POST['ribo_retry_max_attempts']) ? absint($_POST['ribo_retry_max_attempts']) : (int)get_option('ribo_retry_max_attempts', 3);
        $cron  = isset($_POST['ribo_cron_interval_minutes']) ? absint($_POST['ribo_cron_interval_minutes']) : (int)get_option('ribo_cron_interval_minutes', 5);


        // If user leaves API key blank, keep the existing one.
        $existing_key = (string)get_option('ribo_crm_api_key', '');
        if ($key === '' && $existing_key) {
            $key = $existing_key;
        }

        update_option('ribo_crm_api_key', sanitize_text_field($key), false);
        update_option('ribo_retry_max_attempts', max(1, min(10, (int)$retry)), false);
        update_option('ribo_cron_interval_minutes', max(1, min(60, (int)$cron)), false);

        // Immediately verify the connection so the user gets fast feedback.
        $res = RIBO_Api_Client::test_connection();
        update_option('ribo_last_test_time', current_time('mysql'), false);
        update_option('ribo_last_test_code', (int)$res['code'], false);
        update_option('ribo_last_test_error', (string)$res['error'], false);

        RIBO_Logger::log($res['ok'] ? 'info' : 'error', 'quick_connect', $res['ok'] ? 'Connected and verified' : 'Connection failed', $res);

        $qs = 'admin.php?page=ribo-crm&connected=' . ($res['ok'] ? '1' : '0');
        wp_safe_redirect(admin_url($qs));
        exit;
    }

    public static function handle_save_form() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('ribo_save_form');

        $id = sanitize_text_field($_POST['id'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $status = sanitize_text_field($_POST['status'] ?? 'draft');
        $schema_json = wp_unslash($_POST['schema_json'] ?? '');

        if (!$id) {
            $id = 'form_' . substr(md5(uniqid('', true)), 0, 10);
        }
        if (!$name) { $name = 'Untitled Form'; }

        $name = trim($name);
        if (mb_strlen($name) > 40) {
            $redirect = add_query_arg([
                'page' => 'ribo-crm-builder',
                'form_id' => $id,
                'name_error' => 'max_length'
            ], admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        $name = RIBO_DB::make_unique_form_name($name, $id, 40);

        // validate json
        $schema = json_decode($schema_json, true);
        if (!is_array($schema)) {
            $schema = [
                'id' => $id,
                'name' => $name,
                'status' => $status,
                'version' => 1,
                'fields' => [
                    ['id'=>'fld_name','type'=>'text','label'=>'Name','required'=>true,'settings'=>['placeholder'=>'']],
                    ['id'=>'fld_email','type'=>'email','label'=>'Email','required'=>true,'settings'=>['placeholder'=>'you@company.com']],
                    ['id'=>'fld_message','type'=>'textarea','label'=>'Message','required'=>false,'settings'=>['placeholder'=>'']],
                ],
                'ui' => ['submit_text'=>'Send'],
                'mapping' => [
                    'fld_name' => 'name',
                    'fld_email' => 'email',
                    'fld_message' => 'message'
                ]
            ];
            $schema_json = wp_json_encode($schema, JSON_PRETTY_PRINT);
        }

        $schema = RIBO_DB::normalize_schema($schema, [
            'id' => $id,
            'name' => $name,
            'status' => $status,
        ]);
        $schema_json = wp_json_encode($schema, JSON_PRETTY_PRINT);

        RIBO_DB::upsert_form($id, $name, $schema_json, $status, $schema['version']);
        RIBO_Logger::log('info','form_saved','Form saved', ['form_id'=>$id,'status'=>$status,'version'=>$schema['version']], $id, null);

        wp_safe_redirect(admin_url('admin.php?page=ribo-crm-builder&form_id=' . urlencode($id) . '&saved=1'));
        exit;
    }

    public static function handle_delete_form() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('ribo_delete_form');
        $id = sanitize_text_field($_GET['id'] ?? '');
        if ($id) {
            RIBO_DB::delete_form($id);
            RIBO_Logger::log('warn','form_deleted','Form deleted', ['form_id'=>$id], $id, null);
        }
        wp_safe_redirect(admin_url('admin.php?page=ribo-crm-forms'));
        exit;
    }

    public static function handle_duplicate_form() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('ribo_duplicate_form');
        $id = sanitize_text_field($_GET['id'] ?? '');
        if (!$id) {
            wp_safe_redirect(admin_url('admin.php?page=ribo-crm-forms'));
            exit;
        }

        $form = RIBO_DB::get_form($id);
        if (!$form) {
            wp_safe_redirect(admin_url('admin.php?page=ribo-crm-forms'));
            exit;
        }

        $schema = json_decode($form['schema_json'], true);
        if (!is_array($schema)) { $schema = []; }

        $new_id = 'form_' . substr(md5(uniqid('', true)), 0, 10);
        $new_name = 'Copy of ' . ($form['name'] ?: 'Untitled Form');
        $new_name = RIBO_DB::make_unique_form_name($new_name, '', 40);

        // update schema
        $schema['id'] = $new_id;
        $schema['name'] = $new_name;
        $schema['status'] = 'draft';
        $schema['version'] = RIBO_WP_INBOUND_SCHEMA_VERSION;
        // ensure unique field IDs inside schema
        if (!empty($schema['fields']) && is_array($schema['fields'])) {
            $seen = [];
            foreach ($schema['fields'] as &$fld) {
                if (!is_array($fld)) { continue; }
                $old = isset($fld['id']) ? (string)$fld['id'] : '';
                $new = 'fld_' . substr(md5(uniqid('fld', true)), 0, 8);
                $fld['id'] = $new;
                $seen[$old] = $new;
            }
            unset($fld);
            // remap mapping keys
            if (!empty($schema['mapping']) && is_array($schema['mapping'])) {
                $newmap = [];
                foreach ($schema['mapping'] as $k => $v) {
                    $nk = isset($seen[$k]) ? $seen[$k] : $k;
                    $newmap[$nk] = $v;
                }
                $schema['mapping'] = $newmap;
            }
        }

        $schema = RIBO_DB::normalize_schema($schema, [
            'id' => $new_id,
            'name' => $new_name,
            'status' => 'draft',
        ]);
        $schema_json = wp_json_encode($schema, JSON_PRETTY_PRINT);
        RIBO_DB::upsert_form($new_id, $new_name, $schema_json, 'draft', (int)$schema['version']);
        RIBO_Logger::log('info','form_duplicated','Form duplicated', ['from'=>$id,'to'=>$new_id], $new_id, null);

        wp_safe_redirect(admin_url('admin.php?page=ribo-crm-builder&form_id=' . urlencode($new_id) . '&duplicated=1'));
        exit;
    }

    public static function handle_export_form() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('ribo_export_form');
        $id = sanitize_text_field($_GET['id'] ?? '');
        $form = $id ? RIBO_DB::get_form($id) : null;
        if (!$form) { wp_die('Form not found'); }

        $filename = 'ribo-form-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        echo $form['schema_json'];
        exit;
    }

    public static function handle_import_form() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('ribo_import_form');

        if (empty($_FILES['schema_file']) || empty($_FILES['schema_file']['tmp_name'])) {
            wp_safe_redirect(admin_url('admin.php?page=ribo-crm-forms&import=missing'));
            exit;
        }

        $raw = file_get_contents($_FILES['schema_file']['tmp_name']);
        $schema = json_decode($raw, true);
        if (!is_array($schema)) {
            wp_safe_redirect(admin_url('admin.php?page=ribo-crm-forms&import=invalid'));
            exit;
        }

        $id = isset($schema['id']) ? sanitize_text_field($schema['id']) : '';
        $name = isset($schema['name']) ? sanitize_text_field($schema['name']) : 'Imported Form';
        if (mb_strlen($name) > 40) {
            $name = mb_substr($name, 0, 40);
        }
        $name = RIBO_DB::make_unique_form_name($name, $id, 40);
        if (!$id || RIBO_DB::get_form($id)) {
            $id = 'form_' . substr(md5(uniqid('', true)), 0, 10);
        }
        $schema['id'] = $id;
        $schema['name'] = $name;
        $schema['status'] = isset($schema['status']) ? sanitize_text_field($schema['status']) : 'draft';

        // ensure fields have IDs
        if (empty($schema['fields']) || !is_array($schema['fields'])) {
            $schema['fields'] = [];
        }
        foreach ($schema['fields'] as &$fld) {
            if (!is_array($fld)) { $fld = []; }
            if (empty($fld['id'])) {
                $fld['id'] = 'fld_' . substr(md5(uniqid('fld', true)), 0, 8);
            }
        }
        unset($fld);

        $schema = RIBO_DB::normalize_schema($schema, [
            'id' => $id,
            'name' => $name,
            'status' => $schema['status'],
        ]);
        $schema_json = wp_json_encode($schema, JSON_PRETTY_PRINT);
        RIBO_DB::upsert_form($id, $name, $schema_json, $schema['status'], (int)$schema['version']);
        RIBO_Logger::log('info','form_imported','Form imported', ['form_id'=>$id], $id, null);

        wp_safe_redirect(admin_url('admin.php?page=ribo-crm-builder&form_id=' . urlencode($id) . '&imported=1'));
        exit;
    }

    public static function handle_save_mapping() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('ribo_save_mapping');

        $form_id = sanitize_text_field($_POST['form_id'] ?? '');
        $mapping = $_POST['mapping'] ?? [];
        if (!$form_id) {
            wp_safe_redirect(admin_url('admin.php?page=ribo-crm-mapping'));
            exit;
        }
        $form = RIBO_DB::get_form($form_id);
        if (!$form) {
            wp_safe_redirect(admin_url('admin.php?page=ribo-crm-mapping'));
            exit;
        }

        $schema = json_decode($form['schema_json'], true);
        if (!is_array($schema)) { $schema = []; }
        $clean = [];
        if (is_array($mapping)) {
            foreach ($mapping as $k => $v) {
                $k = sanitize_text_field($k);
                $v = sanitize_text_field($v);
                if ($k === '') { continue; }
                if ($v === '') { continue; }
                $clean[$k] = $v;
            }
        }

        $schema['mapping'] = $clean;
        $schema = RIBO_DB::normalize_schema($schema, [
            'id' => $form_id,
            'name' => $form['name'],
            'status' => $form['status'],
        ]);
        $schema_json = wp_json_encode($schema, JSON_PRETTY_PRINT);

        RIBO_DB::upsert_form($form_id, $form['name'], $schema_json, $form['status'], (int)$schema['version']);
        RIBO_Logger::log('info','mapping_saved','Mapping saved', ['count'=>count($clean)], $form_id, null);

        wp_safe_redirect(admin_url('admin.php?page=ribo-crm-mapping&form_id=' . urlencode($form_id) . '&saved=1'));
        exit;
    }

    public static function handle_clear_logs() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('ribo_clear_logs');
        RIBO_Logger::clear_logs();
        wp_safe_redirect(admin_url('admin.php?page=ribo-crm-logs&cleared=1'));
        exit;
    }

    public static function handle_export_logs_csv() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('ribo_export_logs_csv');

        $filters = [
            'level' => isset($_GET['level']) ? sanitize_text_field(wp_unslash($_GET['level'])) : '',
            'event_type' => isset($_GET['event']) ? sanitize_text_field(wp_unslash($_GET['event'])) : '',
            'form_id' => isset($_GET['form_id']) ? sanitize_text_field(wp_unslash($_GET['form_id'])) : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
        ];

        $rows = RIBO_Logger::list_logs(5000, $filters);

        $filename = 'ribo-logs-' . gmdate('Ymd-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        fputcsv($out, ['time','level','event','form_id','submission_id','message']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['created_at'],
                $r['level'],
                $r['event_type'],
                $r['form_id'],
                $r['submission_id'],
                $r['message'],
            ]);
        }
        fclose($out);
        exit;
    }
}
