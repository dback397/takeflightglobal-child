<?php
/**
 * ==========================================================
 * TFG_Magic_Login
 * Renders and processes the magic login form (send link only)
 * ==========================================================
 *
 * 🔐 Gatekeeping
 * - POST only, via TFG_Form_Router::matches('magic_login', 'tfg_magic_login_submit')
 * - Hidden field: <input type="hidden" name="handler_id" value="magic_login">
 * - Nonce: wp_nonce_field('tfg_magic_login_submit')
 *
 * 📨 Flow
 * - Validates email
 * - Optional check: verified subscriber via TFG_Subscriber_Utilities
 * - Creates magic token + emails signed link
 *
 * ⚠️ Clicking the link is handled by TFG_Magic_Handler (verification + redirect)
 */

class TFG_Magic_Login {

    public static function init(): void {
        (new self())->register_hooks();
    }

    public function register_hooks(): void {
        add_shortcode('tfg_magic_login', [self::class, 'magic_login']);
    }

    /**
     * Shortcode entry: render form or handle submission.
     */
    public static function magic_login(): string {
        if (class_exists('TFG_Form_Router') && TFG_Form_Router::matches('magic_login', 'tfg_magic_login_submit')) {
            return self::handle_submit();
        }
        return self::render_magic_login_form();
    }

    /**
     * Render the magic login form.
     */
    public static function render_magic_login_form(): string {
        $is_subscribed = (isset($_COOKIE['is_subscribed']) && $_COOKIE['is_subscribed'] === '1');

        $method        = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $was_submitted = ($method === 'POST' && isset($_POST['magic_email']));

        // Prefer posted redirect if present; otherwise current request URI
        if ($was_submitted && isset($_POST['redirect'])) {
            $redirect = esc_url_raw(wp_unslash($_POST['redirect']));
        } else {
            $redirect = esc_url_raw($_SERVER['REQUEST_URI'] ?? home_url('/'));
        }

        ob_start(); ?>
        <div style="display:flex;justify-content:center;text-align:center;">
            <form method="post"
                  action=""
                  class="revalidate-form"
                  style="max-width:400px;width:100%;display:flex;flex-wrap:nowrap;align-items:stretch;">
                <?php wp_nonce_field('tfg_magic_login_submit'); ?>
                <input type="hidden" name="handler_id" value="magic_login">
                <input type="hidden" name="redirect" value="<?php echo esc_attr($redirect); ?>">

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
        return (string) ob_get_clean();
    }

    /**
     * Handle POST: validate email, gate by subscriber status, create+send magic link.
     */
    private static function handle_submit() {
        $email = TFG_Utils::normalize_email($_POST['magic_email'] ?? '');
        $out   = '';

        if (!is_email($email)) {
            error_log('[Magic Login] Invalid email format.');
            return;
        }

        // (Optional) Require verified subscriber status before sending login link
        if (class_exists('TFG_Subscriber_Utilities') && method_exists('TFG_Subscriber_Utilities', 'is_verified_subscriber')) {
            if (!TFG_Subscriber_Utilities::is_verified_subscriber($email)) {
                // Set prefill cookie for the newsletter form and redirect there
                error_log('[Magic Login] Email not verified: ' . $email);

                if (class_exists('TFG_Cookies') && method_exists('TFG_Cookies', 'set_ui_cookie')) {
                    TFG_Cookies::set_ui_cookie('tfg_prefill_email', $email, 600); // 10 min
                }

                // Allow customization of the target signup URL
                $signup_url = apply_filters('tfg_magic_login_signup_url', home_url('/newsletter-signup'));

                if (!headers_sent()) {
                    nocache_headers();
                    wp_safe_redirect($signup_url);
                    exit;
                }

                // Headers already sent — show a friendly message and re-render
                error_log("[Magic Login] Verification failed");
                return;
            }
        }

        // Create a magic token (signed link)
        $expires_in = (int) apply_filters('tfg_magic_login_expires_in', 15 * MINUTE_IN_SECONDS);

$magic = TFG_Magic_Utilities::create_magic_token($email, [
    'expires_in' => $expires_in,
]);

$magic_url     = is_array($magic) ? (string)($magic['url']     ?? '') : '';
$magic_post_id = is_array($magic) ? (int)   ($magic['post_id'] ?? 0)  : 0;

if ($magic_url === '' || $magic_post_id === 0) {
    error_log('[Magic Login] Failed to create magic token for ' . $email);
    return;
}

/**
 * OPTIONAL: If you want the VT verification_code duplicated into the magic token CPT here too,
 * we’ll pull it off the subscriber record (if it exists). This does NOT put it in the URL.
 */
$subs = get_posts([
    'post_type'        => 'subscriber',
    'posts_per_page'   => 1,
    'post_status'      => 'publish',
    'fields'           => 'ids',
    'suppress_filters' => true,
    'no_found_rows'    => true,
    'meta_query'       => [[ 'key' => 'email', 'value' => $email, 'compare' => '=' ]],
]);
if ($subs) {
    $vc = function_exists('get_field')
        ? (string) (get_field('verification_code', $subs[0]) ?: '')
        : (string) get_post_meta($subs[0], 'verification_code', true);

    if ($vc !== '') {
        update_post_meta($magic_post_id, 'verification_code', $vc);
    }
}

// SEND ONCE (correct arg order)
$sent = TFG_Magic_Utilities::send_magic_link($magic_url, $email);
if (!$sent) {
    error_log('[Magic Login] wp_mail() failed for ' . $email);
    return;
}

error_log('[Magic Login] Link sent');
return;

}
}