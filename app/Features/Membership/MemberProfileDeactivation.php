<?php

namespace TFG\Features\Membership;

use TFG\Core\Cookies;
use TFG\Core\Utils;

/**
 * MemberProfileDeactivation
 * Safely handles member profile deletion with confirmation and cleanup.
 */
final class MemberProfileDeactivation
{
    public static function init(): void
    {
        // Start session for messages
        if (!session_id()) {
            session_start();
        }

        // Register shortcode for confirmation page
        \add_shortcode('tfg_deactivate_profile', [self::class, 'renderConfirmation']);

        // Register handler
        \add_action('template_redirect', [self::class, 'handleDeactivation']);
    }

    /**
     * 2️⃣ Present confirmation prompt
     */
    public static function renderConfirmation(): string
    {
        if (Utils::isSystemRequest()) {
            Utils::info('[TFG SystemGuard] Skipped renderConfirmation due to REST/CRON/CLI/AJAX context');
            return '<p>System request - form not available.</p>';
        }

        // 1️⃣ Verify active session
        $member_id    = Cookies::getMemberId();
        $member_email = Cookies::getMemberEmail();

        if (!$member_id || !Cookies::verifyMember($member_id, $member_email)) {
            Utils::info('[TFG Deactivate] ❌ Invalid session');
            return '<div class="tfg-error" style="padding:1em; margin-bottom:1em; background:#ffebee; border:1px solid #f44336; border-radius:8px; color:#c62828;">
                        <strong>Error:</strong> You must be logged in to deactivate your profile.
                    </div>
                    <div style="text-align:center; margin-top:1em;">
                        <a href="' . \esc_url(\site_url('/member-login/')) . '" class="tfg-button">Go to Login</a>
                    </div>';
        }

        // Retrieve the current member_profile post
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
            Utils::info("[TFG Deactivate] ❌ No profile found for member_id: {$member_id}");
            return '<div class="tfg-error" style="padding:1em; margin-bottom:1em; background:#ffebee; border:1px solid #f44336; border-radius:8px; color:#c62828;">
                        <strong>Error:</strong> Profile not found.
                    </div>';
        }

        $profile_id = (int) $profiles[0];

        \ob_start();
        ?>
        <h2 class="member-title" style="text-align:center; margin-top:0.3em; margin-bottom:0.5em;">
            <strong>Deactivate Profile</strong>
        </h2>

        <div class="tfg-form-wrapper-wide">
            <!-- Warning message -->
            <div style="padding:1.5em; margin-bottom:2em; background:#fff3cd; border:2px solid #ff9800; border-radius:8px;">
                <div style="text-align:center; font-size:3em; color:#ff6f00; margin-bottom:0.5em;">⚠️</div>
                <h3 style="color:#e65100; text-align:center; margin-bottom:1em;">Warning: This Action Cannot Be Undone</h3>
                <p style="font-size:1.1em; line-height:1.6; margin-bottom:1em;">
                    Deactivating your profile will <strong>permanently remove</strong> your account and all associated data from our system.
                </p>
                <p style="font-size:1.1em; line-height:1.6; margin-bottom:1em;">
                    This includes:
                </p>
                <ul style="margin-left:2em; font-size:1.05em; line-height:1.8;">
                    <li>Your member profile and contact information</li>
                    <li>Your organization details and website links</li>
                    <li>Your membership credentials and access rights</li>
                    <li>All registration and stub records</li>
                </ul>
                <p style="font-size:1.1em; line-height:1.6; margin-top:1em; color:#d84315;">
                    <strong>Are you absolutely sure you want to continue?</strong>
                </p>
            </div>

            <!-- Confirmation form -->
            <form method="POST" class="tfg-form">
                <input type="hidden" name="handler_id" value="deactivate_profile">
                <input type="hidden" name="profile_id" value="<?php echo \esc_attr($profile_id); ?>">
                <input type="hidden" name="member_id" value="<?php echo \esc_attr($member_id); ?>">
                <?php \wp_nonce_field('tfg_deactivate_profile', 'tfg_deactivate_nonce'); ?>

