<?php
namespace TFG\Core;

final class Utils
{
    public static function init(): void {}

    /**
     * Determine if the current request is a system call
     * (REST API, CRON, WP-CLI, AJAX, or editor autosave)
     */
    public static function isSystemRequest(): bool
        {
            // --- REST API
            if (defined('REST_REQUEST') && REST_REQUEST) {
                return true;
            }

            // --- WP-CLI
            if (\defined('WP_CLI') && \constant('WP_CLI')) {
                return true;
            }

            // --- Cron
            if (defined('DOING_CRON') && DOING_CRON) {
                return true;
            }

            // --- AJAX
            if (defined('DOING_AJAX') && DOING_AJAX) {
                return true;
            }

            // --- Autosave or Heartbeat (editor)
            if (!empty($_POST['action']) && in_array($_POST['action'], ['heartbeat', 'wp_autosave'], true)) {
                return true;
            }

            // --- JSON endpoints or Kadence autosave URIs
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            if (
                stripos($uri, '/wp-json/') !== false ||
                stripos($uri, 'wp-cron.php') !== false ||
                stripos($uri, 'wpforms/v1/') !== false ||
                stripos($uri, 'kadence_element') !== false
            ) {
                return true;
            }

            return false;
        }

          // -----------------------------------------------------------------------------
          // Lightweight internal logger with throttling
          // -----------------------------------------------------------------------------
          private static $last_log_times = [];

          public static function logOnce($message, $interval = 15)
          {
                  // Completely disable if TFG_DEBUG is off
                  if (!defined('TFG_DEBUG') || !TFG_DEBUG) return;

                  $key = md5($message);
                  $now = microtime(true);

                  if (!isset(self::$last_log_times[$key]) || ($now - self::$last_log_times[$key]) > $interval) {
                      self::$last_log_times[$key] = $now;
                      \TFG\Core\Utils::info($message);
                  }
          }

              private static $last = [];

              public static function info($msg, $interval = 15) {
                  // Disable completely in production
                  if (!\defined('TFG_DEBUG') || !\constant('TFG_DEBUG')) return;

                  $key = md5($msg);
                  $now = microtime(true);

                  if (!isset(self::$last[$key]) || ($now - self::$last[$key]) > $interval) {
                      self::$last[$key] = $now;
                      \TFG\Core\Utils::info($msg);
                  }
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
