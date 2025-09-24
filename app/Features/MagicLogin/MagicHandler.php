<?php

namespace TFG\Features\MagicLogin;

use TFG\Core\Utils;
use TFG\Core\Cookies;
use \WP_Error;

/**
 * Handles incoming magic link validation and subscriber updates.
 */
final class MagicHandler
{
    public static function init(): void
    {
        (new self())->registerHooks();

        // Keep token/sig vars available if theme or plugins query them
        \add_filter('query_vars', function ($vars) {
            $vars[] = 'token';
            $vars[] = 'sig';
            return $vars;
        });
    }

    private function registerHooks(): void
    {
        \add_action('template_redirect', [self::class, 'handleMagicLink']);
    }

    /* ---------------- Internals ---------------- */

    private static function rateLimited(string $email): bool
    {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
        $key = 'tfg_ml_rl_' . md5($email . '|' . $ip);
        $hits = (int) \get_transient($key);
        if ($hits >= 10) {
            return true;
        }
        \set_transient($key, $hits + 1, 5 * MINUTE_IN_SECONDS);
        return false;
    }

    private static function setField(string $key, $value, int $post_id): void
    {
        if (\function_exists('update_field')) {
            \update_field($key, $value, $post_id);
        } else {
            \update_post_meta($post_id, $key, $value);
        }
    }

    private static function getField(string $key, int $post_id)
    {
        if (\function_exists('get_field')) {
            return \get_field($key, $post_id);
        }
        return \get_post_meta($post_id, $key, true);
    }

    /* ---------------- Main Handler ---------------- */

    public static function handleMagicLink(): void
    {
        if (empty($_GET['token']) || empty($_GET['sig']) || empty($_GET['email'])) {
            return;
        }

        $token     = Utils::normalizeToken(\wp_unslash($_GET['token'] ?? ''));
        $signature = Utils::normalizeSignature(\wp_unslash($_GET['sig']   ?? ''));
        $email     = Utils::normalizeEmail(\wp_unslash($_GET['email']   ?? ''));

        if (!$token || !$signature || !$email) {
            \error_log("[MagicHandler] Invalid link parameters");
            return;
        }

        if (self::rateLimited($email)) {
            \error_log("[MagicHandler] Rate-limited for {$email}");
            return;
        }

        $result = MagicUtilities::verifyMagicToken($token, $email, $signature);
        if (!$result || empty($result['success'])) {
            \error_log("[MagicHandler] Invalid/expired/signature mismatch");
            return;
        }

        $magic_post_id = (int) $result['post_id'];
        $ip            = MagicUtilities::getUserIpAddress();
        self::setField('ip_address', $ip, $magic_post_id);
        \error_log("[MagicHandler] Magic token {$magic_post_id} used from IP {$ip}");

        // Look up verification token
        $seq_id   = isset($result['sequence_id']) ? (int) $result['sequence_id'] : 0;
        $vt_posts = [];

        if ($seq_id > 0) {
            $vt_posts = \get_posts([
                'post_type'      => 'verification_tokens',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => [[
                    'key'     => 'sequence_id',
                    'value'   => $seq_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ]],
            ]);
        }

        if (!$vt_posts) {
            $vt_posts = \get_posts([
                'post_type'      => 'verification_tokens',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => [[
                    'key'     => 'verification_code',
                    'value'   => $token,
                    'compare' => '=',
                ]],
            ]);
        }

        if (!$vt_posts) {
            \error_log("[MagicHandler] No matching verification token");
            return;
        }

        $v_id    = (int) $vt_posts[0];
        $is_used = (bool) self::getField('is_used', $v_id);
        if ($is_used) {
            \error_log("[MagicHandler] Token already used: {$v_id}");
            return;
        }

        $stored_email = Utils::normalizeEmail(self::getField('email_used', $v_id) ?: '');
        if ($stored_email && $stored_email !== $email) {
            \error_log("[MagicHandler] Token email mismatch");
            return;
        }
        if (!$stored_email) {
            self::setField('email_used', $email, $v_id);
        }

        // Subscriber upsert
        $existing = \get_posts([
            'post_type'      => 'subscriber',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => 'email',
                'value'   => $email,
                'compare' => '=',
            ]],
        ]);

        $sub_id = $existing ? (int) $existing[0] : \wp_insert_post([
            'post_type'   => 'subscriber',
            'post_title'  => $email,
            'post_status' => 'publish',
        ]);

        if (!$sub_id || \is_wp_error($sub_id)) {
            \error_log('[MagicHandler] Failed to create subscriber');
            return;
        }

        self::setField('email',            $email,               $sub_id);
        self::setField('is_verified',      true,                 $sub_id);
        self::setField('verification_code',$token,               $sub_id);
        self::setField('is_subscribed',    true,                 $sub_id);
        self::setField('date_subscribed',  \current_time('mysql'), $sub_id);
        self::setField('source',           'magic_link',         $sub_id);

        Cookies::setSubscriberCookie($email);

        self::setField('is_used',      true,                  $v_id);
        self::setField('is_used_copy', 1,                     $v_id);
        self::setField('used_on',      \current_time('mysql'), $v_id);

        \error_log("[MagicHandler] Subscriber {$sub_id} verified; token {$v_id} consumed");

        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect(\home_url('/subscription-confirmed'));
            exit;
        }
    }
}

/* ---- Legacy alias for transition ---- */
\class_alias(\TFG\Features\MagicLogin\MagicHandler::class, 'TFG_Magic_Handler');