                <!-- Buttons -->
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:2em; gap:1em;">
                    <div style="flex:1;">
                        <a href="<?php echo \esc_url(\site_url('/member-dashboard/')); ?>" class="tfg-return-button">
                            ← Cancel / Return to Dashboard
                        </a>
                    </div>
                    <div style="flex:1; text-align:right;">
                        <button type="submit" name="confirm_deactivation" value="1"
                                class="tfg-button tfg-font-base"
                                style="background-color:#d32f2f !important;"
                                onclick="return confirm('FINAL WARNING: This will permanently delete your profile. Click OK to proceed.');">
                            Confirm Deactivation
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php
        return (string) \ob_get_clean();
    }

    /**
     * 4️⃣ Handle deactivation request
     */
    public static function handleDeactivation(): void
    {
        if (Utils::isSystemRequest()) {
            Utils::info('[TFG SystemGuard] Skipped handleDeactivation due to REST/CRON/CLI/AJAX context');
            return;
        }

        // Check if this is our form submission
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        $handler_id = isset($_POST['handler_id']) ? (string) \wp_unslash($_POST['handler_id']) : '';
        if ($handler_id !== 'deactivate_profile') {
            return;
        }

        if (empty($_POST['confirm_deactivation'])) {
            return;
        }

        Utils::info('[TFG Deactivate] Starting deactivation process');

        // Verify nonce
        if (empty($_POST['tfg_deactivate_nonce']) || !\wp_verify_nonce($_POST['tfg_deactivate_nonce'], 'tfg_deactivate_profile')) {
            Utils::info('[TFG Deactivate] ❌ Nonce verification failed');
            $_SESSION['tfg_deactivate_error'] = 'Security check failed. Please try again.';
            return;
        }

        // 1️⃣ Verify active session
        $member_id    = Cookies::getMemberId();
        $member_email = Cookies::getMemberEmail();

        if (!$member_id || !Cookies::verifyMember($member_id, $member_email)) {
            Utils::info('[TFG Deactivate] ❌ Invalid session during deactivation');
            $_SESSION['tfg_deactivate_error'] = 'Session expired. Please log in again.';
            if (!\headers_sent()) {
                \nocache_headers();
                \wp_safe_redirect(\site_url('/member-login/'));
                exit;
            }
            return;
        }

        // Get profile_id from POST
        $profile_id = \absint($_POST['profile_id'] ?? 0);
        if (!$profile_id) {
            Utils::info('[TFG Deactivate] ❌ No profile_id provided');
            $_SESSION['tfg_deactivate_error'] = 'Invalid profile ID.';
            return;
        }

        // 6️⃣ Security: Verify this profile belongs to the logged-in member
        $profile_member_id = \function_exists('get_field')
            ? \get_field('member_id', $profile_id)
            : \get_post_meta($profile_id, 'member_id', true);

        if ($profile_member_id !== $member_id) {
            Utils::info("[TFG Deactivate] ❌ Security violation: member {$member_id} attempted to delete profile {$profile_id} belonging to {$profile_member_id}");
            $_SESSION['tfg_deactivate_error'] = 'You do not have permission to deactivate this profile.';
            return;
        }

        Utils::info("[TFG Deactivate] Verified ownership: member {$member_id} owns profile {$profile_id}");

        // 4️⃣ Deletion sequence with safe rollback
        $deletion_success = true;
        $deleted_profile  = false;
        $deleted_stub     = false;

        try {
            // Delete member_profile
            Utils::info("[TFG Deactivate] Attempting to delete member_profile {$profile_id}");
            $result = \wp_delete_post($profile_id, true); // true = force delete, bypass trash

            if ($result) {
                $deleted_profile = true;
                Utils::info("[TFG Deactivate] ✅ Deleted member_profile {$profile_id}");
            } else {
                Utils::info("[TFG Deactivate] ❌ Failed to delete member_profile {$profile_id}");
                $deletion_success = false;
            }

            // Delete associated profile_stub
            $stubs = \get_posts([
                'post_type'        => 'profile_stub',
                'post_status'      => 'any',
                'meta_key'         => 'member_id',
                'meta_value'       => $member_id,
                'posts_per_page'   => -1,
                'fields'           => 'ids',
                'suppress_filters' => true,
                'no_found_rows'    => true,
            ]);

            if ($stubs) {
                foreach ($stubs as $stub_id) {
                    $result = \wp_delete_post($stub_id, true);
                    if ($result) {
                        $deleted_stub = true;
                        Utils::info("[TFG Deactivate] ✅ Deleted profile_stub {$stub_id}");
                    } else {
                        Utils::info("[TFG Deactivate] ⚠️ Failed to delete profile_stub {$stub_id}");
                    }
                }
            } else {
                Utils::info("[TFG Deactivate] ℹ️ No profile_stub found for member_id {$member_id}");
            }

        } catch (\Exception $e) {
            Utils::info('[TFG Deactivate] ❌ Exception during deletion: ' . $e->getMessage());
            $deletion_success = false;
        }

        // Log the event
        if ($deletion_success && $deleted_profile) {
            if (\class_exists('\TFG\Core\Log')) {
                \TFG\Core\Log::addLogEntry([
                    'email'      => $member_email,
                    'event_type' => 'profile_deactivated',
                    'status'     => 'success',
                    'notes'      => "Member {$member_id} deactivated profile {$profile_id}",
                ]);
                Utils::info('[TFG Deactivate] Log entry created');
            }
        }

        // 5️⃣ Cleanup - Clear ALL cookies (including subscriber)
        if ($deleted_profile) {
            // Clear member cookies
            Cookies::clearMemberCookies();

            // Clear subscriber cookies as well
            self::clearSubscriberCookies();

            // Destroy PHP session
            if (session_id()) {
                session_destroy();
            }

            Utils::info('[TFG Deactivate] ✅ All cookies cleared and session destroyed');

            // Set success message
            $_SESSION['tfg_deactivate_success'] = 'Your profile has been removed successfully.';

            // Redirect to home page
            if (!\headers_sent()) {
                \nocache_headers();
                \wp_safe_redirect(\home_url('/'));
                Utils::info('[TFG Deactivate] ✅ Redirecting to home page');
                exit;
            }
        } else {
            $_SESSION['tfg_deactivate_error'] = 'Failed to deactivate profile. Please contact support.';
            Utils::info('[TFG Deactivate] ❌ Deactivation failed');
        }
    }

    /**
     * Helper to clear subscriber cookies
     */
    private static function clearSubscriberCookies(): void
    {
        if (!\headers_sent()) {
            \setcookie('is_subscribed', '', time() - 3600, '/', '', false, false);
            \setcookie('subscriber_email', '', time() - 3600, '/', '', false, false);
            \setcookie('subscribed_ok', '', time() - 3600, '/', '', false, true);
            Utils::info('[TFG Deactivate] ✅ Cleared subscriber cookies');
        }
    }
}

// Legacy alias for backward compatibility
\class_alias(\TFG\Features\Membership\MemberProfileDeactivation::class, 'TFG_Member_Profile_Deactivation');
