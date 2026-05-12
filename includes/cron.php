<?php

if (!defined('ABSPATH')) exit;

class KLP_Cron {
    public static function init() {
        add_action('klp_hourly_reminder_check', [__CLASS__, 'process_customer_reminders']);
        add_action('klp_daily_pickup_reminder', [__CLASS__, 'process_pickup_reminders']);
    }

    public static function schedule() {
        if (!wp_next_scheduled('klp_daily_admin_summary')) {
            $time = strtotime('tomorrow 07:00', current_time('timestamp'));
            wp_schedule_event($time, 'daily', 'klp_daily_admin_summary');
        }

        if (!wp_next_scheduled('klp_hourly_reminder_check')) {
            wp_schedule_event(time(), 'hourly', 'klp_hourly_reminder_check');
        }

        if (!wp_next_scheduled('klp_daily_pickup_reminder')) {
            $time = strtotime('tomorrow 08:00', current_time('timestamp'));
            wp_schedule_event($time, 'daily', 'klp_daily_pickup_reminder');
        }
    }

    public static function unschedule() {
        $hooks = ['klp_daily_admin_summary', 'klp_hourly_reminder_check', 'klp_daily_pickup_reminder'];
        foreach ($hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }
    }

    public static function process_customer_reminders() {
        $settings = KLP_Settings::get();
        $days_before = (int) $settings['reminder_days_before'];
        $target_date = date('Y-m-d', strtotime("+{$days_before} days", current_time('timestamp')));

        $orders = KLP_Order_Meta::get_orders_for_date($target_date);
        foreach ($orders as $order) {
            if ($order && !KLP_Order_Meta::get_reminder_sent($order->get_id())) {
                KLP_Emails::send_customer_reminder($order->get_id());
            }
        }
    }

    public static function process_pickup_reminders() {
        $settings = KLP_Settings::get();
        $days = (int) $settings['pickup_reminder_days'];
        $escalated_days = (int) ($settings['escalated_reminder_days'] ?? 21);

        $orders = KLP_Order_Meta::get_orders_without_pickup_request($days);
        foreach ($orders as $order) {
            if (!$order) continue;
            $oid = $order->get_id();

            if (!KLP_Order_Meta::get_pickup_reminder_sent($oid)) {
                KLP_Emails::send_pickup_reminder($oid);
            }
        }

        $orders2 = KLP_Order_Meta::get_orders_without_pickup_request($escalated_days);
        foreach ($orders2 as $order) {
            if (!$order) continue;
            $oid = $order->get_id();

            if (!KLP_Order_Meta::get_escalated_reminder_sent($oid)) {
                KLP_Emails::send_escalated_pickup_reminder($oid);
            }
        }
    }
}
