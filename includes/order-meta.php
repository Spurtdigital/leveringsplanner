<?php

if (!defined('ABSPATH')) exit;

class KLP_Order_Meta {
    const DATE_KEY = '_klp_delivery_date';
    const TIME_SLOT_KEY = '_klp_time_slot';
    const PICKUP_CODE_KEY = '_klp_pickup_code';
    const PICKUP_REQUESTED_KEY = '_klp_pickup_requested';
    const PICKUP_REQUESTED_AT_KEY = '_klp_pickup_requested_at';
    const REMINDER_SENT_KEY = '_klp_reminder_sent';
    const PICKUP_REMINDER_SENT_KEY = '_klp_pickup_reminder_sent';
    const ESCALATED_REMINDER_SENT_KEY = '_klp_escalated_reminder_sent';

    public static function init() {
        add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'admin_order_pickup_status']);
        add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'admin_order_gcal_status']);
        add_filter('manage_edit-shop_order_columns', [__CLASS__, 'add_pickup_column']);
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_pickup_column'], 10, 2);
        add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'add_pickup_column']);
        add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'render_pickup_column_hpos'], 10, 2);
        add_filter('manage_edit-shop_order_columns', [__CLASS__, 'add_gcal_column']);
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_gcal_column'], 10, 2);
        add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'add_gcal_column']);
        add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'render_gcal_column_hpos'], 10, 2);
    }

    public static function admin_order_pickup_status($order) {
        $order_id = $order->get_id();
        $pickup_code = self::get_pickup_code($order_id);
        if (empty($pickup_code)) return;

        $requested = self::get_pickup_requested($order_id);
        $requested_at = get_post_meta($order_id, self::PICKUP_REQUESTED_AT_KEY, true);
        ?>
        <p class="form-field form-field-wide">
            <label><?php _e('Ophaalstatus:', 'kolenbrander-leveringsplanner'); ?></label>
            <?php if ($requested): ?>
                <span style="color:#46b450;font-weight:bold;">&#10003; Aangemeld voor ophalen</span>
                <?php if ($requested_at): ?>
                    <br><small>op <?php echo esc_html(date_i18n('d-m-Y H:i', strtotime($requested_at))); ?></small>
                <?php endif; ?>
            <?php else: ?>
                <span style="color:#dc3232;">&#10007; Nog niet aangemeld</span>
            <?php endif; ?>
            <br><small>Ophaalcode: <?php echo esc_html($pickup_code); ?></small>
        </p>
        <?php
    }

    public static function add_pickup_column($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'order_status') {
                $new['klp_pickup'] = 'Ophaalstatus';
            }
        }
        return $new;
    }

    public static function render_pickup_column($column, $order_id) {
        if ($column !== 'klp_pickup') return;
        self::render_pickup_badge($order_id);
    }

    public static function render_pickup_column_hpos($column, $order) {
        if ($column !== 'klp_pickup') return;
        self::render_pickup_badge($order->get_id());
    }

    private static function render_pickup_badge($order_id) {
        $pickup_code = self::get_pickup_code($order_id);
        if (empty($pickup_code)) return;
        if (self::get_pickup_requested($order_id)) {
            echo '<span style="color:#46b450;">✓ Aangemeld</span>';
        } else {
            echo '<span style="color:#dc3232;">✗ Niet aangemeld</span>';
        }
    }

    public static function admin_order_gcal_status($order) {
        $order_id = $order->get_id();
        $event_id = get_post_meta($order_id, '_klp_gcal_event_id', true);
        if (empty($event_id)) return;
        ?>
        <p class="form-field form-field-wide">
            <label>Google Calendar:</label>
            <span style="color:#46b450;font-weight:bold;">&#10003; Gesynchroniseerd</span>
        </p>
        <?php
    }

    public static function add_gcal_column($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'order_status') {
                $new['klp_gcal'] = 'GC';
            }
        }
        return $new;
    }

    public static function render_gcal_column($column, $order_id) {
        if ($column !== 'klp_gcal') return;
        self::render_gcal_badge($order_id);
    }

    public static function render_gcal_column_hpos($column, $order) {
        if ($column !== 'klp_gcal') return;
        self::render_gcal_badge($order->get_id());
    }

    private static function render_gcal_badge($order_id) {
        $event_id = get_post_meta($order_id, '_klp_gcal_event_id', true);
        if (!empty($event_id)) {
            echo '<span style="color:#46b450;">✓</span>';
        }
    }

    public static function get_delivery_date($order_id) {
        return get_post_meta($order_id, self::DATE_KEY, true);
    }

    public static function get_time_slot($order_id) {
        return get_post_meta($order_id, self::TIME_SLOT_KEY, true);
    }

    public static function get_pickup_code($order_id) {
        return get_post_meta($order_id, self::PICKUP_CODE_KEY, true);
    }

    public static function get_reminder_sent($order_id) {
        return get_post_meta($order_id, self::REMINDER_SENT_KEY, true) === 'yes';
    }

    public static function get_pickup_reminder_sent($order_id) {
        return get_post_meta($order_id, self::PICKUP_REMINDER_SENT_KEY, true) === 'yes';
    }

    public static function get_escalated_reminder_sent($order_id) {
        return get_post_meta($order_id, self::ESCALATED_REMINDER_SENT_KEY, true) === 'yes';
    }

    public static function get_pickup_requested($order_id) {
        return get_post_meta($order_id, self::PICKUP_REQUESTED_KEY, true) === 'yes';
    }

    public static function generate_pickup_code($order_id) {
        $hash = substr(wp_hash($order_id . time() . wp_rand()), 0, 8);
        $code = 'KL-' . $order_id . '-' . strtoupper($hash);
        update_post_meta($order_id, self::PICKUP_CODE_KEY, $code);
        return $code;
    }

    public static function find_order_by_pickup_code($code) {
        global $wpdb;
        $order_id = $wpdb->get_var($wpdb->prepare("
            SELECT post_id FROM {$wpdb->prefix}postmeta
            WHERE meta_key = %s AND meta_value = %s
        ", self::PICKUP_CODE_KEY, $code));
        return $order_id ? wc_get_order($order_id) : null;
    }

    public static function get_orders_for_date($date_ymd) {
        global $wpdb;
        $statuses = "'wc-processing','wc-completed','wc-on-hold'";

        $ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm.post_id
            FROM {$wpdb->prefix}postmeta pm
            INNER JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s
            AND pm.meta_value = %s
            AND p.post_status IN ({$statuses})
        ", self::DATE_KEY, $date_ymd));

        return array_map('wc_get_order', $ids);
    }

    public static function get_orders_without_pickup_request($older_than_days = 7) {
        global $wpdb;
        $cutoff = date('Y-m-d', strtotime("-{$older_than_days} days", current_time('timestamp')));
        $statuses = "'wc-processing','wc-completed','wc-on-hold'";

        $ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm.post_id
            FROM {$wpdb->prefix}postmeta pm
            INNER JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s
            AND pm.meta_value <= %s
            AND p.post_status IN ({$statuses})
            AND pm.post_id NOT IN (
                SELECT post_id FROM {$wpdb->prefix}postmeta
                WHERE meta_key = %s AND meta_value = 'yes'
            )
        ", self::DATE_KEY, $cutoff, self::PICKUP_REQUESTED_KEY));

        return array_map('wc_get_order', $ids);
    }

    public static function format_item_line($item) {
        $line = $item->get_name() . ' x' . $item->get_quantity();
        $epo = [];
        foreach ($item->get_meta_data() as $m) {
            if (strpos($m->key, '_') === 0) continue;
            if (strpos($m->key, 'pa_') === 0) continue;
            $val = is_string($m->value) ? $m->value : (is_array($m->value) ? implode(', ', $m->value) : '');
            if (preg_match('/^Geen\s/', $val)) continue;
            $epo[] = $m->key . ' ' . $val;
        }
        if (!empty($epo)) {
            $line .= ' (' . implode('; ', $epo) . ')';
        }
        return $line;
    }
}
