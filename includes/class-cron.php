<?php
if (!defined('ABSPATH')) { exit; }

class RIBO_Cron {
    const HOOK = 'ribo_retry_pending';

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'schedules']);
        add_action(self::HOOK, [__CLASS__, 'process']);
        self::schedule();

        // admin manual trigger
        add_action('admin_post_ribo_resend_pending', [__CLASS__, 'admin_resend_pending']);
        add_action('admin_post_ribo_delete_pending', [__CLASS__, 'admin_delete_pending']);
        add_action('admin_post_ribo_bulk_pending', [__CLASS__, 'admin_bulk_pending']);
    }

    public static function schedules($schedules) {
        $mins = max(1, (int)get_option('ribo_cron_interval_minutes', 5));
        $schedules['ribo_every_x_minutes'] = [
            'interval' => $mins * 60,
            'display' => 'RIBO every ' . $mins . ' minutes'
        ];
        return $schedules;
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'ribo_every_x_minutes', self::HOOK);
        }
    }

    public static function deactivate() {
        $ts = wp_next_scheduled(self::HOOK);
        if ($ts) { wp_unschedule_event($ts, self::HOOK); }
    }

    public static function process() {
        $due = RIBO_DB::get_due_pending(20);
        if (empty($due)) { return; }

        $max_attempts = max(1, (int)get_option('ribo_retry_max_attempts', 3));

        foreach ($due as $row) {
            $id = (int)$row['id'];
            $form_id = $row['form_id'];
            $submission_id = $row['submission_id'];
            $attempts = (int)$row['attempts'];

            $payload = json_decode($row['payload_json'], true);
            if (!is_array($payload)) {
                RIBO_DB::update_pending($id, ['status'=>'failed','last_error'=>'Invalid payload JSON','next_retry_at'=>null]);
                RIBO_Logger::log('error','retry_failed','Invalid queued payload JSON', [], $form_id, $submission_id);
                continue;
            }

            $result = RIBO_Api_Client::send_lead($payload);

            if ($result['ok']) {
                RIBO_DB::delete_pending($id);
                if ($result['type'] === 'conflict') {
                    RIBO_Logger::log('info','retry_conflict','Queued submission skipped (already exists in CRM)', ['code'=>409], $form_id, $submission_id);
                } else {
                    RIBO_Logger::log('info','retry_success','Queued submission delivered', ['code'=>$result['code']], $form_id, $submission_id);
                }
                continue;
            }

            $attempts++;
            if ($result['type'] !== 'transient' || $attempts >= $max_attempts) {
                RIBO_DB::update_pending($id, [
                    'attempts' => $attempts,
                    'status' => 'failed',
                    'last_error' => $result['error'],
                    'next_retry_at' => null
                ]);
                RIBO_Logger::log('error','retry_failed','Queued submission failed permanently', ['code'=>$result['code'],'error'=>$result['error']], $form_id, $submission_id);
                continue;
            }

            // exponential-ish backoff
            $delay = min(3600, (int)pow(3, max(0, $attempts)) * 20); // 60, 180, 540, ...
            $next = gmdate('Y-m-d H:i:s', time() + $delay);

            RIBO_DB::update_pending($id, [
                'attempts' => $attempts,
                'status' => 'pending',
                'last_error' => $result['error'],
                'next_retry_at' => $next
            ]);
            RIBO_Logger::log('warn','retry_scheduled','Retry scheduled', ['attempts'=>$attempts,'next_retry_at'=>$next,'error'=>$result['error']], $form_id, $submission_id);
        }
    }

    public static function admin_resend_pending() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('ribo_pending_actions');
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if ($id) {
            $rows = RIBO_DB::list_pending(500);
            foreach ($rows as $r) {
                if ((int)$r['id'] === $id) {
                    RIBO_DB::update_pending($id, ['status'=>'pending','next_retry_at'=>RIBO_DB::now_mysql()]);
                    break;
                }
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=ribo-crm-pending'));
        exit;
    }

    public static function admin_delete_pending() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('ribo_pending_actions');
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if ($id) { RIBO_DB::delete_pending($id); }
        wp_safe_redirect(admin_url('admin.php?page=ribo-crm-pending'));
        exit;
    }

    public static function admin_bulk_pending() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('ribo_pending_actions');

        $action = isset($_POST['bulk_action']) ? sanitize_text_field(wp_unslash($_POST['bulk_action'])) : '';
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('absint', $_POST['ids']) : [];
        $ids = array_filter($ids);

        if (empty($action) || empty($ids)) {
            wp_safe_redirect(admin_url('admin.php?page=ribo-crm-pending'));
            exit;
        }

        if ($action === 'delete') {
            foreach ($ids as $id) { RIBO_DB::delete_pending((int)$id); }
            RIBO_Logger::log('info','pending_bulk_deleted','Bulk deleted pending rows', ['count'=>count($ids)], null, null);
        }

        if ($action === 'resend') {
            foreach ($ids as $id) {
                RIBO_DB::update_pending((int)$id, ['status'=>'pending','next_retry_at'=>RIBO_DB::now_mysql()]);
            }
            RIBO_Logger::log('info','pending_bulk_resend','Bulk resend scheduled', ['count'=>count($ids)], null, null);
        }

        wp_safe_redirect(admin_url('admin.php?page=ribo-crm-pending'));
        exit;
    }
}
