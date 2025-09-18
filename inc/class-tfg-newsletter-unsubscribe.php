<?php

class TFG_Newsletter_Unsubscribe {

    public static function init(): void {
        add_shortcode('tfg_unsubscribe_form', [__CLASS__, 'render_unsubscribe_form']);
        add_action('init', [__CLASS__, 'handle_unsubscribe_request']);
    }

    // === 1) Render unsubscribe form ===
    public static function render_unsubscribe_form(): string {
        $success = isset($_GET['unsubscribed']) && $_GET['unsubscribed'] === '1';
        $error   = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';

        ob_start();

        if ($success) {
            echo '<div class="tfg-success">You have been unsubscribed successfully.</div>';
        } elseif ($error) {
            echo '<div class="tfg-error">' . esc_html($error) . '</div>';
        }
        ?>
        <form method="POST" class="tfg-unsubscribe-form" action="">
            <?php wp_nonce_field('tfg_unsubscribe'); ?>
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
        return (string) ob_get_clean();
    }

    // === 2) Handle unsubscribe submission ===
    public static function handle_unsubscribe_request(): void {
        if (!class_exists('TFG_Form_Router') || !TFG_Form_Router::matches('unsubscribe')) {
            return;
        }

        // Verify nonce
        $nonce_ok = isset($_POST['_wpnonce']) && wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['_wpnonce'])),
            'tfg_unsubscribe'
        );
        if (!$nonce_ok) {
            self::redirect_with_error('Security check failed.');
        }

        // Confirm actual submit
        if (empty($_POST['submit_unsubscribe'])) {
            self::redirect_with_error('Invalid submission.');
        }

        // Normalize email
        $email = TFG_Utils::normalize_email(wp_unslash($_POST['unsubscribe_email'] ?? ''));
        if (!$email || !is_email($email)) {
            self::redirect_with_error('Missing or invalid email.');
        }

        // Find active subscriber
        $matches = get_posts([
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
            self::redirect_with_error('No active subscriber found with that email.');
        }

        $post_id = (int) $matches[0];

        // Update subscribed flag using ACF if available, else meta
        if (function_exists('update_field')) {
            update_field('is_subscribed', 0, $post_id);
        } else {
            update_post_meta($post_id, 'is_subscribed', 0);
        }

        // Expire subscriber cookies (use your helper; it already checks headers)
        if (class_exists('TFG_Cookies') && method_exists('TFG_Cookies', 'unset_subscriber_cookie')) {
            TFG_Cookies::unset_subscriber_cookie();
        }

        // Log
        if (class_exists('TFG_Log')) {
            TFG_Log::add_log_entry([
                'email'      => $email,
                'event_type' => 'newsletter_unsubscribe',
                'status'     => 'success',
                'notes'      => 'Subscriber unsubscribed via form.',
            ]);
        }

        self::redirect_success();
    }

    /* =========================
       Redirect helpers
       ========================= */

    private static function referer_or_home(): string {
        $ref = wp_get_referer();
        return $ref ? $ref : home_url('/');
    }

    private static function redirect_success(): void {
        $url = add_query_arg(['unsubscribed' => '1'], self::referer_or_home());
        if (!headers_sent()) {
            nocache_headers();
            wp_safe_redirect($url);
            exit;
        }
        error_log("[Redirect Success] Redirect failed, headers sent");
    }

    private static function redirect_with_error(string $msg): void {
        $url = add_query_arg(['error' => rawurlencode($msg)], self::referer_or_home());
        if (!headers_sent()) {
            nocache_headers();
            wp_safe_redirect($url);
            exit;
        }
        error_log("[Redirect w Error] Redirect failed, headers sent");
    }
}
