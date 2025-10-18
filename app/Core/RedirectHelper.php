<?php

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
        // Don't interfere with WordPress admin login
        if (\is_admin() || \strpos($_SERVER['REQUEST_URI'] ?? '', '/wp-login.php') !== false) {
            \error_log('[TFG RedirectHelper] Skipping redirect - admin area or wp-login.php');
            return;
        }
        
        if (\headers_sent()) {
            \error_log('[TFG RedirectHelper] Headers already sent, cannot redirect to: ' . $url);
            return;
        }

        $current_url = self::getCurrentUrl();
        $target_url = self::normalizeUrl($url);

        // Check for redirect loop
        if (self::isRedirectLoop($current_url, $target_url)) {
            \error_log('[TFG RedirectHelper] Redirect loop detected from ' . $current_url . ' to ' . $target_url);
            return;
        }

        // Record this redirect attempt
        self::recordRedirect($current_url, $target_url);

        \error_log('[TFG RedirectHelper] Redirecting from ' . $current_url . ' to ' . $target_url);
        \nocache_headers();
        \wp_safe_redirect($target_url, $status_code);
        exit;
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
