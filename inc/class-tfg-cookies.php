<?php
// === class-tfg-cookies.php ===
// Centralized cookie utilities with header checks, UI/HttpOnly split, and HMAC verification.

class TFG_Cookies
{

    /* =========================
       Configuration / Defaults
       ========================= */
    // Cookie names
    private const SUB_UI = 'is_subscribed';   // JS-readable flag (no secrets)
    private const SUB_OK = 'subscribed_ok';   // HttpOnly trust token (server-checked)
    private const MEM_UI = 'is_member';       // JS-readable member flag (optional)
    private const MEM_OK = 'member_ok';       // HttpOnly trust token for member

    // Lifetimes
    private const DAYS = 30;                  // default lifetime (days)

    // SameSite policies (use 'Strict' if you never need cross-site navigations)
    private const SAMESITE_SUB = 'Lax';
    private const SAMESITE_MEM = 'Lax';

    // Rotate HMAC monthly to limit replay windows (set to false to disable)
    private const ROTATE_MONTHLY = true;

    public static function init(): void
    {
        // Reserved for hooks if needed later
    }

    /* ===========================
       Subscriber cookie interface
       =========================== */

    public static function set_subscriber_cookie(string $email): void
    {
        $email = TFG_Utils::normalize_email($email);
        if (!$email) return;

        if (!self::guardHeaders('set subscriber cookies')) return;

        // UI flag (no secrets)
        self::setCookie(self::SUB_UI, '1', [
            'httponly' => false,
            'samesite' => self::SAMESITE_SUB,
            'expires'  => time() + self::DAYS * DAY_IN_SECONDS,
        ]);

        // HttpOnly trust token
        $h = self::subscriber_hmac($email);
        self::setCookie(self::SUB_OK, $h, [
            'httponly' => true,
            'samesite' => self::SAMESITE_SUB,
            'expires'  => time() + self::DAYS * DAY_IN_SECONDS,
        ]);
    }

    public static function renew_subscriber_cookie(string $email): void
    {
        // Re-issue with fresh expiry (same values)
        self::set_subscriber_cookie($email);
    }

    public static function unset_subscriber_cookie(): void
    {
        if (!self::guardHeaders('unset subscriber cookies')) return;

        self::deleteCookie(self::SUB_UI);
        self::deleteCookie(self::SUB_OK);
    }

    /**
     * Server-side truth. The UI cookie is ignored for trust.
     * If $email is provided, we validate HMAC(email) === HttpOnly cookie.
     * If not provided, we return false (no way to recompute safely).
     */
    public static function is_subscribed(?string $email = null): bool
    {
        $server = $_COOKIE[self::SUB_OK] ?? '';
        if (!$server) return false;

        if ($email) {
            $email = TFG_Utils::normalize_email($email);
            if (!$email) return false;
            return hash_equals(self::subscriber_hmac($email), $server);
        }

        return false;
    }

    /* =======================
       Member cookie interface
       ======================= */

    public static function set_member_cookie(string $member_id, string $email = ''): void
    {
        $member_id = TFG_Utils::normalize_member_id($member_id);
        $email     = $email ? TFG_Utils::normalize_email($email) : '';

        if (!$member_id) return;
        if (!self::guardHeaders('set member cookies')) return;

        // JS-visible convenience bit (optional)
        self::setCookie(self::MEM_UI, '1', [
            'httponly' => false,
            'samesite' => self::SAMESITE_MEM,
            'expires'  => time() + self::DAYS * DAY_IN_SECONDS,
        ]);

        // HttpOnly trust token
        $h = self::member_hmac($member_id, $email);
        self::setCookie(self::MEM_OK, $h, [
            'httponly' => true,
            'samesite' => self::SAMESITE_MEM,
            'expires'  => time() + self::DAYS * DAY_IN_SECONDS,
        ]);
    }

    public static function renew_member_cookie(string $member_id, string $email = ''): void
    {
        self::set_member_cookie($member_id, $email);
    }

    public static function unset_member_cookie(): void
    {
        if (!self::guardHeaders('unset member cookies')) return;

        self::deleteCookie(self::MEM_UI);
        self::deleteCookie(self::MEM_OK);
    }

