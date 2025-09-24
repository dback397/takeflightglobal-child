<?php
// File: Newsletter/SubscriberConfirm.php
namespace TFG\Features\Newsletter;

use TFG\Core\Utils;
use TFG\Core\Cookies;
use TFG\Features\MagicLogin\MagicUtilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles subscriber confirmation via tokenized link.
 * - Confirms MT/VT signature
 * - Finalizes subscriber record
 * - Sets cookie + redirect
 */
final class SubscriberConfirm
{
    public static function init(): void
    {
        \add_shortcode('tfg_confirm_subscription', [self::class, 'render_shortcode']);
        \add_action('template_redirect', [self::class, 'maybe_handle_confirm'], 0);
    }

    protected static function error_html(string $msg): string
    {
        return '<div class="notice notice-error"><p>' . \esc_html($msg) . '</p></div>';
    }

    protected static function success_html(string $email): string
    {
        return '<div class="notice notice-success"><p>✅ Subscription confirmed for <strong>' .
            \esc_html($email) .
            '</strong>.</p></div>';
    }

    public static function render_shortcode($atts = []): string
    {
        $email = isset($_GET['email']) ? \sanitize_email((string) $_GET['email']) : '';
        if ($email !== '' && isset($_GET['ok']) && $_GET['ok'] === '1') {
            return self::success_html($email);
        }
        return self::error_html('Missing or invalid confirmation parameters.');
    }

    public static function maybe_handle_confirm(): void
    {
        if (\is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }

        $token    = isset($_GET['token']) ? (string) $_GET['token'] : '';
        $email    = isset($_GET['email']) ? (string) $_GET['email'] : '';
        $sig      = isset($_GET['sig'])   ? (string) $_GET['sig']   : '';
        $seq_code = isset($_GET['sequence_code']) ? (string) $_GET['sequence_code'] : '';
        $magic_id = isset($_GET['magic_id']) ? (int) $_GET['magic_id'] : 0;

        if ($token === '' || $email === '' || $sig === '') {
            return;
        }

        $core = self::confirm_core($token, $email, $sig, $seq_code, $magic_id);
        if (!$core['ok']) {
            return;
        }

        $subscriber_id = self::finalizeSubscriber($core['email'], $core['vt_id'], $core['magic_id']);

        Cookies::setSubscriberCookie($email);

        \wp_safe_redirect(\home_url('/'), 303);
        exit;
    }

    protected static function confirm_core(
        string $token,
        string $email,
        string $signature,
        ?string $seq_code = null,
        ?int $magic_id = null
    ): array {
        $email     = Utils::normalizeEmail(is_string($email) ? \wp_unslash($email) : '');
        $token     = Utils::normalizeToken(is_string($token) ? \wp_unslash($token) : '');
        $signature = Utils::normalizeSignature(is_string($signature) ? \wp_unslash($signature) : '');
        $seq_code  = is_string($seq_code) ? Utils::normalizeToken(\wp_unslash($seq_code)) : '';

        if ($email === '' || $token === '' || $signature === '') {
            \error_log('[Confirm] ❌ Missing required params (email/token/sig).');
            return ['ok' => false, 'error' => 'missing_params'];
        }

        if (!MagicUtilities::verifyMagicToken($token, $email, $signature)) {
            \error_log('[Confirm] ❌ verify_magic_token failed.');
            return ['ok' => false, 'error' => 'bad_signature_or_token'];
        }

        $seq_id = $_GET['seq_id'] ?? $_POST['seq_id'] ?? '';
        $seq_id = \sanitize_text_field((string) $seq_id);

        if ($seq_code === '' && $seq_id) {
            $seq_code = (string) \get_post_meta((int) $magic_id, 'sequence_code', true);
            $seq_code = $seq_code ? Utils::normalizeToken($seq_code) : '';
        }

        $vt_id = 0;
        if ($seq_code !== '') {
            $vt_posts = \get_posts([
                'post_type'        => 'verification_tokens',
                'posts_per_page'   => 1,
                'post_status'      => 'any',
                'suppress_filters' => true,
                'no_found_rows'    => true,
                'meta_query'       => [
                    'relation' => 'AND',
                    ['key' => 'sequence_code', 'value' => $seq_code],
                    ['key' => 'email_used',    'value' => $email],
                ],
                'orderby' => 'date',
                'order'   => 'DESC',
                'fields'  => 'ids',
            ]);
            if (!empty($vt_posts)) {
                $vt_id = (int) $vt_posts[0];
            }
        }

        return [
            'ok'        => true,
            'vt_id'     => $vt_id,
            'seq_code'  => $seq_code,
            'magic_id'  => $magic_id ? (int) $magic_id : 0,
            'email'     => $email,
            'token'     => $token,
        ];
    }

