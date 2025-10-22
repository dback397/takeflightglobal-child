<?php
// ✅ TFG System Guard injected by Cursor – prevents REST/CRON/CLI/AJAX interference

namespace TFG\Core;

/**
 * Centralized redirect helper to prevent redirect loops
 */
final class RedirectHelper
{
    private static array $redirect_history = [];
    private const MAX_REDIRECTS = 3;
    private const REDIRECT_TIMEOUT = 5; // seconds

    /**
     * Safe redirect with loop prevention
     */
    public static function safeRedirect(string $url, int $status_code = 302): void
    {
        // --- 1. Guard against system or hybrid requests
        if (\TFG\Core\Utils::isSystemRequest()) {
            \TFG\Core\Utils::info('[TFG RedirectHelper] 🛡 Skipping redirect to ' . $url . ' (REST/CRON/CLI/AJAX context)');
            return;
        }

        // --- 2. Detect WP autosave / heartbeat
        $action = $_POST['action'] ?? '';
        if (\in_array($action, ['heartbeat', 'wp_autosave'], true)) {
            \TFG\Core\Utils::info('[TFG RedirectHelper] 🛡 Skipping redirect — autosave or heartbeat context');
            return;
        }

        // --- 3. Don’t interfere with wp-admin or login areas
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (\is_admin() || \strpos($uri, '/wp-login.php') !== false) {
            \TFG\Core\Utils::info('[TFG RedirectHelper] Skipping redirect — admin or login context');
            return;
        }

        // --- 4. Bail if headers already sent
        if (\headers_sent()) {
            \TFG\Core\Utils::info('[TFG RedirectHelper] ⚠️ Headers already sent, cannot redirect to: ' . $url);
            return;
        }

        // --- 5. Get URLs
        $current_url = self::getCurrentUrl();
        $target_url  = self::normalizeUrl($url);

        // --- 6. Prevent redirect loops
        if (self::isRedirectLoop($current_url, $target_url)) {
            \TFG\Core\Utils::info('[TFG RedirectHelper] ⚠️ Redirect loop prevented (' . $current_url . ' → ' . $target_url . ')');
            return;
        }

        // --- 7. Record the redirect
        self::recordRedirect($current_url, $target_url);

        // --- 8. Perform redirect only in true browser contexts
        $from = (isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']))
            ? ('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'])
            : '[unknown origin]';

        \TFG\Core\Utils::info('[TFG RedirectHelper] 🚀 Redirecting from ' . $from . ' to ' . $target_url);

        // --- Silent mode for CLI/CRON even if reached here accidentally
        if (\defined('WP_CLI') && \constant('WP_CLI')) {
            \TFG\Core\Utils::info('[TFG RedirectHelper] 🛑 Suppressed redirect in WP-CLI');
            return;
        }

        \nocache_headers();
        \wp_safe_redirect($target_url, $status_code);

        // --- 9. Exit only in HTTP context
        if (php_sapi_name() !== 'cli') {
            exit;
        }
    }

    /**
     * Check if a redirect would create a loop
     */
    public static function isRedirectLoop(string $from_url, string $to_url): bool
    {
        // Same URL check
        if ($from_url === $to_url) {
            return true;
        }

        // Check if we're already on the target page
        if (\strpos($from_url, \parse_url($to_url, PHP_URL_PATH)) !== false) {
            return true;
        }

        // Check redirect history for loops
        $redirect_key = $from_url . ' -> ' . $to_url;
        if (isset(self::$redirect_history[$redirect_key])) {
            $last_redirect = self::$redirect_history[$redirect_key];
            if ((time() - $last_redirect) < self::REDIRECT_TIMEOUT) {
                return true;
            }
        }

        // Check if we've exceeded max redirects
        if (\count(self::$redirect_history) >= self::MAX_REDIRECTS) {
            return true;
        }

        return false;
    }

    /**
     * Record a redirect attempt
     */
    private static function recordRedirect(string $from_url, string $to_url): void
    {
        $redirect_key = $from_url . ' -> ' . $to_url;
        self::$redirect_history[$redirect_key] = time();

        // Clean old redirects
        foreach (self::$redirect_history as $key => $timestamp) {
            if ((time() - $timestamp) > self::REDIRECT_TIMEOUT) {
                unset(self::$redirect_history[$key]);
            }
        }
    }

    /**
     * Get current URL
     */
    private static function getCurrentUrl(): string
    {
        return \home_url($_SERVER['REQUEST_URI'] ?? '/');
    }

    /**
     * Normalize URL for comparison
     */
    private static function normalizeUrl(string $url): string
    {
        $parsed = \parse_url($url);
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        return $path . $query;
    }

    /**
     * Check if user is already on a specific page
     */
    public static function isOnPage(string $page_path): bool
    {
        $current_path = \parse_url(self::getCurrentUrl(), PHP_URL_PATH);
        return \strpos($current_path, $page_path) !== false;
    }

    /**
     * Get redirect history for debugging
     */
    public static function getRedirectHistory(): array
    {
        return self::$redirect_history;
    }

    /**
     * Clear redirect history
     */
    public static function clearHistory(): void
    {
        self::$redirect_history = [];
    }
}
