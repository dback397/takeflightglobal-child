<?php
namespace TFG\Features\Newsletter;

use TFG\Core\FormRouter;
use TFG\Core\Utils;
use TFG\Core\Cookies;
use TFG\Core\Log;

final class NewsletterUnsubscribe
{
    public static function init(): void
    {
        \add_shortcode('tfg_unsubscribe_form', [self::class, 'renderUnsubscribeForm']);
        \add_action('init', [self::class, 'handleUnsubscribeRequest']);
    }

    // === 1) Render unsubscribe form ===
    public static function renderUnsubscribeForm(): string
    {
        $success = isset($_GET['unsubscribed']) && $_GET['unsubscribed'] === '1';
        $error   = isset($_GET['error']) ? \sanitize_text_field(\wp_unslash($_GET['error'])) : '';

        \ob_start();

        if ($success) {
            echo '<div class="tfg-success">You have been unsubscribed successfully.</div>';
        } elseif ($error) {
            echo '<div class="tfg-error">' . \esc_html($error) . '</div>';
        }
        ?>
        <form method="POST" class="tfg-unsubscribe-form" action="">
            <?php \wp_nonce_field('tfg_unsubscribe'); ?>
            <div style="display:flex;flex-direction:column;gap:1em;max-width:500px;margin:auto;">

                <input type="hidden" name="handler_id" value="unsubscribe">

                <label for="unsubscribe_email" class="tfg-font-base">
                    <strong>Email <span class="tfg-required">*</span></strong>
                </label>
                <input type="email"
                       id="unsubscribe_email"
                       name="unsubscribe_email"
                       placeholder="Your Email"
                       class="tfg-input tfg-font-base"
                       autocomplete="email"
                       required>

                <button type="submit"
                        name="submit_unsubscribe"
                        value="1"
                        class="tfg-button tfg-font-base">
                    Unsubscribe
                </button>
            </div>
        </form>
        <?php
        return (string) \ob_get_clean();
    }

    // === 2) Handle unsubscribe submission ===
    public static function handleUnsubscribeRequest(): void
    {
        if (!\class_exists(FormRouter::class) || !FormRouter::matches('unsubscribe')) {
            return;
        }

        // Verify nonce
        $nonce_ok = isset($_POST['_wpnonce']) && \wp_verify_nonce(
            \sanitize_text_field(\wp_unslash($_POST['_wpnonce'])),
            'tfg_unsubscribe'
        );
        if (!$nonce_ok) {
            self::redirectWithError('Security check failed.');
        }

        // Confirm actual submit
        if (empty($_POST['submit_unsubscribe'])) {
            self::redirectWithError('Invalid submission.');
        }

        // Normalize email
        $email = Utils::normalizeEmail(\wp_unslash($_POST['unsubscribe_email'] ?? ''));
        if (!$email || !\is_email($email)) {
            self::redirectWithError('Missing or invalid email.');
        }

        // Find active subscriber
        $matches = \get_posts([
            'post_type'        => 'subscriber',
            'posts_per_page'   => 1,
            'post_status'      => 'publish',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'fields'           => 'ids',
            'meta_query'       => [
                ['key' => 'email',         'value' => $email, 'compare' => '='],
                ['key' => 'is_subscribed', 'value' => 1,      'compare' => '=', 'type' => 'NUMERIC'],
            ],
        ]);

        if (!$matches) {
            self::redirectWithError('No active subscriber found with that email.');
        }

        $post_id = (int) $matches[0];

        // Update subscribed flag using ACF if available, else meta
        if (\function_exists('update_field')) {
            \update_field('is_subscribed', 0, $post_id);
        } else {
            \update_post_meta($post_id, 'is_subscribed', 0);
        }

        // Expire subscriber cookies
        if (\class_exists(Cookies::class) && \method_exists(Cookies::class, 'unset_subscriber_cookie')) {
            Cookies::unsetSubscriberCookie();
        }

        // Log
        if (\class_exists(Log::class)) {
            Log::addLogEntry([
                'email'      => $email,
                'event_type' => 'newsletter_unsubscribe',
                'status'     => 'success',
                'notes'      => 'Subscriber unsubscribed via form.',
            ]);
        }

        self::redirectSuccess();
    }

    /* =========================
       Redirect helpers
       ========================= */

    private static function refererOrHome(): string
    {
        $ref = \wp_get_referer();
        return $ref ? $ref : \home_url('/');
    }

    private static function redirectSuccess(): void
    {
        $url = \add_query_arg(['unsubscribed' => '1'], self::refererOrHome());
        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect($url);
            exit;
        }
        \TFG\Core\Utils::info("[Redirect Success] Redirect failed, headers sent");
    }

    private static function redirectWithError(string $msg): void
    {
        $url = \add_query_arg(['error' => \rawurlencode($msg)], self::refererOrHome());
        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect($url);
            exit;
        }
        \TFG\Core\Utils::info("[Redirect w Error] Redirect failed, headers sent");
    }
}

// Legacy alias for back-compat
\class_alias(\TFG\Features\Newsletter\NewsletterUnsubscribe::class, 'TFG_Newsletter_Unsubscribe');
