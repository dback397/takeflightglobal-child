<?php

if (!defined('ABSPATH')) { exit; }

add_action('init', function () {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log('[DEBUG] init hook triggered by POST');
        error_log('[DEBUG] Raw POST dump: ' . print_r($_POST, true));
    }
}, 1);

/** DO NOT define TFG_SAFEMODE here. Only read it. */
if (defined('TFG_SAFEMODE') && TFG_SAFEMODE) {
    // rescue hooks...
}

// === Environment Checks ===
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>TFG requires PHP 7.4 or higher.</p></div>';
    });
    return;
}

// ACF notice only (no early return)
if (!function_exists('get_field')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>ACF plugin is required for TFG to function.</p></div>';
    });
    // no return here
}


// === Flush rewrite rules on theme activation ===
add_action('after_switch_theme', function () {
    flush_rewrite_rules();
});


// === Require Class Files Safely ===
$classes = [
    // 0) Core utilities
    // 'class-tfg-admin-guard.php',          // DISABLED
    'class-tfg-debug.php',
    'class-tfg-utils.php',
    'class-tfg-cookies.php',
    'class-tfg-log.php',

    // 1) Theme + infrastructure
    'class-tfg-theme-setup.php',
    'class-tfg-assets.php',
    'class-tfg-rest-api.php',
    // 'class-tfg-access-control.php',       // DISABLED

    // 2) Validation / form infra
    'class-tfg-acfvalidator.php',
    'class-tfg-recaptcha.php',
    'class-tfg-form-router.php',
    'class-tfg-mailer.php',
    'class-tfg-subscriber-confirm.php',

    // 3) Admin & sequencing
    'class-tfg-sequence.php',
    'class-tfg-admin-processes.php',

    // 4) Token systems
    'class-tfg-verification-token.php',
    'class-tfg-reset-token-cpt.php',
    'class-tfg-magic-utilities.php',
    'class-tfg-subscriber-utilities.php',
    
    // 5) Member system
    'class-tfg-membership.php',
    'class-tfg-member-id-generator.php',
    'class-tfg-member-form-utilities.php',
    'class-tfg-member-gdpr-consent.php',
    'class-tfg-member-profile-display.php',
    'class-tfg-member-login.php',
    'class-tfg-member-dashboard.php',
    'class-tfg-member-stub-manager.php',
    'class-tfg-member-stub-access.php',
    'class-tfg-university-form.php',

    // 6) Workflows
    'class-tfg-newsletter-subscription.php',
    'class-tfg-newsletter-unsubscribe.php',
    'class-tfg-magic-login.php',
    'class-tfg-magic-handler.php',

    // 7) UI helpers
    'class-tfg-shortcodes.php',
    'class-tfg-prefill.php',
    'class-tfg-carousel.php',
    'class-tfg-profile-buttons.php',
    'class-tfg-error-modal.php',
];

$missing_files = [];
foreach ($classes as $class_file) {
    $path = get_theme_file_path('/inc/' . $class_file);
    if (file_exists($path)) {
        require_once $path;
    } else {
        $missing_files[] = $class_file;
        error_log("[TFG Init] Missing class file: $class_file");
    }
}

// === Admin notice for missing files ===
if (!empty($missing_files)) {
    add_action('admin_notices', function () use ($missing_files) {
        echo '<div class="notice notice-error"><p><strong>TFG init:</strong> The following class files were not found in <code>/inc</code>:</p><ul style="margin-left:1em;">';
        foreach ($missing_files as $file) {
            echo '<li><code>' . esc_html($file) . '</code></li>';
        }
        echo '</ul><p>Please upload these files or update your include list.</p></div>';
    });
}

// === Init Hooks for Classes (dependency-safe order) ===
$tfg_inits = [
    // 0) Core
    TFG_Utils::class,
    TFG_Cookies::class,
    TFG_Log::class,

    // 1) Theme + infrastructure
    TFG_Theme_Setup::class,
    TFG_Assets::class,
    TFG_REST_API::class,
    TFG_Access_Control::class,

    // 2) Validation / form infra
    TFG_ACFValidator::class,
    TFG_ReCAPTCHA::class,
    TFG_Form_Router::class,
    TFG_Mailer::class,
    TFG_Subscriber_Confirm::class,

    // 3) Admin & sequencing
    TFG_Sequence::class,
    TFG_Admin_Processes::class,

    // 4) Token systems
    TFG_Verification_Token::class,
    TFG_Reset_Token_CPT::class,
    TFG_Magic_Utilities::class,
    TFG_Subscriber_Utilities::class,
    

    // 5) Member system
    TFG_Membership::class,
    TFG_Member_ID_Generator::class,
    TFG_Member_Form_Utilities::class,
    TFG_Member_GDPR_Consent::class,
    TFG_Member_Profile_Display::class,
    TFG_Member_Login::class,
    TFG_Member_Dashboard::class,
    TFG_Member_Stub_Manager::class,
    TFG_Member_Stub_Access::class,
    TFG_University_Form::class,

    // 6) Workflows
    TFG_Newsletter_Subscription::class,
    TFG_Newsletter_Unsubscribe::class,
    TFG_Magic_Login::class,
    TFG_Magic_Handler::class,

    // 7) UI helpers
    TFG_Shortcodes::class,
    TFG_Prefill::class,
    TFG_Carousel::class,
    TFG_Profile_Buttons::class,
    TFG_Error_Modal::class,
];

