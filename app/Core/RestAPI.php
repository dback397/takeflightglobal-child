<?php

namespace TFG\Core;

use TFG\Core\Utils;
use TFG\Admin\Sequence;
use TFG\Features\MagicLogin\VerificationToken;

use WP_REST_Request;

/**
 * REST API endpoints for subscription and verification flows.
 */
final class RestAPI
{
    private const NS  = 'custom-api/v1';
    private const HDR = 'HTTP_X_TFG_TOKEN'; // maps to header 'X-TFG-Token'

    public static function init(): void
    {
        \add_action('rest_api_init', [__CLASS__, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        // Restore access probe
        register_rest_route(self::NS, '/restore-access', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'restore_access'],
            'permission_callback' => '__return_true',
            'args'                => [
                'email' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_email',
                    'validate_callback' => fn($value) => (bool) is_email($value),
                ],
            ],
        ]);

        // Issue a verification code (server-to-server only)
        register_rest_route(self::NS, '/get-verification-code', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'get_verification_code'],
            'permission_callback' => [__CLASS__, 'check_token_permission'],
            'args'                => [
                'subscriber_email' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_email',
                    'validate_callback' => fn($v) => (bool) is_email($v),
                ],
                'subscriber_name' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'gdpr_consent' => [
                    'required'          => true,
                    'sanitize_callback' => fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                ],
                'source' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => 'api_get_code',
                ],
            ],
        ]);
    }

    /* -------------------- Permission helpers -------------------- */

    public static function checkTokenPermission(): bool
    {
        if (!defined('TFG_VERIFICATION_API_TOKEN') || !TFG_VERIFICATION_API_TOKEN) {
            \TFG\Core\Utils::info('[TFG REST] TFG_VERIFICATION_API_TOKEN not defined.');
            return false;
        }
        $got = $_SERVER[self::HDR] ?? '';
        return $got && hash_equals(TFG_VERIFICATION_API_TOKEN, $got);
    }

    /* -------------------- Endpoints -------------------- */

    public static function restoreAccess(WP_REST_Request $request)
    {
        $email = Utils::normalizeEmail(wp_unslash($request->get_param('email') ?? ''));
        if (!$email) {
            return self::response(['success' => false, 'message' => 'Missing or invalid email.'], 400);
        }

        $matches = get_posts([
            'post_type'        => 'subscriber',
            'posts_per_page'   => 1,
            'post_status'      => 'publish',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'meta_query'       => [
                ['key' => 'email',        'value' => $email, 'compare' => '='],
                ['key' => 'is_verified',  'value' => 1, 'type' => 'NUMERIC', 'compare' => '='],
                ['key' => 'is_subscribed','value' => 1, 'type' => 'NUMERIC', 'compare' => '='],
            ],
            'fields'           => 'ids',
        ]);

        $found = !empty($matches);
        \TFG\Core\Utils::info("[TFG REST] restore-access probe for {$email}: " . ($found ? 'YES' : 'NO'));

        if ($found) {
            $sub_id = (int) $matches[0];
            return self::response([
                'success'    => true,
                'email'      => $email,
                'post_id'    => $sub_id,
                'verified'   => (bool) get_field('is_verified',   $sub_id),
                'subscribed' => (bool) get_field('is_subscribed', $sub_id),
            ], 200);
        }

        return self::response(['success' => false, 'message' => 'No matching subscriber found.'], 404);
    }

    public static function getVerificationCode(WP_REST_Request $request)
    {
        $email   = \TFG\Core\Utils::normalizeEmail(wp_unslash($request->get_param('subscriber_email') ?? ''));
        $name    = sanitize_text_field(wp_unslash($request->get_param('subscriber_name') ?? ''));
        $consent = (bool) $request->get_param('gdpr_consent');
        $source  = sanitize_text_field(wp_unslash($request->get_param('source') ?? 'api_get_code'));

        if (!$email || !$consent) {
            return self::response(['error' => 'Missing required fields.'], 400);
        }

        $existing = get_posts([
            'post_type'        => 'subscriber',
            'posts_per_page'   => 1,
            'post_status'      => 'publish',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'meta_query'       => [
                ['key' => 'email',        'value' => $email, 'compare' => '='],
                ['key' => 'is_subscribed','value' => 1, 'type' => 'NUMERIC', 'compare' => '='],
            ],
            'fields'           => 'ids',
        ]);
        if ($existing) {
            return self::response([
                'error'   => 'already_subscribed',
                'message' => 'This email appears to be already subscribed.',
            ], 409);
        }

        $seq      = Sequence::next('newsletter_seq', 1);
        $seq_code = Sequence::format($seq, 'N', 6);
        $code     = wp_generate_password(10, false, false);

        $created = VerificationToken::createVerificationToken(
            $email, $code, $name, $source, true, $seq, $seq_code, 15 * MINUTE_IN_SECONDS
        );
        if ($created === false) {
            return self::response([
                'error'   => 'token_create_failed',
                'message' => 'Token creation failed.',
            ], 500);
        }

        return self::response([
            'verification_code' => $code,
            'verif_post_id'     => (int) $created['post_id'],
            'sequence_id'       => (int) $created['sequence_id'],
            'sequence_code'     => (string) $created['sequence_code'],
            'expires_at'        => (int) $created['expires_at'],
        ], 200);
    }

    /* -------------------- Utils -------------------- */

    private static function response(array $data, int $status)
    {
        $resp = rest_ensure_response($data);
        $resp->set_status($status);
        $resp->header('Cache-Control', 'no-store, max-age=0');
        return $resp;
    }
}

// Legacy alias for backwards compatibility
\class_alias(\TFG\Core\RestAPI::class, 'TFG_REST_API');
