<?php
// ✅ TFG System Guard injected by Cursor – prevents REST/CRON/CLI/AJAX interference
namespace TFG\Core;

final class Cookies
{
    // =========================
    // Configuration / Defaults
    // =========================
    private const SUB_UI = 'is_subscribed';   // JS-readable flag (no secrets)
    private const SUB_OK = 'subscribed_ok';   // HttpOnly trust token
    private const MEM_UI = 'is_member';       // JS-readable member flag
    private const MEM_OK = 'member_ok';       // HttpOnly trust token for member

    private const DAYS = 30;                  // default lifetime (days)

    private const SAMESITE_SUB = 'Lax';
    private const SAMESITE_MEM = 'Lax';

    private const ROTATE_MONTHLY = true;      // rotate HMAC monthly

    public static function init(): void
    {
        // Reserved for hooks if needed later
    }

    // ===========================
    // Subscriber cookie interface
    // ===========================
    public static function setSubscriberCookie(string $email): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \error_log('[TFG SystemGuard] Skipped setSubscriberCookie due to REST/CRON/CLI/AJAX context');
            return;
        }

        $email = Utils::normalizeEmail($email);
        if (!$email) return;

        if (!self::guardHeaders('set subscriber cookies')) return;

        // UI flag
        self::setCookie(self::SUB_UI, '1', [
            'httponly' => false,
            'samesite' => self::SAMESITE_SUB,
            'expires'  => time() + self::DAYS * DAY_IN_SECONDS,
        ]);

        // HttpOnly trust token
        $h = self::subscriberHmac($email);
        self::setCookie(self::SUB_OK, $h, [
            'httponly' => true,
            'samesite' => self::SAMESITE_SUB,
            'expires'  => time() + self::DAYS * DAY_IN_SECONDS,
        ]);
    }

    public static function renewSubscriberCookie(string $email): void
    {
        self::setSubscriberCookie($email);
    }

    public static function unsetSubscriberCookie(): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \error_log('[TFG SystemGuard] Skipped unsetSubscriberCookie due to REST/CRON/CLI/AJAX context');
            return;
        }

        if (!self::guardHeaders('unset subscriber cookies')) return;

        self::deleteCookie(self::SUB_UI);
        self::deleteCookie(self::SUB_OK);
    }

    public static function isSubscribed(?string $email = null): bool
    {
        // --- 1. Skip during system requests (REST, CRON, CLI, AJAX, heartbeat/autosave)
        if (\TFG\Core\Utils::isSystemRequest()) {
            $trace = wp_debug_backtrace_summary(null, 0, false);
            \error_log('[TFG Cookies] 🛡 Skipping subscription check during system request → ' . $trace);
            return true; // treat as subscribed to prevent backend interference
        }

        // --- 2. Retrieve subscription cookie value
        $cookie_value = $_COOKIE[self::SUB_OK] ?? '';

        if (empty($cookie_value)) {
            \error_log('[TFG Cookies] ⚠️ No subscription cookie found (' . self::SUB_OK . ')');
            return false;
        }

        // --- 3. If email provided, validate via HMAC
        if (!empty($email)) {
            $normalized_email = \TFG\Core\Utils::normalizeEmail($email);

            if (empty($normalized_email)) {
                \error_log('[TFG Cookies] ⚠️ Invalid or empty email provided for subscription check');
                return false;
            }

            $expected_hmac = self::subscriberHmac($normalized_email);
            $is_valid = \hash_equals($expected_hmac, $cookie_value);

            if ($is_valid) {
                \error_log('[TFG Cookies] ✅ Valid subscription cookie confirmed for ' . $normalized_email);
            } else {
                \error_log('[TFG Cookies] ❌ HMAC mismatch for subscription cookie (' . $normalized_email . ')');
            }

            return $is_valid;
        }

        // --- 4. Email not provided, cookie exists but cannot be verified
        \error_log('[TFG Cookies] ⚠️ Subscription cookie exists but no email provided — assuming subscribed for front-end context');
        return true; // assume subscribed for limited contexts like front-end display
    }

    // =======================
    // Member cookie interface
    // =======================
    public static function setMemberCookie(string $member_id, string $email = ''): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \error_log('[TFG SystemGuard] Skipped setMemberCookie due to REST/CRON/CLI/AJAX context');
            return;
        }

        $member_id = Utils::normalizeMemberId($member_id);
        $email     = $email ? Utils::normalizeEmail($email) : '';

        if (!$member_id) return;
        if (!self::guardHeaders('set member cookies')) return;

        // JS-visible flag
        self::setCookie(self::MEM_UI, '1', [
            'httponly' => false,
            'samesite' => self::SAMESITE_MEM,
            'expires'  => time() + self::DAYS * DAY_IN_SECONDS,
        ]);

        // HttpOnly trust token
        $h = self::memberHmac($member_id, $email);
        self::setCookie(self::MEM_OK, $h, [
            'httponly' => true,
            'samesite' => self::SAMESITE_MEM,
            'expires'  => time() + self::DAYS * DAY_IN_SECONDS,
        ]);
    }

    public static function renewMemberCookie(string $member_id, string $email = ''): void
    {
        self::setMemberCookie($member_id, $email);
    }

    public static function unsetMemberCookie(): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \error_log('[TFG SystemGuard] Skipped unsetMemberCookie due to REST/CRON/CLI/AJAX context');
            return;
        }

        if (!self::guardHeaders('unset member cookies')) return;

        self::deleteCookie(self::MEM_UI);
        self::deleteCookie(self::MEM_OK);
    }

    public static function isMember(?string $member_id = null, string $email = ''): bool
    {
        $server = $_COOKIE[self::MEM_OK] ?? '';
        if (!$server) {
            \error_log('[TFG Cookies] No member cookie found (member_ok)');
            return false;
        }

        if ($member_id) {
            $member_id = Utils::normalizeMemberId($member_id);
            if (!$member_id) {
                \error_log('[TFG Cookies] Invalid member ID provided for member check');
                return false;
            }

            $email = $email ? Utils::normalizeEmail($email) : '';
            $expected_hmac = self::memberHmac($member_id, $email);
            $is_valid = \hash_equals($expected_hmac, $server);
            if (!$is_valid) {
                \error_log('[TFG Cookies] Member cookie HMAC mismatch for member_id: ' . $member_id . ', email: ' . $email);
            }
            return $is_valid;
        }

        \error_log('[TFG Cookies] Member cookie exists but no member_id provided for validation');
        return false;
    }

    // ==========================
    // Internals
    // ==========================
    private static function guardHeaders(string $what): bool
    {
        if (\headers_sent($file, $line)) {
            \error_log("[TFG\\Core\\Cookies] Headers already sent at $file:$line; cannot $what.");
            return false;
        }
        return true;
    }

    private static function domain(): string
    {
        return (\defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : '';
    }

    private static function baseCookieArgs(): array
    {
        return [
            'path'     => '/',
            'domain'   => self::domain(),
            'secure'   => \is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
            'expires'  => time() + self::DAYS * DAY_IN_SECONDS,
        ];
    }

    private static function setCookie(string $name, string $value, array $overrides = []): void
    {
        $args = \array_merge(self::baseCookieArgs(), $overrides);
        \setcookie($name, $value, $args);
    }

    private static function deleteCookie(string $name): void
    {
        $base = self::baseCookieArgs();
        $base['expires'] = time() - HOUR_IN_SECONDS;

        $args = $base;
        $args['httponly'] = true;
        \setcookie($name, '', $args);

        $args['httponly'] = false;
        \setcookie($name, '', $args);
    }

    private static function subscriberHmac(string $email): string
    {
        $payload = $email;
        if (self::ROTATE_MONTHLY) {
            $payload .= '|' . \gmdate('Y-m');
        }
        return \hash_hmac('sha256', $payload, \TFG_HMAC_SECRET);
    }

    private static function memberHmac(string $member_id, string $email = ''): string
    {
        $payload = $member_id . '|' . ($email ?: '-');
        if (self::ROTATE_MONTHLY) {
            $payload .= '|' . \gmdate('Y-m');
        }
        return \hash_hmac('sha256', $payload, \TFG_HMAC_SECRET);
    }

    // ============================
    // UI cookie helpers
    // ============================
    public static function setUiCookie(string $name, string $value, int $ttl_seconds = 300): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \error_log('[TFG SystemGuard] Skipped setUiCookie due to REST/CRON/CLI/AJAX context');
            return;
        }

        if (\headers_sent($f, $l)) {
            \error_log("[TFG\\Core\\Cookies] Headers already sent at $f:$l; cannot setUiCookie($name).");
            return;
        }
        \setcookie($name, $value, [
            'expires'  => time() + max(5, $ttl_seconds),
            'path'     => '/',
            'domain'   => self::domain(),
            'secure'   => \is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    public static function deleteUiCookie(string $name): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \error_log('[TFG SystemGuard] Skipped deleteUiCookie due to REST/CRON/CLI/AJAX context');
            return;
        }

        if (\headers_sent($f, $l)) {
            \error_log("[TFG\\Core\\Cookies] Headers already sent at $f:$l; cannot deleteUiCookie($name).");
            return;
        }

        foreach (['', self::domain()] as $domain) {
            \setcookie($name, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'domain'   => $domain,
                'secure'   => \is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
    }

    // ============================
    // Legacy / compatibility getters
    // ============================
    public static function getMemberEmail(): ?string
    {
        if (!isset($_COOKIE['member_email'])) return null;
        $raw = \wp_unslash($_COOKIE['member_email']);
        return Utils::normalizeEmail($raw) ?: null;
    }

    public static function getMemberRole(): ?string
    {
        if (!isset($_COOKIE['member_role'])) return null;
        $raw = \wp_unslash($_COOKIE['member_role']);
        $val = \sanitize_key($raw);
        return $val !== '' ? $val : null;
    }

    public static function getMemberId(): ?string
    {
        if (!isset($_COOKIE['member_id'])) return null;
        $raw = \wp_unslash($_COOKIE['member_id']);
        $val = Utils::normalizeMemberId($raw);
        return $val !== '' ? $val : null;
    }
}
