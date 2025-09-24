<?php
/**
 * TakeFlightGlobal Child Theme bootstrap
 * - Loads Composer autoloader
 * - Boots TFG\App::register()
 * - Keeps ACF + mail filters
 */

if (!defined('ABSPATH')) {
    exit;
}

/** -----------------------------------------------------------------
 *  Safe defaults (guarded)
 *  ---------------------------------------------------------------- */
\defined('TFG_SAFEMODE')      || \define('TFG_SAFEMODE', false);
\defined('TFG_PLUGIN_PATH')   || \define('TFG_PLUGIN_PATH', WP_CONTENT_DIR . '/plugins');
\defined('KADENCE_VERSION')   || \define('KADENCE_VERSION', '0.0.0');
\defined('WP_ENV')            || \define('WP_ENV', 'development');
\defined('WP_ENVIRONMENT')    || \define('WP_ENVIRONMENT', 'local');
\defined('TFG_VERSION')       || \define('TFG_VERSION', '1.0.0');

/** -----------------------------------------------------------------
 *  ACF admin visibility (optional)
 *  ---------------------------------------------------------------- */
add_filter('acf/settings/show_admin', '__return_true', PHP_INT_MAX);
add_filter('acf/settings/capability', static function () {
    return 'manage_options';
}, PHP_INT_MAX);

/** -----------------------------------------------------------------
 *  Outbound mail identity
 *  ---------------------------------------------------------------- */
add_filter('wp_mail_from', static fn () => 'no-reply@your-domain.tld');
add_filter('wp_mail_from_name', static fn () => 'TakeFlight Global');

/** -----------------------------------------------------------------
 *  Composer autoload
 *  ---------------------------------------------------------------- */
$autoload = get_stylesheet_directory() . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
} else {
    error_log('[TFG] Composer autoloader not found at ' . $autoload);
}

/** -----------------------------------------------------------------
 *  App bootstrap (can be disabled via constant)
 *  Set TFG_DISABLE_TFG_INIT=true in wp-config.php to skip boot.
 *  ---------------------------------------------------------------- */
if (!defined('TFG_DISABLE_TFG_INIT') || !TFG_DISABLE_TFG_INIT) {
    if (class_exists(\TFG\App::class)) {
        \TFG\App::register();
    } else {
        error_log('[TFG] \\TFG\\App not found. Check PSR-4 ("TFG\\\\": "app/") and run composer dump-autoload -o.');
    }
}

/** -----------------------------------------------------------------
 *  (No legacy includes)
 *  ---------------------------------------------------------------- */
// Removed: require_once get_theme_file_path('inc/init.php');



