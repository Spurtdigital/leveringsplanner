<?php

if (!defined('ABSPATH')) exit;

class KLP_Google_Calendar {
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://www.googleapis.com/calendar/v3';

    public static function init() {
        add_action('woocommerce_checkout_update_order_meta', [__CLASS__, 'maybe_create_event'], 20, 2);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_status_change'], 10, 4);
        add_action('admin_init', [__CLASS__, 'handle_oauth_callback']);
        add_action('woocommerce_order_actions', [__CLASS__, 'add_order_action']);
        add_action('woocommerce_order_action_klp_sync_gcal', [__CLASS__, 'handle_order_action']);
    }

    public static function is_enabled() {
        $s = KLP_Settings::get();
        return $s['gc_enabled'] === 'yes' && !empty($s['gc_access_token']);
    }

    public static function has_oauth_creds() {
        $s = KLP_Settings::get();
        return !empty($s['gc_client_id']) && !empty($s['gc_client_secret']);
    }

    public static function has_oauth() {
        return self::has_oauth_creds() && !empty(KLP_Settings::get('gc_access_token'));
    }

    public static function get_redirect_uri() {
        return admin_url('admin.php?page=klp-settings&klp_gc_oauth=1');
    }

    public static function get_auth_url() {
        if (!self::has_oauth_creds()) return '';
        $s = KLP_Settings::get();
        $params = http_build_query([
            'client_id' => $s['gc_client_id'],
            'redirect_uri' => self::get_redirect_uri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    public static function handle_oauth_callback() {
        if (!current_user_can('manage_options')) return;

        if (isset($_GET['klp_gc_disconnect'])) {
            $s = KLP_Settings::get();
            foreach (['gc_access_token', 'gc_refresh_token', 'gc_token_created'] as $k) {
                $s[$k] = '';
            }
            $s['gc_calendars'] = [];
            $s['gc_calendar_id'] = '';
            $s['gc_enabled'] = 'no';
            update_option(KLP_Settings::OPTION_KEY, $s);
            wp_redirect(admin_url('admin.php?page=klp-settings'));
            exit;
        }

        if (!isset($_GET['klp_gc_oauth'])) return;

        $code = $_GET['code'] ?? '';
        $error = $_GET['error'] ?? '';

        if ($error) {
            add_settings_error('klp_settings', 'gc_oauth_error', 'Google OAuth fout: ' . esc_html($error), 'error');
            return;
        }

        if (empty($code)) return;

        $s = KLP_Settings::get();
        if (empty($s['gc_client_id']) || empty($s['gc_client_secret'])) {
            add_settings_error('klp_settings', 'gc_oauth_error', 'Client ID en Secret zijn vereist.', 'error');
            return;
        }

        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'code' => $code,
                'client_id' => $s['gc_client_id'],
                'client_secret' => $s['gc_client_secret'],
                'redirect_uri' => self::get_redirect_uri(),
                'grant_type' => 'authorization_code',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            add_settings_error('klp_settings', 'gc_oauth_error', 'Token aanvraag mislukt: ' . $response->get_error_message(), 'error');
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['error'])) {
            add_settings_error('klp_settings', 'gc_oauth_error', 'OAuth fout: ' . esc_html($body['error']), 'error');
            return;
        }

        $s['gc_access_token'] = $body['access_token'] ?? '';
        $s['gc_refresh_token'] = $body['refresh_token'] ?? '';
        $s['gc_token_created'] = current_time('mysql');

        $calendars = self::fetch_calendar_list($body['access_token']);
        if ($calendars !== null) {
            $s['gc_calendars'] = $calendars;
            if (!empty($calendars)) {
                $s['gc_calendar_id'] = array_key_first($calendars);
            }
            $s['gc_enabled'] = 'yes';
        }

        update_option(KLP_Settings::OPTION_KEY, $s);
        wp_redirect(admin_url('admin.php?page=klp-settings&klp_gc_oauth=success'));
        exit;
    }

    public static function add_order_action($actions) {
        $actions['klp_sync_gcal'] = 'Verstuur naar Google Calendar';
        return $actions;
    }

    public static function handle_order_action($order) {
        $result = self::sync_event($order->get_id());
        $order->add_order_note(
            $result ? 'Handmatig doorgestuurd naar Google Calendar.' : 'Fout bij doorsturen naar Google Calendar.'
        );
    }

    public static function maybe_create_event($order_id, $data) {
        if (!self::is_enabled()) return;
        if (!empty(get_post_meta($order_id, '_klp_gcal_event_id', true))) return;
        self::sync_event($order_id);
    }

    public static function on_status_change($order_id, $old_status, $new_status, $order) {
        if (!self::is_enabled()) return;

        $cancelled = ['cancelled', 'refunded', 'failed'];

        if (in_array($new_status, $cancelled)) {
            self::delete_event($order_id);
        } elseif (in_array($new_status, ['processing', 'completed', 'on-hold'])) {
            if (empty(get_post_meta($order_id, '_klp_gcal_event_id', true))) {
                self::sync_event($order_id);
            }
        }
    }

    public static function sync_event($order_id) {
        if (!self::is_enabled()) return false;

        $token = self::get_valid_token();
        if (!$token) return false;

        $order = wc_get_order($order_id);
        if (!$order) return false;

        $s = KLP_Settings::get();
        $calendar_id = urlencode($s['gc_calendar_id'] ?? 'primary');

        $date = KLP_Order_Meta::get_delivery_date($order_id);
        $time_slot = KLP_Order_Meta::get_time_slot($order_id);
        if (empty($date)) return false;

        $time_label = $time_slot === 'morning' ? $s['morning_label'] : ($time_slot === 'afternoon' ? $s['afternoon_label'] : 'Onbekend');
        $start_time = $time_slot === 'morning' ? '08:00' : '12:00';
        $end_time = $time_slot === 'morning' ? '12:00' : '17:00';

        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $summary = sprintf('Levering %s - %s', $order->get_order_number(), $customer_name);

        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = KLP_Order_Meta::format_item_line($item);
        }

        $description = "𝐁𝐄𝐒𝐓𝐄𝐋𝐍𝐔𝐌𝐌𝐄𝐑: {$order->get_order_number()}\n\n";
        $description .= "𝐋𝐄𝐕𝐄𝐑𝐀𝐃𝐑𝐄𝐒\n";
        $description .= "{$customer_name}\n";
        $description .= "{$order->get_billing_address_1()}\n";
        $description .= "{$order->get_billing_postcode()} {$order->get_billing_city()}\n";
        $description .= "{$order->get_billing_phone()}\n";
        $description .= "{$order->get_billing_email()}\n\n";

        $shipping_phone = method_exists($order, 'get_shipping_phone') ? $order->get_shipping_phone() : '';
        $description .= "𝐅𝐀𝐂𝐓𝐔𝐔𝐑𝐀𝐃𝐑𝐄𝐒\n";
        $description .= "{$order->get_shipping_first_name()} {$order->get_shipping_last_name()}\n";
        $desc_address = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
        $desc_postcode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
        $desc_city = $order->get_shipping_city() ?: $order->get_billing_city();
        $description .= "{$desc_address}\n{$desc_postcode} {$desc_city}";
        if (!empty($shipping_phone)) {
            $description .= "\n{$shipping_phone}";
        }
        $description .= "\n\n";

        $description .= "𝐓𝐄 𝐋𝐄𝐕𝐄𝐑𝐄𝐍 𝐏𝐑𝐎𝐃𝐔𝐂𝐓𝐄𝐍\n";
        foreach ($order->get_items() as $item) {
            $name = $item->get_name();
            $parts = explode(' - ', $name, 2);
            $description .= ($parts[0]) . "\n";
            $description .= " " . (isset($parts[1]) ? $parts[1] : '') . " x{$item->get_quantity()}\n";
            foreach ($item->get_meta_data() as $m) {
                if (strpos($m->key, '_') === 0) continue;
                if (strpos($m->key, 'pa_') === 0) continue;
                $val = is_string($m->value) ? $m->value : '';
                if (preg_match('/^Geen\s/', $val)) continue;
                $description .= " {$m->key} {$val}\n";
            }
            $description .= "\n";
        }
        $description .= "\n";

        $description .= "Tijdvak: {$time_label}\n";

        $pickup_code = KLP_Order_Meta::get_pickup_code($order_id);
        if ($pickup_code) {
            $description .= "Ophaalcode: {$pickup_code}\n";
            $description .= "Aanmeldlink: " . KLP_Settings::pickup_url($pickup_code) . "\n";
        }

        $event = [
            'summary' => $summary,
            'description' => $description,
            'start' => [
                'dateTime' => $date . 'T' . $start_time . ':00',
                'timeZone' => 'Europe/Amsterdam',
            ],
            'end' => [
                'dateTime' => $date . 'T' . $end_time . ':00',
                'timeZone' => 'Europe/Amsterdam',
            ],
        ];

        $existing_event_id = get_post_meta($order_id, '_klp_gcal_event_id', true);
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ];

