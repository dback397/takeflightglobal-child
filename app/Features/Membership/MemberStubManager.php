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
use \TFG\Features\Membership\MemberFormUtilities;


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
        \error_log('[TFG RENDER STUB] Entering renderStubForm()');

        // 1) Resolve member_type (shortcode attr → POST on submit)
        $atts        = \shortcode_atts(['type' => ''], $atts);
        $memberType  = \sanitize_text_field($atts['type']);

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['member_type'])) {
            $memberType = \sanitize_text_field(\wp_unslash($_POST['member_type']));
        }

        if ($memberType !== '' && !\in_array($memberType, self::VALID_MEMBER_TYPES, true)) {
            \error_log("Invalid member type resolved in render: {$memberType}");
            return '<p class="tfg-error">Invalid member type.</p>';
        }

        // 2) Edit vs New
        $postId = \absint($_GET['post_id'] ?? 0);
        $mode   = $postId ? 'edit' : 'new';
        \error_log("Rendering stub form: mode={$mode}, post_id={$postId}, member_type={$memberType}");

        // 3) Prefill values on edit
        $values = [
            'contact_name'         => '',
            'title_and_department' => '',
            'contact_email'        => '',
            'organization_name'    => '',
            'website'              => '',
        ];

        if ($mode === 'edit' && $postId) {
            foreach ($values as $key => &$val) {
                $val = \function_exists('get_field')
                    ? (string) \get_field($key, $postId)
                    : (string) \get_post_meta($postId, $key, true);
                \error_log("Editing value {$key} = {$val}");
            }
            unset($val);
        }

        \ob_start();

        // Header helper echoes; don’t concatenate it
        if (\class_exists(MemberFormUtilities::class)) {
            $emailForHeader = $postId
                ? (\function_exists('get_field') ? (\get_field('contact_email', $postId) ?: '') : (\get_post_meta($postId, 'contact_email', true) ?: ''))
                : '';
            MemberFormUtilities::stubAccessHeader($emailForHeader);
        }

        ?>
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
                    <p style="margin-bottom:5px;"><strong>Select Membership Type:</strong></p>
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

                <div class="tfg-field" style="margin-top:25px;">
                    <button type="submit" class="tfg-submit">Save and Continue</button>
                    <?php if ($mode === 'edit' && $postId): ?>
                        <button type="submit" name="submit_profile_stub" value="1" class="tfg-submit">Submit</button>
                        <button type="submit" name="return_to_edit" value="1" class="tfg-submit">Return</button>
                    <?php endif; ?>
                </div>
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
            \error_log('[TFG SystemGuard] Skipped handleStubSubmission due to REST/CRON/CLI/AJAX context');
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

        \error_log('[TFG HANDLE STUB] Entering handleStubSubmission()');

        // Confirm which submit path we’re on
        $isNew  = isset($_POST['save_profile_stub']);
        $isEdit = isset($_POST['save_stub_edit']);
        if (!$isNew && !$isEdit) {
            \error_log('[TFG HANDLE STUB] ❌ No matching submit button.');
            return;
        }

        // CSRF
        if (empty($_POST['tfg_stub_nonce']) || !\wp_verify_nonce($_POST['tfg_stub_nonce'], 'tfg_stub_form')) {
            \error_log('[TFG HANDLE STUB] ❌ Nonce check failed.');
            return;
        }

        // Sanitize + validate payload
        $memberType = \sanitize_text_field(\wp_unslash($_POST['member_type'] ?? ''));
        if (!\in_array($memberType, self::VALID_MEMBER_TYPES, true)) {
            \error_log("[TFG HANDLE STUB] ❌ Invalid member_type: {$memberType}");
            echo '<p class="tfg-error">Invalid member type.</p>';
            return;
        }

        $contactName         = \sanitize_text_field(\wp_unslash($_POST['contact_name'] ?? ''));
        $titleAndDepartment  = \sanitize_text_field(\wp_unslash($_POST['title_and_department'] ?? ''));
        $contactEmail        = Utils::normalizeEmail(\wp_unslash($_POST['contact_email'] ?? ''));
        $organizationName    = \sanitize_text_field(\wp_unslash($_POST['organization_name'] ?? ''));
        $websiteRaw          = \wp_unslash($_POST['website'] ?? '');
        $website             = \esc_url_raw($websiteRaw);

        if (!$contactName || !$titleAndDepartment || !$contactEmail || !$organizationName || !$website) {
            echo '<p class="tfg-error">Please complete all required fields.</p>';
            return;
        }

        // Fallback setters (ACF or postmeta)
        $set = static function (string $key, $value, int $pid) {
            if (\function_exists('update_field')) {
                \update_field($key, $value, $pid);
            } else {
                \update_post_meta($pid, $key, $value);
            }
        };

        if ($isNew) {
            // === NEW STUB CREATION ===
            \error_log('[TFG HANDLE STUB] Creating NEW profile_stub…');

            $newPostId = \wp_insert_post([
                'post_type'   => 'profile_stub',
                'post_status' => 'draft',
                'post_title'  => $organizationName,
            ]);

            if (\is_wp_error($newPostId) || !$newPostId) {
                \error_log('[TFG HANDLE STUB] ❌ wp_insert_post failed for profile_stub.');
                echo '<p class="tfg-error">There was an error creating your profile. Please try again.</p>';
                return;
            }

            $set('contact_name',         $contactName,        $newPostId);
            $set('title_and_department', $titleAndDepartment, $newPostId);
            $set('contact_email',        $contactEmail,       $newPostId);
            $set('member_type',          $memberType,         $newPostId);
            $set('organization_name',    $organizationName,   $newPostId);
            $set('website',              $website,            $newPostId);

            \error_log("[TFG HANDLE STUB] ✅ Created profile_stub ID {$newPostId} (type {$memberType})");

            $redirectUrl = \add_query_arg([
                'post_id' => $newPostId,
                'mode'    => 'edit',
                'type'    => "{$memberType}_profile",
            ], \site_url('/gdpr-consent/'));

            if (!\headers_sent()) {
                \nocache_headers();
                \wp_safe_redirect($redirectUrl);
                exit;
            }
        }

        if ($isEdit) {
            // === EXISTING STUB EDIT ===
            $postId = \absint($_POST['post_id'] ?? 0);
            if (!$postId) {
                \error_log('[TFG HANDLE STUB] ❌ Missing post_id for edit.');
                echo '<p class="tfg-error">Missing profile reference.</p>';
                return;
            }

            \error_log("[TFG HANDLE STUB] Updating profile_stub ID {$postId}");

            $set('member_type',          $memberType,         $postId);
            $set('contact_name',         $contactName,        $postId);
            $set('title_and_department', $titleAndDepartment, $postId);
            $set('contact_email',        $contactEmail,       $postId);
            $set('organization_name',    $organizationName,   $postId);
            $set('website',              $website,            $postId);

            $redirectUrl = \add_query_arg([
                'post_id' => $postId,
                'mode'    => 'edit',
                'type'    => "{$memberType}_profile",
            ], \site_url('/gdpr-consent/'));

            if (!\headers_sent()) {
                \nocache_headers();
                \wp_safe_redirect($redirectUrl);
                exit;
            }
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
        $n = Sequence::next('member_stub_seq', 1);
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
