<?php
// === class-tfg-form-handlers.php ===

/**
 * ==========================================================
 * TFG_Form_Handlers
 * Handles form submissions for custom member forms
 * ==========================================================
 *
 * 📝 HOW TO USE
 * ----------------------------------------------------------
 * 1. Hook into `init` to route form POSTs:
 *    TFG_Form_Handlers::init();
 *
 * 2. Forms must:
 *    - Use method="POST"
 *    - Include a hidden input: 
 *        <input type="hidden" name="form_type" value="university">
 *
 * 3. Only handles logged-out email-based flow (no WPForms).
 *
 *
 * 🔐 GATEKEEPING LOGIC
 * ----------------------------------------------------------
 * • Only POST submissions are processed.
 * • If user is not subscribed (via cookie), they are redirected using:
 *      TFG_Error_Modal::show_r('104', 20, home_url('/subscribe'));
 * • Each form must include:
 *      <input type="hidden" name="form_type" value="university">
 *
 * Future expansions:
 * • Add support for other form types ('agency', 'affiliate', etc.)
 * • Integrate with stub-profile system
 */
class TFG_Form_Handlers {

    public static function init() {
        //add_action('init', [__CLASS__, 'route_submission']);
    }

    public static function route_submission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

        // 👇 Gatekeep: Require subscription cookie
        if (!TFG_Cookies::is_subscribed()) {
            return;
        }

        if (isset($_POST['form_type']) && $_POST['form_type'] === 'university') {
            self::handle_university_form($_POST);
        }

        // Add conditions here for 'agency', 'affiliate', etc. in the future
    }

    private static function handle_university_form($data) {
        $post_id = wp_insert_post([
            'post_type'   => 'member_profile',
            'post_status' => 'pending',
            'post_title'  => sanitize_text_field($data['organization_name'] ?? 'Unnamed University'),
        ]);

        if (!$post_id || is_wp_error($post_id)) {
            error_log('❌ Failed to insert member_profile post.');
            return;
        }

        update_field('contact_name', $data['contact_name'] ?? '', $post_id);
        update_field('title_and_department', $data['title_and_department'] ?? '', $post_id);
        update_field('contact_email', $data['contact_email'] ?? '', $post_id);
        update_field('member_type', $data['member_type'] ?? 'university', $post_id);
        update_field('organization_name', $data['organization_name'] ?? '', $post_id);
        update_field('website', $data['website'] ?? '', $post_id);
        update_field('programs', $data['programs'] ?? '', $post_id);
        update_field('other_program', $data['other_program'] ?? '', $post_id);
        update_field('comment', $data['comment'] ?? '', $post_id);
        update_field('gdpr_consent', true, $post_id);

        if (is_user_logged_in()) {
            update_field('submitted_by_user', get_current_user_id(), $post_id);
        }

        // ✅ Success
        wp_redirect(home_url('/thanks'));
        exit;
    }
}