    protected static function finalizeSubscriber(string $email, int $vt_id = 0, int $magic_id = 0): int
    {
        $seq      = '';
        $seq_code = '';
        if ($vt_id > 0) {
            $seq      = (string) \get_post_meta($vt_id, 'sequence_id', true);
            $seq_code = (string) \get_post_meta($vt_id, 'sequence_code', true);
        }
        if ($seq === '' && $magic_id) {
            $seq = (string) \get_post_meta($magic_id, 'sequence_id', true);
        }
        if ($seq_code === '' && $magic_id) {
            $seq_code = (string) \get_post_meta($magic_id, 'sequence_code', true);
        }

        $sub_id = 0;
        if ($seq_code !== '') {
            $by_seq = \get_posts([
                'post_type'        => 'subscriber',
                'posts_per_page'   => 1,
                'post_status'      => 'any',
                'suppress_filters' => true,
                'no_found_rows'    => true,
                'fields'           => 'ids',
                'meta_query'       => [[ 'key' => 'sequence_code', 'value' => $seq_code, 'compare' => '=' ]],
            ]);
            if (!empty($by_seq)) {
                $sub_id = (int) $by_seq[0];
            }
        }
        if (!$sub_id && $email !== '') {
            $by_email = \get_posts([
                'post_type'        => 'subscriber',
                'posts_per_page'   => 1,
                'post_status'      => 'any',
                'suppress_filters' => true,
                'no_found_rows'    => true,
                'fields'           => 'ids',
                'meta_query'       => [[ 'key' => 'email', 'value' => $email, 'compare' => '=' ]],
            ]);
            if (!empty($by_email)) {
                $sub_id = (int) $by_email[0];
            }
        }

        if (!$sub_id) {
            \error_log('[Confirm] ❌ Subscriber stub not found (seq_code=' . $seq_code . ', email=' . $email . ').');
            return 0;
        }

        $set = function (string $key, $val) use ($sub_id) {
            if (\function_exists('update_field')) {
                \update_field($key, $val, $sub_id);
            } else {
                \update_post_meta($sub_id, $key, $val);
            }
        };

        if ($email !== '') {
            $set('email', $email);
        }

        $have_seq      = (string) \get_post_meta($sub_id, 'sequence_id', true);
        $have_seq_code = (string) \get_post_meta($sub_id, 'sequence_code', true);
        if ($seq !== '' && $have_seq === '') {
            $set('sequence_id', $seq);
        }
        if ($seq_code !== '' && $have_seq_code === '') {
            $set('sequence_code', $seq_code);
        }

        if ($vt_id > 0) {
            $vcode = (string) \get_post_meta($vt_id, 'verification_code', true);
            if ($vcode !== '') {
                $set('verification_code', $vcode);
            }
        }

        $now = \current_time('mysql');
        $set('is_verified',   1);
        $set('verified_on',   $now);
        $set('is_subscribed', 1);

        $existing_date = (string) \get_post_meta($sub_id, 'date_subscribed', true);
        if ($existing_date === '') {
            $set('date_subscribed', $now);
        }

        $existing_legacy = (string) \get_post_meta($sub_id, 'subscribed_on', true);
        if ($existing_legacy === '') {
            $set('subscribed_on', $now);
        }

        $set('request_ip', MagicUtilities::clientIp());

        return $sub_id;
    }
}

// Legacy alias for backwards compatibility
\class_alias(\TFG\Features\Newsletter\SubscriberConfirm::class, 'TFG_Subscriber_Confirm');
