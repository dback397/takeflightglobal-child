<?php
/**
 * TakeFlightGlobal Child Theme Functions
 */

if (!defined('ABSPATH')) { exit; }

define('TFG_VERSION', '1.0.0');

// Always show ACF menu (admin-only by default)
add_filter('acf/settings/show_admin', '__return_true', PHP_INT_MAX);

// Optional: lock ACF admin to manage_options (default already)
add_filter('acf/settings/capability', function () { return 'manage_options'; }, PHP_INT_MAX);

add_filter('wp_mail_from',      fn() => 'no-reply@your-domain.tld');
add_filter('wp_mail_from_name', fn() => 'TakeFlight Global');

// Allow disabling init via wp-config.php: define('TFG_DISABLE_TFG_INIT', true);
if (!defined('TFG_DISABLE_TFG_INIT') || !TFG_DISABLE_TFG_INIT) {
    require_once get_theme_file_path('inc/init.php');
}

