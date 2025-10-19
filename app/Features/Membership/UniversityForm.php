<?php

namespace TFG\Features\Membership;

use TFG\Core\FormRouter;
use TFG\Core\Utils;

/**
 * UniversityForm
 * - Renders and handles submission of a university profile form.
 * - Prefills data if a profile exists for the current user
 * - Allows only one university_profile CPT entry per user
 *
 * Directory:
 * private static function get_profile_by_member_id($member_id)
 * private static function get_user_profile($user_id)
 * private static function handle_form_submission()
 * public static function render_university_interest_form()
 * public static function render_university_profile_display_form()
 */
final class UniversityForm
{
    public static function init(): void
    {
        \add_shortcode('tfg_university_profile_display', [self::class, 'render_university_profile_display_form']);
        \add_action('init', [self::class, 'handleUniversityFormSubmission']);
        // \add_action('init', [self::class, 'handle_new_profile_submission']); // for new submissions
    }

    private static function getProfileByMemberId($member_id)
    {
        $posts = \get_posts([
            'post_type'   => 'member_profile',
            'meta_key'    => 'member_id',
            'meta_value'  => $member_id,
            'post_status' => ['publish', 'pending', 'draft'],
            'numberposts' => 1,
        ]);
        return $posts ? $posts[0]->ID : null;
    }

    private static function getUserProfile($user_id)
    {
        \error_log("[TFG UNI GETUSER] Entering get_user_profile($user_id)");
        $member_id = \get_user_meta($user_id, 'member_id', true);
        if (!$member_id) return null;

        $posts = \get_posts([
            'post_type'   => 'member_profile',
            'meta_key'    => 'member_id',
            'meta_value'  => $member_id,
            'post_status' => ['publish', 'pending', 'draft'],
            'numberposts' => 1,
        ]);

        return $posts ? $posts[0]->ID : null;
    }

    public static function handleUniversityFormSubmission(): void
    {
        if (!FormRouter::matches('university-form')) return;
        \error_log('[TFG UNI SUBMIT] POST ID received in handler: ' . ($_POST['post_id'] ?? 'MISSING'));

        if (!\is_user_logged_in()) return;

        $user_id = \get_current_user_id();
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || \get_post_type($post_id) !== 'member_profile') {
            \error_log('[TFG UNI SUBMIT] Invalid or missing post ID on submission.');
            return;
        }

        // Update fields
        \update_field('contact_name', \sanitize_text_field($_POST['contact_name']), $post_id);
        \update_field('title_and_department', \sanitize_text_field($_POST['title_and_department']), $post_id);
        \update_field('contact_email', \sanitize_email($_POST['contact_email']), $post_id);
        \update_field('organization_name', \sanitize_text_field($_POST['organization_name']), $post_id);
        \update_field('website', \esc_url_raw($_POST['website']), $post_id);
        \update_field('programs', array_map('sanitize_text_field', $_POST['programs'] ?? []), $post_id);
        \update_field('other_programs', \sanitize_text_field($_POST['other_programs']), $post_id);
        \update_field('comment', \sanitize_textarea_field($_POST['comment']), $post_id);

        if (!\get_field('member_id', $post_id) && isset($_COOKIE['member_id'])) {
            \update_field('member_id', \sanitize_text_field($_COOKIE['member_id']), $post_id);
        }

