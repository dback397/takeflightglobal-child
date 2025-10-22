<?php
// ✅ TFG System Guard injected by Cursor – prevents REST/CRON/CLI/AJAX interference
namespace TFG\Features\Membership;

use TFG\Core\FormRouter;
use TFG\Core\Utils;
use TFG\Core\Cookies;
use TFG\Features\Membership\MemberProfileDisplay;
use TFG\Features\Membership\MemberIDGenerator;

/**
 * GDPR consent + password setup, then stub → profile transfer.
 */
final class MemberGdprConsent
{
    public static function init(): void
    {
        \add_shortcode('gdpr_consent_form', [self::class, 'renderGdprForm']);
        \add_action('init', [self::class, 'handleGdprSubmission']);
        \add_action('init', [self::class, 'handlePasswordSubmission']);
    }

    /* ---------------- Render ---------------- */

    public static function renderGdprForm(): string
    {
        $post_id = isset($_GET['post_id']) ? \absint($_GET['post_id']) : 0;
        if (!$post_id) {
            \TFG\Core\Utils::info('[TFG_GDPR Form] Missing post_id in render_gdpr_form');
            return '<p>Error: Missing profile reference.</p>';
        }

        // Password step (after consent)
        if ((isset($_GET['submitted']) && $_GET['submitted'] === '1') || isset($_POST['submit_password'])) {
            $member_id = \function_exists('get_field') ? (string) \get_field('member_id', $post_id) : '';
            \ob_start(); ?>
            <h2>✅ Profile Submission Successful</h2>
            <p><strong>Please record your Member ID:</strong> <?php echo \esc_html($member_id); ?></p>

            <form method="POST" class="tfg-member-login-form">
                <div class="tfg-login-row">
                    <input type="hidden" name="handler_id" value="gdpr_password">
                    <input type="hidden" name="post_id" value="<?php echo \esc_attr((string) $post_id); ?>">
                    <?php \wp_nonce_field('tfg_gdpr_set_password', '_tfg_nonce'); ?>
                    <?php \TFG\Core\Utils::info("[TFG_GDPR Form] Rendering password form with post_id = {$post_id}"); ?>

                    <div class="child-1">
                        <div class="tfg-password-wrapper">
                            <input type="password" name="new_password" id="new_password"
                                   class="tfg-password-input tfg-font-base" placeholder="Enter Password"
                                   autocomplete="new-password" required>
                            <button type="button" class="tfg-toggle-password" onclick="tfgTogglePassword()" aria-label="Show password">👁️</button>
                        </div>
                    </div>

                    <div class="child-2">
                        <div class="tfg-password-wrapper">
                            <input type="password" name="confirm_password" id="confirm_password"
                                   class="tfg-password-input tfg-font-base" placeholder="Confirm Password"
                                   autocomplete="new-password" required>
                            <button type="button" class="tfg-toggle-password" onclick="tfgTogglePassword()" aria-label="Show password">👁️</button>
                        </div>
                    </div>

                    <div class="child-3">
                        <button type="submit" name="submit_password" class="tfg-login-button tfg-font-base">Save</button>
                    </div>
                </div>
            </form>

            <div style='margin-top:1.5em; display:flex; flex-wrap:wrap; gap:1em;'>
                <?php
                \TFG\Core\Utils::info("[TFG_GDPR Form] Password layout post_id = {$post_id}");
                if (\class_exists('MemberProfileDisplay')) {
                    echo MemberProfileDisplay::renderProfileColumns($post_id);
                }
                ?>
            </div>
            <?php
            return (string) \ob_get_clean();
        }

        // Initial consent step
        \ob_start(); ?>
        <h2>GDPR Agreement</h2>
        <form method="POST" class="tfg-gdpr-form">
            <input type="hidden" name="handler_id" value="gdpr_consent">
            <input type="hidden" name="post_id" value="<?php echo \esc_attr((string) $post_id); ?>">
            <?php \wp_nonce_field('tfg_gdpr_consent', '_tfg_nonce'); ?>

            <div class="tfg-gdpr-box" style="margin-top:10px;">
                <label style="margin:0;">
                    <input type="checkbox" id="gdpr_consent" name="gdpr_consent" value="1" required>
                    By checking this box, you affirm that you have read and agree to our TERMS OF USE regarding storage of the data submitted through this form.
                </label>
            </div>
            <button type="submit" class="tfg-button" name="submit_gdpr_consent" style="margin-top:10px;">Submit Consent</button>
        </form>
        <?php
        return (string) \ob_get_clean();
    }

