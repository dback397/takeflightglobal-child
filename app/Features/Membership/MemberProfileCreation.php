<?php

namespace TFG\Features\Membership;

use TFG\Core\Utils;
use TFG\Core\Cookies;

final class MemberProfileCreation
{
    public static function init(): void
    {
        // Enable when you’re ready to wire the POST:
        // \add_action('init', [self::class, 'handleProfileTransfer']);
    }

    /**
     * Handle stub → final member_profile transfer + password set.
     * Expects POST: post_id, institution_password, institution_password_confirm
     */
    public static function handleProfileTransfer(): void
    {
        // If you prefer strict routing, re-enable your router:
        // if (!FormRouter::matches('profile_transfer')) return;

        \TFG\Core\Utils::info('[TFG Profile Transfer] Entering handleProfileTransfer()');

        $post_id  = isset($_POST['post_id']) ? \absint($_POST['post_id']) : 0;
        $password = (string) ($_POST['institution_password'] ?? '');
        $confirm  = (string) ($_POST['institution_password_confirm'] ?? '');

        if ($post_id <= 0 || $password === '' || $password !== $confirm) {
            \TFG\Core\Utils::info('[TFG Profile Creation] ❌ Missing post_id or password mismatch');
            return;
        }

        $stub = \get_post($post_id);
        if (!$stub || $stub->post_type !== 'profile_stub') {
            \TFG\Core\Utils::info("[TFG Profile Creation] ❌ Invalid stub post_type for ID: {$post_id}");
            return;
        }

        // Pull required fields from the stub (ACF first, meta fallback)
        $member_type = \function_exists('get_field') ? (string) \get_field('member_type', $post_id) : (string) \get_post_meta($post_id, 'member_type', true);
        $member_id   = \function_exists('get_field') ? (string) \get_field('member_id',   $post_id) : (string) \get_post_meta($post_id, 'member_id',   true);
        $org_name    = \function_exists('get_field') ? (string) \get_field('organization_name', $post_id) : (string) \get_post_meta($post_id, 'organization_name', true);
        $email       = \function_exists('get_field') ? (string) \get_field('contact_email',     $post_id) : (string) \get_post_meta($post_id, 'contact_email',     true);

        $member_id = Utils::normalizeMemberId($member_id ?? '');
        $email     = Utils::normalizeEmail($email ?? '');

        if ($member_type === '' || $member_id === '') {
            \TFG\Core\Utils::info("[TFG Profile Creation] ❌ Missing member_type/member_id on stub {$post_id}");
            return;
        }

        // Hash & save password back to stub (keeps source-of-truth intact)
        $hash = \password_hash($password, \defined('MEMBER_PASSWORD_DEFAULT') ? MEMBER_PASSWORD_DEFAULT : PASSWORD_DEFAULT);
        if (!$hash) {
            \TFG\Core\Utils::info('[TFG Profile Creation] ❌ password_hash() failed');
            return;
        }
        if (\function_exists('update_field')) {
            \update_field('institution_password_hash', $hash, $post_id);
        } else {
            \update_post_meta($post_id, 'institution_password_hash', $hash);
        }

        // Create final member profile (your unified CPT)
        $new_post_id = \wp_insert_post([
            'post_type'   => 'member_profile',
            'post_status' => 'pending',
            'post_title'  => ($org_name !== '' ? $org_name : $member_id),
        ], true);

        if (\is_wp_error($new_post_id) || !$new_post_id) {
            $msg = \is_wp_error($new_post_id) ? $new_post_id->get_error_message() : 'unknown';
            \TFG\Core\Utils::info("[TFG Profile Creation] ❌ Failed to create member_profile from stub {$post_id}: {$msg}");
            return;
        }

        // Copy all ACF fields from stub → final profile
        $fields = \function_exists('get_fields') ? \get_fields($post_id) : [];
        if (\is_array($fields) && !empty($fields)) {
            foreach ($fields as $key => $value) {
                if (\function_exists('update_field')) {
                    \update_field($key, $value, $new_post_id);
                } else {
                    \update_post_meta($new_post_id, $key, $value);
                }
            }
        }

        // Ensure active + timestamp
        if (\function_exists('update_field')) {
            \update_field('is_active', 1, $new_post_id);
            \update_field('registration_date', \current_time('mysql'), $new_post_id);
        } else {
            \update_post_meta($new_post_id, 'is_active', 1);
            \update_post_meta($new_post_id, 'registration_date', \current_time('mysql'));
        }

        \TFG\Core\Utils::info("[TFG Profile Creation] ✅ Final profile created: ID={$new_post_id} from stub ID={$post_id}");

        // Trusted member session (HttpOnly HMAC cookie + UI flag)
        if (\class_exists(Cookies::class)) {
            Cookies::setMemberCookie($member_id, $email ?: '');
        } else {
            // Fallback (not recommended for production)
            \setcookie('member_authenticated', '1', \time() + 3600, '/');
            \setcookie('member_id', $member_id, \time() + 3600, '/');
            \setcookie('member_type', $member_type, \time() + 3600, '/');
        }

        // Redirect to dashboard
        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect(\site_url('/member-dashboard/?created=' . $new_post_id));
            exit;
        }
    }
}
