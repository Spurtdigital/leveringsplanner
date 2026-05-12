<?php

if (!defined('ABSPATH')) exit;

class KLP_Emails {
    public static function init() {
        add_action('klp_daily_admin_summary', [__CLASS__, 'send_admin_summary']);
        add_action('klp_customer_reminder', [__CLASS__, 'send_customer_reminder']);
        add_action('klp_pickup_reminder', [__CLASS__, 'send_pickup_reminder']);
    }

    public static function send($to, $subject, $body_html, $heading = '') {
        if (!$heading) $heading = $subject;

        $html = wc_get_template_html('emails/email-header.php', ['email_heading' => $heading]);
        $html .= $body_html;
        $html .= wc_get_template_html('emails/email-footer.php');

        $html = self::style_inline($html);

        add_filter('wp_mail_content_type', [__CLASS__, 'set_html_content_type']);
        $result = wp_mail($to, $subject, $html);
        remove_filter('wp_mail_content_type', [__CLASS__, 'set_html_content_type']);

        return $result;
    }

    private static function style_inline($html) {
        $css = wc_get_template_html('emails/email-styles.php');
        if (class_exists('\Pelago\Emogrifier\CssInliner')) {
            try {
                return \Pelago\Emogrifier\CssInliner::fromHtml($html)->inlineCss($css)->render();
            } catch (\Exception $e) {
            }
        }
        $style_tag = "<style type=\"text/css\">\n$css\n</style>";
        return str_replace('</head>', "$style_tag\n</head>", $html);
    }

    public static function set_html_content_type() {
        return 'text/html';
    }

    public static function resolve_subject($key, $data = []) {
        $template = KLP_Settings::get($key);
        if (!$template) return '';
        return self::replace_placeholders($template, $data);
    }

    public static function resolve_body($key, $data = []) {
        $template = KLP_Settings::get($key);
        if (!$template) return '';
        $text = self::replace_placeholders($template, $data);
        $text = self::bullets_to_html($text);
        return wpautop($text);
    }