    /**
     * Server-side truth for member. UI cookie is ignored for trust.
     * Provide $member_id (and optionally $email if you bind it) to validate.
     */
    public static function is_member(?string $member_id = null, string $email = ''): bool
    {
        $server = $_COOKIE[self::MEM_OK] ?? '';
        if (!$server) return false;

        if ($member_id) {
            $member_id = TFG_Utils::normalize_member_id($member_id);
            if (!$member_id) return false;

            $email = $email ? TFG_Utils::normalize_email($email) : '';
            return hash_equals(self::member_hmac($member_id, $email), $server);
        }

        return false;
    }

    /* ==========================
       Internals (DRY + safety)
       ========================== */

    private static function guardHeaders(string $what): bool
    {
        if (headers_sent($file, $line)) {
            error_log("[TFG_Cookies] Headers already sent at $file:$line; cannot $what.");
            return false;
        }
        return true;
    }

    private static function domain(): string
    {
        return (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : '';
    }

    private static function baseCookieArgs(): array
    {
        return [
            'path'     => '/',
            'domain'   => self::domain(),
            'secure'   => is_ssl(),
            'httponly' => true,     // callers may override
            'samesite' => 'Lax',    // callers may override
            'expires'  => time() + self::DAYS * DAY_IN_SECONDS,
        ];
    }

    private static function setCookie(string $name, string $value, array $overrides = []): void
    {
        $args = array_merge(self::baseCookieArgs(), $overrides);
        setcookie($name, $value, $args);
    }

    private static function deleteCookie(string $name): void
    {
        // Delete both the HttpOnly and non-HttpOnly variants to cover past sets
        $base = self::baseCookieArgs();
        $base['expires']  = time() - HOUR_IN_SECONDS;

        // HttpOnly deletion
        $args = $base;
        $args['httponly'] = true;
        setcookie($name, '', $args);

        // Non-HttpOnly deletion
        $args['httponly'] = false;
        setcookie($name, '', $args);
    }

    private static function subscriber_hmac(string $email): string
    {
        $payload = $email;
        if (self::ROTATE_MONTHLY) {
            $payload .= '|' . gmdate('Y-m'); // rotate monthly
        }
        return hash_hmac('sha256', $payload, TFG_HMAC_SECRET);
    }

    private static function member_hmac(string $member_id, string $email = ''): string
    {
        $payload = $member_id . '|' . ($email ?: '-');
        if (self::ROTATE_MONTHLY) {
            $payload .= '|' . gmdate('Y-m');
        }
        return hash_hmac('sha256', $payload, TFG_HMAC_SECRET);
    }


    public static function set_ui_cookie(string $name, string $value, int $ttl_seconds = 300): void {
    if (headers_sent($f, $l)) {
        error_log("[TFG_Cookies] Headers already sent at $f:$l; cannot set_ui_cookie($name).");
        return;
    }
    setcookie($name, $value, [
        'expires'  => time() + max(5, $ttl_seconds),
        'path'     => '/',
        'domain'   => (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : '',
        'secure'   => is_ssl(),
        'httponly' => false, // UI cookie: readable client-side if needed
        'samesite' => 'Lax',
    ]);
    }

    public static function delete_ui_cookie(string $name): void {
    if (headers_sent($f, $l)) {
        error_log("[TFG_Cookies] Headers already sent at $f:$l; cannot delete_ui_cookie($name).");
        return;
    }

    // delete with and without domain (covers legacy sets)
    foreach (['', (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : ''] as $domain) {
        setcookie($name, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => $domain,
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
    }

    /* ============================
    Legacy / compatibility getters
    (UI-only; do NOT use for auth)
    ============================ */

    /** Convenience getter for UI; may be absent if you don’t set this cookie. */
    public static function get_member_email(): ?string  {
    if (!isset($_COOKIE['member_email'])) return null;
    $raw = wp_unslash($_COOKIE['member_email']);
    return TFG_Utils::normalize_email($raw) ?: null;
    }

/** Convenience getter for UI; sanitize to a safe key. */
public static function get_member_role(): ?string  {
    if (!isset($_COOKIE['member_role'])) return null;
    $raw = wp_unslash($_COOKIE['member_role']);
    $val = sanitize_key($raw);
    return $val !== '' ? $val : null;
    }

/** Convenience getter for UI; normalize to your allowed charset. */
public static function get_member_id(): ?string  {
    if (!isset($_COOKIE['member_id'])) return null;
    $raw = wp_unslash($_COOKIE['member_id']);
    $val = TFG_Utils::normalize_member_id($raw);
    return $val !== '' ? $val : null;
    }


}
