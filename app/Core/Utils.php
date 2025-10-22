<?php
namespace TFG\Core;

final class Utils
{
    public static function init(): void {}

    /**
     * Determine if the current request is a system call
     * (REST API, CRON, WP-CLI, AJAX, or editor autosave)
     */
    public static function isSystemRequest(bool $log = false): bool
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        $path = \wp_parse_url($uri, PHP_URL_PATH) ?? '';

        // True system conditions
        $is_system =
            (\defined('REST_REQUEST') && \constant('REST_REQUEST')) ||
            (\defined('DOING_CRON') && \constant('DOING_CRON')) ||
            (\defined('WP_CLI') && \constant('WP_CLI')) ||
            \strpos($path, '/wp-json/') !== false ||
            \strpos($path, '/wp-admin/admin-ajax.php') !== false ||
            \strpos($path, '/wp-cron.php') !== false;

        // Skip WordPress heartbeat and autosave requests too
        if (\strpos($path, 'heartbeat') !== false || \strpos($path, 'autosave') !== false) {
            $is_system = true;
        }

        // DO NOT treat front-end POSTs as system
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !\is_admin()) {
            $is_system = false;
        }

        if ($is_system && $log) {
            \error_log('[TFG Utils] Detected system request (REST, CRON, CLI, AJAX)');
        }

        return $is_system;
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
