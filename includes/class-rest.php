<?php
if (!defined('ABSPATH')) {
    exit;
}

class RIBO_REST
{
    public static function init()
    {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes()
    {
        register_rest_route('ribo/v1', '/forms/(?P<form_id>[a-zA-Z0-9_\-]+)/submit', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'submit_form'],
            'permission_callback' => '__return_true',
        ]);
    }

    private static function truncate($val, $limit)
    {
        if (!is_string($val)) {
            return $val;
        }
        if (function_exists('mb_substr')) {
            return mb_strlen($val) > $limit ? mb_substr($val, 0, $limit) : $val;
        }
        return strlen($val) > $limit ? substr($val, 0, $limit) : $val;
    }

    private static function normalize_phone($val)
    {
        // Strip everything except digits and the plus sign
        return preg_replace('/[^\d+]/', '', (string) $val);
    }

    public static function submit_form($request)
    {
        $form_id = sanitize_text_field($request['form_id']);
        $form = RIBO_DB::get_form($form_id);

        if (!$form || $form['status'] !== 'published') {
            return new WP_REST_Response(['ok' => false, 'error' => 'Form not found or not published'], 404);
        }

        $schema = RIBO_DB::normalize_schema(json_decode($form['schema_json'], true), [
            'id' => $form['id'],
            'name' => $form['name'],
            'status' => $form['status'],
        ]);
        if (!is_array($schema)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid form schema'], 500);
        }

        $fields = isset($schema['fields']) && is_array($schema['fields']) ? $schema['fields'] : [];
        $mapping = isset($schema['mapping']) && is_array($schema['mapping']) ? $schema['mapping'] : [];

        $raw = $request->get_json_params();
        if (!is_array($raw)) {
            $raw = [];
        }

        $payload = [];
        $errors = [];

        foreach ($fields as $f) {
            $fid = sanitize_text_field($f['id'] ?? '');
            if (!$fid) {
                continue;
            }
            $type = sanitize_text_field($f['type'] ?? 'text');
            $required = !empty($f['required']);

            $val = $raw[$fid] ?? '';
            if (is_array($val)) {
                $val = array_map('sanitize_text_field', $val);
            } else {
                $val = sanitize_text_field((string) $val);
            }

            if ($required) {
                $empty = (is_array($val) ? count($val) === 0 : trim((string) $val) === '');
                if ($empty) {
                    $errors[$fid] = 'Required';
                }
            }

            // type-specific
            if ($type === 'email' && $val) {
                $val = sanitize_email($val);
                if (!is_email($val)) {
                    $errors[$fid] = 'Invalid email';
                }
            }
            if ($type === 'phone' && $val) {
                $val = self::normalize_phone($val);
            }

            // apply mapping: if mapped, use mapped key; else use field id
            $key = $mapping[$fid] ?? $fid;

            // Strict API length limits
            if ($key === 'phone') {
                $val = self::truncate($val, 50);
            } elseif ($key === 'address') {
                $val = self::truncate($val, 1000);
            } elseif (in_array($key, ['message', 'notes'])) {
                $val = self::truncate($val, 5000);
            } elseif (is_string($val)) {
                $val = self::truncate($val, 255);
            }

            $payload[$key] = $val;
        }

        $has_identifier = false;
        foreach (['name', 'email', 'phone'] as $identifier_key) {
            if (!array_key_exists($identifier_key, $payload)) {
                continue;
            }
            $identifier_value = $payload[$identifier_key];
            if (is_array($identifier_value)) {
                if (!empty($identifier_value)) {
                    $has_identifier = true;
                    break;
                }
            } elseif (trim((string) $identifier_value) !== '') {
                $has_identifier = true;
                break;
            }
        }
        if (!$has_identifier) {
            $errors['_payload'] = 'At least one of name, email, or phone is required.';
        }

        if (!empty($errors)) {
            RIBO_Logger::log('warn', 'submission_validation', 'Validation errors', ['errors' => $errors], $form_id, null);
            return new WP_REST_Response(['ok' => false, 'errors' => $errors], 422);
        }

        $submission_id = wp_generate_uuid4();
        $timestamp = gmdate('Y-m-d\TH:i:s\Z'); // Forced ISO 8601 Zulu (UTC)

        $crm_payload = [
            'submission_id' => $submission_id,
            'timestamp' => $timestamp,
            'form_id' => $form_id,
            'form_name' => self::truncate($form['name'], 255),
            'payload' => $payload,
        ];

        RIBO_Logger::log('info', 'submission_received', 'Submission processed', [
            'payload_keys' => array_keys($payload),
            'submission_id' => $submission_id,
            'timestamp' => $timestamp
        ], $form_id, $submission_id);

        $result = RIBO_Api_Client::send_lead($crm_payload);

        if ($result['ok']) {
            if ($result['type'] === 'conflict') {
                RIBO_Logger::log('info', 'crm_conflict', 'Lead already exists in CRM (Ignored duplicate)', ['code' => 409], $form_id, $submission_id);
            } else {
                RIBO_Logger::log('info', 'crm_success', 'Lead delivered to CRM', ['code' => $result['code'], 'response' => $result['body']], $form_id, $submission_id);
            }
            return new WP_REST_Response(['ok' => true, 'submission_id' => $submission_id], 200);
        }

        $max_attempts = max(1, (int) get_option('ribo_retry_max_attempts', 3));

        if ($result['type'] === 'transient') {
            // queue for retry
            $attempts = 1;
            // Delay first retry to give server a break
            $next_retry = gmdate('Y-m-d H:i:s', time() + 180);
            RIBO_DB::enqueue_pending($form_id, $submission_id, wp_json_encode($crm_payload), $attempts, $result['error'], $next_retry, 'pending');
            RIBO_Logger::log('warn', 'crm_queued', 'Temporary delivery failure. Queued for retry.', [
                'code' => $result['code'],
                'error' => $result['error'],
                'payload' => $crm_payload // Save payload for trace
            ], $form_id, $submission_id);
            return new WP_REST_Response(['ok' => true, 'queued' => true, 'submission_id' => $submission_id], 202);
        }

        // permanent fail -> store in queue as failed for visibility
        RIBO_DB::enqueue_pending($form_id, $submission_id, wp_json_encode($crm_payload), $max_attempts, $result['error'], null, 'failed');
        RIBO_Logger::log('error', 'crm_failed', 'Terminal delivery failure. Not retrying.', [
            'code' => $result['code'],
            'error' => $result['error'],
            'payload' => $crm_payload
        ], $form_id, $submission_id);
        return new WP_REST_Response(['ok' => false, 'error' => 'Delivery failed permanently.'], 502);
    }
}