    private static function bullets_to_html($text) {
        $lines = explode("\n", $text);
        $in_list = false;
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^[-*]\s+(.+)/', $line, $m)) {
                if (!$in_list) {
                    $out[] = '<ul style="margin:8px 0;padding-left:20px;">';
                    $in_list = true;
                }
                $out[] = '<li style="margin:2px 0;">' . trim($m[1]) . '</li>';
            } else {
                if ($in_list) {
                    $out[] = '</ul>';
                    $in_list = false;
                }
                $out[] = $line;
            }
        }
        if ($in_list) $out[] = '</ul>';
        return implode("\n", $out);
    }

    private static function replace_placeholders($text, $data = []) {
        $replacements = [
            '{order_number}' => $data['order_number'] ?? '',
            '{customer_name}' => $data['customer_name'] ?? '',
            '{date}' => $data['date'] ?? date_i18n('l d F Y'),
            '{days_before}' => $data['days_before'] ?? '1',
            '{delivery_date}' => $data['delivery_date'] ?? '',
            '{time_slot}' => $data['time_slot'] ?? '',
            '{site_name}' => get_bloginfo('name'),
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    private static function button_html($url, $label = 'Container aanmelden voor ophalen') {
        if (empty($url) || $url === '#') return '';
        return '<p style="margin:20px 0;"><a href="' . esc_url($url) . '" style="background:#46b450;color:#fff;padding:10px 24px;border-radius:4px;text-decoration:none;display:inline-block;font-size:15px;">' . esc_html($label) . '</a></p>';
    }

    public static function replace_pickup_url_with_button($body, $url) {
        if (empty($url)) return str_replace("\n{pickup_url}\n", "\n\n", $body);
        $btn = self::button_html($url);
        if (strpos($body, '{pickup_url}') !== false) {
            return str_replace('{pickup_url}', $btn, $body);
        }
        return $body . $btn;
    }

    public static function send_admin_summary($test = false) {
        $settings = KLP_Settings::get();
        $to = array_filter(array_map('trim', explode(',', $settings['admin_email'])));
        $date_nice = date_i18n('l d F Y');

        $body = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1d2327;">';

        if ($test) {
            $today_row = self::build_dummy_order_row();
            $tomorrow_row = self::build_dummy_order_row();

            $body .= '<h2 style="font-size:16px;margin:0 0 8px;padding-bottom:6px;border-bottom:2px solid #46b450;">Leveringen vandaag</h2>';
            $body .= '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;">' . $today_row . '</table>';

            $body .= '<h2 style="font-size:16px;margin:24px 0 8px;padding-bottom:6px;border-bottom:2px solid #2271b1;">Leveringen morgen</h2>';
            $body .= '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;">' . $tomorrow_row . '</table>';

            $body .= '<h2 style="font-size:16px;margin:24px 0 8px;padding-bottom:6px;border-bottom:2px solid #f0a030;">Niet aangemeld voor ophaal</h2>';
            $body .= '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;">';
            $body .= '<tr><td style="padding:6px;border-bottom:1px solid #eee;"><strong>#9998</strong> Jan Jansen <span style="color:#b32d2e;">(4 dagen geleden geleverd)</span></td></tr>';
            $body .= '</table>';

            $body .= '<h2 style="font-size:16px;margin:24px 0 8px;padding-bottom:6px;border-bottom:2px solid #46b450;">Ophaalverzoeken vandaag</h2>';
            $body .= '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;">' . $tomorrow_row . '</table>';
        } else {
            $today = date('Y-m-d', current_time('timestamp'));
            $tomorrow = date('Y-m-d', strtotime('+1 day', current_time('timestamp')));

            $today_orders = KLP_Order_Meta::get_orders_for_date($today);
            $tomorrow_orders = KLP_Order_Meta::get_orders_for_date($tomorrow);

            $body .= '<h2 style="font-size:16px;margin:0 0 8px;padding-bottom:6px;border-bottom:2px solid #46b450;">Leveringen vandaag</h2>';
            if (!empty($today_orders)) {
                $body .= '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;">';
                foreach ($today_orders as $order) {
                    $body .= self::format_order_row($order);
                }
                $body .= '</table>';
            } else {
                $body .= '<p style="color:#999;">Geen leveringen gepland vandaag.</p>';
            }

            $body .= '<h2 style="font-size:16px;margin:24px 0 8px;padding-bottom:6px;border-bottom:2px solid #2271b1;">Leveringen morgen</h2>';
            if (!empty($tomorrow_orders)) {
                $body .= '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;">';
                foreach ($tomorrow_orders as $order) {
                    $body .= self::format_order_row($order);
                }
                $body .= '</table>';
            } else {
                $body .= '<p style="color:#999;">Geen leveringen gepland morgen.</p>';
            }

            $body .= '<h2 style="font-size:16px;margin:24px 0 8px;padding-bottom:6px;border-bottom:2px solid #f0a030;">Niet aangemeld voor ophaal</h2>';
            $not_picked = self::get_not_picked_up_orders();
            if (!empty($not_picked)) {
                $body .= '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;">';
                foreach ($not_picked as $order) {
                    $delivery_date = KLP_Order_Meta::get_delivery_date($order->get_id());
                    $days_ago = $delivery_date ? round((current_time('timestamp') - strtotime($delivery_date)) / 86400) : '?';
                    $body .= '<tr><td style="padding:6px;border-bottom:1px solid #eee;">';
                    $body .= '<strong>#' . esc_html($order->get_order_number()) . '</strong> '
                        . esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())
                        . ' <span style="color:#b32d2e;">(' . (int) $days_ago . ' dagen geleden geleverd)</span>';
                    $body .= '</td></tr>';
                }
                $body .= '</table>';
            } else {
                $body .= '<p style="color:#999;">Alle containers zijn aangemeld.</p>';
            }

            $body .= '<h2 style="font-size:16px;margin:24px 0 8px;padding-bottom:6px;border-bottom:2px solid #46b450;">Ophaalverzoeken vandaag</h2>';
            $pickup_ids = self::get_pickup_requests_for_today();
            if (!empty($pickup_ids)) {
                $body .= '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;">';
                foreach ($pickup_ids as $order_id) {
                    $order = wc_get_order($order_id);
                    if ($order) $body .= self::format_order_row($order);
                }
                $body .= '</table>';
            } else {
                $body .= '<p style="color:#999;">Geen ophaalverzoeken vandaag.</p>';
            }
        }

        $body .= '</div>';

        $subject = self::resolve_subject('email_subject_summary', [
            'date' => $date_nice,
            'site_name' => get_bloginfo('name'),
        ]);

        self::send($to, $subject, $body, 'Dagoverzicht ' . $date_nice);
    }

    public static function send_customer_reminder($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        if (KLP_Order_Meta::get_reminder_sent($order_id)) return;

        $date = KLP_Order_Meta::get_delivery_date($order_id);
        $settings = KLP_Settings::get();
        $time_slot = KLP_Order_Meta::get_time_slot($order_id);
        $time_label = $time_slot === 'morning' ? $settings['morning_label'] : ($time_slot === 'afternoon' ? $settings['afternoon_label'] : $time_slot);

        $code = KLP_Order_Meta::get_pickup_code($order_id);
        $data = [
            'customer_name' => $order->get_billing_first_name(),
            'order_number' => $order->get_order_number(),
            'delivery_date' => date_i18n('l d F Y', strtotime($date)),
            'time_slot' => $time_label,
            'days_before' => $settings['reminder_days_before'],
            'pickup_url' => $code ? KLP_Settings::pickup_url($code) : '',
        ];

        $body = self::resolve_body('email_body_reminder', $data);
        $body = self::replace_pickup_url_with_button($body, $data['pickup_url']);
        $body .= self::build_order_items_table($order);

        self::send($order->get_billing_email(), self::resolve_subject('email_subject_reminder', $data), $body, 'Bestelling #' . $order->get_order_number());

        update_post_meta($order_id, KLP_Order_Meta::REMINDER_SENT_KEY, 'yes');
    }

    public static function send_pickup_reminder($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        if (KLP_Order_Meta::get_pickup_requested($order_id)) return;
        if (KLP_Order_Meta::get_pickup_reminder_sent($order_id)) return;

        $code = KLP_Order_Meta::get_pickup_code($order_id);
        $data = [
            'customer_name' => $order->get_billing_first_name(),
            'order_number' => $order->get_order_number(),
            'pickup_url' => $code ? KLP_Settings::pickup_url($code) : '#',
        ];

        $body = self::resolve_body('email_body_pickup', $data);
        $body = self::replace_pickup_url_with_button($body, $data['pickup_url']);
        $body .= self::build_order_items_table($order);

        self::send($order->get_billing_email(), self::resolve_subject('email_subject_pickup', $data), $body, 'Container aanmelden voor ophalen');

        update_post_meta($order_id, KLP_Order_Meta::PICKUP_REMINDER_SENT_KEY, 'yes');
    }

    public static function send_escalated_pickup_reminder($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        if (KLP_Order_Meta::get_pickup_requested($order_id)) return;
        if (KLP_Order_Meta::get_escalated_reminder_sent($order_id)) return;

        $code = KLP_Order_Meta::get_pickup_code($order_id);
        $data = [
            'customer_name' => $order->get_billing_first_name(),
            'order_number' => $order->get_order_number(),
            'pickup_url' => $code ? KLP_Settings::pickup_url($code) : '#',
        ];

        $body = self::resolve_body('email_body_pickup_escalated', $data);
        $body = self::replace_pickup_url_with_button($body, $data['pickup_url']);
        $body .= self::build_order_items_table($order);

        self::send($order->get_billing_email(), self::resolve_subject('email_subject_pickup_escalated', $data), $body, 'Laatste herinnering');

        update_post_meta($order_id, KLP_Order_Meta::ESCALATED_REMINDER_SENT_KEY, 'yes');
    }

    private static function format_order_row($order) {
        $order_id = $order->get_id();
        $time_slot = KLP_Order_Meta::get_time_slot($order_id);
        $settings = KLP_Settings::get();
        $time_label = $time_slot === 'morning' ? $settings['morning_label'] : ($time_slot === 'afternoon' ? $settings['afternoon_label'] : $time_slot);

        $items_html = '';
        foreach ($order->get_items() as $item) {
            $items_html .= '<div style="padding:1px 0;">&#9632; ' . esc_html(KLP_Order_Meta::format_item_line($item)) . '</div>';
        }

        return '<tr><td style="padding:8px 10px;border-bottom:1px solid #eee;font-size:13px;">'
            . '<strong>#' . esc_html($order->get_order_number()) . '</strong> '
            . esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . '<br>'
            . '<span style="color:#666;font-size:12px;">'
            . esc_html($order->get_billing_address_1() . ', ' . $order->get_billing_postcode() . ' ' . $order->get_billing_city()) . '<br>'
            . esc_html($time_label) . ' &middot; ' . esc_html($order->get_billing_phone())
            . '</span>'
            . '<div style="margin-top:4px;font-size:13px;color:#1d2327;">' . $items_html . '</div>'
            . '</td></tr>';
    }

    private static function build_order_items_table($order) {
        $html = '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;margin-top:16px;border:1px solid #ddd;border-radius:4px;">';
        $html .= '<thead><tr style="background:#f6f7f7;"><th style="text-align:left;padding:8px 12px;border-bottom:1px solid #ddd;">Product</th>';
        $html .= '<th style="text-align:right;padding:8px 12px;border-bottom:1px solid #ddd;">Aantal</th></tr></thead><tbody>';
        foreach ($order->get_items() as $item) {
            $line = KLP_Order_Meta::format_item_line($item);
            $html .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;">' . esc_html($item->get_name()) . '</td>';
            $html .= '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;text-align:right;">' . $item->get_quantity() . '</td></tr>';
            $epo_parts = [];
            foreach ($item->get_meta_data() as $m) {
                if (strpos($m->key, '_') === 0) continue;
                if (strpos($m->key, 'pa_') === 0) continue;
                $val = is_string($m->value) ? $m->value : (is_array($m->value) ? implode(', ', $m->value) : '');
                if (preg_match('/^Geen\s/', $val)) continue;
                $epo_parts[] = esc_html($m->key . ' ' . $val);
            }
            if (!empty($epo_parts)) {
                $html .= '<tr><td colspan="2" style="padding:0 12px 8px;font-size:12px;color:#666;">' . implode('<br>', $epo_parts) . '</td></tr>';
            }
        }
        $html .= '</tbody></table>';
        return $html;
    }

    public static function build_dummy_items_table() {
        return '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;margin-top:16px;border:1px solid #ddd;border-radius:4px;">
<thead><tr style="background:#f6f7f7;"><th style="text-align:left;padding:8px 12px;border-bottom:1px solid #ddd;">Product</th>
<th style="text-align:right;padding:8px 12px;border-bottom:1px solid #ddd;">Aantal</th></tr></thead><tbody>
<tr><td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;">Container 20m&sup3;</td>
<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;text-align:right;">2</td></tr>
<tr><td colspan="2" style="padding:0 12px 8px;font-size:12px;color:#666;">Zand/grond 0-5mm</td></tr>
<tr><td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;">Container 10m&sup3;</td>
<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;text-align:right;">1</td></tr>
<tr><td colspan="2" style="padding:0 12px 8px;font-size:12px;color:#666;">Sloopmaterialen; Puin 0-20mm</td></tr>
</tbody></table>';
    }

    public static function build_dummy_order_row() {
        return '<tr><td style="padding:8px 10px;border-bottom:1px solid #eee;font-size:13px;">'
            . '<strong>#9999</strong> Test Persoon<br>'
            . '<span style="color:#666;font-size:12px;">'
            . 'Hoofdstraat 1, 1234 AB Amsterdam<br>'
            . 'Ochtend (08:00 - 12:00) &middot; 06-12345678'
            . '</span>'
            . '<div style="margin-top:4px;font-size:13px;color:#1d2327;">'
            . '<div style="padding:1px 0;">&#9632; Container 20m&sup3; x2 (Zand/grond 0-5mm)</div>'
            . '<div style="padding:1px 0;">&#9632; Container 10m&sup3; x1 (Sloopmaterialen; Puin 0-20mm)</div>'
            . '</div>'
            . '</td></tr>';
    }

    public static function build_dummy_order_data() {
        return [
            'customer_name' => 'Test Persoon',
            'order_number' => '9999',
            'delivery_date' => date_i18n('l d F Y', strtotime('+1 day')),
            'time_slot' => 'Ochtend (08:00 - 12:00)',
            'address' => 'Hoofdstraat 1, 1234 AB Amsterdam',
            'phone' => '06-12345678',
            'email' => 'test@example.com',
            'pickup_url' => KLP_Settings::pickup_url('KL-9999-TEST'),
            'pickup_date' => current_time('d-m-Y H:i'),
            'days_before' => 1,
        ];
    }

    private static function get_not_picked_up_orders() {
        global $wpdb;
        $days_ago = 3;
        $cutoff = date('Y-m-d', strtotime("-{$days_ago} days", current_time('timestamp')));
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
        ", KLP_Order_Meta::DATE_KEY, $cutoff, KLP_Order_Meta::PICKUP_REQUESTED_KEY));

        return array_map('wc_get_order', $ids);
    }

    private static function get_pickup_requests_for_today() {
        global $wpdb;
        $today = date('Y-m-d', current_time('timestamp'));

        return $wpdb->get_col($wpdb->prepare("
            SELECT post_id FROM {$wpdb->prefix}postmeta
            WHERE meta_key = %s
            AND DATE(meta_value) = %s
        ", KLP_Order_Meta::PICKUP_REQUESTED_AT_KEY, $today));
    }
}
