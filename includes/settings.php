<?php

if (!defined('ABSPATH')) exit;

class KLP_Settings {
    const OPTION_KEY = 'klp_settings';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_klp_test_gc', [__CLASS__, 'ajax_test_gc']);
        add_action('wp_ajax_klp_get_calendar', [__CLASS__, 'ajax_get_calendar']);
        add_action('wp_ajax_klp_save_closed_dates', [__CLASS__, 'ajax_save_closed_dates']);
        add_action('wp_ajax_klp_test_email', [__CLASS__, 'ajax_test_email']);
        add_filter('plugin_action_links_' . plugin_basename(KLP_PLUGIN_DIR . 'kolenbrander-leveringsplanner.php'), [__CLASS__, 'action_links']);
    }

    public static function get($key = null) {
        $defaults = [
            'morning_label' => 'Ochtend (08:00 - 12:00)',
            'afternoon_label' => 'Middag (12:00 - 17:00)',
            'max_per_day' => 200,
            'pickup_email' => 'ophalen@kolenbrandercontainers.nl',
            'admin_email' => get_option('admin_email'),
            'reminder_days_before' => 1,
            'pickup_reminder_days' => 7,
            'escalated_reminder_days' => 21,
            'gc_calendar_id' => 'primary',
            'gc_enabled' => 'no',
            'gc_client_id' => '',
            'gc_client_secret' => '',
            'gc_access_token' => '',
            'gc_refresh_token' => '',
            'gc_token_created' => '',
            'gc_calendars' => [],
            'email_subject_summary' => 'Dagoverzicht leveringen {date}',
            'email_subject_reminder' => 'Herinnering: levering over {days_before} dagen - Bestelling {order_number}',
            'email_subject_tomorrow' => 'Uw container wordt morgen geleverd - Bestelling {order_number}',
            'email_subject_pickup' => 'Container aanmelden voor ophalen - Bestelling {order_number}',
            'email_subject_pickup_escalated' => 'Laatste herinnering: container nog niet aangemeld - Bestelling {order_number}',
            'email_subject_pickup_notify_admin' => 'Ophaalverzoek - Bestelling {order_number}',
            'email_subject_pickup_notify_customer' => 'Bevestiging ophaalaanmelding - Bestelling {order_number}',
            'email_body_reminder' => 'Hallo {customer_name},

Over {days_before} dag(en) wordt bestelling {order_number} bij je bezorgd.

Bezorging: {delivery_date} ({time_slot})

Let op: zorg voor voldoende ruimte! Zorg ervoor dat er voldoende ruimte is op de locatie waar de container geplaatst wordt. Denk aan:
- Vrije doorgang voor de vrachtwagen
- Genoeg ruimte naast en voor de container
- Geen geparkeerde auto\'s of obstakels op de gewenste plek

Is je container vol of ben je klaar met de werkzaamheden? Of wil je gewoon dat de container weer wordt opgehaald? Meld hem dan vast aan via de knop hieronder.

{pickup_url}',
            'email_body_tomorrow' => 'Hallo {customer_name},

Morgen ({delivery_date}) wordt je container bezorgd tijdens het tijdvak {time_slot}.

Let op: zorg voor voldoende ruimte! Zorg ervoor dat er voldoende ruimte is op de locatie waar de container geplaatst wordt. Denk aan:
- Vrije doorgang voor de vrachtwagen
- Genoeg ruimte naast en voor de container
- Geen geparkeerde auto\'s of obstakels op de gewenste plek

Nadat de container is geleverd, kun je hem eenvoudig aanmelden voor ophalen via de knop hieronder. De chauffeur zorgt ervoor dat hij zo snel mogelijk wordt opgehaald.

Let op: zolang je de container niet aanmeldt, blijft hij bij je staan.

{pickup_url}',
            'email_body_pickup' => 'Hallo {customer_name},

Bedankt voor je bestelling {order_number}. Je container is inmiddels geleverd.

Is de container leeg of heb je hem niet meer nodig? Meld hem dan aan voor ophalen via de knop hieronder. Onze chauffeur haalt hem zo spoedig mogelijk op.

{pickup_url}

Al aangemeld? Dan kun je deze e-mail negeren.',
            'email_body_pickup_escalated' => 'Hallo {customer_name},

Je container van bestelling {order_number} is inmiddels een aantal dagen geleden geleverd, maar nog niet aangemeld voor ophalen.

Wil je de container niet meer? Meld hem dan alsnog aan via de knop hieronder, dan komt de chauffeur hem ophalen.

{pickup_url}

Al aangemeld? Dan kun je deze e-mail negeren.',
            'email_body_pickup_notify_customer' => 'Hallo {customer_name},

Je ophaalaanmelding voor bestelling {order_number} is goed ontvangen.

We zorgen ervoor dat de container zo snel mogelijk bij je wordt opgehaald. Je ontvangt hier geen aparte bevestiging meer van.',
        ];
        $settings = get_option(self::OPTION_KEY, []);
        $settings = wp_parse_args($settings, $defaults);
        return $key ? ($settings[$key] ?? null) : $settings;
    }

    public static function add_admin_menu() {
        add_menu_page(
            'Leveringsplanner',
            'Leveringsplanner',
            'manage_options',
            'klp-settings',
            [__CLASS__, 'render_page'],
            'dashicons-calendar-alt',
            55
        );
    }

    public static function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_klp-settings') return;

        wp_enqueue_style('jquery-ui-datepicker');
        wp_enqueue_style('klp-admin', KLP_PLUGIN_URL . 'assets/css/admin.css', [], KLP_VERSION);
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_script('klp-admin', KLP_PLUGIN_URL . 'assets/js/admin.js', ['jquery', 'jquery-ui-datepicker'], KLP_VERSION, true);
        wp_localize_script('klp-admin', 'klp_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('klp_save_closed_dates'),
            'test_nonce' => wp_create_nonce('klp_test_email'),
        ]);
    }

    public static function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'sanitize_callback' => [__CLASS__, 'sanitize'],
        ]);
    }

    public static function sanitize($input) {
        $existing = self::get();
        $out = $existing;

        if (isset($input['max_per_day'])) $out['max_per_day'] = absint($input['max_per_day']);
        if (isset($input['reminder_days_before'])) $out['reminder_days_before'] = absint($input['reminder_days_before']);
        if (isset($input['pickup_reminder_days'])) $out['pickup_reminder_days'] = absint($input['pickup_reminder_days']);
        if (isset($input['escalated_reminder_days'])) $out['escalated_reminder_days'] = absint($input['escalated_reminder_days']);
        if (isset($input['pickup_email'])) $out['pickup_email'] = sanitize_email($input['pickup_email']);
        if (isset($input['admin_email'])) $out['admin_email'] = sanitize_email($input['admin_email']);
        if (isset($input['morning_label'])) $out['morning_label'] = sanitize_text_field($input['morning_label']);
        if (isset($input['afternoon_label'])) $out['afternoon_label'] = sanitize_text_field($input['afternoon_label']);
        if (isset($input['closed_dates'])) $out['closed_dates'] = sanitize_textarea_field($input['closed_dates']);

        if (isset($input['gc_enabled'])) $out['gc_enabled'] = !empty($input['gc_enabled']) ? 'yes' : 'no';
        if (isset($input['gc_client_id'])) $out['gc_client_id'] = sanitize_text_field($input['gc_client_id']);
        if (isset($input['gc_client_secret'])) $out['gc_client_secret'] = sanitize_text_field($input['gc_client_secret']);
        if (isset($input['gc_calendar_id'])) $out['gc_calendar_id'] = sanitize_text_field($input['gc_calendar_id']);

        $email_subject_keys = ['email_subject_summary', 'email_subject_reminder', 'email_subject_tomorrow', 'email_subject_pickup', 'email_subject_pickup_escalated', 'email_subject_pickup_notify_admin', 'email_subject_pickup_notify_customer'];
        foreach ($email_subject_keys as $key) {
            if (isset($input[$key])) $out[$key] = sanitize_text_field($input[$key]);
        }

        $email_body_keys = ['email_body_reminder', 'email_body_tomorrow', 'email_body_pickup', 'email_body_pickup_escalated', 'email_body_pickup_notify_customer'];
        foreach ($email_body_keys as $key) {
            if (isset($input[$key])) $out[$key] = sanitize_textarea_field($input[$key]);
        }

        $gc_preserve = ['gc_access_token', 'gc_refresh_token', 'gc_token_created', 'gc_calendars'];
        foreach ($gc_preserve as $key) {
            if (isset($input[$key]) && !empty($input[$key])) {
                $out[$key] = $input[$key];
            }
        }

        return $out;
    }

    public static function ajax_test_gc() {
        if (!current_user_can('manage_options')) wp_die(-1);

        $connected = KLP_Google_Calendar::has_oauth();
        $enabled = KLP_Google_Calendar::is_enabled();

        if (!$connected) {
            wp_send_json_error('Niet verbonden met Google. Klik op "Koppelen met Google".');
        }

        if (!$enabled) {
            wp_send_json_error('Google Calendar sync is uitgeschakeld.');
        }

        try {
            $client = KLP_Google_Calendar::get_test_client();
            if (!$client) {
                wp_send_json_error('Kon geen verbinding maken met Google API.');
            }

            $service = new \Google_Service_Calendar($client);
            $settings = self::get();
            $cal_id = $settings['gc_calendar_id'] ?? 'primary';
            $cal = $service->calendarList->get($cal_id);
            wp_send_json_success(sprintf(
                'Verbinding OK. Agenda: <strong>%s</strong> (%s)',
                esc_html($cal->getSummary()),
                esc_html($cal_id)
            ));
        } catch (\Exception $e) {
            wp_send_json_error('Fout: ' . $e->getMessage());
        }
    }

    public static function ajax_test_email() {
        if (!current_user_can('manage_options')) wp_die(-1);
        check_ajax_referer('klp_test_email', 'nonce');

        $type = $_POST['email_type'] ?? '';
        $admin_email = self::get('admin_email');

        switch ($type) {
            case 'summary':
                KLP_Emails::send_admin_summary(true);
                break;
            case 'reminder':
                $d = KLP_Emails::build_dummy_order_data();
                $body = KLP_Emails::resolve_body('email_body_reminder', $d);
                $body = KLP_Emails::replace_pickup_url_with_button($body, $d['pickup_url']);
                $body .= KLP_Emails::build_dummy_items_table();
                KLP_Emails::send($admin_email, 'Test: ' . KLP_Emails::resolve_subject('email_subject_reminder', $d), $body, 'Bestelling #' . $d['order_number']);
                break;
            case 'pickup_reminder':
                $d = KLP_Emails::build_dummy_order_data();
                $body = KLP_Emails::resolve_body('email_body_pickup', $d);
                $body = KLP_Emails::replace_pickup_url_with_button($body, $d['pickup_url']);
                $body .= KLP_Emails::build_dummy_items_table();
                KLP_Emails::send($admin_email, 'Test: ' . KLP_Emails::resolve_subject('email_subject_pickup', $d), $body, 'Container aanmelden voor ophalen');
                break;
            case 'pickup_escalated':
                $d = KLP_Emails::build_dummy_order_data();
                $body = KLP_Emails::resolve_body('email_body_pickup_escalated', $d);
                $body = KLP_Emails::replace_pickup_url_with_button($body, $d['pickup_url']);
                $body .= KLP_Emails::build_dummy_items_table();
                KLP_Emails::send($admin_email, 'Test: ' . KLP_Emails::resolve_subject('email_subject_pickup_escalated', $d), $body, 'Laatste herinnering');
                break;
            case 'pickup_notify':
                $d = KLP_Emails::build_dummy_order_data();
                $admin_body = '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;">'
                    . KLP_Emails::build_dummy_order_row() . '</table>';
                $admin_body .= '<h3 style="margin-top:20px;">Ophaalverzoek aangemeld op ' . esc_html($d['pickup_date']) . '</h3>';
                KLP_Emails::send($admin_email, 'Test: ' . KLP_Emails::resolve_subject('email_subject_pickup_notify_admin', $d), $admin_body, 'Ophaalverzoek');
                break;
            default:
                wp_send_json_error('Onbekend e-mailtype.');
        }

        wp_send_json_success('Test e-mail verzonden naar ' . $admin_email);
    }

    public static function build_month_data($year, $month) {
        $max = self::get('max_per_day');
        $counts = KLP_Lockout::get_all_date_counts();

        $holidays = KLP_Holidays::get_dutch_holidays($year);
        $closed_dates_raw = self::get('closed_dates') ?? '';
        $user_closed_set = [];
        foreach (explode("\n", $closed_dates_raw) as $line) {
            $line = trim($line);
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $line, $m)) {
                $user_closed_set["{$m[3]}-{$m[2]}-{$m[1]}"] = true;
            }
        }

        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $dates = [];

        for ($d = 1; $d <= $days_in_month; $d++) {
            $ymd = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $ts = strtotime($ymd);

            $is_sunday = (int) date('w', $ts) === 0;
            $holiday_name = $holidays[$ymd] ?? null;
            $is_user_closed = isset($user_closed_set[$ymd]);
            $count = $counts[$ymd] ?? 0;
            $is_full = $count >= $max;

            if ($is_sunday) {
                $status = 'closed';
                $can_toggle = false;
                $reason = 'Zondag';
            } elseif ($holiday_name) {
                $status = 'closed';
                $can_toggle = false;
                $reason = $holiday_name;
            } elseif ($is_user_closed) {
                $status = 'user-closed';
                $can_toggle = true;
                $reason = 'Extra sluitingsdag';
            } elseif ($is_full) {
                $status = 'full';
                $can_toggle = true;
                $reason = sprintf('Volgeboekt (%d/%d)', $count, $max);
            } else {
                $status = 'available';
                $can_toggle = true;
                $reason = sprintf('Beschikbaar (%d/%d)', $count, $max);
            }

            $dates[] = [
                'day' => $d,
                'ymd' => $ymd,
                'status' => $status,
                'can_toggle' => $can_toggle,
                'reason' => $reason,
                'count' => $count,
                'max' => $max,
            ];
        }

        return $dates;
    }

    public static function ajax_get_calendar() {
        if (!current_user_can('manage_options')) wp_die(-1);

        $year = (int) ($_POST['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? 0);
        $mode = $_POST['mode'] ?? 'month';

        $closed_dates_raw = self::get('closed_dates') ?? '';
        $closed_dates = [];
        foreach (explode("\n", $closed_dates_raw) as $line) {
            $line = trim($line);
            if ($line) $closed_dates[] = $line;
        }

        $dmy_closed = [];
        foreach ($closed_dates as $dmy) {
            $parts = explode('-', $dmy);
            if (count($parts) === 3) {
                $dmy_closed[] = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            }
        }

        if ($mode === 'year') {
            $months = [];
            for ($m = 1; $m <= 12; $m++) {
                $dates = self::build_month_data($year, $m);
                $months[] = [
                    'month' => $m,
                    'month_name' => date_i18n('F', mktime(0, 0, 0, $m, 1, $year)),
                    'dates' => $dates,
                ];
            }

            wp_send_json_success([
                'year' => $year,
                'months' => $months,
                'closed_dates' => $dmy_closed,
            ]);
        } else {
            $dates = self::build_month_data($year, $month);

            wp_send_json_success([
                'year' => $year,
                'month' => $month,
                'month_name' => date_i18n('F', mktime(0, 0, 0, $month, 1, $year)),
                'dates' => $dates,
                'closed_dates' => $dmy_closed,
            ]);
        }
    }

    public static function ajax_save_closed_dates() {
        if (!current_user_can('manage_options')) wp_die(-1);
        check_ajax_referer('klp_save_closed_dates', 'nonce');

        $dates = sanitize_textarea_field($_POST['dates'] ?? '');
        $settings = self::get();
        $settings['closed_dates'] = $dates;
        update_option(self::OPTION_KEY, $settings);

        wp_send_json_success();
    }

    public static function action_links($links) {
        $links[] = '<a href="' . admin_url('admin.php?page=klp-settings') . '">Instellingen</a>';
        return $links;
    }

    public static function render_page() {
        $settings = self::get();
        include KLP_PLUGIN_DIR . 'templates/admin-settings.php';
    }
}
