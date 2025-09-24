<?php

namespace TFG\Features\MagicLogin;

use TFG\Core\FormRouter;
use TFG\Core\Utils;
use TFG\Core\Cookies;
use TFG\Features\Newsletter\SubscriberUtilities;

/**
 * Renders and processes the magic login form (send link only).
 *
 * Shortcode: [tfg_magic_login]
 */
final class MagicLogin
{
    public static function init(): void
    {
        (new self())->registerHooks();
    }

    private function registerHooks(): void
    {
        \add_shortcode('tfg_magic_login', [self::class, 'magicLogin']);
    }

    /** Shortcode entry: render form or handle submission. */
    public static function magicLogin(): string
    {
        if (FormRouter::matches('magic_login', 'tfg_magic_login_submit')) {
            self::handleSubmit();
            return '';
        }
        return self::renderMagicLoginForm();
    }

    /** Render the magic login form. */
    public static function renderMagicLoginForm(): string
    {
        $is_subscribed = (isset($_COOKIE['is_subscribed']) && $_COOKIE['is_subscribed'] === '1');

        $method        = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $was_submitted = ($method === 'POST' && isset($_POST['magic_email']));

        // Prefer posted redirect if present; otherwise current request URI
        if ($was_submitted && isset($_POST['redirect'])) {
            $redirect = \esc_url_raw(\wp_unslash($_POST['redirect']));
        } else {
            $redirect = \esc_url_raw($_SERVER['REQUEST_URI'] ?? \home_url('/'));
        }

        \ob_start(); ?>
        <div style="display:flex;justify-content:center;text-align:center;">
            <form method="post"
                  action=""
                  class="revalidate-form"
                  style="max-width:400px;width:100%;display:flex;flex-wrap:nowrap;align-items:stretch;">
                <?php \wp_nonce_field('tfg_magic_login_submit'); ?>
                <input type="hidden" name="handler_id" value="magic_login">
                <input type="hidden" name="redirect" value="<?php echo \esc_attr($redirect); ?>">

                <!-- Email input -->
                <input type="email"
                       name="magic_email"
                       id="magic_email"
                       required
                       autocomplete="email"
                       placeholder="Enter your email..."
                       style="
                           flex:1;
                           font-size:1rem;
                           padding:0.5em 0.75em;
                           border:1px solid #ccc;
                           border-right:none;
                           border-radius:5px 0 0 5px;
                           outline:none;">

                <!-- Submit button -->
                <button type="submit"
                        title="Send magic link"
                        style="
                           width:3em;
                           font-size:1.2rem;
                           background-color: <?php echo $is_subscribed ? '#28a745' : '#0E94FF'; ?>;
                           color:#fff;
                           border:1px solid <?php echo $is_subscribed ? '#28a745' : '#0E94FF'; ?>;
                           border-left:none;
                           border-radius:0 5px 5px 0;
                           cursor:pointer;
                           display:flex;
                           align-items:center;
                           justify-content:center;
                           transition:background-color .3s ease;"
                        onmouseover="this.style.backgroundColor='<?php echo $is_subscribed ? '#218838' : '#295CFF'; ?>'"
                        onmouseout="this.style.backgroundColor='<?php echo $is_subscribed ? '#28a745' : '#0E94FF'; ?>'">
                    🪄
                </button>
            </form>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    /** Handle POST: validate email, (optionally) require verified subscriber, create+send magic link. */
    private static function handleSubmit(): void
    {
        $email = Utils::normalizeEmail($_POST['magic_email'] ?? '');
        if (!$email || !\is_email($email)) {
            \error_log('[Magic Login] Invalid email format.');
            return;
        }

        // (Optional) Require verified subscriber status before sending login link
        $isVerified = true;
        if (\class_exists('\TFG\Features\Membership\SubscriberUtilities') &&
            \method_exists('\TFG\Features\Membership\SubscriberUtilities', 'isVerifiedSubscriber')) {
            $isVerified = SubscriberUtilities::isVerifiedSubscriber($email);
        } elseif (\class_exists('TFG_Subscriber_Utilities') &&
                  \method_exists('TFG_Subscriber_Utilities', 'is_verified_subscriber')) {
            // legacy fallback
            $isVerified = SubscriberUtilities::isVerifiedSubscriber($email);
        }

        if (!$isVerified) {
            \error_log('[Magic Login] Email not verified: ' . $email);

            // Prefill cookie for signup form
            Cookies::setUiCookie('tfg_prefill_email', $email, 600);

            $signup_url = \apply_filters('tfg_magic_login_signup_url', \home_url('/newsletter-signup'));
            if (!\headers_sent()) {
                \nocache_headers();
                \wp_safe_redirect($signup_url);
                exit;
            }
            \error_log("[Magic Login] Verification failed (headers already sent)");
            return;
        }

        $expires_in = (int) \apply_filters('tfg_magic_login_expires_in', 15 * MINUTE_IN_SECONDS);

        // Create magic token (prefer new API, fallback to legacy)
        $magic = null;
        if (\class_exists(__NAMESPACE__ . '\\MagicUtilities') &&
            \method_exists(__NAMESPACE__ . '\\MagicUtilities', 'createMagicToken')) {
            $magic = MagicUtilities::createMagicToken($email, ['expires_in' => $expires_in]);
        } elseif (\class_exists('TFG_Magic_Utilities') &&
                  \method_exists('TFG_Magic_Utilities', 'create_magic_token')) {
            $magic = MagicUtilities::createMagicToken($email, ['expires_in' => $expires_in]);
        }

        $magic_url     = \is_array($magic) ? (string) ($magic['url']     ?? '') : '';
        $magic_post_id = \is_array($magic) ? (int)    ($magic['post_id'] ?? 0)  : 0;

        if ($magic_url === '' || $magic_post_id === 0) {
            \error_log('[Magic Login] Failed to create magic token for ' . $email);
            return;
        }

        // OPTIONAL: copy verification_code from subscriber to magic token meta
        $subs = \get_posts([
            'post_type'        => 'subscriber',
            'posts_per_page'   => 1,
            'post_status'      => 'publish',
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'meta_query'       => [[ 'key' => 'email', 'value' => $email, 'compare' => '=' ]],
        ]);
        if ($subs) {
            $vc = \function_exists('get_field')
                ? (string) (\get_field('verification_code', $subs[0]) ?: '')
                : (string) \get_post_meta($subs[0], 'verification_code', true);

            if ($vc !== '') {
                \update_post_meta($magic_post_id, 'verification_code', $vc);
            }
        }

        // Send link (prefer new API, fallback to legacy). Correct arg order: (url, email).
        $sent = false;
        if (\class_exists(__NAMESPACE__ . '\\MagicUtilities') &&
            \method_exists(__NAMESPACE__ . '\\MagicUtilities', 'sendMagicLink')) {
            $sent = MagicUtilities::sendMagicLink($magic_url, $email);
        } elseif (\class_exists('TFG_Magic_Utilities') &&
                  \method_exists('TFG_Magic_Utilities', 'send_magic_link')) {
            $sent = MagicUtilities::sendMagicLink($magic_url, $email);
        }

        if (!$sent) {
            \error_log('[Magic Login] wp_mail() failed for ' . $email);
            return;
        }

        \error_log('[Magic Login] Link sent');
    }
}

/* ---- Legacy alias for transition (remove when old references are gone) ---- */
\class_alias(\TFG\Features\MagicLogin\MagicLogin::class, 'TFG_Magic_Login');
