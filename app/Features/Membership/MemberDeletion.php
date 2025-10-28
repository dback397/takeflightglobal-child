<?php

namespace TFG\Features\Membership;

final class MemberDeletion
{
    public static function handleDeletion(): void
    {
        // 1️⃣ Validate presence of POST variables
        if (!isset($_POST['member_id'], $_POST['_tfg_nonce'])) {
            return;
        }

        // 2️⃣ Verify nonce for CSRF protection
        if (!\wp_verify_nonce($_POST['_tfg_nonce'], 'tfg_delete_member')) {
            \TFG\Core\Utils::info('[TFG MemberDeletion] Nonce verification failed.');
            return;
        }

        // 3️⃣ Sanitize and validate identity
        $memberId = \sanitize_text_field($_POST['member_id']);
        $email    = \TFG\Core\Cookies::getMemberEmail();

        // 4️⃣ Verify the cookie signature / ownership
        if (!\TFG\Core\Cookies::verifyMember($memberId, $email)) {
            \TFG\Core\Utils::info("[TFG MemberDeletion] Cookie verification failed for {$memberId}");
            return;
        }

        // 5️⃣ Retrieve and delete member profile
        $profile = \get_posts([
            'post_type'  => 'member_profile',
            'meta_key'   => 'member_id',
            'meta_value' => $memberId,
        ]);

        if ($profile) {
            \wp_delete_post($profile[0]->ID, true); // true = force delete
            \TFG\Core\Utils::info("[TFG MemberDeletion] ✅ Deleted member {$memberId}");
        } else {
            \TFG\Core\Utils::info("[TFG MemberDeletion] ⚠️ No profile found for {$memberId}");
        }

        // 6️⃣ Clear authentication cookies
        \TFG\Core\Cookies::clearMemberCookies();

        // 7️⃣ Redirect to confirmation page
        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect(\home_url('/account-deleted/'));
            exit;
        }
    }
}
