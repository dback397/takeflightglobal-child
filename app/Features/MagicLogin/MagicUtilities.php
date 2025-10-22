<?php

namespace TFG\Features\MagicLogin;

use \WP_Query;

/**
 * Magic login utilities: token creation, verification, and mailing.
 */
final class MagicUtilities
{
    /* ========================
     * Logging helpers
     * ====================== */
    protected static function log(string $msg): void
    {
        \TFG\Core\Utils::info('[Magic] ' . $msg);
    }

    protected static function tfgLog(string $msg): void
    {
        \TFG\Core\Utils::info('[TFG MAGIC] ' . $msg);
    }

    /* ========================
     * Normalizers
     * ====================== */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(\sanitize_email(\wp_unslash($email)));
    }

    public static function normalizeToken(string $token): string
    {
        return \preg_replace('/[^A-Za-z0-9]/', '', (string) $token);
    }

    public static function normalizeSignature(string $sig): string
    {
        return \preg_replace('/[^a-f0-9]/i', '', (string) $sig);
    }

    /* ========================
     * IP helpers
     * ====================== */
    public static function getUserIpAddress(): string
    {
        $candidates = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CF_CONNECTING_IP',
            'REMOTE_ADDR',
        ];
        $ip = '';
        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $raw = (string) $_SERVER[$key];
            $list = $key === 'HTTP_X_FORWARDED_FOR'
                ? array_map('trim', explode(',', $raw))
                : [trim($raw)];
            foreach ($list as $cand) {
                if (\filter_var($cand, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $ip = $cand;
                    break 2;
                }
                if (!$ip && \filter_var($cand, FILTER_VALIDATE_IP)) {
                    $ip = $cand;
                }
            }
        }
        if (!$ip && !empty($_SERVER['REMOTE_ADDR'])) {
            $ip = (string) $_SERVER['REMOTE_ADDR'];
        }
        if ($ip === '::1') {
            $ip = '127.0.0.1';
        }
        return $ip ?: 'unknown';
    }

    public static function clientIp(): string
    {
        return self::getUserIpAddress();
    }

    /* ========================
     * HMAC + URL builders
     * ====================== */
    protected static function hmacSecret(): string
    {
        if (\defined('TFG_HMAC_SECRET') && TFG_HMAC_SECRET) {
            return (string) TFG_HMAC_SECRET;
        }
        if (\defined('AUTH_SALT') && AUTH_SALT) {
            return (string) AUTH_SALT;
        }
        return '';
    }

    protected static function buildSignature(string $token, string $email): string
    {
        $secret = self::hmacSecret();
        if (!$secret) {
            return '';
        }
        $qs = \http_build_query(['token' => $token, 'email' => $email]);
        return \hash_hmac('sha256', $qs, $secret);
    }

    protected static function buildMagicUrl(
        string $token,
        string $email,
        string $basePath = '/subscription-confirmed'
    ): string {
        $email_qs = \rawurlencode($email);
        $base     = \home_url($basePath);
        $url      = \add_query_arg(['token' => $token, 'email' => $email_qs], $base);
        $sig      = self::buildSignature($token, $email);
        if ($sig) {
            $url = \add_query_arg('sig', $sig, $url);
        } else {
            self::tfgLog('⚠️ TFG_HMAC_SECRET not defined; verifyMagicToken will fail');
        }
        return $url;
    }

    /* ========================
     * Core: Create magic token
     * ====================== */
    public static function createMagicToken(string $email, array $args = []): array|false
    {
        $email = self::normalizeEmail($email);
        $now   = \time();
        $exp   = $now + max(60, (int) ($args['expires_in'] ?? 900));

        $seq_id     = $args['sequence_id']       ?? null;
        $seq_code   = $args['sequence_code']     ?? null;
        $verif_code = $args['verification_code'] ?? null;

        $token = \wp_generate_password(24, false);
        $hash  = \hash('sha256', $token);

        $basePath = (string) ($args['base_path'] ?? '/subscription-confirmed');
        $url      = self::buildMagicUrl($token, $email, $basePath);
        $sig      = self::buildSignature($token, $email);

        if (!\post_type_exists('magic_tokens')) {
            self::tfgLog('❌ CPT magic_tokens not registered when creating token for ' . $email);
            return false;
        }

        $post_id = \wp_insert_post([
            'post_type'   => 'magic_tokens',
            'post_title'  => "MAG: {$seq_code} {$email}",
            'post_status' => 'publish',
            'meta_input'  => [
                'email'             => $email,
                'token'             => $token,
                'token_hash'        => $hash,
                'sequence_id'       => $seq_id,
                'sequence_code'     => $seq_code,
                'verification_code' => $verif_code,
                'issued_on'         => \gmdate('c', $now),
                'expires_at'        => $exp,
                'expires_on'        => \gmdate('c', $exp),
                'is_used'           => 0,
                'magic_url'         => $url,
                'ip_address'        => self::getUserIpAddress(),
                'signature'         => $sig,
            ],
        ], true);

        if (\is_wp_error($post_id)) {
            self::tfgLog('❌ wp_insert_post error: ' . $post_id->get_error_message());
            return false;
        }

        $site = \function_exists('get_current_blog_id') ? (string) \get_current_blog_id() : '1';
        \set_transient('last_magic_url_' . md5($site . '|' . strtolower($email)), $url, $exp - $now);

        self::tfgLog("✅ Created magic token #$post_id for $email seq=" . ($seq_code ?? ''));
        return [
            'post_id'    => (int) $post_id,
            'token'      => $token,
            'token_hash' => $hash,
            'url'        => $url,
            'expires_at' => $exp,
        ];
    }

    /* ========================
     * Verify magic token
     * ====================== */
    public static function verifyMagicToken(string $token, string $email, string $signature): array|false
    {
        $email     = self::normalizeEmail($email);
        $token     = self::normalizeToken($token);
        $signature = self::normalizeSignature($signature);

        if (!$email || !$token || !$signature) {
            self::log('❌ Missing/invalid params for verifyMagicToken');
            return false;
        }

        $secret = self::hmacSecret();
        if (!$secret) {
            self::log('❌ TFG_HMAC_SECRET not defined');
            return false;
        }

        $qs       = \http_build_query(['token' => $token, 'email' => $email]);
        $expected = \hash_hmac('sha256', $qs, $secret);
        if (!\hash_equals($expected, $signature)) {
            self::log("❌ Invalid signature for $email");
            return false;
        }

        $hash = \hash('sha256', $token);
        $q    = new WP_Query([
            'post_type'        => 'magic_tokens',
            'posts_per_page'   => 1,
            'post_status'      => 'publish',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'meta_query'       => [
                ['key' => 'token_hash', 'value' => $hash],
                ['key' => 'email',      'value' => $email],
            ],
            'fields' => 'ids',
        ]);
        if (!$q->have_posts()) {
            self::log("❌ Token record not found for $email");
            return false;
        }
        $post_id = (int) $q->posts[0];

        $used       = (int) \get_post_meta($post_id, 'is_used', true);
        $now        = \time();
        $expires_at = (int) \get_post_meta($post_id, 'expires_at', true);

        if ($used === 1) {
            return ['success' => true, 'already_used' => true, 'email' => $email, 'post_id' => $post_id];
        }
        if ($expires_at && $now > $expires_at) {
            self::log("❌ Token expired for $email");
            return false;
        }

        \update_post_meta($post_id, 'is_used', 1);
        \update_post_meta($post_id, 'used_at', $now);
        \update_post_meta($post_id, 'ip_used', self::getUserIpAddress());

        return ['success' => true, 'email' => $email, 'post_id' => $post_id];
    }

    /* ========================
     * Mailer
     * ====================== */
    public static function sendMagicLink(string $url, string $email): bool
    {
        if (!$email || !$url) {
            \TFG\Core\Utils::info('[TFG MAGIC] ❌ sendMagicLink: bad parameters');
            return false;
        }

        $host       = \wp_parse_url(\home_url(), PHP_URL_HOST) ?: 'example.com';
        $from_email = 'no-reply@' . $host;
        $from_name  = \get_bloginfo('name') ?: 'WordPress';

        $subject = 'Confirm your subscription';
        $body    = '<p>Click to confirm your subscription:</p><p><a href="' .
                   \esc_url($url) . '">' . \esc_html($url) . '</a></p>';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        ];

        @\ini_set('sendmail_from', $from_email);
        $ok = \wp_mail($email, $subject, $body, $headers);

        if ($ok) {
            \TFG\Core\Utils::info("[Magic] 📤 Magic link sent to $email url=$url");
            return true;
        }
        \TFG\Core\Utils::info("[TFG MAGIC] ❌ wp_mail returned false for $email");
        return false;
    }
}

/* ---- Legacy alias for transition (remove once stable) ---- */
\class_alias(\TFG\Features\MagicLogin\MagicUtilities::class, 'TFG_Magic_Utilities');
