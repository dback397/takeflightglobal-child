<?php
// ✅ TFG System Guard injected by Cursor – prevents REST/CRON/CLI/AJAX interference

namespace TFG\Features\Membership;

use TFG\Core\Cookies;
use TFG\Core\Utils;
use TFG\Core\FormRouter;
use TFG\Core\RedirectHelper;
// use TFG\Core\Mailer; // optional if you email the member ID

/**
 * Member login, reset, and ID recovery workflow.
 */
final class MemberLogin
{
    public static function init(): void
    {
        // Shortcodes
        \add_shortcode('tfg_member_login_form',     [self::class, 'renderLoginForm']);
        \add_shortcode('tfg_member_reset_request',  [self::class, 'renderResetRequestForm']); // identity check
        \add_shortcode('tfg_member_reset_form',     [self::class, 'renderPasswordResetForm']); // requires cookie
        \add_shortcode('tfg_forgot_member_id_form', [self::class, 'renderForgotMemberIdForm']);

        // Handlers
        \add_action('init', [self::class, 'handleLogin']);
        \add_action('init', [self::class, 'handleResetRequest']);   // identity check
        \add_action('init', [self::class, 'handlePasswordReset']);  // write new password
        \add_action('init', [self::class, 'handleForgotMemberId']);

        // Keep logged-in members out of the login page
        \add_action('templateRedirect', function () {
            // 🧩 Skip all system or admin contexts
            if (
              \is_admin() ||
              \is_user_logged_in() ||
              \strpos($_SERVER['REQUEST_URI'] ?? '', '/wp-login.php') !== false ||
              Utils::isSystemRequest()
            ) {
              \error_log('[TFG MemberLogin] Skipping template_redirect - admin/logged in/wp-login.php/system request');
               return;
            }

            // Normal login-page redirection logic
            if ((\is_page('member-login')) && !Utils::isSystemRequest()) {
              \error_log('[TFG MemberLogin] On member-login page, checking for member cookie');
              $member_id = Cookies::getMemberId();
              if (!empty($member_id) && !\headers_sent()) {
                  $dashboard_url = \site_url('/member-dashboard/');
                  if (RedirectHelper::isOnPage('/member-dashboard')) {
                      \error_log('[TFG MemberLogin] Redirect loop prevented: already on dashboard');
                      return;
                  }
                  \error_log('[TFG MemberLogin] Redirecting logged-in member to dashboard');
                  RedirectHelper::safeRedirect($dashboard_url);
              }
          }
        });
    }

    /* ===========================
       LOGIN FORM + HANDLER
       =========================== */

