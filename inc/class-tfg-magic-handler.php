<?php
// === class-tfg-magic-handler.php ===
// Handles incoming magic link validation and subscriber updates

require_once __DIR__ . '/class-tfg-magic-utilities.php';

class TFG_Magic_Handler {

    public static function init(): void {
        (new self())->register_hooks();

        // Only needed if you plan to use get_query_var('token'/'sig'), safe to keep.
        add_filter('query_vars', function ($vars) {
            $vars[] = 'token';
            $vars[] = 'sig';
            return $vars;
        });
    }

    public function register_hooks(): void {
        // Handle after query is parsed but before output; redirects are safe here.
        add_action('template_redirect', [self::class, 'handle_magic_link']);
    }

    /**
     * Lightweight rate limit: 10 events / 5 min per (email, ip).
     */
    private static function rate_limited(string $email): bool {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $key = 'tfg_ml_rl_' . md5($email . '|' . $ip);
        $hits = (int) get_transient($key);
        if ($hits >= 10) {
            return true;
        }
        set_transient($key, $hits + 1, 5 * MINUTE_IN_SECONDS);
        return false;
    }

    /**
     * Safe wrapper: update ACF field if available, otherwise post meta.
     */
    private static function set_field(string $key, $value, int $post_id): void {
        if (function_exists('update_field')) {
            update_field($key, $value, $post_id);
        } else {
            update_post_meta($post_id, $key, $value);
        }
    }

    /**
     * Safe wrapper: get field if ACF available, otherwise post meta.
     */
    private static function get_field(string $key, int $post_id) {
        if (function_exists('get_field')) {
            return get_field($key, $post_id);
        }
        return get_post_meta($post_id, $key, true);
    }

    public static function handle_magic_link(): void {
        // Only act when magic params are present
        if (empty($_GET['token']) || empty($_GET['sig']) || empty($_GET['email'])) {
            return;
        }

        // Normalize inputs (avoid sanitize_text_field which can mangle tokens)
        $token     = TFG_Utils::normalize_token( wp_unslash($_GET['token'] ?? '') );
        $signature = TFG_Utils::normalize_signature( wp_unslash($_GET['sig']   ?? '') );
        $email     = TFG_Utils::normalize_email(   wp_unslash($_GET['email']   ?? '') );

        if (!$token || !$signature || !$email) {
            error_log("[Get Field] Invalid link parameters");
            return;
        }

        // Simple rate-limit to avoid hammering
        if (self::rate_limited($email)) {
            error_log("[Get Field] Technical error set to avoid hammering");
            return;
        }

        // Verify HMAC + magic token (and ensure not used / not expired)
        $result = TFG_Magic_Utilities::verify_magic_token($token, $email, $signature);
        if (!$result || empty($result['success'])) {
            error_log("[Get Field] Invalid/expired/signature mismatch");
            return;
        }

        // Record requester IP on the magic token post
        $magic_post_id = (int) $result['post_id'];
        $ip            = TFG_Magic_Utilities::get_user_ip_address();
        self::set_field('ip_address', $ip, $magic_post_id);
        error_log("[TFG] Magic token {$magic_post_id} used from IP {$ip}");

        /* -------------------------------------------------------
         * Locate the verification token (by sequence, then by code)
         * ----------------------------------------------------- */
        $seq_id   = isset($result['sequence_id']) ? (int) $result['sequence_id'] : 0;

        $vt_posts = [];
        if ($seq_id > 0) {
            $vt_posts = get_posts([
                'post_type'        => 'verification_tokens',
                'posts_per_page'   => 1,
                'fields'           => 'ids',
                'suppress_filters' => true,
                'no_found_rows'    => true,
                'meta_query'       => [[
                    'key'     => 'sequence_id',
                    'value'   => $seq_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ]],
            ]);
        }

        // Fallback: match by verification_code == token (legacy behavior)
        if (!$vt_posts) {
            $vt_posts = get_posts([
                'post_type'        => 'verification_tokens',
                'posts_per_page'   => 1,
                'fields'           => 'ids',
                'suppress_filters' => true,
                'no_found_rows'    => true,
                'meta_query'       => [[
                    'key'     => 'verification_code',
                    'value'   => $token,
                    'compare' => '=',
                ]],
            ]);
        }

        if (!$vt_posts) {
            error_log("[Get Field] Invalid or expired verification link");
            return;
        }

        $v_id    = (int) $vt_posts[0];
        $is_used = (bool) self::get_field('is_used', $v_id);
        if ($is_used) {
            error_log("[TFG] Verification token already used: post_id={$v_id}");
            return;
        }

        // Bind or enforce email on the verification token
        $stored_email = TFG_Utils::normalize_email(self::get_field('email_used', $v_id) ?: '');
        if ($stored_email && $stored_email !== $email) {
            error_log("[Get Field] Issued for different email");
            return;
        }
        if (!$stored_email) {
            self::set_field('email_used', $email, $v_id);
        }

        /* --------------------------------
         * Upsert subscriber (avoid dupes)
         * ------------------------------ */
        $existing = get_posts([
            'post_type'        => 'subscriber',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'meta_query'       => [[
                'key'     => 'email',
                'value'   => $email,
                'compare' => '=',
            ]],
        ]);

        if ($existing) {
            $sub_id = (int) $existing[0];
        } else {
            $sub_id = wp_insert_post([
                'post_type'   => 'subscriber',
                'post_title'  => $email,
                'post_status' => 'publish',
            ]);
            if (!$sub_id || is_wp_error($sub_id)) {
                error_log('[TFG] Failed to create subscriber post'); // DO NOT burn the verification token; allow retry
                return;
            }
            self::set_field('email', $email, $sub_id);
        }

        // Mark verified + metadata on subscriber
        self::set_field('is_verified',       true,                  $sub_id);
        self::set_field('verification_code', $token,                $sub_id);
        self::set_field('is_subscribed',     true,                  $sub_id);
        self::set_field('date_subscribed',   current_time('mysql'), $sub_id); // Y-m-d H:i:s
        self::set_field('source',            'magic_link',          $sub_id);

        // Set cookies (secure, HttpOnly handled inside)
        TFG_Cookies::set_subscriber_cookie($email);

        // NOW it’s safe to burn the verification token
        self::set_field('is_used',      true,                  $v_id);
        self::set_field('is_used_copy', 1,                     $v_id);
        self::set_field('used_on',      current_time('mysql'), $v_id);

        error_log("[TFG] subscriber {$sub_id} verified; verification_token {$v_id} consumed");

        // Redirect to confirmation page if headers still open
        if (!headers_sent()) {
            nocache_headers();
            wp_safe_redirect(home_url('/subscription-confirmed'));
            exit;
        }

        // Fallback: show success modal if we cannot redirect
        error_log("[Get Field] show success modal if we cannot redirect");
    }
}
