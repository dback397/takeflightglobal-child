<?php
namespace TFG\Core;

final class Utils
{
    public static function init(): void {}

    /**
     * Determine if the current request is a system call
     * (REST API, CRON, WP-CLI, AJAX, or editor autosave)
     */
    public static function is_system_request(): bool
    {
        // Avoid namespace conflicts
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        if (
            (\defined('REST_REQUEST') && \constant('REST_REQUEST')) ||
            (\defined('DOING_CRON') && \constant('DOING_CRON')) ||
            (\defined('WP_CLI') && \constant('WP_CLI')) ||
            \strpos($uri, '/wp-json/') !== false ||
            \strpos($uri, '/wp-admin/admin-ajax.php') !== false ||
            \strpos($uri, 'wp-cron.php') !== false
        ) {
            \error_log('[TFG Utils] Detected system request (REST, CRON, CLI, AJAX)');
            return true;
        }

        return false;
    }

    /**
     * Normalize member ID (uppercase, trimmed, A–Z/0–9/_/- only).
     */
    public static function normalizeMemberId($id): string
    {
        if (!\is_string($id)) return '';
        $id = \strtoupper(\trim($id));
        return \preg_match('/^[A-Z0-9_-]+$/', $id) ? $id : '';
    }

    /**
     * Normalize token (trim, base64url-ish, >= 8 chars).
     */
    public static function normalizeToken(?string $v): ?string
    {
        $v = \is_string($v) ? \trim($v) : '';
        return \preg_match('/^[A-Za-z0-9_-]{8,}$/', $v) ? $v : null;
    }

    /**
     * Normalize signature (trim, hex length 40–128, lowercased).
     */
    public static function normalizeSignature(?string $v): ?string
    {
        $v = \is_string($v) ? \trim($v) : '';
        return \preg_match('/^[A-Fa-f0-9]{40,128}$/', $v) ? \strtolower($v) : null;
    }

    /**
     * Normalize email (trim, lowercase, validate via is_email()).
     */
    public static function normalizeEmail(?string $v): ?string
    {
        $v = \is_string($v) ? \trim($v) : '';
        $v = \strtolower($v);
        return \is_email($v) ? $v : null;
    }

}
