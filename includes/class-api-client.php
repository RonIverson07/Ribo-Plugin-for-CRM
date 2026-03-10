<?php
if (!defined('ABSPATH')) { exit; }

class RIBO_Api_Client {

    const LEADS_ENDPOINT = 'https://staging.ribo.com.ph/api/inbound/wordpress/leads';
    const HEALTH_ENDPOINT = 'https://staging.ribo.com.ph/api/health';

    public static function get_endpoint() {
        return self::LEADS_ENDPOINT;
    }

    public static function get_api_key() {
        return (string)get_option('ribo_crm_api_key', '');
    }

    public static function test_connection() {
        $key  = self::get_api_key();
        if (!$key) {
            return ['ok'=>false,'code'=>0,'error'=>'Missing API key'];
        }

        $resp = wp_remote_get(self::HEALTH_ENDPOINT, [
            'timeout' => 10,
            'headers' => [
                'X-WP-API-Key' => $key
            ],
        ]);
        if (is_wp_error($resp)) {
            return ['ok'=>false,'code'=>0,'error'=>$resp->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($resp);
        return ['ok'=>($code>=200 && $code<300), 'code'=>$code, 'error'=>($code>=200 && $code<300)?'':wp_remote_retrieve_body($resp)];
    }

    public static function send_lead($payload) {
        $key  = self::get_api_key();
        if (!$key) {
            return ['ok'=>false,'code'=>0,'type'=>'config','error'=>'Missing API key'];
        }

        $resp = wp_remote_post(self::LEADS_ENDPOINT, [
            'timeout' => 15,
            'headers' => [
                'X-WP-API-Key' => $key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($resp)) {
            return ['ok'=>false,'code'=>0,'type'=>'transient','error'=>$resp->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);

        if ($code >= 200 && $code < 300) {
            return ['ok'=>true,'code'=>$code,'type'=>'success','body'=>$body];
        }

        if ($code === 409) {
            // Already exists in CRM. Treat as success/done.
            return ['ok'=>true,'code'=>$code,'type'=>'conflict','body'=>$body];
        }

        if ($code >= 500 || $code === 408 || $code === 0) {
            return ['ok'=>false,'code'=>$code,'type'=>'transient','error'=>$body ?: 'Connection error'];
        }

        return ['ok'=>false,'code'=>$code,'type'=>'permanent','error'=>$body];
    }
}
