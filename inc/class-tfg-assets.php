<?php
// File: class-tfg-assets.php

class TFG_Assets {

    public static function init(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_public_assets']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    }

    /* -------------------------- Helpers -------------------------- */

    private static function uri(string $rel): string {
        return get_stylesheet_directory_uri() . $rel;
    }

    private static function ver(string $rel): string {
        $path = get_stylesheet_directory() . $rel;
        return file_exists($path) ? (string) filemtime($path) : time();
    }

    /* ------------------------- Frontend -------------------------- */

    public static function enqueue_public_assets(): void {
        // Prefill (public, lightweight)
        wp_register_script(
            'tfg-prefill',
            self::uri('/js/prefill.js'),
            [],
            self::ver('/js/prefill.js'),
            true
        );
        wp_enqueue_script('tfg-prefill');
        wp_script_add_data('tfg-prefill', 'defer', true);

        // Core styles
        self::enqueue_style('tfg-custom-stylesheet', '/css/tfg-custom-stylesheet.css');
        self::enqueue_style('tfg-buttons',            '/css/tfg-buttons.css');
        self::enqueue_style('tfg-fonts',              '/css/tfg-fonts.css');
        self::enqueue_style('tfg-forms',              '/css/tfg-forms.css');

        // Error modal (JS + CSS) + localized messages
        wp_register_script(
            'tfg-error-modal',
            self::uri('/js/tfg-error-modal.js'),
            [],
            self::ver('/js/tfg-error-modal.js'),
            true
        );
        if (class_exists('TFG_Error_Modal') && method_exists('TFG_Error_Modal', 'get_error_messages')) {
            wp_localize_script('tfg-error-modal', 'tfgErrorMessages', TFG_Error_Modal::get_error_messages());
        }
        wp_enqueue_script('tfg-error-modal');
        wp_script_add_data('tfg-error-modal', 'defer', true);

        self::enqueue_style('tfg-error-modal', '/css/tfg-error-modal.css');

        // Member login assets (only if needed; adjust condition as appropriate)
        if (is_page(['login', 'member-login'])) {
            wp_register_script(
                'tfg-member-login',
                self::uri('/js/tfg-member-login.js'),
                [],
                self::ver('/js/tfg-member-login.js'),
                true
            );
            wp_enqueue_script('tfg-member-login');
            wp_script_add_data('tfg-member-login', 'defer', true);

            self::enqueue_style('tfg-member-login', '/css/tfg-member-login.css');
        }

        // Subscription / gate pages: verification code + reCAPTCHA
        if (is_page(['subscribe', 'subscribe-gate'])) {
            // Verification script + config
            wp_register_script(
                'tfg-verification-code',
                self::uri('/js/verification-code.js'),
                [],
                self::ver('/js/verification-code.js'),
                true
            );

            $rest_nonce = wp_create_nonce('wp_rest');
            wp_localize_script('tfg-verification-code', 'tfgVerificationConfig', [
                'restUrl' => rest_url('custom-api/v1/get-verification-code'),
                'nonce'   => $rest_nonce,
                'token'   => defined('TFG_VERIFICATION_API_TOKEN') ? TFG_VERIFICATION_API_TOKEN : '',
            ]);

            wp_enqueue_script('tfg-verification-code');
            wp_script_add_data('tfg-verification-code', 'defer', true);

            // Load reCAPTCHA only where needed
            wp_enqueue_script(
                'google-recaptcha',
                'https://www.google.com/recaptcha/api.js',
                [],
                null,
                true
            );
        }
    }

    /* --------------------------- Admin --------------------------- */

    public static function enqueue_admin_assets($hook): void {
        // Admin dashboard / custom page only
        // - Dashboard widget scripts
        // - tfg-dashboard.js (admin-only)
        $need_dashboard = ($hook === 'index.php') || $hook === 'toplevel_page_tfg_member_id_tracker';

        if ($need_dashboard) {
            wp_register_script(
                'tfg-dashboard-js',
                self::uri('/js/tfg-dashboard.js'),
                ['jquery'],
                self::ver('/js/tfg-dashboard.js'),
                true
            );
            wp_enqueue_script('tfg-dashboard-js');
            // No defer for scripts depending on jQuery unless you’re sure about order
        }
    }

    /* -------------------------- Internals ------------------------ */

    private static function enqueue_style(string $handle, string $relPath): void {
        wp_enqueue_style(
            $handle,
            self::uri($relPath),
            [],
            self::ver($relPath)
        );
    }
}
