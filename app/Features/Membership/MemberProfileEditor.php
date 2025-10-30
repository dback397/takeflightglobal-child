<?php

namespace TFG\Features\Membership;

use TFG\Core\Cookies;
use TFG\Core\Utils;
use TFG\Core\FormRouter;

/**
 * MemberProfileEditor
 * Allows logged-in members to safely edit their profile data.
 */
final class MemberProfileEditor
{
    public static function init(): void
    {
        // Start session for messages
        if (!session_id()) {
            session_start();
        }

        // Register shortcode
        \add_shortcode('tfg_edit_profile', [self::class, 'renderEditForm']);

        // Register handler
        \add_action('template_redirect', [self::class, 'handleProfileSave']);
    }

    /**
     * Render the edit profile form
     */
    public static function renderEditForm(): string
    {
        if (Utils::isSystemRequest()) {
            Utils::info('[TFG SystemGuard] Skipped renderEditForm due to REST/CRON/CLI/AJAX context');
            return '<p>System request - form not available.</p>';
        }

        // 1️⃣ Verify session
        $member_id    = Cookies::getMemberId();
        $member_email = Cookies::getMemberEmail();

        if (!$member_id || !Cookies::verifyMember($member_id, $member_email)) {
            Utils::info('[TFG Edit Profile] ❌ Invalid session');
            return '<div class="tfg-error" style="padding:1em; margin-bottom:1em; background:#ffebee; border:1px solid #f44336; border-radius:8px; color:#c62828;">
                        <strong>Error:</strong> You must be logged in to edit your profile.
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
            Utils::info("[TFG Edit Profile] ❌ No profile found for member_id: {$member_id}");
            return '<div class="tfg-error" style="padding:1em; margin-bottom:1em; background:#ffebee; border:1px solid #f44336; border-radius:8px; color:#c62828;">
                        <strong>Error:</strong> Profile not found.
                    </div>';
        }

        $profile_id = (int) $profiles[0];
        Utils::info("[TFG Edit Profile] Found profile ID: {$profile_id} for member_id: {$member_id}");

        // 2️⃣ Pre-populate form fields with current ACF values
        $contact_name = \function_exists('get_field')
            ? \get_field('contact_name', $profile_id)
            : \get_post_meta($profile_id, 'contact_name', true);

        $title_and_department = \function_exists('get_field')
            ? \get_field('title_and_department', $profile_id)
            : \get_post_meta($profile_id, 'title_and_department', true);

        $contact_email = \function_exists('get_field')
            ? \get_field('contact_email', $profile_id)
            : \get_post_meta($profile_id, 'contact_email', true);

        $organization_name = \function_exists('get_field')
            ? \get_field('organization_name', $profile_id)
            : \get_post_meta($profile_id, 'organization_name', true);

        $member_type = \function_exists('get_field')
            ? \get_field('member_type', $profile_id)
            : \get_post_meta($profile_id, 'member_type', true);

        $website = \function_exists('get_field')
            ? \get_field('website', $profile_id)
            : \get_post_meta($profile_id, 'website', true);

        Utils::info("[TFG Edit Profile] Loaded data: name={$contact_name}, email={$contact_email}, type={$member_type}");

        \ob_start();

        // Display success message (only if form was submitted)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['tfg_profile_success'])) {
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
            echo '<strong>*** Success:</strong> ' . \esc_html($_SESSION['tfg_profile_success']) . ' ***';
            echo '</div>';
            unset($_SESSION['tfg_profile_success']);
        }

