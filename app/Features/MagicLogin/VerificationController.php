<?php

namespace TFG\Features\MagicLogin;

use TFG\Core\Utils;

/**
* REST endpoints for verification tokens.
* Routes:
* - POST /custom-api/v1/get-verification-code { email }
* - POST /custom-api/v1/mark-verification-used { code, email }
*/
final class VerificationController
{
    public function __construct()
    {
        \add_action('rest_api_init', function () {
            // NOTE: Duplicate route removed - this endpoint is now registered in RestAPI.php
            // with proper permission_callback (checkTokenPermission)
            
            // register_rest_route('custom-api/v1', '/get-verification-code', [
            //     'methods'             => 'POST',
            //     'callback'            => [$this, 'create'],
            //     'permission_callback' => '__return_true',
            // ]);

            register_rest_route('custom-api/v1', '/mark-verification-used', [
            'methods'             => 'POST',
            'callback'            => [$this, 'markUsed'],
            'permission_callback' => '__return_true',
            ]);
        });
    }


    public function create(\WP_REST_Request $request)
    {
        $params  = $request->get_params();
        $email   = Utils::normalizeEmail($params['subscriber_email'] ?? '');
        $name    = sanitize_text_field($params['subscriber_name'] ?? '');
        $consent = filter_var($params['gdpr_consent'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $source  = sanitize_text_field($params['source'] ?? 'api_get_code');

        return VerificationToken::createVerificationToken($email, wp_generate_password(10, false, false), $name, $source, $consent);
    }

    public function markUsed(\WP_REST_Request $request)
    {
        $params = $request->get_params();
        $code   = sanitize_text_field($params['verification_code'] ?? '');
        $email  = Utils::normalizeEmail($params['email'] ?? '');

        return VerificationToken::markUsed($code, null, $email);
    }
}
