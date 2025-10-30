<?php

namespace TFG\Features\Membership;

final class MemberFormHandlers
{
    public static function init(): void
    {
        \add_action('init', [self::class, 'routeSubmission']);
    }

    public static function routeSubmission(): void
    {
        // --- 1. Master Guard: skip for REST, AJAX, CRON, or CLI
        if (\TFG\Core\Utils::isSystemRequest()) {
            \TFG\Core\Utils::info('[TFG MemberFormHandlers] 🛡 Skipping routeSubmission() — system request context: ' . current_action());
            return;
        }

        // --- 2. Only process actual POST form submissions
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (strtoupper($method) !== 'POST') {
            return;
        }

        // --- 3. Double-guard: ensure we're not running in heartbeat or autosave
        if (!empty($_POST['action']) && \in_array($_POST['action'], ['heartbeat', 'wp_autosave'], true)) {
            \TFG\Core\Utils::info('[TFG MemberFormHandlers] 🛡 Skipping due to heartbeat/autosave action');
            return;
        }

        // --- 4. Subscription validation (DISABLED - Allow non-subscribers to register as members)
        // Uncomment below if you want to require newsletter subscription before member registration
        /*
        if (!\TFG\Core\Cookies::isSubscribed()) {

            // Prevent redirect loops on /subscribe
            if (\TFG\Core\RedirectHelper::isOnPage('/subscribe')) {
                \TFG\Core\Utils::info('[TFG FormHandlers] ⚠️ Redirect loop prevented — already on /subscribe');
                return;
            }

            // Final sanity: if this somehow fires in a system request, bail anyway
            if (\TFG\Core\Utils::isSystemRequest()) {
                \TFG\Core\Utils::info('[TFG FormHandlers] 🛡 Redirect suppressed — system context (secondary guard)');
                return;
            }

            // --- 5. Safe redirect
            \TFG\Core\Utils::info('[TFG FormHandlers] ⚠️ No subscription cookie found — redirecting to /subscribe');
            \TFG\Core\RedirectHelper::safeRedirect(\home_url('/subscribe'));
            return;
        }
        */

        // --- 6. Route form types (legitimate POSTs)
        $type = isset($_POST['form_type']) ? \sanitize_key($_POST['form_type']) : '';

        switch ($type) {
            case 'university':
                self::handleUniversityForm($_POST);
                break;

                // Future cases: agency, affiliate, etc.
            default:
                \TFG\Core\Utils::info('[TFG MemberFormHandlers] Unknown form type: ' . $type);
                break;
        }
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
            \TFG\Core\Utils::info('❌ Failed to insert member_profile post.');
            return;
        }

        // ACF fields (adjust keys if needed)
        \update_field('contact_name', $data['contact_name'] ?? '', $post_id);
        \update_field('title_and_department', $data['title_and_department'] ?? '', $post_id);
        \update_field('contact_email', $data['contact_email'] ?? '', $post_id);
        \update_field('member_type', $data['member_type'] ?? 'university', $post_id);
        \update_field('organization_name', $data['organization_name'] ?? '', $post_id);
        \update_field('website', $data['website'] ?? '', $post_id);
        \update_field('programs', $data['programs'] ?? '', $post_id);
        \update_field('other_program', $data['other_program'] ?? '', $post_id);
        \update_field('comment', $data['comment'] ?? '', $post_id);
        \update_field('gdpr_consent', true, $post_id);

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
    public static function init_legacy(): void
    {
        self::init();
    }
}
