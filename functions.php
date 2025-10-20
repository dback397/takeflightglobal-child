<?php
/**
 * TakeFlightGlobal Child Theme bootstrap
 * - Defines safe defaults
 * - Loads Composer autoloader
 * - Boots TFG\App::register() (unless disabled)
 * - Keeps ACF + mail filters
 */

if (!defined('ABSPATH')) {
    exit;
}

/** -----------------------------------------------------------------
 *  Safe defaults (guarded)
 *  ---------------------------------------------------------------- */
\defined('WP_ENV')              || \define('WP_ENV', 'development');
\defined('WP_ENVIRONMENT')      || \define('WP_ENVIRONMENT', 'local');
\defined('TFG_VERSION')         || \define('TFG_VERSION', '1.0.0');
\defined('TFG_THEME_PATH')      || \define('TFG_THEME_PATH', __DIR__);
\defined('TFG_DISABLE_TFG_INIT')|| \define('TFG_DISABLE_TFG_INIT', false); // silence undefined warnings

// Security constants
\defined('TFG_HMAC_SECRET')     || \define('TFG_HMAC_SECRET', \defined('AUTH_SALT') ? AUTH_SALT : 'tfg-default-hmac-secret-' . \wp_generate_password(32, false));
\defined('MEMBER_PASSWORD_DEFAULT') || \define('MEMBER_PASSWORD_DEFAULT', 'password123');

/** ------------------------------------------------------------------------
 *  Debug / Environment
 * --------------------------------------------------------------------- */
\defined('TFG_DEBUG_CAPS')        || \define('TFG_DEBUG_CAPS', true);    // Enable extra admin-cap logging
\defined('TFG_SAFEMODE')          || \define('TFG_SAFEMODE', false);     // Disable heavy or risky operations
\defined('TFG_MIN_PASSWORD_LENGTH')|| \define('TFG_MIN_PASSWORD_LENGTH', 10);

/** ------------------------------------------------------------------------
 *  Paths / Versions
 * --------------------------------------------------------------------- */
\defined('TFG_PLUGIN_PATH')       || \define('TFG_PLUGIN_PATH', WP_CONTENT_DIR . '/mu-plugins/tfg/');

/** ------------------------------------------------------------------------
 *  API / Security Tokens
 *  (⚠️ replace with real values in wp-config.php or environment vars)
 * --------------------------------------------------------------------- */
\defined('TFG_VERIFICATION_API_TOKEN')
    || \define('TFG_VERIFICATION_API_TOKEN', 'dev-verification-token-placeholder');

\defined('RECAPTCHA_SITE_KEY')
    || \define('RECAPTCHA_SITE_KEY', 'recaptcha-site-key-placeholder');

\defined('RECAPTCHA_SECRET_KEY')
    || \define('RECAPTCHA_SECRET_KEY', 'recaptcha-secret-key-placeholder');

/** ------------------------------------------------------------------------
 *  Misc / Default Environment Labels
 * --------------------------------------------------------------------- */
\defined('TFG_ENVIRONMENT')       || \define('TFG_ENVIRONMENT', 'local'); // local | staging | production

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
$autoload = TFG_THEME_PATH . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
} else {
    // Log + admin notice for visibility
    error_log('[TFG] Composer autoloader not found at ' . $autoload);
    if (is_admin()) {
        add_action('admin_notices', function () use ($autoload) {
            echo '<div class="notice notice-error"><p><strong>TFG:</strong> Missing <code>vendor/autoload.php</code>. Run <code>composer install</code> in <code>'
                . esc_html($autoload) . '</code>.</p></div>';
        });
    }
}

/** -----------------------------------------------------------------
 *  App bootstrap (can be disabled via constant)
 *  Set TFG_DISABLE_TFG_INIT=true in wp-config.php to skip boot.
 *  ---------------------------------------------------------------- */
if (!TFG_DISABLE_TFG_INIT) {
    if (class_exists(\TFG\App::class)) {
        \TFG\App::register();
    } elseif (file_exists(TFG_THEME_PATH . '/app/App.php')) {
        // Fallback if class not autoloaded yet
        require_once TFG_THEME_PATH . '/app/App.php';
        if (class_exists(\TFG\App::class)) {
            \TFG\App::register();
        }
    } else {
        error_log('[TFG] \\TFG\\App not found. Check PSR-4 ("TFG\\\\": "app/") and run composer dump-autoload -o.');
    }
}

/** -----------------------------------------------------------------
 *  (No legacy includes)
 *  ---------------------------------------------------------------- */
// Removed: require_once get_theme_file_path('inc/init.php');
