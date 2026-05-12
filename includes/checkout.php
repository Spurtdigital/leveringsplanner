<?php

if (!defined('ABSPATH')) exit;

class KLP_Checkout {
    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('woocommerce_after_checkout_billing_form', [__CLASS__, 'render_fields']);
        add_action('woocommerce_after_checkout_validation', [__CLASS__, 'validate_fields'], 10, 2);
        add_action('woocommerce_checkout_update_order_meta', [__CLASS__, 'save_fields']);
        add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'display_order_meta'], 10);
    }

    public static function enqueue_assets() {
        if (!is_checkout() && !is_cart()) return;

        wp_enqueue_style('klp-checkout', KLP_PLUGIN_URL . 'assets/css/checkout.css', [], KLP_VERSION);
        wp_enqueue_script('klp-checkout', KLP_PLUGIN_URL . 'assets/js/checkout.js', ['jquery', 'jquery-ui-datepicker'], KLP_VERSION, true);

        $settings = KLP_Settings::get();
        $closed = KLP_Holidays::get_closed_dates();
        $max = (int) $settings['max_per_day'];

        $full_at_capacity = KLP_Lockout::get_full_dates();

        $full_date_strings = [];
        foreach ($full_at_capacity as $date => $count) {
            $ts = strtotime($date);
            $full_date_strings[] = date('d-m-Y', $ts);
        }

        $closed_date_strings = [];
        foreach ($closed as $ymd => $val) {
            $ts = strtotime($ymd);
            if ($ts) {
                $closed_date_strings[] = date('d-m-Y', $ts);
            }
        }

        wp_localize_script('klp-checkout', 'klp_params', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'closed_dates' => $closed_date_strings,
            'full_dates' => $full_date_strings,
            'max_per_day' => $max,
            'morning_label' => esc_html($settings['morning_label']),
            'afternoon_label' => esc_html($settings['afternoon_label']),
            'date_format' => 'dd-mm-yy',
            'min_date' => self::calc_min_date((int) $settings['min_lead_hours']),
            'nonce' => wp_create_nonce('klp_checkout'),
        ]);
    }

    public static function render_fields() {
        $settings = KLP_Settings::get();
        ?>
        <div class="klp-checkout-fields">
            <h3><?php esc_html_e('Leverdag & tijdvak', 'kolenbrander-leveringsplanner'); ?></h3>

            <p class="form-row form-row-wide klp-date-field validate-required">
                <label for="klp_delivery_date">
                    <?php esc_html_e('Kies een leverdag', 'kolenbrander-leveringsplanner'); ?>
                    <abbr class="required" title="verplicht">*</abbr>
                </label>
                <input type="text" id="klp_delivery_date" name="klp_delivery_date"
                       placeholder="<?php esc_attr_e('Kies een datum...', 'kolenbrander-leveringsplanner'); ?>"
                       class="input-text form-control" autocomplete="off">
            </p>

            <p class="form-row form-row-wide klp-time-field validate-required">
                <label for="klp_time_slot">
                    <?php esc_html_e('Kies een tijdvak', 'kolenbrander-leveringsplanner'); ?>
                    <abbr class="required" title="verplicht">*</abbr>
                </label>
                <select id="klp_time_slot" name="klp_time_slot" class="input-select form-select" disabled>
                    <option value=""><?php esc_html_e('Selecteer eerst een datum...', 'kolenbrander-leveringsplanner'); ?></option>
                    <option value="morning"><?= esc_html($settings['morning_label']); ?></option>
                    <option value="afternoon"><?= esc_html($settings['afternoon_label']); ?></option>
                </select>
            </p>
        </div>
        <?php
    }

    public static function validate_fields($data, $errors) {
        if (empty($_POST['klp_delivery_date'])) {
            $errors->add('klp_delivery_date', __('Kies een leverdag.', 'kolenbrander-leveringsplanner'));
            return;
        }

        $date = sanitize_text_field($_POST['klp_delivery_date']);
        $time_slot = sanitize_text_field($_POST['klp_time_slot'] ?? '');

        $date_ymd = self::parse_date($date);
        if (!$date_ymd) {
            $errors->add('klp_delivery_date', __('Ongeldige datum.', 'kolenbrander-leveringsplanner'));
            return;
        }

        if (KLP_Holidays::is_closed($date_ymd)) {
            $errors->add('klp_delivery_date', __('Deze datum is niet beschikbaar (feestdag/sluitingsdag).', 'kolenbrander-leveringsplanner'));
            return;
        }

        if (KLP_Holidays::is_sunday($date_ymd)) {
            $errors->add('klp_delivery_date', __('Op zondag leveren wij niet.', 'kolenbrander-leveringsplanner'));
            return;
        }

        if (KLP_Lockout::is_full($date_ymd)) {
            $errors->add('klp_delivery_date', __('Deze datum is volgeboekt. Kies een andere datum.', 'kolenbrander-leveringsplanner'));
            return;
        }

        if (!in_array($time_slot, ['morning', 'afternoon'])) {
            $errors->add('klp_time_slot', __('Kies een tijdvak.', 'kolenbrander-leveringsplanner'));
            return;
        }

        $cutoff = (int) KLP_Settings::get('min_lead_hours');
        $min_date_ymd = self::calc_min_date($cutoff, 'Y-m-d');
        if ($date_ymd < $min_date_ymd) {
            $errors->add('klp_delivery_date', sprintf(
                __('Deze leverdag is niet meer beschikbaar. Vroegste leverdag: %s.', 'kolenbrander-leveringsplanner'),
                date_i18n('l d F Y', strtotime($min_date_ymd))
            ));
        }
    }

    public static function save_fields($order_id) {
        if (!empty($_POST['klp_delivery_date'])) {
            $date_ymd = self::parse_date(sanitize_text_field($_POST['klp_delivery_date']));
            if ($date_ymd) {
                update_post_meta($order_id, KLP_Order_Meta::DATE_KEY, $date_ymd);
            }
        }

        if (!empty($_POST['klp_time_slot'])) {
            update_post_meta($order_id, KLP_Order_Meta::TIME_SLOT_KEY, sanitize_text_field($_POST['klp_time_slot']));
        }

        KLP_Order_Meta::generate_pickup_code($order_id);
    }

    public static function display_order_meta($order) {
        $date = KLP_Order_Meta::get_delivery_date($order->get_id());
        $time = KLP_Order_Meta::get_time_slot($order->get_id());
        $code = KLP_Order_Meta::get_pickup_code($order->get_id());
        $requested = KLP_Order_Meta::get_pickup_requested($order->get_id());

        if (!$date) return;

        $settings = KLP_Settings::get();
        $time_label = $time === 'morning' ? $settings['morning_label'] : ($time === 'afternoon' ? $settings['afternoon_label'] : $time);

        echo '<h2>' . esc_html__('Levering', 'kolenbrander-leveringsplanner') . '</h2>';
        echo '<p><strong>' . esc_html__('Leverdag:', 'kolenbrander-leveringsplanner') . '</strong> ' . esc_html(date_i18n('l d F Y', strtotime($date))) . '</p>';
        echo '<p><strong>' . esc_html__('Tijdvak:', 'kolenbrander-leveringsplanner') . '</strong> ' . esc_html($time_label) . '</p>';

        if (!$requested && $code) {
            $url = KLP_Settings::pickup_url($code);
            echo '<p><strong>' . esc_html__('Container aanmelden voor ophalen:', 'kolenbrander-leveringsplanner') . '</strong><br>';
            echo '<a href="' . esc_url($url) . '" class="button">' . esc_html__('Meld uw container aan voor ophalen', 'kolenbrander-leveringsplanner') . '</a></p>';
        } elseif ($requested) {
            echo '<p><em>' . esc_html__('Container is aangemeld voor ophalen.', 'kolenbrander-leveringsplanner') . '</em></p>';
        }
    }

    public static function calc_min_date($cutoff_hour, $format = 'd-m-Y') {
        $now = new DateTime('now', wp_timezone());
        $days = (int) $now->format('H') >= $cutoff_hour ? 2 : 1;
        $now->setTime(0, 0, 0);
        $now->modify("+{$days} days");
        return $now->format($format);
    }

    public static function parse_date($date_str) {
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $date_str, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date_str, $m)) {
            return $date_str;
        }
        return false;
    }
}
