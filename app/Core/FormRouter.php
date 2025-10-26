<?php

namespace TFG\Core;

/**
 * Lightweight utility to gate POST requests by handler ID (+ optional nonce)
 */
final class FormRouter
{
    /**
     * Check if the current POST matches the expected handler id.
     *
     * @param string      $expected_handler_id Slug-like id (e.g. 'newsletter')
     * @param string|null $nonce_action        Optional wp_nonce_field action to verify
     * @param string      $nonce_field         Nonce field name (default _wpnonce)
     */
    public static function matches(string $expected_handler_id, ?string $nonce_action = null, string $nonce_field = '_wpnonce'): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        if (!isset($_POST['handler_id'])) {
            return false;
        }

        $posted   = \sanitize_key(\wp_unslash($_POST['handler_id']));
        $expected = \sanitize_key($expected_handler_id);

        if ($posted === '' || $expected === '' || $posted !== $expected) {
            return false;
        }

        // Optional CSRF protection
        if ($nonce_action !== null) {
            $nonce = isset($_POST[$nonce_field]) ? \wp_unslash($_POST[$nonce_field]) : '';
            if (!\wp_verify_nonce($nonce, $nonce_action)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convenience: fetch a POST value safely (returns null if missing).
     */
    public static function postString(string $key): ?string
    {
        if (!isset($_POST[$key])) {
            return null;
        }
        $val = \wp_unslash($_POST[$key]);
        $val = \is_string($val) ? \sanitize_text_field($val) : null;
        return ($val === '') ? null : $val;
    }

}

/* ---- Legacy class alias (remove once all references are updated) ---- */
\class_alias(\TFG\Core\FormRouter::class, 'TFG_Form_Router');
