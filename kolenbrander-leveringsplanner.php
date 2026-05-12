<?php
/**
 * Plugin Name: Kolenbrander Leveringsplanner
 * Description: Beheer leverdata, tijdvakken, ophaalaanmeldingen en Google Calendar sync voor containerverhuur
 * Version: 1.0.0
 * Author: Kolenbrander Containers
 * Requires Plugins: woocommerce
 * Text Domain: kolenbrander-leveringsplanner
 */

if (!defined('ABSPATH')) exit;

define('KLP_VERSION', '1.0.0');
define('KLP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KLP_PLUGIN_URL', plugin_dir_url(__FILE__));

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

require_once KLP_PLUGIN_DIR . 'includes/settings.php';
require_once KLP_PLUGIN_DIR . 'includes/holidays.php';
require_once KLP_PLUGIN_DIR . 'includes/checkout.php';
require_once KLP_PLUGIN_DIR . 'includes/lockout.php';
require_once KLP_PLUGIN_DIR . 'includes/order-meta.php';
require_once KLP_PLUGIN_DIR . 'includes/pickup.php';
require_once KLP_PLUGIN_DIR . 'includes/emails.php';
require_once KLP_PLUGIN_DIR . 'includes/cron.php';
require_once KLP_PLUGIN_DIR . 'includes/google-calendar.php';

register_activation_hook(__FILE__, function () {
    KLP_Cron::schedule();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    KLP_Cron::unschedule();
});

add_action('init', function () {
    KLP_Pickup::register_rewrite();
    load_plugin_textdomain('kolenbrander-leveringsplanner', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) return;

    KLP_Settings::init();
    KLP_Holidays::init();
    KLP_Checkout::init();
    KLP_Lockout::init();
    KLP_Order_Meta::init();
    KLP_Pickup::init();
    KLP_Emails::init();
    KLP_Cron::init();
    KLP_Google_Calendar::init();
});
