<?php
// === class-tfg-rest-api.php ===
class TFG_REST_API
{
    private const NS  = 'custom-api/v1';
    private const HDR = 'HTTP_X_TFG_TOKEN'; // maps to header 'X-TFG-Token'

    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void
    {
        // (Preferred) Restore access check for a verified+subscribed email
        register_rest_route(self::NS, '/restore-access', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'restore_access'],
            'permission_callback' => '__return_true',
            'args'                => [
                'email' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_email',
                    'validate_callback' => function($value) {
                        return (bool) is_email($value);
                    },
                ],
            ],
        ]);

        // (Optional) Issue a verification code (server-to-server only; keep if you truly need it)
        register_rest_route(self::NS, '/get-verification-code', [
            'methods'             => 'POST', // use POST, not GET
            'callback'            => [__CLASS__, 'get_verification_code'],
            'permission_callback' => [__CLASS__, 'check_token_permission'],
            'args'                => [
                'subscriber_email' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_email',
                    'validate_callback' => function($v){ return (bool) is_email($v); },
                ],
                'subscriber_name' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'gdpr_consent' => [
                    'required'          => true,
                    'sanitize_callback' => function($v){ return filter_var($v, FILTER_VALIDATE_BOOLEAN); },
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

    public static function check_token_permission(): bool
    {
        // Expect a shared secret in wp-config.php:
        // define('TFG_VERIFICATION_API_TOKEN', 'super-secret');
        if (!defined('TFG_VERIFICATION_API_TOKEN') || !TFG_VERIFICATION_API_TOKEN) {
            error_log('[TFG REST] TFG_VERIFICATION_API_TOKEN not defined.');
            return false;
        }
        // Read header reliably (fastCGI/Apache/Nginx expose as $_SERVER var)
        $got = $_SERVER[self::HDR] ?? '';
        return $got && hash_equals(TFG_VERIFICATION_API_TOKEN, $got);
    }

    /* -------------------- Endpoints -------------------- */

    // Safer “restore access” probe: confirms a verified & subscribed user exists.
    public static function restore_access(WP_REST_Request $request)
    {
        $email = TFG_Utils::normalize_email( wp_unslash($request->get_param('email') ?? '') );
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
                ['key' => 'is_verified',  'value' => 1,      'type' => 'NUMERIC', 'compare' => '='],
                ['key' => 'is_subscribed','value' => 1,      'type' => 'NUMERIC', 'compare' => '='],
            ],
            'fields'           => 'ids',
        ]);

        $found = !empty($matches);
        error_log("[TFG REST] restore-access probe for {$email}: " . ($found ? 'YES' : 'NO'));

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

        // 404 keeps semantics clearer for “not found”
        return self::response(['success' => false, 'message' => 'No matching subscriber found.'], 404);
    }

    /**
     * (Optional) Issue a verification code (new token) via server-to-server call.
     * We DO NOT burn/mark-used here; this only mints and returns context.
     * Prefer using your existing TFG_Verification_Token::tfg_create_verification_token POST route instead.
     */
    public static function get_verification_code(WP_REST_Request $request)
    {
        $email   = TFG_Utils::normalize_email( wp_unslash($request->get_param('subscriber_email') ?? '') );
        $name    = sanitize_text_field( wp_unslash($request->get_param('subscriber_name') ?? '') );
        $consent = (bool) $request->get_param('gdpr_consent');
        $source  = sanitize_text_field( wp_unslash($request->get_param('source') ?? 'api_get_code') );

        if (!$email || !$consent) {
            return self::response(['error' => 'Missing required fields.'], 400);
        }

        // Respect your “already subscribed” rule to avoid spamming tokens
        $existing = get_posts([
            'post_type'        => 'subscriber',
            'posts_per_page'   => 1,
            'post_status'      => 'publish',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'meta_query'       => [
                ['key' => 'email',        'value' => $email, 'compare' => '='],
                ['key' => 'is_subscribed','value' => 1,      'type' => 'NUMERIC', 'compare' => '='],
            ],
            'fields'           => 'ids',
        ]);
        if ($existing) {
            return self::response([
                'error'   => 'already_subscribed',
                'message' => 'This email appears to be already subscribed.',
            ], 409);
        }

        // Mint a shared sequence
        $seq      = TFG_Sequence::next('newsletter_seq', 1);
        $seq_code = TFG_Sequence::format($seq, 'N', 6);

        // Client-visible verification code (short-lived)
        $code = wp_generate_password(10, false, false);

        // Create a verification_tokens CPT row (does NOT mark used)
        $created = TFG_Verification_Token::create_verification_token(
            $email, $code, $name, $source, true, $seq, $seq_code, 15 * MINUTE_IN_SECONDS
        );
        if ($created === false) {
            return self::response([
                'error'   => 'token_create_failed',
                'message' => 'Token creation failed.',
            ], 500);
        }

        // Return non-cacheable response with the payload you need
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
        // Avoid caching secrets in intermediaries
        $resp->header('Cache-Control', 'no-store, max-age=0');
        return $resp;
    }
}
