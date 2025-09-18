<?php


// === class-tfg-member-forms.php ===
class TFG_Member_Form_Utilities {

    private const NONCE_ACTION_PW = 'tfg_generic_password';
    private const NONCE_FIELD_PW  = '_tfg_pw_nonce';
    private const MIN_PW_LEN      = 8; // fallback if you don't define TFG_MIN_PASSWORD_LENGTH

    public static function init(): void {
        add_action('init', [__CLASS__, 'handle_generic_password_submission']);
    }

    /* ---------- Small render helpers (return strings) ---------- */

    public static function submit_button(string $label = 'Submit'): string {
        return '<div class="tfg-field"><button type="submit" class="tfg-submit">' . esc_html($label) . '</button></div>';
    }

    /** Fetch a member_profile post ID by user id (if you use that mapping). */
    public static function get_user_profile($user_id): ?int {
        $posts = get_posts([
            'post_type'   => 'member_profile',
            'meta_key'    => 'submitted_by_user',
            'meta_value'  => (int) $user_id,
            'post_status' => ['publish', 'pending', 'draft'],
            'numberposts' => 1,
            'fields'      => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        return $posts ? (int) $posts[0] : null;
    }

    public static function render_gdpr_agreement(int $post_id = 0): string {
        $checked = '';
        if ($post_id && function_exists('get_field')) {
            $checked = get_field('gdpr_consent', $post_id) ? 'checked' : '';
        }
        ob_start(); ?>
        <div class="tfg-field tfg-gdpr-consent">
            <label for="gdpr_consent" class="tfg-font-base">
                <input type="checkbox" id="gdpr_consent" name="gdpr_consent" value="1" <?php echo $checked; ?>>
                By checking this box, you affirm that you have read and agree to our TERMS OF USE regarding storage of the data submitted through this form.
            </label>
            <div id="gdpr-warning" style="display:none; color:red; font-size:14px;">
                You must agree to the GDPR policy before submitting.
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function whitelist_note(): string {
        ob_start(); ?>
        <div class="tfg-info-box">
            <p class="tfg-font-base">
                <strong>NOTE:</strong><br>Please whitelist the URL<br><strong>takeflightglobal.com</strong><br>in your email app.
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function insert_recaptcha(): string {
        if (!class_exists('TFG_ReCAPTCHA')) {
            return '';
        }
        $keys = TFG_ReCAPTCHA::get_keys();
        if (empty($keys['site'])) {
            return '';
        }
        ob_start(); ?>
        <div class="recaptcha-flex">
            <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($keys['site']); ?>"></div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** Optional “header card” for stub access pages. */
    public static function stub_access_header(string $email = ''): string {
        ob_start(); ?>
        <h2>Member Registration</h2>
        <div style="margin-top:1em; padding:1em; border:1px solid #ccc; border-radius:8px;">
            <div class="tfg-font-base"><strong>Member ID:</strong> <span class="tfg-font-light">Pending</span></div>
            <div class="tfg-font-base"><strong>Email:</strong> <span class="tfg-font-light"><?php echo esc_html($email); ?></span></div>
        </div>
        <div class="tfg-section-divider"></div>
        <?php
        return (string) ob_get_clean();
    }

    /* ---------- Password setup (generic) ---------- */

    public static function render_password_setup_form(int $post_id): string {
        ob_start(); ?>
        <form method="POST" class="tfg-member-login-form">
            <div class="tfg-login-row">
                <input type="hidden" name="handler_id" value="generic_password">
                <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION_PW, self::NONCE_FIELD_PW); ?>

                <div class="child-1">
                    <div class="tfg-password-wrapper">
                        <input type="password" name="institution_password" id="institution_password"
                               class="tfg-password-input tfg-font-base" placeholder="Enter Password"
                               autocomplete="new-password" required>
                        <button type="button" class="tfg-toggle-password" onclick="tfgTogglePassword()" aria-label="Show password">👁️</button>
                    </div>
                </div>

                <div class="child-2">
                    <div class="tfg-password-wrapper">
                        <input type="password" name="institution_password_confirm" id="institution_password_confirm"
                               class="tfg-password-input tfg-font-base" placeholder="Confirm Password"
                               autocomplete="new-password" required>
                        <button type="button" class="tfg-toggle-password" onclick="tfgTogglePassword()" aria-label="Show password">👁️</button>
                    </div>
                </div>

                <div class="child-3">
                    <button type="submit" name="submit_password_setup" class="tfg-login-button tfg-font-base">Save Password</button>
                </div>
            </div>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    public static function handle_generic_password_submission(): void {
        if (!TFG_Form_Router::matches('generic_password')) return;
        if (empty($_POST['submit_password_setup'])) return;

        // CSRF
        if (empty($_POST[self::NONCE_FIELD_PW]) || !wp_verify_nonce($_POST[self::NONCE_FIELD_PW], self::NONCE_ACTION_PW)) {
            error_log('[TFG GENERIC PW] Nonce verification failed.');
            return;
        }

        $post_id  = absint($_POST['post_id'] ?? 0);
        $password = (string) wp_unslash($_POST['institution_password'] ?? '');
        $confirm  = (string) wp_unslash($_POST['institution_password_confirm'] ?? '');

        if (!$post_id) {
            error_log('[TFG GENERIC PW] ❌ Missing post_id.');
            return;
        }
        if ($password !== $confirm) {
            error_log('[TFG GENERIC PW] ❌ Passwords do not match.');
            return;
        }

        $min_len = defined('TFG_MIN_PASSWORD_LENGTH') ? (int) TFG_MIN_PASSWORD_LENGTH : self::MIN_PW_LEN;
        if (strlen($password) < $min_len) {
            error_log("[TFG GENERIC PW] ❌ Password too short (min {$min_len}).");
            return;
        }

        $hash = password_hash($password, MEMBER_PASSWORD_DEFAULT);
        if (!$hash) {
            error_log('[TFG GENERIC PW] ❌ password_hash() failed.');
            return;
        }

        // Store hash on the post (ACF or meta)
        $ok = false;
        if (function_exists('update_field')) {
            $ok = (bool) update_field('institution_password_hash', $hash, $post_id);
        } else {
            $ok = (bool) update_post_meta($post_id, 'institution_password_hash', $hash);
        }

        if (!$ok) {
            error_log("[TFG GENERIC PW] ❌ Could not save password hash for post {$post_id}.");
            return;
        }

        error_log("[TFG GENERIC PW] ✅ Password set for post {$post_id}.");

        $redirect = add_query_arg('post_id', $post_id, site_url('/profile-confirmation/'));
        if (!headers_sent()) {
            nocache_headers();
            wp_safe_redirect($redirect);
            exit;
        }
    }

    /* ---------- reCAPTCHA helpers ---------- */

    /** Simple wrapper; keeps callsite stable. */
public static function validate_recaptcha(): bool
{
    $token = sanitize_text_field($_POST['g-recaptcha-response'] ?? '');

    if ($token === '') {
        error_log('[TFG reCAPTCHA] ❌ Token missing.');
        return false;
    }

    if (!class_exists('TFG_ReCAPTCHA')) {
        error_log('[TFG reCAPTCHA] ❌ TFG_ReCAPTCHA class missing.');
        return false;
    }

    $verified = (bool) TFG_ReCAPTCHA::verify($token);

    if (!$verified) {
        error_log('[TFG reCAPTCHA] ❌ Verification failed.');
    } else {
        error_log('[TFG reCAPTCHA] ✅ Verification passed.');
    }

    return $verified;
}


    /** Example generic handler (unused by default, kept for reference). */
    public static function tfg_handle_form_submission(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

        // reCAPTCHA (optional)
        if (!self::validate_recaptcha()) {
            echo 'reCAPTCHA failed. Please try again.';
            return;
        }

        $name  = sanitize_text_field((string) ($_POST['name']  ?? ''));
        $email = TFG_Utils::normalize_email((string) ($_POST['email'] ?? ''));

        // ... your form processing logic here ...
    }
}
