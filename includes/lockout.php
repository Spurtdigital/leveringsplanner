<?php

if (!defined('ABSPATH')) exit;

class KLP_Lockout {
    public static function init() {
        add_action('wp_ajax_klp_check_availability', [__CLASS__, 'ajax_check_availability']);
        add_action('wp_ajax_nopriv_klp_check_availability', [__CLASS__, 'ajax_check_availability']);
    }

    public static function get_count($date_ymd, $time_slot = null) {
        global $wpdb;

        $meta_key = '_klp_delivery_date';
        $statuses = ['wc-processing', 'wc-completed', 'wc-on-hold'];
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "SELECT COUNT(DISTINCT pm.post_id)
                FROM {$wpdb->prefix}postmeta pm
                INNER JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id
                WHERE pm.meta_key = %s
                AND p.post_status IN ({$placeholders})
                AND pm.meta_value = %s";

        $params = array_merge([$meta_key], $statuses, [$date_ymd]);

        if ($time_slot) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM {$wpdb->prefix}postmeta pm2
                WHERE pm2.post_id = pm.post_id
                AND pm2.meta_key = '_klp_time_slot'
                AND pm2.meta_value = %s
            )";
            $params[] = $time_slot;
        }

        $count = $wpdb->get_var($wpdb->prepare($sql, $params));
        return (int) $count;
    }

    public static function get_available_count($date_ymd, $time_slot = null) {
        $max = KLP_Settings::get('max_per_day');
        $count = self::get_count($date_ymd, $time_slot);
        return $max - $count;
    }

    public static function is_full($date_ymd) {
        return self::get_available_count($date_ymd) <= 0;
    }

    public static function get_full_dates() {
        global $wpdb;
        $max = KLP_Settings::get('max_per_day');
        $from = date('Y-m-d', current_time('timestamp'));
        $statuses = ['wc-processing', 'wc-completed', 'wc-on-hold'];
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT pm.meta_value AS delivery_date, COUNT(*) AS cnt
            FROM {$wpdb->prefix}postmeta pm
            INNER JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_klp_delivery_date'
            AND pm.meta_value >= %s
            AND p.post_status IN ({$placeholders})
            GROUP BY pm.meta_value
            HAVING cnt >= %d
        ", array_merge([$from], $statuses, [$max])));

        $dates = [];
        foreach ($results as $row) {
            $dates[$row->delivery_date] = (int) $row->cnt;
        }
        return $dates;
    }

    public static function get_all_date_counts() {
        global $wpdb;
        $from = date('Y-m-d', current_time('timestamp'));
        $statuses = ['wc-processing', 'wc-completed', 'wc-on-hold'];
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT pm.meta_value AS delivery_date, COUNT(*) AS cnt
            FROM {$wpdb->prefix}postmeta pm
            INNER JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_klp_delivery_date'
            AND pm.meta_value >= %s
            AND p.post_status IN ({$placeholders})
            GROUP BY pm.meta_value
        ", array_merge([$from], $statuses)));

        $dates = [];
        foreach ($results as $row) {
            $dates[$row->delivery_date] = (int) $row->cnt;
        }
        return $dates;
    }

    public static function ajax_check_availability() {
        check_ajax_referer('klp_checkout', 'nonce');

        $date = sanitize_text_field($_POST['date'] ?? '');
        if (empty($date) || !preg_match('/^\d{2}-\d{2}-\d{4}$/', $date)) {
            wp_send_json_error(['message' => 'Invalid date']);
        }

        $ymd = date('Y-m-d', strtotime($date));
        $available = self::get_available_count($ymd);
        $max = KLP_Settings::get('max_per_day');

        wp_send_json_success([
            'date' => $date,
            'available' => $available,
            'max' => $max,
            'full' => $available <= 0,
        ]);
    }
}
