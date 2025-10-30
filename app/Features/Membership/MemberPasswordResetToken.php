<?php

namespace TFG\Features\Membership;

use TFG\Core\Utils;

/**
 * MemberPasswordResetToken
 * Token-based password reset for non-logged-in members (industry standard).
 */
final class MemberPasswordResetToken
{
    public static function init(): void
    {
        // Start session for messages
        if (!session_id()) {
            session_start();
        }

        // Register shortcodes
        \add_shortcode('tfg_password_reset_request', [self::class, 'renderRequestForm']);
        \add_shortcode('tfg_password_reset_form', [self::class, 'renderResetForm']);

        // Register handlers
        \add_action('template_redirect', [self::class, 'handleResetRequest']);
        \add_action('template_redirect', [self::class, 'handlePasswordUpdate']);
    }

    /**
     * 1️⃣ Step 1: Password Reset Request Form
     */
    public static function renderRequestForm(): string
    {
        if (Utils::isSystemRequest()) {
            Utils::info('[TFG SystemGuard] Skipped renderRequestForm due to REST/CRON/CLI/AJAX context');
            return '<p>System request - form not available.</p>';
        }

        \ob_start();

        // Display success message
        if (!empty($_SESSION['tfg_reset_request_success'])) {
            echo '<div class="tfg-success" style="
                display:block;
                margin:0.5em auto 1.2em;
                padding:0;
                background:transparent;
                border:none;
                color:#2e7d32;
                font-size:18px;
                text-align:center;
                max-width:100%;
            ">';
            echo '<strong>*** Success:</strong> ' . \esc_html($_SESSION['tfg_reset_request_success']) . ' ***';
            echo '</div>';
            unset($_SESSION['tfg_reset_request_success']);
        }

        // Display error message
        if (!empty($_SESSION['tfg_reset_request_error'])) {
            echo '<div class="tfg-error" style="
                display:block;
                margin:0.5em auto 1.2em;
                padding:0;
                background:transparent;
                border:none;
                color:#b71c1c;
                font-size:18px;
                text-align:center;
                max-width:100%;
            ">';
            echo '<strong>*** Error:</strong> ' . \esc_html($_SESSION['tfg_reset_request_error']) . ' ***';
            echo '</div>';
            unset($_SESSION['tfg_reset_request_error']);
        }

        ?>
        <h2 class="member-title" style="text-align:center; margin-top:0.3em; margin-bottom:0.5em;">
            <strong>Request Password Reset</strong>
        </h2>

        <div class="tfg-form-wrapper-wide">
            <form method="POST" class="tfg-form">
                <?php \wp_nonce_field('tfg_password_reset_request', 'tfg_reset_request_nonce'); ?>
                <input type="hidden" name="handler_id" value="password_reset_request_token">

                <!-- Email field inline with label -->
                <div style="display:flex; align-items:center; gap:1em; margin-bottom:2em;">
                    <label for="reset_email" style="font-size:1.2em; white-space:nowrap;">Enter your contact email:</label>
                    <input type="email" name="reset_email" id="reset_email" required class="tfg-input" style="flex:1;">
                </div>

                <!-- Buttons -->
                <div style="display:flex; justify-content:space-between; align-items:center; gap:1em;">
                    <div style="flex:1;">
                        <a href="<?php echo \esc_url(\site_url('/member-login/')); ?>" class="tfg-return-button">
                            ← Return to Login
                        </a>
                    </div>
                    <div style="flex:1; text-align:right;">
                        <button type="submit" name="send_reset_link" value="1" class="tfg-button tfg-font-base">
                            Send Reset Link
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    /**
     * 2️⃣ Step 2: Handle Reset Request
     */
    public static function handleResetRequest(): void
    {
        if (Utils::isSystemRequest()) {
            Utils::info('[TFG SystemGuard] Skipped handleResetRequest due to REST/CRON/CLI/AJAX context');
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        $handler_id = isset($_POST['handler_id']) ? (string) \wp_unslash($_POST['handler_id']) : '';
        if ($handler_id !== 'password_reset_request_token') {
            return;
        }

        if (empty($_POST['send_reset_link'])) {
            return;
        }

        Utils::info('[TFG Password Reset Token] Processing reset request');

        // Verify nonce
        if (empty($_POST['tfg_reset_request_nonce']) || !\wp_verify_nonce($_POST['tfg_reset_request_nonce'], 'tfg_password_reset_request')) {
            $_SESSION['tfg_reset_request_error'] = 'Security check failed. Please try again.';
            Utils::info('[TFG Password Reset Token] ❌ Nonce verification failed');
            return;
        }

        // Sanitize and validate email
        $email = Utils::normalizeEmail(\wp_unslash($_POST['reset_email'] ?? ''));
        if (!$email || !\is_email($email)) {
            $_SESSION['tfg_reset_request_error'] = 'Please provide a valid email address.';
            Utils::info("[TFG Password Reset Token] ❌ Invalid email: {$email}");
            return;
        }

        // Query member_profile by contact_email
        $profiles = \get_posts([
            'post_type'        => 'member_profile',
            'post_status'      => 'any',
            'meta_key'         => 'contact_email',
            'meta_value'       => $email,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);

        // Always show generic success message (security best practice - don't reveal if email exists)
        $_SESSION['tfg_reset_request_success'] = 'If that email is registered, you will receive a password reset link shortly.';

        if (!$profiles) {
            Utils::info("[TFG Password Reset Token] ℹ️ No profile found for email: {$email} (generic message shown)");
            return;
        }

        $profile_id = (int) $profiles[0];

        // Get member details
        $member_id = \function_exists('get_field')
            ? \get_field('member_id', $profile_id)
            : \get_post_meta($profile_id, 'member_id', true);

        $contact_name = \function_exists('get_field')
            ? \get_field('contact_name', $profile_id)
            : \get_post_meta($profile_id, 'contact_name', true);

        // Generate secure reset token (32 bytes = 64 hex characters)
        $token   = \bin2hex(\random_bytes(32));
        $expires = \time() + 3600; // 1 hour validity

        // Store token and expiration
        if (\function_exists('update_field')) {
            \update_field('password_reset_token', $token, $profile_id);
            \update_field('password_reset_expires', $expires, $profile_id);
        } else {
            \update_post_meta($profile_id, 'password_reset_token', $token);
            \update_post_meta($profile_id, 'password_reset_expires', $expires);
        }

        Utils::info("[TFG Password Reset Token] ✅ Generated token for member {$member_id}, expires at " . \date('Y-m-d H:i:s', $expires));

        // Construct reset link
        $reset_url = \add_query_arg('token', $token, \site_url('/password-reset-confirm/'));

        // Send email
        $subject = 'Password Reset Request - Take Flight Global';
        $message = sprintf(
            "Hello %s,\n\n" .
            "We received a request to reset your password for Member ID: %s\n\n" .
            "Click the link below to set a new password:\n%s\n\n" .
            "This link will expire in 1 hour.\n\n" .
            "If you didn't request this, please ignore this message.\n\n" .
            "Best regards,\nTake Flight Global Team",
            $contact_name ?: 'Member',
            $member_id,
            $reset_url
        );

        $sent = \wp_mail($email, $subject, $message);

        if ($sent) {
            Utils::info("[TFG Password Reset Token] ✅ Reset link sent to {$email}");

            // Log event
            if (\class_exists('\TFG\Core\Log')) {
                \TFG\Core\Log::addLogEntry([
                    'email'      => $email,
                    'event_type' => 'password_reset_link_sent',
                    'status'     => 'success',
                    'notes'      => "Reset token sent for member {$member_id}",
                ]);
            }
        } else {
            Utils::info("[TFG Password Reset Token] ⚠️ Failed to send email to {$email}, but showing generic success");
            // Still show success message (don't reveal if email exists)
        }
    }

    /**
     * 3️⃣ Step 3: Password Reset Form (via token link)
     */
    public static function renderResetForm(): string
    {
        if (Utils::isSystemRequest()) {
            Utils::info('[TFG SystemGuard] Skipped renderResetForm due to REST/CRON/CLI/AJAX context');
            return '<p>System request - form not available.</p>';
        }

        $token = \sanitize_text_field($_GET['token'] ?? '');

        if (!$token) {
            return '<div class="tfg-error" style="
                        padding:1em; margin-bottom:1em; background:#ffebee;
                        border:1px solid #f44336; border-radius:8px; color:#c62828;">
                        <strong>Error:</strong> Invalid or missing reset token.
                    </div>
                    <div style="text-align:center; margin-top:1em;">
                        <a href="' . \esc_url(\site_url('/member-login/')) . '" class="tfg-button">Go to Login</a>
                    </div>';
        }

        // Verify token and check expiration
        $profiles = \get_posts([
            'post_type'        => 'member_profile',
            'post_status'      => 'any',
            'meta_key'         => 'password_reset_token',
            'meta_value'       => $token,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);

        if (!$profiles) {
            return '<div class="tfg-error" style="
                        padding:1em; margin-bottom:1em; background:#ffebee;
                        border:1px solid #f44336; border-radius:8px; color:#c62828;">
                        <strong>Error:</strong> Invalid or expired reset token.
                    </div>
                    <div style="text-align:center; margin-top:1em;">
                        <a href="' . \esc_url(\site_url('/member-login/')) . '" class="tfg-button">Go to Login</a>
                    </div>';
        }

        $profile_id = (int) $profiles[0];

        // Check expiration
        $expires = \function_exists('get_field')
            ? \get_field('password_reset_expires', $profile_id)
            : \get_post_meta($profile_id, 'password_reset_expires', true);

        if (!$expires || \time() > (int) $expires) {
            return '<div class="tfg-error" style="
                        padding:1em; margin-bottom:1em; background:#ffebee;
                        border:1px solid #f44336; border-radius:8px; color:#c62828;">
                        <strong>Error:</strong> This reset link has expired. Please request a new one.
                    </div>
                    <div style="text-align:center; margin-top:1em;">
                        <a href="' . \esc_url(\site_url('/request-password-reset/')) . '" class="tfg-button">Request New Link</a>
                    </div>';
        }

        $member_id = \function_exists('get_field')
            ? \get_field('member_id', $profile_id)
            : \get_post_meta($profile_id, 'member_id', true);

        \ob_start();

        // Display error message
        if (!empty($_SESSION['tfg_token_reset_error'])) {
            echo '<div class="tfg-error" style="
                display:block;
                margin:0.5em auto 1.2em;
                padding:0;
                background:transparent;
                border:none;
                color:#b71c1c;
                font-size:18px;
                text-align:center;
                max-width:100%;
            ">';
            echo '<strong>*** Error:</strong> ' . \esc_html($_SESSION['tfg_token_reset_error']) . ' ***';
            echo '</div>';
            unset($_SESSION['tfg_token_reset_error']);
        }

        ?>
        <h2 class="member-title" style="text-align:center; margin-top:0.3em; margin-bottom:0.5em;">
            <strong>Set New Password</strong>
        </h2>
        <p style="text-align:center; margin-bottom:1em;"><strong>Member ID:</strong> <?php echo \esc_html($member_id); ?></p>

        <div class="tfg-form-wrapper-wide">
            <form method="POST" class="tfg-form" autocomplete="off">
                <?php \wp_nonce_field('tfg_password_reset_confirm', 'tfg_reset_confirm_nonce'); ?>
                <input type="hidden" name="handler_id" value="password_reset_confirm_token">
                <input type="hidden" name="token" value="<?php echo \esc_attr($token); ?>">
                <input type="hidden" name="profile_id" value="<?php echo \esc_attr($profile_id); ?>">

                <!-- Password fields row -->
                <div class="tfg-login-row">
                    <div class="child-1">
                        <div class="tfg-password-combo">
                            <input type="password" name="new_password" id="new_password" tabindex="1"
                                   class="tfg-password-input tfg-font-base" placeholder="Enter New Password"
                                   autocomplete="off" readonly onfocus="this.removeAttribute('readonly');" required>
                            <button type="button" class="tfg-toggle-password" tabindex="-1"
                                    onclick="tfgTogglePassword(this)" aria-label="Show password" title="Show password"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                        </div>
                    </div>

                    <div class="child-2">
                        <div class="tfg-password-combo">
                            <input type="password" name="confirm_password" id="confirm_password" tabindex="2"
                                   class="tfg-password-input tfg-font-base" placeholder="Confirm New Password"
                                   autocomplete="off" readonly onfocus="this.removeAttribute('readonly');" required>
                            <button type="button" class="tfg-toggle-password" tabindex="-1"
                                    onclick="tfgTogglePassword(this)" aria-label="Show password" title="Show password"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                        </div>
                    </div>
                </div>

                <!-- Buttons -->
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.5em; gap:1em;">
                    <div style="flex:1;">
                        <a href="<?php echo \esc_url(\site_url('/member-login/')); ?>" class="tfg-return-button">
                            ← Cancel
                        </a>
                    </div>
                    <div style="flex:1; text-align:right;">
                        <button type="submit" name="confirm_password_reset" value="1" class="tfg-button tfg-font-base">
                            Set New Password
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    /**
     * 4️⃣ Step 4: Handle Password Update
     */
    public static function handlePasswordUpdate(): void
    {
        if (Utils::isSystemRequest()) {
            Utils::info('[TFG SystemGuard] Skipped handlePasswordUpdate due to REST/CRON/CLI/AJAX context');
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        $handler_id = isset($_POST['handler_id']) ? (string) \wp_unslash($_POST['handler_id']) : '';
        if ($handler_id !== 'password_reset_confirm_token') {
            return;
        }

        if (empty($_POST['confirm_password_reset'])) {
            return;
        }

        Utils::info('[TFG Password Reset Token] Processing password update');

        // Verify nonce
        if (empty($_POST['tfg_reset_confirm_nonce']) || !\wp_verify_nonce($_POST['tfg_reset_confirm_nonce'], 'tfg_password_reset_confirm')) {
            $_SESSION['tfg_token_reset_error'] = 'Security check failed. Please try again.';
            Utils::info('[TFG Password Reset Token] ❌ Nonce verification failed');
            return;
        }

        // Get and verify token
        $token      = \sanitize_text_field($_POST['token'] ?? '');
        $profile_id = \absint($_POST['profile_id'] ?? 0);

        if (!$token || !$profile_id) {
            $_SESSION['tfg_token_reset_error'] = 'Invalid reset token.';
            Utils::info('[TFG Password Reset Token] ❌ Missing token or profile_id');
            return;
        }

        // Verify token matches
        $stored_token = \function_exists('get_field')
            ? \get_field('password_reset_token', $profile_id)
            : \get_post_meta($profile_id, 'password_reset_token', true);

        if ($stored_token !== $token) {
            $_SESSION['tfg_token_reset_error'] = 'Invalid reset token.';
            Utils::info("[TFG Password Reset Token] ❌ Token mismatch for profile {$profile_id}");
            return;
        }

        // Check expiration
        $expires = \function_exists('get_field')
            ? \get_field('password_reset_expires', $profile_id)
            : \get_post_meta($profile_id, 'password_reset_expires', true);

        if (!$expires || \time() > (int) $expires) {
            $_SESSION['tfg_token_reset_error'] = 'This reset link has expired. Please request a new one.';
            Utils::info("[TFG Password Reset Token] ❌ Token expired for profile {$profile_id}");
            return;
        }

        // Get and validate passwords
        $new_password = (string) \wp_unslash($_POST['new_password'] ?? '');
        $confirm      = (string) \wp_unslash($_POST['confirm_password'] ?? '');

        if ($new_password === '' || $confirm === '') {
            $_SESSION['tfg_token_reset_error'] = 'Please enter and confirm your new password.';
            return;
        }

        if ($new_password !== $confirm) {
            $_SESSION['tfg_token_reset_error'] = 'Passwords do not match. Please try again.';
            return;
        }

        // Validate password strength
        $min_len = \defined('TFG_MIN_PASSWORD_LENGTH') ? (int) \TFG_MIN_PASSWORD_LENGTH : 8;
        if (\strlen($new_password) < $min_len) {
            $_SESSION['tfg_token_reset_error'] = "Password must be at least {$min_len} characters.";
            return;
        }

        // Hash password
        $password_hash = \password_hash($new_password, \PASSWORD_DEFAULT);

        // Update password in profile
        if (\function_exists('update_field')) {
            \update_field('institution_password_hash', $password_hash, $profile_id);
        } else {
            \update_post_meta($profile_id, 'institution_password_hash', $password_hash);
        }

        // Clear token fields (one-time use)
        if (\function_exists('update_field')) {
            \update_field('password_reset_token', '', $profile_id);
            \update_field('password_reset_expires', 0, $profile_id);
        } else {
            \delete_post_meta($profile_id, 'password_reset_token');
            \delete_post_meta($profile_id, 'password_reset_expires');
        }

        $member_id = \function_exists('get_field')
            ? \get_field('member_id', $profile_id)
            : \get_post_meta($profile_id, 'member_id', true);

        $contact_email = \function_exists('get_field')
            ? \get_field('contact_email', $profile_id)
            : \get_post_meta($profile_id, 'contact_email', true);

        Utils::info("[TFG Password Reset Token] ✅ Password updated for member {$member_id}, token cleared");

        // Log event
        if (\class_exists('\TFG\Core\Log')) {
            \TFG\Core\Log::addLogEntry([
                'email'      => $contact_email,
                'event_type' => 'password_reset_success',
                'status'     => 'success',
                'notes'      => "Password reset completed for member {$member_id}",
            ]);
        }

        // Set success message and redirect to login
        $_SESSION['tfg_login_success'] = 'Password reset successfully! You can now log in with your new password.';

        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect(\site_url('/member-login/'));
            Utils::info('[TFG Password Reset Token] ✅ Redirecting to login page');
            exit;
        }
    }
}

// Legacy alias for backward compatibility
\class_alias(\TFG\Features\Membership\MemberPasswordResetToken::class, 'TFG_Member_Password_Reset_Token');
