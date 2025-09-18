<?php
// === class-tfg-recaptcha.php ===
/* 
* Use:
* TFG_ReCAPTCHA::get_keys()['site'] in your form rendering
* TFG_ReCAPTCHA::get_keys()['secret'] in your backend validation
*/

class TFG_ReCAPTCHA {

    /**
     * Returns the appropriate site and secret keys based on environment.
     */
    public static function get_keys() {
        $host = $_SERVER['HTTP_HOST'];
        $is_local = strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;

        return [
        'site'   => defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '',
        'secret' => defined('RECAPTCHA_SECRET_KEY') ? RECAPTCHA_SECRET_KEY : ''
    ];
    }

    /**
     * Verifies the reCAPTCHA response token with Google API.
     *
     * @param string $token The token submitted from the frontend.
     * @return bool True if verified, false otherwise.
     */
    public static function verify($token) {
        if (empty($token)) {
            return false;
        }

        $keys = self::get_keys();
        $secret = $keys['secret'];

        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'body' => [
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'],
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return !empty($data['success']);
    }
}

