<?php

/**
 * Take Flight Global — Child Theme Bootstrap
 *
 * This version is streamlined for production:
 * - Assumes MU-plugins handle all initialization, constants, and security
 * - Keeps only UI- and presentation-related hooks
 * - Loads minimal fallbacks if MU bootstrap is unavailable
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ------------------------------------------------------------------------
 * Ensure MU Bootstrap
 * ------------------------------------------------------------------------
 * This guarantees MU-plugins (core logic, security, autoloaders)
 * are loaded even if the theme is activated manually.
 */
if (!defined('TFG_ENV')) {
    $bootstrap = WP_CONTENT_DIR . '/mu-plugins/01-core-bootstrap.php';
    if (file_exists($bootstrap)) {
        require_once $bootstrap;
    } else {
        if (function_exists('tfg_log')) {
            tfg_log('MU bootstrap not found at ' . $bootstrap);
        } elseif ((defined('TFG_DEBUG') && TFG_DEBUG) || (defined('WP_DEBUG') && WP_DEBUG)) {
            error_log('[TFG] MU bootstrap not found at ' . $bootstrap);
        }

    }
}

/** ------------------------------------------------------------------------
 * Theme Setup (Menus, Thumbnails, Title Tag, etc.)
 * ------------------------------------------------------------------------
 * Handles only presentation logic and WordPress UI features.
 */
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    register_nav_menus([
        'primary' => __('Primary Menu', 'takeflightglobal'),
    ]);
});

/**
 * ------------------------------------------------------------------------
 * Enqueue Theme Styles
 * ------------------------------------------------------------------------
 */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'tfg-child-style',
        get_stylesheet_uri(),
        [],
        defined('TFG_VERSION') ? TFG_VERSION : wp_get_theme()->get('Version')
    );
});

/**
 * ------------------------------------------------------------------------
 * Optional Fallback App Bootstrap
 * ------------------------------------------------------------------------
 * Normally handled by MU-plugins, but included for redundancy.
 */
if (!defined('TFG_DISABLE_TFG_INIT') && class_exists('\TFG\App')) {
    \TFG\App::register();
}