    public static function renderLoginForm(): string
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \error_log('[TFG SystemGuard] Skipped renderLoginForm due to REST/CRON/CLI/AJAX context');
            return '<p>System request - login form not available.</p>';
        }

        if (Cookies::getMemberId() && !\headers_sent()) {
            // Use RedirectHelper to prevent loops
            $dashboard_url = \site_url('/member-dashboard/');

            if (RedirectHelper::isOnPage('/member-dashboard')) {
                \error_log('[TFG MemberLogin] Redirect loop prevented in renderLoginForm: already on dashboard');
                return '<p>You are already logged in and on the dashboard.</p>';
            }

            \error_log('[TFG MemberLogin] Redirecting logged-in member to dashboard from form');
            RedirectHelper::safeRedirect($dashboard_url);
        }

        $is_member_ui = (bool) Cookies::getMemberId();
        $status_text  = $is_member_ui ? 'Logged in' : 'Not logged in';
        $status_color = $is_member_ui ? 'green' : 'red';

        \ob_start(); ?>
        <form method="POST" class="tfg-member-login-form">
            <?php \wp_nonce_field('tfg_member_login', '_tfg_nonce'); ?>
            <div class="tfg-login-row">
                <input type="hidden" name="handler_id" value="member_login">

                <div class="child-1">
                    <input type="text" name="member_id" placeholder="Member ID"
                           class="tfg-id-input tfg-font-base" autocomplete="off" required>
                </div>

                <div class="child-2">
                    <div class="tfg-password-wrapper">
                        <input type="password" name="member_password" id="tfg_member_password"
                               class="tfg-password-input tfg-font-base" placeholder="Password"
                               autocomplete="current-password" required>
                    </div>
                </div>

                <div class="child-3">
                    <button type="submit" name="tfg_member_login_submit" value="1"
                            class="tfg-login-button tfg-font-base">Login</button>
                </div>
            </div>
        </form>

        <p style="margin-top:1em; font-weight:bold; color:<?php echo \esc_attr($status_color); ?>">
            Current Login Status: <?php echo \esc_html($status_text); ?>
        </p>

        <hr style="margin:2em 0; border:none; border-top:1px solid #ccc;">

        <div class="tfg-member-menu-row" style="display:flex; gap:1em; justify-content:space-between; flex-wrap:wrap;">
            <a class="tfg-button" href="<?php echo \esc_url(\site_url('/reset-password')); ?>" style="flex:1; text-align:center;">Reset Your Password</a>
            <a class="tfg-button" href="<?php echo \esc_url(\site_url('/forgot-member-id')); ?>" style="flex:1; text-align:center;">Forgot Member ID?</a>
            <a class="tfg-button" href="<?php echo \esc_url(\site_url('/stub-access')); ?>" style="flex:1; text-align:center;">Register as a New Member</a>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    public static function handleLogin(): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \error_log('[TFG SystemGuard] Skipped handleLogin due to REST/CRON/CLI/AJAX context');
            return;
        }

        // Don't interfere with WordPress admin login
        if (\is_admin() || \strpos($_SERVER['REQUEST_URI'] ?? '', '/wp-login.php') !== false || \strpos($_SERVER['REQUEST_URI'] ?? '', '/wp-admin') !== false) {
            return;
        }

        if (!FormRouter::matches('member_login')) return;
        if (empty($_POST['tfg_member_login_submit'])) return;
        if (empty($_POST['_tfg_nonce']) || !\wp_verify_nonce($_POST['_tfg_nonce'], 'tfg_member_login')) {
            echo '<p class="tfg-error">Security check failed. Please refresh and try again.</p>';
            return;
        }

        $member_id = Utils::normalizeMemberId(\wp_unslash($_POST['member_id'] ?? ''));
        $password  = (string) \wp_unslash($_POST['member_password'] ?? '');

        if ($member_id === '' || $password === '') {
            echo '<p class="tfg-error">Please enter both your Member ID and password.</p>';
            return;
        }

        // Find member_profile
        $profiles = \get_posts([
            'post_type'        => 'member_profile',
            'post_status'      => 'any',
            'meta_key'         => 'member_id',
            'meta_value'       => $member_id,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        if (!$profiles) {
            echo '<p class="tfg-error">No profile found for the provided Member ID.</p>';
            return;
        }
        $profile_id = (int) $profiles[0];

        // Verify password
        $stored_hash = \function_exists('get_field')
            ? \get_field('institution_password_hash', $profile_id)
            : \get_post_meta($profile_id, 'institution_password_hash', true);

        if (!$stored_hash || !\is_string($stored_hash) || !\password_verify($password, $stored_hash)) {
            echo '<p class="tfg-error">Incorrect password. Please try again.</p>';
            return;
        }

        $email = \function_exists('get_field')
            ? Utils::normalizeEmail(\get_field('contact_email', $profile_id) ?: '')
            : Utils::normalizeEmail(\get_post_meta($profile_id, 'contact_email', true));

        Cookies::setMemberCookie($member_id, $email);

        echo '<p class="tfg-success">Login successful. Redirecting...</p>';
        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect(\site_url('/member-dashboard/'));
            exit;
        }
    }

    /* ===========================
       RESET: REQUEST
       =========================== */

    public static function renderResetRequestForm(): string
    {
        \ob_start(); ?>
        <form method="POST" class="tfg-reset-request-form">
            <?php \wp_nonce_field('tfg_member_reset_request', '_tfg_nonce'); ?>
            <input type="hidden" name="handler_id" value="password_reset_request">

            <div class="tfg-login-row" style="display:flex; gap:1em; flex-wrap:wrap;">
                <div style="flex:1;">
                    <input type="text" name="reset_member_id" placeholder="Member ID" required>
                </div>
                <div style="flex:1;">
                    <input type="email" name="reset_email" placeholder="Contact email on file" required>
                </div>
                <div>
                    <button type="submit" name="tfg_member_reset_request_submit" value="1" class="tfg-button">Continue</button>
                </div>
            </div>
        </form>
        <?php
        return (string) \ob_get_clean();
    }

    public static function handleResetRequest(): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \error_log('[TFG SystemGuard] Skipped handleResetRequest due to REST/CRON/CLI/AJAX context');
            return;
        }

        if (!FormRouter::matches('password_reset_request')) return;
        if (empty($_POST['tfg_member_reset_request_submit'])) return;
        if (empty($_POST['_tfg_nonce']) || !\wp_verify_nonce($_POST['_tfg_nonce'], 'tfg_member_reset_request')) {
            echo '<p class="tfg-error">Security check failed. Please refresh and try again.</p>';
            return;
        }

        $member_id = Utils::normalizeMemberId(\wp_unslash($_POST['reset_member_id'] ?? ''));
        $email     = Utils::normalizeEmail(\wp_unslash($_POST['reset_email'] ?? ''));

        if ($member_id === '' || $email === '') {
            echo '<p class="tfg-error">Please provide your Member ID and contact email.</p>';
            return;
        }

        $stub = \get_posts([
            'post_type'        => 'profile_stub',
            'post_status'      => 'any',
            'meta_query'       => [
                ['key' => 'member_id',     'value' => $member_id],
                ['key' => 'contact_email', 'value' => $email],
            ],
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);

        if (!$stub) {
            echo '<p class="tfg-error">We could not verify that Member ID and email combination.</p>';
            return;
        }

        echo self::renderPasswordResetForm();
    }

    /* ===========================
       RESET: FORM + HANDLER
       =========================== */

    public static function renderPasswordResetForm(): string
    {
        $member_id = Cookies::getMemberId();
        if (!$member_id) {
            return '<p class="tfg-error">You must be logged in to change your password.</p>';
        }

        $return_to = \esc_url_raw($_GET['return_to'] ?? \site_url('/member-login/'));

        \ob_start(); ?>
        <p><strong>Reset your site password:</strong> <?php echo \esc_html($member_id); ?></p>

        <form method="POST" class="tfg-member-login-form">
            <?php \wp_nonce_field('tfg_member_password_reset', '_tfg_nonce'); ?>
            <div class="tfg-login-row">
                <input type="hidden" name="handler_id" value="password_reset">
                <input type="hidden" name="return_to"  value="<?php echo \esc_attr($return_to); ?>">

                <div class="child-1">
                    <div class="tfg-password-wrapper">
                        <input type="password" name="new_password" id="new_password"
                               class="tfg-password-input tfg-font-base" placeholder="Enter Password"
                               autocomplete="new-password" required>
                        <button type="button" class="tfg-toggle-password"
                                onclick="tfgTogglePassword()" aria-label="Show password">👁️</button>
                    </div>
                </div>

                <div class="child-2">
                    <div class="tfg-password-wrapper">
                        <input type="password" name="confirm_password" id="confirm_password"
                               class="tfg-password-input tfg-font-base" placeholder="Confirm Password"
                               autocomplete="new-password" required>
                        <button type="button" class="tfg-toggle-password"
                                onclick="tfgTogglePassword()" aria-label="Show password">👁️</button>
                    </div>
                </div>

                <div class="child-3">
                    <button type="submit" name="tfg_submit_password" value="1"
                            class="tfg-login-button tfg-font-base">Save</button>
                </div>
            </div>
        </form>
        <?php
        return (string) \ob_get_clean();
    }

    public static function handlePasswordReset(): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \error_log('[TFG SystemGuard] Skipped handlePasswordReset due to REST/CRON/CLI/AJAX context');
            return;
        }

        if (!FormRouter::matches('password_reset')) return;
        if (empty($_POST['tfg_submit_password'])) return;
        if (empty($_POST['_tfg_nonce']) || !\wp_verify_nonce($_POST['_tfg_nonce'], 'tfg_member_password_reset')) {
            echo '<p class="tfg-error">Security check failed. Please refresh and try again.</p>';
            return;
        }

        $member_id = Cookies::getMemberId();
        if (!$member_id) {
            echo '<p class="tfg-error">You must be logged in to change your password.</p>';
            return;
        }

        $new_password = (string) \wp_unslash($_POST['new_password'] ?? '');
        $confirm      = (string) \wp_unslash($_POST['confirm_password'] ?? '');

        if ($new_password === '' || $confirm === '') {
            echo '<p class="tfg-error">Please enter and confirm your new password.</p>';
            return;
        }
        if ($new_password !== $confirm) {
            echo '<p class="tfg-error">Passwords do not match.</p>';
            return;
        }
        if (\strlen($new_password) < 6) {
            echo '<p class="tfg-error">Password must be at least 6 characters.</p>';
            return;
        }

        $hash = \password_hash($new_password, MEMBER_PASSWORD_DEFAULT);

        $updated_any = false;

        // profile_stub
        $stubs = \get_posts([
            'post_type'        => 'profile_stub',
            'post_status'      => 'any',
            'meta_key'         => 'member_id',
            'meta_value'       => $member_id,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        if ($stubs) {
            $sid = (int) $stubs[0];
            $ok  = \function_exists('update_field')
                ? \update_field('institution_password_hash', $hash, $sid)
                : \update_post_meta($sid, 'institution_password_hash', $hash);
            $updated_any = $updated_any || (bool) $ok;
        }

        // member_profile
        $profiles = \get_posts([
            'post_type'        => 'member_profile',
            'post_status'      => 'any',
            'meta_key'         => 'member_id',
            'meta_value'       => $member_id,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        if ($profiles) {
            $pid = (int) $profiles[0];
            $ok  = \function_exists('update_field')
                ? \update_field('institution_password_hash', $hash, $pid)
                : \update_post_meta($pid, 'institution_password_hash', $hash);
            $updated_any = $updated_any || (bool) $ok;
        }

        if ($updated_any) {
            Cookies::unsetMemberCookie();

            $return_to = \esc_url_raw($_POST['return_to'] ?? \site_url('/member-login/'));
            if (!\headers_sent()) {
                \nocache_headers();
                \wp_safe_redirect($return_to);
                exit;
            }
            echo '<p class="tfg-success">Password updated. Please log in again.</p>';
        } else {
            echo '<p class="tfg-error">Password update failed. Please try again or contact support.</p>';
        }
    }

    /* ===========================
       FORGOT MEMBER ID
       =========================== */

    public static function renderForgotMemberIdForm(): string
    {
        \ob_start(); ?>
        <form method="POST" class="tfg-forgot-id-form">
            <?php \wp_nonce_field('tfg_member_forgot_id', '_tfg_nonce'); ?>
            <input type="hidden" name="handler_id" value="forgot_member_id">
            <label for="reset_email">Enter your contact email:</label>
            <input type="email" name="reset_email" required>
            <button type="submit" name="tfg_member_id_lookup_submit" value="1">Send My Member ID</button>
        </form>
        <?php
        return (string) \ob_get_clean();
    }

    public static function handleForgotMemberId(): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \error_log('[TFG SystemGuard] Skipped handleForgotMemberId due to REST/CRON/CLI/AJAX context');
            return;
        }

        if (!FormRouter::matches('forgot_member_id')) return;
        if (empty($_POST['tfg_member_id_lookup_submit'])) return;
        if (empty($_POST['_tfg_nonce']) || !\wp_verify_nonce($_POST['_tfg_nonce'], 'tfg_member_forgot_id')) {
            echo "<p class='tfg-error'>Security check failed. Please refresh and try again.</p>";
            return;
        }

        $email = Utils::normalizeEmail(\wp_unslash($_POST['reset_email'] ?? ''));
        if (!$email) {
            echo "<p class='tfg-error'>Please provide a valid email.</p>";
            return;
        }

        $member = \get_posts([
            'post_type'        => 'member_profile',
            'post_status'      => 'any',
            'meta_key'         => 'contact_email',
            'meta_value'       => $email,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        if (!$member) {
            echo "<p class='tfg-error'>We couldn't find a member with that email.</p>";
            return;
        }

        $pid       = (int) $member[0];
        $member_id = \function_exists('get_field') ? \get_field('member_id', $pid) : \get_post_meta($pid, 'member_id', true);
        $member_id = \is_string($member_id) ? $member_id : '';

        if ($member_id === '') {
            echo "<p class='tfg-error'>No Member ID is associated with that email.</p>";
            return;
        }

        // Example: send via mailer instead of displaying
        // Mailer::send($email, 'Your Member ID', 'member_id_reminder', ['member_id' => $member_id]);

        $masked = (\strlen($member_id) > 4)
            ? \substr($member_id, 0, 2) . \str_repeat('•', max(1, \strlen($member_id) - 4)) . \substr($member_id, -2)
            : $member_id;

        echo "<p class='tfg-success'>If this email is in our system, we’ve sent your Member ID. For reference: <strong>{$masked}</strong></p>";
        echo "<p><a href='" . \esc_url(\site_url('/reset-password')) . "'>Reset your password</a> or <a href='" . \esc_url(\site_url('/member-login')) . "'>Log in now</a>.</p>";
    }
}

/* ---- Legacy class alias for transition ---- */
\class_alias(\TFG\Features\Membership\MemberLogin::class, 'TFG_Member_Login');

