<?php

namespace TFG\Core;

/**
 * ReCAPTCHA helper
 *
 * Usage (new namespace):
 *   \TFG\Validation\ReCAPTCHA::get_keys()['site']
 *   \TFG\Validation\ReCAPTCHA::verify($token)
 *
 * Legacy usage still works via class_alias at bottom:
 *   TFG_ReCAPTCHA::get_keys()
 *   TFG_ReCAPTCHA::verify($token)
 */
final class ReCAPTCHA
{
    /**
     * Return site & secret keys from constants (if defined).
     *
     * @return array{site:string,secret:string}
     */
    public static function get_keys(): array
    {
        return [
            'site'   => \defined('RECAPTCHA_SITE_KEY')   ? (string) \RECAPTCHA_SITE_KEY   : '',
            'secret' => \defined('RECAPTCHA_SECRET_KEY') ? (string) \RECAPTCHA_SECRET_KEY : '',
        ];
    }

    /**
     * Verify a frontend token with Google's API.
     *
     * @param string $token
     * @return bool
     */
    public static function verify(string $token): bool
    {
        $token = \trim($token);
        if ($token === '') {
            return false;
        }

        $keys   = self::get_keys();
        $secret = $keys['secret'];
        if ($secret === '') {
            // Fail closed if not configured
            return false;
        }

        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        $resp = \wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'body' => [
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $remote_ip,
            ],
            'timeout' => 10,
        ]);

        if (\is_wp_error($resp)) {
            return false;
        }

        $body = \wp_remote_retrieve_body($resp);
        if (!\is_string($body) || $body === '') {
            return false;
        }

        $data = \json_decode($body, true);
        return !empty($data['success']);
    }
}

/** ---- Legacy alias for back-compat (old global class name) ---- */
\class_alias(\TFG\Core\ReCAPTCHA::class, 'TFG_ReCAPTCHA');
