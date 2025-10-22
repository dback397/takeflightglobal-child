<?php

namespace TFG\Features\Membership;

use TFG\Core\Cookies;
use TFG\Core\Utils;
use TFG\Core\RedirectHelper;
use TFG\UI\ErrorModal;

final class MemberFormHandlers
{
    public static function init(): void
    {
        \add_action('init', [self::class, 'routeSubmission']);
    }

    public static function routeSubmission(): void
    {
        // 🔒 Skip background / system requests early
        if (Utils::isSystemRequest()) {
            \error_log('[TFG MemberFormHandlers] Skipping submission due to system request');
            return;
        }

        // Handle only POST forms
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        // Require valid subscription cookie
        if (!Cookies::isSubscribed()) {
            if (RedirectHelper::isOnPage('/subscribe')) {
                \error_log('[TFG FormHandlers] Redirect loop prevented: already on subscribe page');
                return;
            }

            \error_log('[TFG FormHandlers] No subscription cookie found, redirecting to subscribe');
            ErrorModal::showR('104', 20, \home_url('/subscribe'));
            return;
        }

        $type = isset($_POST['form_type']) ? \sanitize_key($_POST['form_type']) : '';
        if ($type === 'university') {
            self::handleUniversityForm($_POST);
            return;
        }

        // Future: handle agency/affiliate/etc.
    }

    private static function handleUniversityForm(array $data): void
    {
        $title = \sanitize_text_field($data['organization_name'] ?? 'Unnamed University');

        $post_id = \wp_insert_post([
            'post_type'   => 'member_profile',
            'post_status' => 'pending',
            'post_title'  => $title,
        ], true);

        if (\is_wp_error($post_id) || !$post_id) {
            \error_log('❌ Failed to insert member_profile post.');
            return;
        }

        // ACF fields (adjust keys if needed)
        \update_field('contact_name',        $data['contact_name']        ?? '', $post_id);
        \update_field('title_and_department',$data['title_and_department']?? '', $post_id);
        \update_field('contact_email',       $data['contact_email']       ?? '', $post_id);
        \update_field('member_type',         $data['member_type']         ?? 'university', $post_id);
        \update_field('organization_name',   $data['organization_name']   ?? '', $post_id);
        \update_field('website',             $data['website']             ?? '', $post_id);
        \update_field('programs',            $data['programs']            ?? '', $post_id);
        \update_field('other_program',       $data['other_program']       ?? '', $post_id);
        \update_field('comment',             $data['comment']             ?? '', $post_id);
        \update_field('gdpr_consent',        true,                               $post_id);

        if (\is_user_logged_in()) {
            \update_field('submitted_by_user', \get_current_user_id(), $post_id);
        }

        // Redirect to thanks page
        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect(\home_url('/thanks'));
            exit;
        }
    }

    /* ---- Legacy alias (optional; remove when all references updated) ---- */
    /** @deprecated Use MemberFormHandlers::init() */
    public static function init_legacy(): void { self::init(); }
}