        \error_log("[TFG UNI SUBMIT] Profile updated for post ID $post_id");
    }

    public static function handleNewProfileSubmission(): void
    {
        \error_log("[TFG NEW PROFILE] Entering handle_new_profile_submission()");
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        if (!\is_user_logged_in()) return;
        if (!isset($_POST['tfg_university_new_form'])) return;

        $user_id = \get_current_user_id();
        $existing_post_id = self::getUserProfile($user_id);
        if ($existing_post_id) {
            \error_log("[TFG NEW PROFILE] User $user_id already has a profile (Post ID: $existing_post_id).");
            return;
        }

        $post_id = \wp_insert_post([
            'post_type'   => 'member_profile',
            'post_status' => 'pending',
            'post_title'  => \sanitize_text_field($_POST['organization_name'] ?? 'Unnamed Organization'),
        ]);

        if (!$post_id || \is_wp_error($post_id)) {
            \error_log("[TFG NEW PROFILE] Failed to create new post $post_id.");
            return;
        }

        \update_field('submitted_by_user', $user_id, $post_id);
        \update_field('member_id', \get_user_meta($user_id, 'member_id', true), $post_id);
        \update_field('member_type', $_POST['member_type'], $post_id);
        \update_field('contact_name', \sanitize_text_field($_POST['contact_name']), $post_id);
        \update_field('title_and_department', \sanitize_text_field($_POST['title_and_department']), $post_id);
        \update_field('contact_email', \sanitize_email($_POST['contact_email']), $post_id);
        \update_field('organization_name', \sanitize_text_field($_POST['organization_name']), $post_id);
        \update_field('website', \esc_url_raw($_POST['website']), $post_id);
        \update_field('programs', array_map('sanitize_text_field', $_POST['programs'] ?? []), $post_id);
        \update_field('other_programs', \sanitize_text_field($_POST['other_programs']), $post_id);
        \update_field('comment', \sanitize_textarea_field($_POST['comment']), $post_id);
        \update_field('gdpr_consent', true, $post_id);

        \error_log("[TFG NEW PROFILE] New university profile created: $post_id");
    }

    public static function renderUniversityInterestForm($post_id = 0): string
    {
        if (!\is_user_logged_in()) {
            return '<p>You must be logged in to complete this form.</p>';
        }

        $member_id = $post_id ? \get_field('member_id', $post_id) : \get_user_meta(\get_current_user_id(), 'member_id', true);
        $member_id = Utils::normalizeMemberId($member_id);

        if (!$member_id) return '<p>Invalid member ID.</p>';

        $values = [
            'contact_name'         => '',
            'title_and_department' => '',
            'contact_email'        => '',
            'organization_name'    => '',
            'website'              => '',
            'programs'             => [],
            'other_programs'       => '',
            'comment'              => '',
        ];

        $existing_post = $post_id && \get_post_status($post_id) ? $post_id : self::getProfileByMemberId($member_id);
        if ($existing_post) {
            foreach ($values as $key => $v) {
                $val = \get_field($key, $existing_post);
                $values[$key] = \is_array($val) ? $val : \esc_attr($val);
            }
        }

        ob_start();
        ?>
        <!-- form HTML unchanged -->
        <?php
        return (string) ob_get_clean();
    }

    public static function renderUniversityProfileDisplayForm(): string
    {
        $fields = [
            'contact_name'        => 'Contact Name',
            'title_and_department'=> 'Title and Department',
            'contact_email'       => 'Contact Email',
            'organization_name'   => 'University Name',
            'website'             => 'Website',
            'programs'            => 'Programs',
            'other_programs'      => 'Other Programs',
            'comment'             => 'Comment',
        ];

        $values = array_fill_keys(array_keys($fields), '');

        if (isset($_COOKIE['member_id']) && ($_COOKIE['member_authenticated'] ?? '') === '1') {
            $member_id = Utils::normalizeMemberId($_COOKIE['member_id']);
            $profile = \get_posts([
                'post_type'   => 'member_profile',
                'meta_key'    => 'member_id',
                'meta_value'  => $member_id,
                'posts_per_page' => 1,
            ]);

            if (!empty($profile)) {
                $post_id = $profile[0]->ID;
                foreach ($fields as $key => $label) {
                    $field_value = \get_field($key, $post_id);
                    $values[$key] = \is_array($field_value) ? implode(', ', $field_value) : $field_value;
                }
            }
        }

        ob_start();
        ?>
        <!-- display form HTML unchanged -->
        <?php
        return (string) ob_get_clean();
    }

    public static function renderEditFormOld($atts = []): string
    {
        $atts = \shortcode_atts(['post_id' => ''], $atts);
        $post_id = absint($atts['post_id']);
        if (!$post_id) return '<p>Invalid post ID.</p>';

        $post_type = \get_post_type($post_id);
        if ($post_type === 'member_profile') {
            return self::renderUniversityInterestForm();
        }
        return '<p>Edit form not available for this member type.</p>';
    }
}

// Legacy alias
\class_alias(\TFG\Features\Membership\UniversityForm::class, 'TFG_University_Form');