    /* ---------------- Handlers ---------------- */

    public static function handleGdprSubmission(): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \TFG\Core\Utils::info('[TFG SystemGuard] Skipped handleGdprSubmission due to REST/CRON/CLI/AJAX context');
            return;
        }

        if (!\class_exists(FormRouter::class) || !FormRouter::matches('gdpr_consent')) return;
        \TFG\Core\Utils::info('[TFG GDPR Handle] Entering handle_gdpr_submission()');

        if (empty($_POST['_tfg_nonce']) || !\wp_verify_nonce($_POST['_tfg_nonce'], 'tfg_gdpr_consent')) {
            \TFG\Core\Utils::info('[TFG GDPR Handle] Nonce failure');
            return;
        }

        $stub_id = isset($_POST['post_id']) ? \absint($_POST['post_id']) : 0;
        if (!$stub_id || \get_post_type($stub_id) !== 'profile_stub') {
            \TFG\Core\Utils::info('[TFG GDPR Handle] Invalid or missing profile_stub post_id');
            return;
        }

        if (empty($_POST['gdpr_consent'])) {
            \TFG\Core\Utils::info('[TFG GDPR Handle] Missing GDPR checkbox');
            return;
        }

        // Assign member ID if not present
        $member_id   = \function_exists('get_field') ? (string) \get_field('member_id', $stub_id) : '';
        $member_type = \function_exists('get_field') ? (string) \get_field('member_type', $stub_id) : '';
        if (!$member_id) {
            if (!$member_type) {
                \TFG\Core\Utils::info("[TFG GDPR Handle] Missing member_type on stub {$stub_id}");
                return;
            }
            // Prefer namespaced generator if available; fallback to legacy class.
            if (\class_exists(MemberIdGenerator::class)) {
                $member_id = (string) MemberIdGenerator::getNextId($member_type);
            } else {
                \TFG\Core\Utils::info('[TFG GDPR Handle] Missing MemberIdGenerator');
                return;
            }
            if ($member_id) {
                \update_field('member_id', $member_id, $stub_id);
                \TFG\Core\Utils::info("[TFG GDPR Handle] Assigned member_id {$member_id} to stub {$stub_id}");
            }
        }

        // Store consent
        \update_field('gdpr_consent', 1, $stub_id);

        // Redirect to password setup
        $pw_url = \add_query_arg(['post_id' => $stub_id, 'submitted' => '1'], \site_url('/gdpr-consent/'));
        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect($pw_url);
            exit;
        }
    }

    public static function handlePasswordSubmission(): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \TFG\Core\Utils::info('[TFG SystemGuard] Skipped handlePasswordSubmission due to REST/CRON/CLI/AJAX context');
            return;
        }

        if (!\class_exists(FormRouter::class) || !FormRouter::matches('gdpr_password')) return;
        \TFG\Core\Utils::info('[TFG Password] Entering handle_password_submission()');

        if (empty($_POST['_tfg_nonce']) || !\wp_verify_nonce($_POST['_tfg_nonce'], 'tfg_gdpr_set_password')) {
            \TFG\Core\Utils::info('[TFG Password] Nonce failure');
            return;
        }
        if (empty($_POST['submit_password'])) return;

        $post_id = isset($_POST['post_id']) ? \absint($_POST['post_id']) : 0;
        if (!$post_id || \get_post_type($post_id) !== 'profile_stub') {
            \TFG\Core\Utils::info('[TFG Password] Missing/invalid post_id in password submission');
            return;
        }

        $min_len      = \defined('TFG_MIN_PASSWORD_LENGTH') ? (int) \TFG_MIN_PASSWORD_LENGTH : 8;
        $password     = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['confirm_password'] ?? '');

        if ($password !== $confirmation) {
            \TFG\Core\Utils::info("[TFG Password] Passwords do not match for post_id {$post_id}");
            return;
        }
        if (\strlen($password) < $min_len) {
            \TFG\Core\Utils::info("[TFG Password] Password too short for post_id {$post_id}");
            return;
        }

        $hash = \password_hash($password, \PASSWORD_DEFAULT);
        if (!$hash) {
            \TFG\Core\Utils::info("[TFG Password] Failed to hash password for post_id {$post_id}");
            return;
        }

        // Store hash on stub (copied during transfer)
        \update_field('institution_password_hash', $hash, $post_id);
        \TFG\Core\Utils::info("[TFG Password] ✅ Password hash stored for stub {$post_id}");

        // Transfer stub → member_profile and set cookies
        self::handleProfileTransferFromStub($post_id);

        // Redirect to member login
        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect(\site_url('/member-login/'));
            exit;
        }
    }

    /* ---------------- Transfer ---------------- */

    public static function handleProfileTransferFromStub(int $stub_id): void
    {
        if (\TFG\Core\Utils::isSystemRequest()) {
            \TFG\Core\Utils::info('[TFG SystemGuard] Skipped handleProfileTransferFromStub due to REST/CRON/CLI/AJAX context');
            return;
        }

        \TFG\Core\Utils::info("[TFG Profile Transfer] Entering handle_profile_transfer_from_stub({$stub_id})");

        if ($stub_id <= 0 || \get_post_type($stub_id) !== 'profile_stub') {
            \TFG\Core\Utils::info("[TFG Profile Transfer] ❌ Invalid stub_id or post_type mismatch");
            return;
        }

        $email       = \function_exists('get_field') ? (string) \get_field('email', $stub_id) : (string) \get_post_meta($stub_id, 'email', true);
        $member_id   = \function_exists('get_field') ? (string) \get_field('member_id', $stub_id) : (string) \get_post_meta($stub_id, 'member_id', true);
        $member_type = \function_exists('get_field') ? (string) \get_field('member_type', $stub_id) : (string) \get_post_meta($stub_id, 'member_type', true);
        $org_name    = \function_exists('get_field') ? (string) \get_field('organization_name', $stub_id) : (string) \get_post_meta($stub_id, 'organization_name', true);

        $member_id = Utils::normalizeMemberId($member_id ?? '');
        $email     = Utils::normalizeEmail($email ?? '');

        if ($member_id === '' || $member_type === '') {
            \TFG\Core\Utils::info("[TFG Profile Transfer] ❌ Missing member_id or member_type for stub {$stub_id}");
            return;
        }

        $new_post_id = \wp_insert_post([
            'post_type'   => 'member_profile',
            'post_status' => 'pending',
            'post_title'  => ($org_name !== '' ? $org_name : $member_id),
        ], true);

        if (\is_wp_error($new_post_id) || !$new_post_id) {
            $msg = \is_wp_error($new_post_id) ? $new_post_id->get_error_message() : 'unknown';
            \TFG\Core\Utils::info("[TFG Profile Transfer] ❌ Failed to create member_profile from stub {$stub_id}: {$msg}");
            return;
        }

        $fields = \function_exists('get_fields') ? \get_fields($stub_id) : [];
        if (\is_array($fields) && !empty($fields)) {
            foreach ($fields as $key => $value) {
                if (\function_exists('update_field')) {
                    \update_field($key, $value, $new_post_id);
                } else {
                    \update_post_meta($new_post_id, $key, $value);
                }
            }
            \TFG\Core\Utils::info("[TFG Profile Transfer] ✅ Copied fields from stub {$stub_id} → profile {$new_post_id}");
        } else {
            \TFG\Core\Utils::info("[TFG Profile Transfer] ⚠️ No fields found on stub {$stub_id}");
        }

        if (\function_exists('update_field')) {
            \update_field('is_active', 1, $new_post_id);
            \update_field('registration_date', \current_time('mysql'), $new_post_id);
        } else {
            \update_post_meta($new_post_id, 'is_active', 1);
            \update_post_meta($new_post_id, 'registration_date', \current_time('mysql'));
        }
        \TFG\Core\Utils::info("[TFG Profile Transfer] ✅ Activated + timestamped profile {$new_post_id}");

        if (\class_exists(Cookies::class)) {
            Cookies::setMemberCookie($member_id, $email ?: '');
            \TFG\Core\Utils::info("[TFG Profile Transfer] ✅ Set member cookie for ID {$member_id}");
        } else {
            \TFG\Core\Utils::info("[TFG Profile Transfer] ⚠️ Cookies class missing; cookie not set");
        }

        \TFG\Core\Utils::info("[TFG Profile Transfer] ✅ Completed: stub={$stub_id} → member={$new_post_id} (ID={$member_id})");
    }
}

/* ---- Legacy alias for smooth migration ---- */
\class_alias(\TFG\Features\Membership\MemberGdprConsent::class, 'TFG_Member_GDPR_Consent');
