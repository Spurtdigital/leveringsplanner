<?php

if (!defined('ABSPATH')) exit;

class KLP_Pickup {
    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_shortcode('klp_pickup_form', [__CLASS__, 'render_form_shortcode']);
        add_action('wp_ajax_klp_request_pickup', [__CLASS__, 'ajax_request_pickup']);
        add_action('wp_ajax_nopriv_klp_request_pickup', [__CLASS__, 'ajax_request_pickup']);
        add_action('template_redirect', [__CLASS__, 'handle_pickup_page']);
    }

    public static function register_rewrite() {
        add_rewrite_rule('^aanmelden-ophalen/?$', 'index.php?klp_pickup=1', 'top');
        add_rewrite_tag('%klp_pickup%', '1');
    }

    public static function enqueue() {
        global $wp_query;
        if (!isset($wp_query->query_vars['klp_pickup']) && !has_shortcode(get_post_field('post_content', get_the_ID()), 'klp_pickup_form')) {
            return;
        }
        wp_enqueue_style('klp-pickup', KLP_PLUGIN_URL . 'assets/css/pickup.css', [], KLP_VERSION);
        wp_enqueue_script('klp-pickup', KLP_PLUGIN_URL . 'assets/js/pickup.js', ['jquery'], KLP_VERSION, true);
        wp_localize_script('klp-pickup', 'klp_pickup', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('klp_pickup'),
        ]);
    }

    public static function render_form_shortcode() {
        return self::render_form();
    }

    public static function handle_pickup_page() {
        global $wp_query;
        if (!isset($wp_query->query_vars['klp_pickup']) || $wp_query->query_vars['klp_pickup'] !== '1') {
            return;
        }

        status_header(200);
        get_header();

        echo '<div class="wrap" style="max-width:800px;margin:40px auto;padding:0 20px;">';
        echo self::render_form();
        echo '</div>';

        get_footer();
        exit;
    }

    public static function render_form() {
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        ob_start();
        include KLP_PLUGIN_DIR . 'templates/pickup-form.php';
        return ob_get_clean();
    }

    public static function ajax_request_pickup() {
        check_ajax_referer('klp_pickup', 'nonce');

        $code = sanitize_text_field($_POST['code'] ?? '');
        if (empty($code)) {
            wp_send_json_error(['message' => 'Geen code opgegeven.']);
        }

        $order = KLP_Order_Meta::find_order_by_pickup_code($code);
        if (!$order) {
            wp_send_json_error(['message' => 'Ongeldige code. Geen bestelling gevonden.']);
        }

        $order_id = $order->get_id();

        if (KLP_Order_Meta::get_pickup_requested($order_id)) {
            wp_send_json_error(['message' => 'Deze container is al aangemeld voor ophalen.']);
        }

        update_post_meta($order_id, KLP_Order_Meta::PICKUP_REQUESTED_KEY, 'yes');
        update_post_meta($order_id, KLP_Order_Meta::PICKUP_REQUESTED_AT_KEY, current_time('mysql'));

        self::send_pickup_notification($order);

        wp_send_json_success(['message' => 'Container succesvol aangemeld voor ophalen. U ontvangt een bevestiging.']);
    }

    private static function send_pickup_notification($order) {
        $settings = KLP_Settings::get();
        $order_id = $order->get_id();

        $delivery_date = KLP_Order_Meta::get_delivery_date($order_id);
        $time_slot = KLP_Order_Meta::get_time_slot($order_id);
        $time_label = $time_slot === 'morning' ? $settings['morning_label'] : ($time_slot === 'afternoon' ? $settings['afternoon_label'] : $time_slot);

        $items_html = '<table cellpadding="4" cellspacing="0" style="width:100%;">';
        foreach ($order->get_items() as $item) {
            $items_html .= '<tr><td style="padding:4px 0;border-bottom:1px solid #f0f0f0;">- ' . esc_html(KLP_Order_Meta::format_item_line($item)) . '</td></tr>';
        }
        $items_html .= '</table>';

        $admin_data = [
            'order_number' => $order->get_order_number(),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'address' => $order->get_billing_address_1() . ', ' . $order->get_billing_postcode() . ' ' . $order->get_billing_city(),
            'phone' => $order->get_billing_phone(),
            'email' => $order->get_billing_email(),
            'delivery_date' => $delivery_date,
            'time_slot' => $time_label,
            'items' => $items_html,
            'pickup_date' => current_time('d-m-Y H:i'),
        ];

        $admin_body = '<p>Er is een nieuw ophaalverzoek van <strong>' . esc_html($admin_data['customer_name']) . '</strong>.</p>';
        $admin_body .= '<table cellpadding="4" cellspacing="0" style="width:100%;">';
        foreach ([
            'Bestelling' => '#' . $admin_data['order_number'],
            'Adres' => $admin_data['address'],
            'Telefoon' => $admin_data['phone'],
            'E-mail' => $admin_data['email'],
            'Leverdatum' => $admin_data['delivery_date'],
            'Tijdvak' => $admin_data['time_slot'],
            'Aangemeld op' => $admin_data['pickup_date'],
        ] as $label => $value) {
            $admin_body .= '<tr><td style="padding:4px 8px 4px 0;font-weight:700;width:120px;">' . esc_html($label) . '</td><td style="padding:4px 0;">' . esc_html($value) . '</td></tr>';
        }
        $admin_body .= '</table>';
        $admin_body .= '<h3 style="margin-top:16px;">Bestelde producten</h3>' . $admin_data['items'];

        KLP_Emails::send($settings['pickup_email'], KLP_Emails::resolve_subject('email_subject_pickup_notify_admin', $admin_data), $admin_body, 'Ophaalverzoek');

        $customer_data = [
            'customer_name' => $order->get_billing_first_name(),
            'order_number' => $order->get_order_number(),
            'pickup_date' => current_time('d-m-Y H:i'),
        ];
        KLP_Emails::send($order->get_billing_email(), KLP_Emails::resolve_subject('email_subject_pickup_notify_customer', $customer_data), KLP_Emails::resolve_body('email_body_pickup_notify_customer', $customer_data), 'Bevestiging ophaalaanmelding');
    }
}
