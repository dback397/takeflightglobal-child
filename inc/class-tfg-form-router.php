<?php
/**
 * TFG_Form_Router
 * Lightweight utility to gate POST requests by handler ID (+ optional nonce)
 */
class TFG_Form_Router {

    /**
     * Check if the current POST matches the expected handler id.
     *
     * @param string      $expected_handler_id Slug-like id (e.g. 'newsletter')
     * @param string|null $nonce_action        Optional wp_nonce_field action to verify
     * @param string      $nonce_field         Nonce field name (default _wpnonce)
     * @return bool
     */
    public static function matches(string $expected_handler_id, ?string $nonce_action = null, string $nonce_field = '_wpnonce'): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        // Pull & normalize posted handler id
        if (!isset($_POST['handler_id'])) {
            return false;
        }
        $posted = sanitize_key( wp_unslash($_POST['handler_id']) );
        $expected = sanitize_key($expected_handler_id);
        if ($posted === '' || $expected === '' || $posted !== $expected) {
            return false;
        }

        // Optional CSRF protection
        if ($nonce_action !== null) {
            $nonce = isset($_POST[$nonce_field]) ? wp_unslash($_POST[$nonce_field]) : '';
            if (!wp_verify_nonce($nonce, $nonce_action)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convenience: fetch a POST value safely (returns null if missing).
     * @param string $key
     * @return string|null
     */
    public static function post_string(string $key): ?string
    {
        if (!isset($_POST[$key])) {
            return null;
        }
        $val = wp_unslash($_POST[$key]);
        // Adjust sanitization per field type as needed
        $val = is_string($val) ? sanitize_text_field($val) : null;
        return ($val === '') ? null : $val;
    }
}
