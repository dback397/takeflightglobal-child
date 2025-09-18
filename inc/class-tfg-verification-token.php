<?php
/**
 * TFG Verification Token System
 * Manages generation and validation of verification tokens for newsletter signup workflow.
 */
class TFG_Verification_Token {

    /** Guard to avoid duplicate route registration */
    private static bool $rest_registered = false;

public static function init(): void {
    add_action('rest_api_init', [self::class, 'register_rest_routes']);

    // Delay CPT check until after init
    add_action('init', function () {
        if (!post_type_exists('verification_tokens')) {
            error_log('[TFG Init] ❌ verification_tokens CPT not registered.');
        } else {
            error_log('[TFG Init] ✅ verification_tokens CPT found.');
        }
    }, 30);
}

public static function check_post_types(): void {
    if (!post_type_exists('verification_tokens')) {
        error_log('[TFG Verification_Token] ❌ verification_tokens CPT not registered.');
    } else {
        error_log('[TFG Verification_Token] ✅ verification_tokens CPT is registered.');
    }
}

    public static function register_rest_routes(): void {
        if (self::$rest_registered) {
            return;
        }
        self::$rest_registered = true;

        register_rest_route('custom-api/v1', '/create-verification-token', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'tfg_create_verification_token'],
            'permission_callback' => '__return_true', // tighten with captcha/nonce if desired
        ]);
    }

    /**
     * POST /custom-api/v1/create-verification-token
     * Body: subscriber_email, subscriber_name, gdpr_consent (bool), source (optional)
     */
    public static function tfg_create_verification_token( WP_REST_Request $request ) {
        // ---- Intake & normalization
        $p       = $request->get_params();
        $raw_em  = isset($p['subscriber_email']) ? wp_unslash($p['subscriber_email']) : '';
        $raw_nm  = isset($p['subscriber_name'])  ? wp_unslash($p['subscriber_name'])  : '';
        $raw_cs  = $p['gdpr_consent'] ?? false;
        $raw_src = isset($p['source']) ? wp_unslash($p['source']) : 'newsletter_form';

        $email   = TFG_Utils::normalize_email($raw_em);
        $name    = sanitize_text_field($raw_nm);
        $consent = filter_var($raw_cs, FILTER_VALIDATE_BOOLEAN);
        $source  = sanitize_text_field($raw_src);

        if (!$email || !$consent) {
            $resp = new WP_REST_Response(['error' => 'Missing required fields.'], 400);
            $resp->header('Cache-Control', 'no-store');
            return $resp;
        }

        // ---- Rate limiting (IP-only bucket)
        $ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rl_ip_key = 'tfg_rl_ip_' . md5($ip);
        $ip_hits   = (int) get_transient($rl_ip_key);

        // 10 requests / 5 minutes per IP
        if ($ip_hits >= 10) {
            $resp = new WP_REST_Response([
                'error'   => 'rate_limited',
                'message' => 'Too many requests from this IP. Try again in 5 minutes.',
            ], 429);
            $resp->header('Retry-After', '300');
            $resp->header('Cache-Control', 'no-store');
            return $resp;
        }
        set_transient($rl_ip_key, $ip_hits + 1, 5 * MINUTE_IN_SECONDS);

        // ---- Rate limiting (per IP + per email)
        $rl_key = 'tfg_rl_' . md5($ip . '|' . $email);
        $hits   = (int) get_transient($rl_key);

        // 5 requests / 5 minutes per (IP,email)
        if ($hits >= 5) {
            $resp = new WP_REST_Response([
                'error'       => 'rate_limited',
                'message'     => 'Too many requests. Try again in 5 minutes.',
                'retry_after' => 300,
            ], 429);
            $resp->header('Retry-After', '300');
            $resp->header('Cache-Control', 'no-store');
            return $resp;
        }
        set_transient($rl_key, $hits + 1, 5 * MINUTE_IN_SECONDS);

        // ---- Optional: short-circuit if already subscribed (tweak meta keys as needed)
        $existing = get_posts([
            'post_type'        => 'subscriber',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'post_status'      => 'publish',
            'meta_query'       => [
                ['key' => 'email', 'value' => $email, 'compare' => '='],
                // If you want to only block verified subs, add:
                // ['key' => 'is_verified', 'value' => 1, 'compare' => '='],
            ],
        ]);

        if ($existing) {
            $resp = new WP_REST_Response([
                'error'   => 'already_subscribed',
                'message' => 'This email appears to be already subscribed.',
            ], 409);
            $resp->header('Cache-Control', 'no-store');
            return $resp;
        }

        // ---- Mint a shared sequence (user + server context)
        $seq      = TFG_Sequence::next('newsletter_seq', 1);
        $seq_code = TFG_Sequence::format($seq, 'N', 6);

        // ---- Client-visible verification code (short-lived)
        $code = wp_generate_password(10, false, false);

        // ---- Create token CPT (bind email at creation)
        $result = self::create_verification_token($email, $code, $name, $source, $consent, $seq, $seq_code);

        if ($result === false) {
            $resp = new WP_REST_Response([
                'error'   => 'token_create_failed',
                'message' => 'Already subscribed or token creation failed.',
            ], 409);
            $resp->header('Cache-Control', 'no-store');
            return $resp;
        }

        // ---- Success
        $resp = new WP_REST_Response([
            'verification_code' => $code,
            'verif_post_id'     => $result['post_id'],
            'sequence_id'       => $seq,
            'sequence_code'     => $seq_code,
        ], 200);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    /**
     * Create the verification token CPT entry.
     */
    public static function create_verification_token(
        string $email,
        string $code,
        string $name = '',
        string $source = 'newsletter_form',
        bool $gdpr = true,
        ?int $sequence_id = null,
        ?string $sequence_code = null,
        int $expires_in = 900
    ) {
        if (self::is_already_subscribed($email)) {
            error_log("[TFG] Already subscribed: $email");
            return false;
        }

        // Ensure sequence present
        if ($sequence_id === null) {
            $sequence_id   = TFG_Sequence::next('newsletter_seq', 1);
            $sequence_code = TFG_Sequence::format($sequence_id, 'N', 6);
        } elseif ($sequence_code === null) {
            $sequence_code = TFG_Sequence::format((int) $sequence_id, 'N', 6);
        }

        $expires_at = time() + max(60, (int) $expires_in); // min 60s

        error_log("[TFG] ✅ Creating verification token for $email with code $code (seq {$sequence_code})");

        $post_id = wp_insert_post([
            'post_type'   => 'verification_tokens',
            'post_status' => 'publish',
            'post_title'  => "VER: {$sequence_code} {$email}",
        ]);
        if (is_wp_error($post_id)) {
            return false;
        }

        // ACF-backed fields
        update_field('verification_code', $code,  $post_id);
        update_field('email_used',        $email, $post_id); // bind at creation
        update_field('subscriber_name',   $name,  $post_id);
        update_field('gdpr_consent',      $gdpr ? 1 : 0, $post_id);
        update_field('source',            $source, $post_id);
        update_field('is_used',           0, $post_id);
        update_field('is_used_copy',      0, $post_id);
        update_field('request_ip', TFG_Magic_Utilities::client_ip(), $post_id);

        // Extra meta
        update_post_meta($post_id, 'expires_at',    $expires_at);
        update_post_meta($post_id, 'sequence_id',   (int) $sequence_id);
        update_post_meta($post_id, 'sequence_code', (string) $sequence_code);

        return [
            'post_id'       => (int) $post_id,
            'sequence_id'   => (int) $sequence_id,
            'sequence_code' => (string) $sequence_code,
            'expires_at'    => $expires_at,
        ];
    }

    /**
     * Fetch latest unused token for an email (quick helper).
     */
    public static function get_unused_token_by_email(string $email, int $expiry_hours = 24) {
        $email = TFG_Utils::normalize_email($email);
        if (!$email) return false;

        $tokens = get_posts([
            'post_type'        => 'verification_tokens',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'meta_query'       => [
                'relation' => 'AND',
                [
                    'key'     => 'email_used',
                    'value'   => $email,
                    'compare' => '=',
                ],
                [
                    'relation' => 'OR',
                    ['key' => 'is_used', 'value' => 0, 'compare' => '='],
                    ['key' => 'is_used', 'compare' => 'NOT EXISTS'],
                ],
            ],
            'orderby' => 'date',
            'order'   => 'DESC',
        ]);

        if (!$tokens) return false;

        $token_id     = (int) $tokens[0];
        $requested_on = get_field('requested_on', $token_id);
        if ($requested_on && strtotime($requested_on) < strtotime("-{$expiry_hours} hours")) {
            error_log("[TFG_Token] ⌛ Token for $email is expired.");
            return false;
        }

        return $token_id;
    }

    /**
     * Check if an email is already subscribed (published subscriber with is_subscribed=1).
     */
    public static function is_already_subscribed(string $email): bool {
        $email = TFG_Utils::normalize_email($email);
        if (!$email) return false;

        $sub = get_posts([
            'post_type'        => 'subscriber',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'post_status'      => 'publish',
            'meta_query'       => [
                ['key' => 'email',         'value' => $email, 'compare' => '='],
                ['key' => 'is_subscribed', 'value' => 1,      'type' => 'NUMERIC'],
            ],
        ]);

        return !empty($sub);
    }

  /**
     * Mark a verification token as used (atomic), attach email, and return sequence info.
     *
     * @param int|string $post_id_or_code  Post ID of verification token OR the raw verification code.
     * @param string|null $code            The verification code (required if first arg is post ID).
     * @param string|null $email           Email that used the code (will be saved).
     * @param array $opts                  Options: ['check_expiry' => bool, 'expiry_meta' => 'expires_on']
     * @return array|WP_Error              ['post_id','email','sequence_id','sequence_code'] on success
     */
    public static function mark_used($post_id_or_code, ?string $code = null, ?string $email = null, array $opts = []) {
        global $wpdb;

        $opts = array_merge([
            'check_expiry' => false,
            'expiry_meta'  => 'expires_on', // ISO8601 or timestamp
        ], $opts);

        $email = $email ? sanitize_email($email) : '';
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
        $ip    = preg_replace('/[^0-9a-fA-F:\.\, ]/', '', (string) $ip); // basic hardening

        // 1) Resolve post ID and code
        $post_id = null;
        if (is_numeric($post_id_or_code)) {
            $post_id = (int) $post_id_or_code;
            $code    = (string) ($code ?? '');
        } else {
            // Arg1 is actually the code
            $code    = (string) $post_id_or_code;
        }

        $code = sanitize_text_field($code);
        if ($code === '') {
            return new WP_Error('tfg_verif_bad_code', 'Missing verification code.');
        }

        if (!$post_id) {
            $post_id = self::find_by_code($code);
            if (!$post_id) {
                error_log("[TFG VERIF] ❌ No verification post found for code={$code}");
                return new WP_Error('tfg_verif_not_found', 'Verification token not found.');
            }
        }

        // 2) Basic post & meta sanity
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'verification_tokens') {
            return new WP_Error('tfg_verif_bad_post', 'Invalid verification token post.');
        }

        // Stored fields (ACF or postmeta)
        $stored_code = get_post_meta($post_id, 'verification_code', true);
        $is_used     = get_post_meta($post_id, 'is_used', true);
        $seq_id      = get_post_meta($post_id, 'sequence_id', true);
        $seq_code    = get_post_meta($post_id, 'sequence_code', true);

        if (!$stored_code || !hash_equals((string)$stored_code, (string)$code)) {
            error_log("[TFG VERIF] ❌ Code mismatch for post #{$post_id}");
            return new WP_Error('tfg_verif_mismatch', 'Verification code mismatch.');
        }

        // Optional expiry check
        if (!empty($opts['check_expiry'])) {
            $exp_raw = get_post_meta($post_id, $opts['expiry_meta'], true);
            if ($exp_raw) {
                $now = current_time('timestamp', true);
                $exp = is_numeric($exp_raw) ? (int)$exp_raw : strtotime($exp_raw);
                if ($exp && $now > $exp) {
                    error_log("[TFG VERIF] ❌ Code expired for post #{$post_id}");
                    return new WP_Error('tfg_verif_expired', 'Verification code has expired.');
                }
            }
        }

        // 3) Short-circuit if already used (idempotent)
        if ($is_used && (string)$is_used !== '0') {
            // already used; return info to allow caller to continue gracefully
            error_log("[TFG VERIF] ℹ️ Already used code for post #{$post_id}");
            return [
                'post_id'       => $post_id,
                'email'         => get_post_meta($post_id, 'email_used', true) ?: $email,
                'sequence_id'   => $seq_id,
                'sequence_code' => $seq_code,
                'already_used'  => true,
            ];
        }

        // 4) Atomic flip: set is_used=1 only if currently 0/empty/null
        $meta_table = $wpdb->postmeta;
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$meta_table}
                 SET meta_value = '1'
                 WHERE post_id = %d
                   AND meta_key IN ('is_used', 'is_used_copy')
                   AND (meta_value = '0' OR meta_value = '' OR meta_value IS NULL)",
                $post_id
            )
        );

        if ($updated === 0) {
            // Either row not found OR someone else already flipped it
            // Ensure the key exists; if not, add it and retry once.
            $has_row = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id FROM {$meta_table} WHERE post_id=%d AND meta_key='is_used' LIMIT 1",
                $post_id
            ));
            if (!$has_row) {
                add_post_meta($post_id, 'is_used', '1', true);
                $updated = 1; // we effectively marked it used
            } else {
                // Another process likely claimed it first; treat as already used
                error_log("[TFG VERIF] ⚠️ Race: token already claimed for post #{$post_id}");
            }
        }

        // 5) Record audit fields (best-effort; not part of the atomic guard)
        if ($email) {
            update_post_meta($post_id, 'email_used', $email);
        }
        update_post_meta($post_id, 'used_on', gmdate('c'));
        if ($ip) {
            update_post_meta($post_id, 'used_by_ip', $ip);
        }

        // Refresh sequence in case not present
        if (!$seq_id)   { $seq_id   = get_post_meta($post_id, 'sequence_id', true); }
        if (!$seq_code) { $seq_code = get_post_meta($post_id, 'sequence_code', true); }

        error_log("[TFG VERIF] ✅ mark_used success for post #{$post_id} email={$email} seq={$seq_code}");

        return [
            'post_id'       => $post_id,
            'email'         => $email,
            'sequence_id'   => $seq_id,
            'sequence_code' => $seq_code,
            'already_used'  => false,
        ];
    }

    /**
     * Find a verification token post ID by its code.
     * @param string $code
     * @return int|null
     */
    public static function find_by_code(string $code): ?int {
        $code = sanitize_text_field($code);
        if ($code === '') return null;

        $q = get_posts([
            'post_type'      => 'verification_tokens',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => 'verification_code',
                    'value' => $code,
                ],
            ],
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'suppress_filters' => true,
        ]);
        return $q ? (int)$q[0] : null;
    }
}

