<?php
// === class-tfg-utils.php ===
// Shared utility functions used across all TFG modules.

if (!defined('ABSPATH')) { exit; }

class TFG_Utils {

// === class-tfg-utils.php ===

    // … existing normalize_* functions etc …
    /**
     * Normalize and sanitize email for consistent comparisons.
     * - Lowercases
     * - Trims whitespace
     * - Applies sanitize_email
     */
    
    public static function normalize_member_id($id): string  {
         if (!is_string($id)) return '';
         $id = strtoupper(trim($id));
        return preg_match('/^[A-Z0-9_-]+$/', $id) ? $id : '';
        }

    public static function normalize_token(?string $v): ?string {
        $v = is_string($v) ? trim($v) : '';
        return preg_match('/^[A-Za-z0-9_-]{8,}$/', $v) ? $v : null; // base64url-ish
        }

    public static function normalize_signature(?string $v): ?string {
        $v = is_string($v) ? trim($v) : '';
        return preg_match('/^[A-Fa-f0-9]{40,128}$/', $v) ? strtolower($v) : null; // HMAC hex
        }

    public static function normalize_email(?string $v): ?string {
        $v = is_string($v) ? trim($v) : '';
        $v = strtolower($v);
        return is_email($v) ? $v : null;
        }
    


}
