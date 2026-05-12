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
        add_action('woocommerce_email_order_meta', [__CLASS__, 'add_delivery_to_wc_email'], 10, 3);
        add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'admin_order_edit_delivery']);
        add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'admin_order_pickup_status']);
        add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'admin_order_gcal_status']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_order_assets']);
        add_action('wp_ajax_klp_update_delivery', [__CLASS__, 'ajax_update_delivery']);
        add_filter('manage_edit-shop_order_columns', [__CLASS__, 'add_pickup_column']);
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_pickup_column'], 10, 2);
        add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'add_pickup_column']);
        add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'render_pickup_column_hpos'], 10, 2);
        add_filter('manage_edit-shop_order_columns', [__CLASS__, 'add_gcal_column']);
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_gcal_column'], 10, 2);
        add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'add_gcal_column']);
        add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'render_gcal_column_hpos'], 10, 2);
    }

    public static function enqueue_order_assets($hook) {
        $is_order_edit = ($hook === 'post.php' && !empty($_GET['post']) && get_post_type(absint($_GET['post'])) === 'shop_order')
                      || ($hook === 'woocommerce_page_wc-orders' && !empty($_GET['id']));
        if (!$is_order_edit) return;

        wp_enqueue_style('jquery-ui-datepicker');
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_script('klp-order', KLP_PLUGIN_URL . 'assets/js/order.js', ['jquery', 'jquery-ui-datepicker'], KLP_VERSION, true);
        wp_localize_script('klp-order', 'klp_order', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('klp_update_delivery'),
        ]);
    }

    public static function admin_order_edit_delivery($order) {
        $order_id   = $order->get_id();
        $date       = self::get_delivery_date($order_id);
        $time_slot  = self::get_time_slot($order_id);
        $settings   = KLP_Settings::get();
        $date_display = $date ? date('d-m-Y', strtotime($date)) : '';
        ?>
        <p class="form-field form-field-wide">
            <label><?php _e('Leverdag wijzigen:', 'kolenbrander-leveringsplanner'); ?></label>
            <span style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:4px;">
                <input type="text" id="klp_edit_date"
                       value="<?= esc_attr($date_display) ?>"
                       placeholder="dd-mm-jjjj"
                       autocomplete="off"
                       data-order-id="<?= esc_attr($order_id) ?>"
                       style="width:120px;">
                <select id="klp_edit_time_slot">
                    <option value="morning"   <?= selected($time_slot, 'morning',   false) ?>><?= esc_html($settings['morning_label']) ?></option>
                    <option value="afternoon" <?= selected($time_slot, 'afternoon', false) ?>><?= esc_html($settings['afternoon_label']) ?></option>
                </select>
                <button type="button" id="klp_save_delivery" class="button">
                    <?php _e('Opslaan', 'kolenbrander-leveringsplanner'); ?>
                </button>
            </span>
            <span id="klp_delivery_feedback" style="display:none;margin-top:4px;font-size:12px;display:block;"></span>
        </p>
        <?php
    }

    public static function ajax_update_delivery() {
        check_ajax_referer('klp_update_delivery', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_die(-1);

        $order_id  = absint($_POST['order_id'] ?? 0);
        $date_raw  = sanitize_text_field($_POST['date'] ?? '');
        $time_slot = sanitize_text_field($_POST['time_slot'] ?? '');

        if (!$order_id) wp_send_json_error('Ongeldig bestelnummer.');

        $date_ymd = KLP_Checkout::parse_date($date_raw);
        if (!$date_ymd) wp_send_json_error('Ongeldige datum.');

        if (!in_array($time_slot, ['morning', 'afternoon'])) wp_send_json_error('Ongeldig tijdvak.');

        $order = wc_get_order($order_id);
        if (!$order) wp_send_json_error('Bestelling niet gevonden.');

        $order->update_meta_data(self::DATE_KEY, $date_ymd);
        $order->update_meta_data(self::TIME_SLOT_KEY, $time_slot);
        $order->save();

        if (KLP_Google_Calendar::is_enabled()) {
            $order->delete_meta_data('_klp_gcal_event_id');
            $order->save();
            KLP_Google_Calendar::sync_event($order_id);
        }

        $settings   = KLP_Settings::get();
        $time_label = $time_slot === 'morning' ? $settings['morning_label'] : $settings['afternoon_label'];

        wp_send_json_success([
            'message' => 'Opgeslagen: ' . date_i18n('l d F Y', strtotime($date_ymd)) . ' — ' . $time_label,
        ]);
    }

    public static function add_delivery_to_wc_email($order, $sent_to_admin, $plain_text) {
        $order_id = $order->get_id();
        $date = self::get_delivery_date($order_id);
        if (!$date) return;

        $settings = KLP_Settings::get();
        $time_slot = self::get_time_slot($order_id);
        $time_label = $time_slot === 'morning' ? $settings['morning_label'] : ($time_slot === 'afternoon' ? $settings['afternoon_label'] : $time_slot);
        $date_formatted = date_i18n('l d F Y', strtotime($date));

        if ($plain_text) {
            echo "\nLeverdag: {$date_formatted}\nTijdvak: {$time_label}\n";
        } else {
            echo '<h2 style="color:#7f54b3;font-size:18px;font-weight:bold;margin:0 0 18px;">Levering</h2>';
            echo '<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #eee;margin-bottom:20px;">';
            echo '<tr><th style="text-align:left;padding:8px;border-bottom:1px solid #eee;">Leverdag</th><td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html($date_formatted) . '</td></tr>';
            echo '<tr><th style="text-align:left;padding:8px;">Tijdvak</th><td style="padding:8px;">' . esc_html($time_label) . '</td></tr>';
            echo '</table>';
        }
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
