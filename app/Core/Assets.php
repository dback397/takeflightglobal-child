<?php

namespace TFG\Core;

use TFG\UI\ErrorModal;

final class Assets
{
    public static function init(): void
    {
        \add_action('wp_enqueue_scripts', [self::class, 'enqueuePublicAssets']);
        \add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
    }

    /* -------------------------- Helpers -------------------------- */

    private static function uri(string $rel): string
    {
        return \get_stylesheet_directory_uri() . $rel;
    }

    private static function ver(string $rel): string
    {
        $path = \get_stylesheet_directory() . $rel;
        return \file_exists($path) ? (string) \filemtime($path) : (string) \time();
    }

    private static function enqueueStyle(string $handle, string $relPath): void
    {
        \wp_enqueue_style($handle, self::uri($relPath), [], self::ver($relPath));
    }

    /* ------------------------- Frontend -------------------------- */

    public static function enqueuePublicAssets(): void
    {
        // Prefill (public, lightweight)
        \wp_register_script(
            'tfg-prefill',
            self::uri('/js/prefill.js'),
            [],
            self::ver('/js/prefill.js'),
            true
        );
        \wp_enqueue_script('tfg-prefill');
        \wp_script_add_data('tfg-prefill', 'defer', true);

        // Core styles
        self::enqueueStyle('tfg-custom-stylesheet', '/css/tfg-custom-stylesheet.css');
        self::enqueueStyle('tfg-buttons', '/css/tfg-buttons.css');
        self::enqueueStyle('tfg-fonts', '/css/tfg-fonts.css');
        self::enqueueStyle('tfg-forms', '/css/tfg-forms.css');

        // ✅ Magic login wand UI updater
        \wp_register_script(
            'tfg-login',
            self::uri('/js/tfg-login.js'),
            [],
            self::ver('/js/tfg-login.js'),
            true
        );
        \wp_enqueue_script('tfg-login');
        \wp_script_add_data('tfg-login', 'defer', true);

        // Error modal (JS + CSS) + localized messages
        \wp_register_script(
            'tfg-error-modal',
            self::uri('/js/tfg-error-modal.js'),
            [],
            self::ver('/js/tfg-error-modal.js'),
            true
        );

        // Support both new and legacy providers for messages
        $messages = null;
        if (\class_exists('\TFG\UI\ErrorModal') && \method_exists('\TFG\UI\ErrorModal', 'getErrorMessages')) {
            /** @phpstan-ignore-next-line */
            $messages = ErrorModal::getErrorMessages();
        } elseif (\class_exists('TFG_Error_Modal') && \method_exists('TFG_Error_Modal', 'getErrorMessages')) {
            /** @phpstan-ignore-next-line */
            $messages = ErrorModal::getErrorMessages();
        }
        if ($messages !== null) {
            \wp_localize_script('tfg-error-modal', 'tfgErrorMessages', $messages);
        }

        \wp_enqueue_script('tfg-error-modal');
        \wp_script_add_data('tfg-error-modal', 'defer', true);
        self::enqueueStyle('tfg-error-modal', '/css/tfg-error-modal.css');

        // Member login assets (login, reset, GDPR password pages)
        if (\is_page(['login', 'member-login', 'member-gdpr', 'reset-password', 'member-dashboard', 'gdpr-consent', 'stub-access', 'request-password-reset', 'password-reset-confirm', 'forgot-member-id', 'edit-profile', 'deactivate-profile'])) {
            \wp_register_script(
                'tfg-member-login',
                self::uri('/js/tfg-member-login.js'),
                [],
                self::ver('/js/tfg-member-login.js'),
                true
            );
            \wp_enqueue_script('tfg-member-login');
            \wp_script_add_data('tfg-member-login', 'defer', true);

            self::enqueueStyle('tfg-member-login', '/css/tfg-member-login.css');
        }

        // Subscription / gate pages: verification code + reCAPTCHA
        if (\is_page(['subscribe', 'subscribe-gate'])) {
            \wp_register_script(
                'tfg-verification-code',
                self::uri('/js/verification-code.js'),
                [],
                self::ver('/js/verification-code.js'),
                true
            );

            $rest_nonce = \wp_create_nonce('wp_rest');
            \wp_localize_script('tfg-verification-code', 'tfgVerificationConfig', [
                'restUrl' => \rest_url('custom-api/v1/get-verification-code'),
                'nonce'   => $rest_nonce,
                'token'   => \defined('TFG_VERIFICATION_API_TOKEN') ? \TFG_VERIFICATION_API_TOKEN : '',
            ]);

            \wp_enqueue_script('tfg-verification-code');
            \wp_script_add_data('tfg-verification-code', 'defer', true);

            // Load reCAPTCHA only where needed
            \wp_enqueue_script(
                'google-recaptcha',
                'https://www.google.com/recaptcha/api.js',
                [],
                null,
                true
            );
        }

        /**
         * -------------------------------------------------------------------------
         * ✅ Always load GDPR / Newsletter verification handler
         * -------------------------------------------------------------------------
         * Needed because newsletter + magic login forms appear on non-subscribe pages
         */
        \wp_register_script(
            'tfg-gdpr-consent',
            self::uri('/js/tfg-gdpr-consent.js'),
            [],
            self::ver('/js/tfg-gdpr-consent.js'),
            true
        );

        // Optional: localize REST URL and token so JS doesn’t hard-code them
        $rest_nonce = \wp_create_nonce('wp_rest');
        \wp_localize_script('tfg-gdpr-consent', 'tfgGDPRConfig', [
            'restUrl' => \rest_url('custom-api/v1/get-verification-code'),
            'nonce'   => $rest_nonce,
            'token'   => \defined('TFG_VERIFICATION_API_TOKEN') ? \TFG_VERIFICATION_API_TOKEN : 'dback-9a4t2g1e5z',
        ]);

        \wp_enqueue_script('tfg-gdpr-consent');
        \wp_script_add_data('tfg-gdpr-consent', 'defer', true);

    }

    /* --------------------------- Admin --------------------------- */

    public static function enqueueAdminAssets(string $hook): void
    {
        $need_dashboard = ($hook === 'index.php') || $hook === 'toplevel_page_tfg_member_id_tracker';

        if ($need_dashboard) {
            \wp_register_script(
                'tfg-dashboard-js',
                self::uri('/js/tfg-dashboard.js'),
                ['jquery'],
                self::ver('/js/tfg-dashboard.js'),
                true
            );
            \wp_enqueue_script('tfg-dashboard-js');
            // (No defer for jQuery-dependent scripts unless order is guaranteed)
        }
    }
}