foreach ($tfg_inits as $class) {
    if (class_exists($class) && method_exists($class, 'init')) {
        $class::init();
    }
}


if (!class_exists('TFG_Magic_Utilities')) {
    require_once get_stylesheet_directory() . '/inc/class-tfg-magic-utilities.php';
    error_log("[INIT] requiring TFG_Magic_Utilities");
}

// ==== DO NOT REMOVE, NECESSARY FOR ReCAPTCHA LOAD ====  //
add_action('wp_footer', function () {
    echo '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
});

// === WIDGET FORM HANDLER TO SET COUNTERS ===
add_action('admin_init', function () {
    if (
        isset($_POST['set_widget_counter'], $_POST['counter']) &&
        current_user_can('manage_options') &&
        check_admin_referer('tfg_set_counter_widget')
    ) {
        foreach ($_POST['counter'] as $prefix => $value) {
            $prefix = strtoupper(sanitize_text_field($prefix));
            $value  = intval($value);

            switch ($prefix) {
                case 'UNI':
                    update_option('tfg_last_uni_id', $value);
                    break;
                case 'AGY':
                    update_option('tfg_last_age_id', $value);
                    break;
                case 'AFF':
                    update_option('tfg_last_aff_id', $value);
                    break;
            }

            if (class_exists('TFG_Log')) {
                TFG_Log::add_log_entry([
                    'event_type'    => 'member_id_set',
                    'related_token' => $prefix,
                    'status'        => 'updated to ' . $value,
                    'notes'         => 'Set via dashboard widget',
                ]);
            }
        }

        wp_safe_redirect(admin_url('index.php?set_member_id=1'));
        exit;
    }
});

// run purge magic tokens manually while logged in as admin:  /wp-admin/?tfg_run_purge=1
add_action('admin_init', function () {
    if (
        current_user_can('manage_options') &&
        isset($_GET['tfg_run_purge']) &&
        $_GET['tfg_run_purge'] === '1' &&
        class_exists('TFG_Magic_Utilities')
    ) {
        TFG_Magic_Utilities::purge_expired_tokens();
        wp_die('Magic-token purge ran.');
    }
});

// === Mailpit override (development only) ===
add_action('phpmailer_init', function ($phpmailer) {
    if (defined('WP_ENV') && WP_ENV === 'development') {
        $phpmailer->isSMTP();
        $phpmailer->Host       = 'localhost';
        $phpmailer->Port       = 1025;
        $phpmailer->SMTPAuth   = false;
        $phpmailer->SMTPSecure = false;
        $phpmailer->setFrom('noreply@takeflightglobal.com', 'Take Flight');
        error_log('[Mailpit] ✅ phpmailer_init SMTP override applied');
    }
});


/**
 * === SETTINGS/ADMIN RESCUE (OFF by default) ===
 * Flip TFG_SAFEMODE to true only if you’re locked out.
 */
/**
 * === SETTINGS/ADMIN RESCUE (OFF by default) ===
 * Flip TFG_SAFEMODE to true only if you’re locked out.
 */
// if (defined('TFG_SAFEMODE') && TFG_SAFEMODE) {

    // Unlock Settings screens for user #1 only (this request)
    // add_action('current_screen', function ($screen)) {
        // if (!is_user_logged_in() || !function_exists('get_current_screen')) { return; }
        // if ((int) get_current_user_id() !== 1) { return; }
        // if (empty($screen)) { return; }

        // $bases = ['options-general','options-writing','options-reading','options-discussion','options-media','options-permalink','options'];
        // if (!in_array($screen->base, $bases, true)) { return; }

        // Ensure admin role still has manage_options
        // if ($role = get_role('administrator')) {
        //    if (!$role->has_cap('manage_options')) {
        //        $role->add_cap('manage_options');
        //        error_log('[RESCUE] added manage_options to administrator role');
        //     }
        // }

        // Neutralize cap filters ONLY on these screens, this request
        // remove_all_filters('map_meta_cap');
        // remove_all_filters('user_has_cap');

        // Belt & suspenders: force caps on settings pages for user #1
        // add_filter('user_has_cap', function ($allcaps) {
        //    $allcaps['manage_options']     = true;
        //    $allcaps['activate_plugins']   = true;
        //    $allcaps['edit_theme_options'] = true;
        //    return $allcaps;
        //}, PHP_INT_MAX);

        // error_log('[RESCUE] unlocked Settings for user #1 on ' . $screen->id);
    // }, 1);

    // Broad admin unlock for user #1 (this request)
    //add_action('plugins_loaded', function () {
    //    if (!is_admin()) { return; }
    //    if ((int) get_current_user_id() !== 1) { return; }

        // Allow core admin caps early for user #1 without touching others
        // add_filter('map_meta_cap', function ($caps, $cap, $user_id) {
        //    if ((int) $user_id === 1 && in_array($cap, ['manage_options','activate_plugins','edit_theme_options'], true)) {
        //        return ['exist'];
        //    }
        //    return $caps;
        //}, -PHP_INT_MAX, 3);
    //}, 1);
// }

