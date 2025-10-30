<?php
// ✅ TFG System Guard injected by Cursor – prevents REST/CRON/CLI/AJAX interference
/**
 * UI/MemberStubManager.php
 * Renders and handles the member stub form (new/edit) and manages stub persistence.
 */

namespace TFG\Features\Membership;

use TFG\Core\Utils;
use TFG\Core\FormRouter;
use TFG\Admin\Sequence;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberStubManager
{
    /** Valid choices coming from the form UI. */
    public const VALID_MEMBER_TYPES = ['university', 'agency', 'affiliate'];

    /* =========================
       Bootstrap
       ========================= */
    public static function init(): void
    {
        // Start session for form error messages
        if (!session_id()) {
            session_start();
        }

        \add_shortcode('tfg_stub_form', [self::class, 'renderStubForm']);

        // Frontend-only handler (avoid running during admin/ajax/rest)
        if (!\is_admin() && !\wp_doing_ajax() && !\defined('REST_REQUEST')) {
            \add_action('template_redirect', [self::class, 'handleStubSubmission']);
        }
    }

    /* =========================
       Shortcode: [tfg_stub_form]
       ========================= */
    public static function renderStubForm($atts): string
    {
        \TFG\Core\Utils::info('[TFG RENDER STUB] Entering renderStubForm()');

        // 1) Resolve member_type (shortcode attr → POST on submit)
        $atts       = \shortcode_atts(['type' => ''], $atts);
        $memberType = \sanitize_text_field($atts['type']);

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['member_type'])) {
            $memberType = \sanitize_text_field(\wp_unslash($_POST['member_type']));
        }

        if ($memberType !== '' && !\in_array($memberType, self::VALID_MEMBER_TYPES, true)) {
            \TFG\Core\Utils::info("Invalid member type resolved in render: {$memberType}");
            return '<p class="tfg-error">Invalid member type.</p>';
        }

        // 2) Edit vs New
        $postId = \absint($_GET['post_id'] ?? 0);
        $mode   = $postId ? 'edit' : 'new';
        \TFG\Core\Utils::info("Rendering stub form: mode={$mode}, post_id={$postId}, member_type={$memberType}");

        // 3) Prefill values on edit OR from failed POST
        $values = [
            'contact_name'         => '',
            'title_and_department' => '',
            'contact_email'        => '',
            'organization_name'    => '',
            'website'              => '',
        ];

        // If POST data exists (validation error), use it to prefill
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_name'])) {
            $values['contact_name']         = \sanitize_text_field(\wp_unslash($_POST['contact_name'] ?? ''));
            $values['title_and_department'] = \sanitize_text_field(\wp_unslash($_POST['title_and_department'] ?? ''));
            $values['contact_email']        = \sanitize_email(\wp_unslash($_POST['contact_email'] ?? ''));
            $values['organization_name']    = \sanitize_text_field(\wp_unslash($_POST['organization_name'] ?? ''));
            $values['website']              = \esc_url_raw(\wp_unslash($_POST['website'] ?? ''));
            // Update memberType from POST as well
            if (isset($_POST['member_type'])) {
                $memberType = \sanitize_text_field(\wp_unslash($_POST['member_type']));
            }
        } elseif ($mode === 'edit' && $postId) {
            foreach ($values as $key => &$val) {
                $val = \function_exists('get_field')
                    ? (string) \get_field($key, $postId)
                    : (string) \get_post_meta($postId, $key, true);
                \TFG\Core\Utils::info("Editing value {$key} = {$val}");
            }
            unset($val);
        }

        \ob_start();

        // Header helper echoes; don't concatenate it
        if (\class_exists(MemberFormUtilities::class)) {
            $emailForHeader = $postId
                ? (\function_exists('get_field') ? (\get_field('contact_email', $postId) ?: '') : (\get_post_meta($postId, 'contact_email', true) ?: ''))
                : '';
            MemberFormUtilities::stubAccessHeader($emailForHeader);
        }

        // Display error message if validation failed
        if (!empty($_SESSION['tfg_form_error'])) {
            echo '<div class="tfg-error" style="
              display:block;
              margin:0.5em auto 1.2em;
              padding:0;
              background:transparent;
              border:none;
              color:#b71c1c;
              font-size:14px;
              text-align:center;
              max-width:100%;
          ">';
            echo '<strong>*** Error:</strong> ' . esc_html($_SESSION['tfg_form_error']) . ' ***';
            echo '</div>';
            unset($_SESSION['tfg_form_error']);
        }
        ?>
        <h2 class="member-title" style="text-align:center; margin-top:0.3em; margin-bottom:0.5em;">
            <strong>Registration</strong>
        </h2>
        <?php
        ?>
        <h3 style="margin-bottom:1em;">1. Enter Organization Details</h3>
        <div class="tfg-form-wrapper-wide">
            <form method="POST" class="tfg-form" action="<?php echo \esc_url($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="handler_id" value="stub_profile">
                <?php \wp_nonce_field('tfg_stub_form', 'tfg_stub_nonce'); ?>

                <!-- Persist member type + post_id -->
                <input type="hidden" name="member_type" value="<?php echo \esc_attr($memberType); ?>">
                <input type="hidden" name="post_id" value="<?php echo \esc_attr($postId); ?>">

                <?php if ($mode === 'edit' && $postId): ?>
                    <input type="hidden" name="save_stub_edit" value="1">
                <?php else: ?>
                    <input type="hidden" name="save_profile_stub" value="1">
                <?php endif; ?>

                <div class="tfg-field">
                    <label for="contact_name">Contact Name <span class="tfg-required">*</span></label>
                    <input type="text" id="contact_name" name="contact_name" required value="<?php echo \esc_attr($values['contact_name']); ?>">
                </div>

                <div class="tfg-field">
                    <label for="title_and_department">Title and Department <span class="tfg-required">*</span></label>
                    <input type="text" id="title_and_department" name="title_and_department" required value="<?php echo \esc_attr($values['title_and_department']); ?>">
                </div>

                <div class="tfg-field">
                    <label for="contact_email">Contact Email <span class="tfg-required">*</span></label>
                    <input type="email" id="contact_email" name="contact_email" required value="<?php echo \esc_attr($values['contact_email']); ?>">
                </div>

                <div class="tfg-field">
                    <label for="organization_name">Organization Name <span class="tfg-required">*</span></label>
                    <input type="text" id="organization_name" name="organization_name" required value="<?php echo \esc_attr($values['organization_name']); ?>">
                </div>

                <div class="tfg-membership-type-box" style="margin-bottom:25px;">
                    <label for="membership_type">Select Membership Type<span class="tfg-required">*</span></label>
                    <!-- <p style="margin-bottom:5px;">Select Membership Type:</p> -->
                    <label style="display:block; margin-bottom:2px;">
                        <input type="radio" name="member_type" value="university" <?php \checked($memberType, 'university'); ?> required> College or University
                    </label>
                    <label style="display:block; margin-bottom:2px;">
                        <input type="radio" name="member_type" value="agency" <?php \checked($memberType, 'agency'); ?> required> Recruiting Agency
                    </label>
                    <label style="display:block;">
                        <input type="radio" name="member_type" value="affiliate" <?php \checked($memberType, 'affiliate'); ?> required> Advertiser / Service Provider
                    </label>
                </div>

                <div class="tfg-form" style="margin-top:20px;">
                    <label for="website">Website <span class="tfg-required">*</span></label>
                    <input type="url" id="website" name="website" required value="<?php echo \esc_attr($values['website'] ?: 'https://'); ?>" class="tfg-input-wide">
                </div>

                <!-- Section divider -->
                <hr style="margin:2em 0; border:none; border-top:1px solid #ccc;">

                <!-- Password Section -->
                <h3 style="margin-bottom:1em;">2. Set Your Password</h3>
                <div class="tfg-login-row">
                    <div class="child-1">
                        <div class="tfg-password-combo">
                            <input type="password" name="new_password" id="new_password" tabindex="1"
                                   class="tfg-password-input tfg-font-base" placeholder="Enter Password"
                                   autocomplete="off" readonly onfocus="this.removeAttribute('readonly');" required>
                            <button type="button" class="tfg-toggle-password" tabindex="-1" onclick="tfgTogglePassword(this)" aria-label="Show password" title="Show password"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                        </div>
                    </div>
                    <div class="child-2">
                        <div class="tfg-password-combo">
                            <input type="password" name="confirm_password" id="confirm_password" tabindex="2"
                                   class="tfg-password-input tfg-font-base" placeholder="Confirm Password"
                                   autocomplete="off" readonly onfocus="this.removeAttribute('readonly');" required>
                            <button type="button" class="tfg-toggle-password" tabindex="-1" onclick="tfgTogglePassword(this)" aria-label="Show password" title="Show password"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
                        </div>
                    </div>
                </div>

                <!-- Section divider -->
                <hr style="margin:2em 0; border:none; border-top:1px solid #ccc;">

                <!-- GDPR Section -->
                <h3 style="margin-bottom:1em;">3. Complete GDPR Agreement</h3>
                <div class="tfg-gdpr-reg-box">
                <label style="display:flex; align-items:flex-start; gap:0.5em;">
                        <input type="checkbox" id="gdpr_consent" name="gdpr_consent" value="1" required style="margin-top:0.3em; flex-shrink:0;">
                        <span>By checking this box, you affirm that you have read and agree to our TERMS OF USE regarding storage of the data submitted through this form.</span>
                    </label>
                </div>

                <!-- Section divider -->
                <hr style="margin:2em 0; border:none; border-top:1px solid #ccc;">

                <!-- Button row -->
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.3em; gap:1em;">
                    <div style="flex:1;">
                        <a href="<?php echo \esc_url(\site_url('/member-login/')); ?>" class="tfg-return-button">
                            ← Return to Login
                        </a>
                    </div>
                    <div style="flex:1; text-align:right;">
                        <button type="submit" name="save_profile_stub" value="1" class="tfg-button tfg-font-base">Submit Registration</button>
                    </div>
                </div>

                <?php if ($mode === 'edit' && $postId): ?>
                <div class="tfg-field" style="margin-top:1em;">
                    <button type="submit" name="submit_profile_stub" value="1" class="tfg-button tfg-font-base">Submit</button>
                    <button type="submit" name="return_to_edit" value="1" class="tfg-button tfg-font-base">Return</button>
                </div>
                <?php endif; ?>
            </form>
        </div>
        <?php

        return (string) \ob_get_clean();
    }

    /* =========================
       Handler
       ========================= */
    public static function handleStubSubmission(): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \TFG\Core\Utils::info('[TFG SystemGuard] Skipped handleStubSubmission due to REST/CRON/CLI/AJAX context');
            return;
        }

        if (\class_exists(FormRouter::class)) {
            if (!FormRouter::matches('stub_profile')) {
                return;
            }
        } else {
            // Fallback router match
            $isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
            $hid    = isset($_POST['handler_id']) ? (string) \wp_unslash($_POST['handler_id']) : '';
            if (!($isPost && $hid === 'stub_profile')) {
                return;
            }
        }

        \TFG\Core\Utils::info('[TFG HANDLE STUB] Entering handleStubSubmission()');

        // Check if headers already sent
        if (\headers_sent($file, $line)) {
            \TFG\Core\Utils::info("[TFG HANDLE STUB] ⚠️ Headers already sent at {$file}:{$line}");
        } else {
            \TFG\Core\Utils::info('[TFG HANDLE STUB] Headers NOT sent yet - good');
        }

        // Confirm submit button
        if (!isset($_POST['save_profile_stub'])) {
            \TFG\Core\Utils::info('[TFG HANDLE STUB] ❌ No submit button.');
            return;
        }

        // CSRF
        if (empty($_POST['tfg_stub_nonce']) || !\wp_verify_nonce($_POST['tfg_stub_nonce'], 'tfg_stub_form')) {
            \TFG\Core\Utils::info('[TFG HANDLE STUB] ❌ Nonce check failed.');
            return;
        }

        // Sanitize + validate payload
        $memberType = \sanitize_text_field(\wp_unslash($_POST['member_type'] ?? ''));
        if (!\in_array($memberType, self::VALID_MEMBER_TYPES, true)) {
            \TFG\Core\Utils::info("[TFG HANDLE STUB] ❌ Invalid member_type: {$memberType}");
            echo '<p class="tfg-error">Invalid member type.</p>';
            return;
        }

        $contactName        = \sanitize_text_field(\wp_unslash($_POST['contact_name'] ?? ''));
        $titleAndDepartment = \sanitize_text_field(\wp_unslash($_POST['title_and_department'] ?? ''));
        $contactEmail       = Utils::normalizeEmail(\wp_unslash($_POST['contact_email'] ?? ''));
        $organizationName   = \sanitize_text_field(\wp_unslash($_POST['organization_name'] ?? ''));
        $websiteRaw         = \wp_unslash($_POST['website'] ?? '');
        $website            = \esc_url_raw($websiteRaw);

        // Validate password fields
        $password     = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['confirm_password'] ?? '');
        $gdprConsent  = !empty($_POST['gdpr_consent']);

        // Validation - errors will cause form to re-render with preserved data
        if (!$contactName || !$titleAndDepartment || !$contactEmail || !$organizationName || !$website) {
            \TFG\Core\Utils::info('[TFG HANDLE STUB] ❌ Incomplete fields');
            $_SESSION['tfg_form_error'] = 'Please complete all required fields.';
            return;
        }

        if ($password !== $confirmation) {
            \TFG\Core\Utils::info('[TFG HANDLE STUB] ❌ Passwords do not match');
            $_SESSION['tfg_form_error'] = 'Passwords do not match. Please try again.';
            return;
        }

        $min_len = \defined('TFG_MIN_PASSWORD_LENGTH') ? (int) \TFG_MIN_PASSWORD_LENGTH : 8;
        if (\strlen($password) < $min_len) {
            \TFG\Core\Utils::info("[TFG HANDLE STUB] ❌ Password too short (min {$min_len})");
            $_SESSION['tfg_form_error'] = "Password must be at least {$min_len} characters.";
            return;
        }

        if (!$gdprConsent) {
            \TFG\Core\Utils::info('[TFG HANDLE STUB] ❌ GDPR not checked');
            $_SESSION['tfg_form_error'] = 'You must agree to the GDPR terms to continue.';
            return;
        }

        // Hash password
        $password_hash = \password_hash($password, \PASSWORD_DEFAULT);
        if (!$password_hash) {
            \TFG\Core\Utils::info('[TFG HANDLE STUB] ❌ Password hash failed');
            $_SESSION['tfg_form_error'] = 'System error: Failed to process password. Please try again.';
            return;
        }

        // Clear any previous errors
        unset($_SESSION['tfg_form_error']);

        // Fallback setters (ACF or postmeta)
        $set = static function (string $key, $value, int $pid) {
            if (\function_exists('update_field')) {
                \update_field($key, $value, $pid);
            } else {
                \update_post_meta($pid, $key, $value);
            }
        };

        // === CREATE PROFILE STUB ===
        \TFG\Core\Utils::info('[TFG HANDLE STUB] Creating NEW profile_stub…');

        $newPostId = \wp_insert_post([
            'post_type'   => 'profile_stub',
            'post_status' => 'draft',
            'post_title'  => $organizationName,
        ]);

        if (\is_wp_error($newPostId) || !$newPostId) {
            \TFG\Core\Utils::info('[TFG HANDLE STUB] ❌ wp_insert_post failed for profile_stub.');
            return;
        }

        $set('contact_name', $contactName, $newPostId);
        $set('title_and_department', $titleAndDepartment, $newPostId);
        $set('contact_email', $contactEmail, $newPostId);
        $set('member_type', $memberType, $newPostId);
        $set('organization_name', $organizationName, $newPostId);
        $set('website', $website, $newPostId);
        $set('email', $contactEmail, $newPostId);
        $set('institution_password_hash', $password_hash, $newPostId);
        $set('gdpr_consent', 1, $newPostId);

        // Generate member_id
        $member_id = '';
        if (\class_exists(\TFG\Features\Membership\MemberIdGenerator::class)) {
            $member_id = (string) \TFG\Features\Membership\MemberIdGenerator::getNextId($memberType);
            if ($member_id) {
                $set('member_id', $member_id, $newPostId);
                \TFG\Core\Utils::info("[TFG HANDLE STUB] ✅ Assigned member_id {$member_id}");
            }
        }

        \TFG\Core\Utils::info("[TFG HANDLE STUB] ✅ Created profile_stub ID {$newPostId} (type {$memberType}, member_id {$member_id})");

        // Transfer stub → member_profile and set cookies
        if (\class_exists(\TFG\Features\Membership\MemberGdprConsent::class)) {
            \TFG\Core\Utils::info("[TFG HANDLE STUB] Calling handleProfileTransferFromStub({$newPostId})");
            \TFG\Features\Membership\MemberGdprConsent::handleProfileTransferFromStub($newPostId);
            \TFG\Core\Utils::info('[TFG HANDLE STUB] Transfer completed, preparing redirect');
        } else {
            \TFG\Core\Utils::info('[TFG HANDLE STUB] ❌ MemberGdprConsent class not found!');
        }

        // Redirect to member dashboard
        \TFG\Core\Utils::info('[TFG HANDLE STUB] Attempting redirect to dashboard...');
        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect(\site_url('/member-dashboard/'));
            \TFG\Core\Utils::info('[TFG HANDLE STUB] ✅ Redirect issued');
            exit;
        } else {
            \TFG\Core\Utils::info('[TFG HANDLE STUB] ❌ Headers already sent, cannot redirect');
        }
    }

    /* =========================
       Helpers (added to prevent "undefined method" errors from other modules)
       ========================= */

    /**
     * Some legacy code (e.g., Stub_Access) referenced a prefix per post type.
     * Keep a conservative mapping here to avoid fatals.
     */
    public static function getPrefixForType(string $postType): string
    {
        $postType = \sanitize_key($postType);
        return match ($postType) {
            'profile_stub'   => 'STB',
            'member_profile' => 'MBR',
            default          => 'STB',
        };
    }

    /**
     * Simple member-id generator used by older flows that expected it here.
     * Prefer the centralized generator if available.
     */
    public static function generateMemberId(string $prefix = 'MBR', int $width = 5): string
    {
        $n   = Sequence::next('member_stub_seq', 1);
        $pad = \str_pad((string) $n, \max(1, $width), '0', STR_PAD_LEFT);
        return \sanitize_text_field($prefix) . $pad;
    }

    /* =========================
       Deprecated Snake_Case Shims
       ========================= */

    /** @deprecated Use renderStubForm() */
    public static function render_stub_form($atts): string
    {
        return self::renderStubForm($atts);
    }

    /** @deprecated Use handleStubSubmission() */
    public static function handle_stub_submission(): void
    {
        self::handleStubSubmission();
    }

    /** @deprecated Use getPrefixForType() */
    public static function get_prefix_for_type(string $post_type): string
    {
        return self::getPrefixForType($post_type);
    }

    /** @deprecated Use generateMemberId() */
    public static function generate_member_id(string $prefix = 'MBR', int $width = 5): string
    {
        return self::generateMemberId($prefix, $width);
    }
}

/**
 * Legacy class alias so existing code referencing TFG_Member_Stub_Manager keeps working.
 * Remove after you’ve updated all references.
 */
\class_alias(\TFG\Features\Membership\MemberStubManager::class, 'TFG_Member_Stub_Manager');