        if (!empty($existing_event_id)) {
            $response = wp_remote_request(self::API_BASE . "/calendars/{$calendar_id}/events/" . urlencode($existing_event_id), [
                'method' => 'PUT',
                'headers' => $headers,
                'body' => wp_json_encode($event),
                'timeout' => 30,
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
                error_log('KLP GC update failed, will try create: ' . wp_remote_retrieve_body($response));
                delete_post_meta($order_id, '_klp_gcal_event_id');
            } else {
                return true;
            }
        }

        $response = wp_remote_post(self::API_BASE . "/calendars/{$calendar_id}/events", [
            'headers' => $headers,
            'body' => wp_json_encode($event),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('KLP GC create error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['id'])) {
            error_log('KLP GC create failed: ' . wp_remote_retrieve_body($response));
            return false;
        }

        update_post_meta($order_id, '_klp_gcal_event_id', $body['id']);
        return true;
    }

    public static function delete_event($order_id) {
        if (!self::is_enabled()) return false;

        $token = self::get_valid_token();
        if (!$token) return false;

        $event_id = get_post_meta($order_id, '_klp_gcal_event_id', true);
        if (empty($event_id)) return false;

        $s = KLP_Settings::get();
        $calendar_id = urlencode($s['gc_calendar_id'] ?? 'primary');

        $response = wp_remote_request(self::API_BASE . "/calendars/{$calendar_id}/events/" . urlencode($event_id), [
            'method' => 'DELETE',
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('KLP GC delete error: ' . $response->get_error_message());
            return false;
        }

        delete_post_meta($order_id, '_klp_gcal_event_id');
        return true;
    }

    private static function get_valid_token() {
        $s = KLP_Settings::get();
        if (empty($s['gc_access_token'])) return null;

        $created = !empty($s['gc_token_created']) ? strtotime($s['gc_token_created']) : 0;
        if (time() - $created < 3500) return $s['gc_access_token'];

        if (empty($s['gc_refresh_token'])) return null;

        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'client_id' => $s['gc_client_id'],
                'client_secret' => $s['gc_client_secret'],
                'refresh_token' => $s['gc_refresh_token'],
                'grant_type' => 'refresh_token',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('KLP GC token refresh error: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            error_log('KLP GC token refresh failed: ' . wp_remote_retrieve_body($response));
            return null;
        }

        $s['gc_access_token'] = $body['access_token'];
        $s['gc_token_created'] = current_time('mysql');
        update_option(KLP_Settings::OPTION_KEY, $s);

        return $body['access_token'];
    }

    private static function fetch_calendar_list($access_token) {
        $response = wp_remote_get(self::API_BASE . '/users/me/calendarList', [
            'headers' => ['Authorization' => 'Bearer ' . $access_token],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) return null;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['items'])) return null;

        $calendars = [];
        foreach ($body['items'] as $cal) {
            $calendars[$cal['id']] = $cal['summary'];
        }
        return $calendars;
    }
}