        // Display error message (only if form was submitted)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['tfg_profile_error'])) {
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
            echo '<strong>*** Error:</strong> ' . \esc_html($_SESSION['tfg_profile_error']) . ' ***';
            echo '</div>';
            unset($_SESSION['tfg_profile_error']);
        }

        ?>
        <h2 class="member-title" style="text-align:center; margin-top:0.3em; margin-bottom:0.5em;">
            <strong>Edit / Expand Profile</strong>
        </h2>
        <?php

        ?>

        <div class="tfg-form-wrapper-wide">
            <form method="POST" class="tfg-form">
                <input type="hidden" name="handler_id" value="edit_profile">
                <input type="hidden" name="profile_id" value="<?php echo \esc_attr($profile_id); ?>">
                <?php \wp_nonce_field('tfg_edit_profile', 'tfg_edit_profile_nonce'); ?>

                <div class="tfg-field">
                    <label for="contact_name">Contact Name <span class="tfg-required">*</span></label>
                    <input type="text" id="contact_name" name="contact_name" required
                           value="<?php echo \esc_attr($contact_name); ?>">
                </div>

                <div class="tfg-field">
                    <label for="title_and_department">Title and Department <span class="tfg-required">*</span></label>
                    <input type="text" id="title_and_department" name="title_and_department" required
                           value="<?php echo \esc_attr($title_and_department); ?>">
                </div>

                <div class="tfg-field">
                    <label for="contact_email">Contact Email <span class="tfg-required">*</span></label>
                    <input type="email" id="contact_email" name="contact_email" required
                           value="<?php echo \esc_attr($contact_email); ?>">
                </div>

                <div class="tfg-field">
                    <label for="organization_name">Organization Name <span class="tfg-required">*</span></label>
                    <input type="text" id="organization_name" name="organization_name" required
                           value="<?php echo \esc_attr($organization_name); ?>">
                </div>

                <div class="tfg-membership-type-box" style="margin-bottom:25px;">
                    <label>Membership Type <span class="tfg-required">*</span></label>
                    <label style="display:block; margin-bottom:2px;">
                        <input type="radio" name="member_type" value="university" <?php \checked($member_type, 'university'); ?> required> College or University
                    </label>
                    <label style="display:block; margin-bottom:2px;">
                        <input type="radio" name="member_type" value="agency" <?php \checked($member_type, 'agency'); ?> required> Recruiting Agency
                    </label>
                    <label style="display:block;">
                        <input type="radio" name="member_type" value="affiliate" <?php \checked($member_type, 'affiliate'); ?> required> Advertiser / Service Provider
                    </label>
                </div>

                <div class="tfg-form" style="margin-top:20px;">
                    <label for="website">Website <span class="tfg-required">*</span></label>
                    <input type="url" id="website" name="website" required
                           value="<?php echo \esc_attr($website ?: 'https://'); ?>" class="tfg-input-wide">
                </div>

                <!-- Buttons -->
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:2em; gap:1em;">
                    <div style="flex:1;">
                        <a href="<?php echo \esc_url(\site_url('/member-dashboard/')); ?>" class="tfg-return-button">
                            ← Cancel / Return to Dashboard
                        </a>
                    </div>
                    <div style="flex:1; text-align:right;">
                        <button type="submit" name="save_profile" value="1" class="tfg-button tfg-font-base">
                            Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php
                return (string) \ob_get_clean();
    }

    /**
     * Handle profile save request
     */
    public static function handleProfileSave(): void
    {
        if (Utils::isSystemRequest()) {
            Utils::info('[TFG SystemGuard] Skipped handleProfileSave due to REST/CRON/CLI/AJAX context');
            return;
        }

        // Check if this is our form submission
        if (!FormRouter::matches('edit_profile')) {
            return;
        }

        if (empty($_POST['save_profile'])) {
            return;
        }

        Utils::info('[TFG Edit Profile] Handling profile save request');

        // Verify nonce
        if (empty($_POST['tfg_edit_profile_nonce']) || !\wp_verify_nonce($_POST['tfg_edit_profile_nonce'], 'tfg_edit_profile')) {
            Utils::info('[TFG Edit Profile] ❌ Nonce verification failed');
            $_SESSION['tfg_profile_error'] = 'Security check failed. Please try again.';
            return;
        }

        // 1️⃣ Verify session again
        $member_id    = Cookies::getMemberId();
        $member_email = Cookies::getMemberEmail();

        if (!$member_id || !Cookies::verifyMember($member_id, $member_email)) {
            Utils::info('[TFG Edit Profile] ❌ Invalid session during save');
            $_SESSION['tfg_profile_error'] = 'Session expired. Please log in again.';
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
            Utils::info('[TFG Edit Profile] ❌ No profile_id provided');
            $_SESSION['tfg_profile_error'] = 'Invalid profile ID.';
            return;
        }

        // 5️⃣ Security: Verify this profile belongs to the logged-in member
        $profile_member_id = \function_exists('get_field')
            ? \get_field('member_id', $profile_id)
            : \get_post_meta($profile_id, 'member_id', true);

        if ($profile_member_id !== $member_id) {
            Utils::info("[TFG Edit Profile] ❌ Security violation: member {$member_id} attempted to edit profile {$profile_id} belonging to {$profile_member_id}");
            $_SESSION['tfg_profile_error'] = 'You do not have permission to edit this profile.';
            return;
        }

        // 3️⃣ Sanitize and validate input
        $contact_name         = \sanitize_text_field(\wp_unslash($_POST['contact_name'] ?? ''));
        $title_and_department = \sanitize_text_field(\wp_unslash($_POST['title_and_department'] ?? ''));
        $contact_email        = \sanitize_email(\wp_unslash($_POST['contact_email'] ?? ''));
        $organization_name    = \sanitize_text_field(\wp_unslash($_POST['organization_name'] ?? ''));
        $member_type          = \sanitize_text_field(\wp_unslash($_POST['member_type'] ?? ''));
        $website              = \esc_url_raw(\wp_unslash($_POST['website'] ?? ''));

        // Log received data
        Utils::info("[TFG Edit Profile] Received: name={$contact_name}, dept={$title_and_department}, email={$contact_email}, org={$organization_name}, type={$member_type}, web={$website}");

        // Validate required fields
        if (!$contact_name || !$title_and_department || !$contact_email || !$organization_name || !$member_type || !$website) {
            Utils::info('[TFG Edit Profile] ❌ Required fields missing - name=' . (empty($contact_name) ? 'EMPTY' : 'OK') . ', dept=' . (empty($title_and_department) ? 'EMPTY' : 'OK') . ', email=' . (empty($contact_email) ? 'EMPTY' : 'OK') . ', org=' . (empty($organization_name) ? 'EMPTY' : 'OK') . ', type=' . (empty($member_type) ? 'EMPTY' : 'OK') . ', web=' . (empty($website) ? 'EMPTY' : 'OK'));
            $_SESSION['tfg_profile_error'] = 'Please fill in all required fields.';
            return;
        }

        // Validate member_type
        $valid_types = ['university', 'agency', 'affiliate'];
        if (!in_array($member_type, $valid_types, true)) {
            Utils::info("[TFG Edit Profile] ❌ Invalid member_type: {$member_type}");
            $_SESSION['tfg_profile_error'] = 'Invalid membership type.';
            return;
        }

        // Validate email format
        if (!\is_email($contact_email)) {
            Utils::info("[TFG Edit Profile] ❌ Invalid email format: {$contact_email}");
            $_SESSION['tfg_profile_error'] = 'Please enter a valid email address.';
            return;
        }

        // Update ACF fields safely
        Utils::info("[TFG Edit Profile] Starting updates for profile {$profile_id}");

        if (\function_exists('update_field')) {
            Utils::info('[TFG Edit Profile] Using ACF update_field()');
            \update_field('contact_name', $contact_name, $profile_id);
            \update_field('title_and_department', $title_and_department, $profile_id);
            \update_field('contact_email', $contact_email, $profile_id);
            \update_field('organization_name', $organization_name, $profile_id);
            \update_field('member_type', $member_type, $profile_id);
            \update_field('website', $website, $profile_id);
            \update_field('email', $contact_email, $profile_id); // Keep email field in sync
            Utils::info('[TFG Edit Profile] ✅ All ACF fields updated');
        } else {
            Utils::info('[TFG Edit Profile] Using update_post_meta()');
            \update_post_meta($profile_id, 'contact_name', $contact_name);
            \update_post_meta($profile_id, 'title_and_department', $title_and_department);
            \update_post_meta($profile_id, 'contact_email', $contact_email);
            \update_post_meta($profile_id, 'organization_name', $organization_name);
            \update_post_meta($profile_id, 'member_type', $member_type);
            \update_post_meta($profile_id, 'website', $website);
            \update_post_meta($profile_id, 'email', $contact_email);
            Utils::info('[TFG Edit Profile] ✅ All post meta updated');
        }

        Utils::info("[TFG Edit Profile] ✅ Successfully updated profile {$profile_id} for member {$member_id}");

        // Add log entry
        if (\class_exists('\TFG\Core\Log')) {
            \TFG\Core\Log::addLogEntry([
                'email'      => $contact_email,
                'event_type' => 'profile_updated',
                'status'     => 'success',
                'notes'      => "Member {$member_id} updated profile {$profile_id}",
            ]);
            Utils::info('[TFG Edit Profile] Log entry created');
        }

        // Update member cookie if email changed
        if ($contact_email !== $member_email) {
            Cookies::setMemberCookie($member_id, $contact_email);
            Utils::info("[TFG Edit Profile] Updated member cookie with new email: {$contact_email}");
        }

        // Set success message
        $_SESSION['tfg_profile_success'] = 'Profile updated successfully!';

        // 3️⃣ Redirect to dashboard with confirmation
        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect(\site_url('/member-dashboard/'));
            Utils::info('[TFG Edit Profile] ✅ Redirecting to dashboard');
            exit;
        }

        Utils::info('[TFG Edit Profile] ❌ Headers already sent, cannot redirect');
    }
}

// Legacy alias for backward compatibility
\class_alias(\TFG\Features\Membership\MemberProfileEditor::class, 'TFG_Member_Profile_Editor');
