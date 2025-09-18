<?php
/**
 * University Profile Form
 * Renders and handles submission of a university profile form.
 * - Prefills data if a profile exists for the current user
 * - Allows only one university_profile CPT entry per user
 *
 * Directory
 * private static function get_profile_by_member_id($member_id)
 * private static function get_user_profile($user_id)
 * private static function handle_form_submission()
 * public static function render_university_interest_form()
 * public static function render_university_profile_display_form() 
 */

class TFG_University_Form {
    public static function init() {
    add_shortcode('tfg_university_profile_display', [self::class, 'render_university_profile_display_form']);
    add_action('init', [self::class, 'handle_university_form_submission']);          // ✅ for edits
    //add_action('init', [self::class, 'handle_new_profile_submission']);   // ✅ for new submissions
}

    private static function get_profile_by_member_id($member_id) {
    $posts = get_posts([
        'post_type'   => 'member_profile',
        'meta_key'    => 'member_id',
        'meta_value'  => $member_id,
        'post_status' => ['publish', 'pending', 'draft'],
        'numberposts' => 1,
    ]);
    return $posts ? $posts[0]->ID : null;
    }

       
private static function get_user_profile($user_id) {
    error_log("[TFG UNI GETUSER] Entering get_user_profile($user_id)");
    $member_id = get_user_meta($user_id, 'member_id', true);
    if (!$member_id) return null;

    $posts = get_posts([
        'post_type'   => 'member_profile',
        'meta_key'    => 'member_id',
        'meta_value'  => $member_id,
        'post_status' => ['publish', 'pending', 'draft'],
        'numberposts' => 1,
    ]);

    return $posts ? $posts[0]->ID : null;
    error_log("[TFG UNI GETUSER] Received post ID in form: $post_id");
}


public static function handle_university_form_submission() {
    if (!TFG_Form_Router::matches('university-form')) return;
    error_log('[TFG UNI SUBMIT] POST ID received in handler: ' . ($_POST['post_id'] ?? 'MISSING'));

    if (!is_user_logged_in()) return;

    $user_id = get_current_user_id();
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

    if (!$post_id || get_post_type($post_id) !== 'member_profile') {
        error_log('[TFG UNI SUBMIT] Invalid or missing post ID on submission.');
        return;
    }

    // Update fields
    update_field('contact_name', sanitize_text_field($_POST['contact_name']), $post_id);
    update_field('title_and_department', sanitize_text_field($_POST['title_and_department']), $post_id);
    update_field('contact_email', sanitize_email($_POST['contact_email']), $post_id);
    update_field('organization_name', sanitize_text_field($_POST['organization_name']), $post_id);
    update_field('website', esc_url_raw($_POST['website']), $post_id);
    update_field('programs', array_map('sanitize_text_field', $_POST['programs'] ?? []), $post_id);
    update_field('other_programs', sanitize_text_field($_POST['other_programs']), $post_id);
    update_field('comment', sanitize_textarea_field($_POST['comment']), $post_id);

    // Ensure member_id is preserved if not already set
    if (!get_field('member_id', $post_id) && isset($_COOKIE['member_id'])) {
        update_field('member_id', sanitize_text_field($_COOKIE['member_id']), $post_id);
    }

    // Optionally update this if you want a timestamp of last update
    // update_field('last_updated_on', current_time('mysql'), $post_id);

    error_log("[TFG UNI SUBMIT] Profile updated for post ID $post_id");
}

public static function handle_new_profile_submission() {
    error_log("[TFG NEW PROFILE] Entering handle_new_profile_submission()");
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!is_user_logged_in()) return;
    if (!isset($_POST['tfg_university_new_form'])) return; // Form intent check

    $user_id = get_current_user_id();

    // Check if user already has a profile
    $existing_post_id = self::get_user_profile($user_id);
    if ($existing_post_id) {
        error_log("[TFG NEW PROFILE] User $user_id already has a profile (Post ID: $existing_post_id). Aborting new creation.");
        return;
    }

    // Create new post
    $post_id = wp_insert_post([
        'post_type'   => 'member_profile',
        'post_status' => 'pending',
        'post_title'  => sanitize_text_field($_POST['organization_name'] ?? 'Unnamed Organization'),
    ]);

    if (!$post_id || is_wp_error($post_id)) {
        error_log("[TFG NEW PROFILE] Failed to create new post $post_id.");
        return;
    }

    // Save user relationship and data
    update_field('submitted_by_user', $user_id, $post_id);
    update_field('member_id', get_user_meta($user_id, 'member_id', true), $post_id);
    update_field('member_type', $_POST['member_type'], $post_id);
    update_field('contact_name', sanitize_text_field($_POST['contact_name']), $post_id);
    update_field('title_and_department', sanitize_text_field($_POST['title_and_department']), $post_id);
    update_field('contact_email', sanitize_email($_POST['contact_email']), $post_id);
    update_field('organization_name', sanitize_text_field($_POST['organization_name']), $post_id);
    update_field('website', esc_url_raw($_POST['website']), $post_id);
    update_field('programs', array_map('sanitize_text_field', $_POST['programs'] ?? []), $post_id);
    update_field('other_programs', sanitize_text_field($_POST['other_programs']), $post_id);
    update_field('comment', sanitize_textarea_field($_POST['comment']), $post_id);
    update_field('gdpr_consent', true, $post_id);

    error_log("[TFG NEW PROFILE] New university profile created: $post_id");
}



    public static function render_university_interest_form($post_id = 0) {
        error_log("[TFG UNI INTEREST] render_university_interest_form($post_id = 0) New university profile created: $post_id");
        if (!TFG_Member_Form_Utilities::is_user_logged_in()) return '<p>You must be logged in to complete this form.</p>';

        if ($post_id) {
            $member_id = get_field('member_id', $post_id);
        } else {
            $member_id = get_user_meta(get_current_user_id(), 'member_id', true);
        }
       $member_id = TFG_Utils::normalize_member_id($member_id);

        if (!$member_id) return '<p>Invalid member ID.</p>';


        
        $values = [
            'contact_name'      => '',
            'title_and_department' => '',
            'contact_email'     => '',
            'organization_name'   => '',
            'website'           => '',
            'programs'          => [],
            'other_programs'    => '',
            'comment'           => '',
        ];

        $existing_post = $post_id && get_post_status($post_id) ? $post_id : self::get_profile_by_member_id($member_id);



        if ($existing_post) {
            foreach ($values as $key => $v) {
                $val = get_field($key, $existing_post);
                $values[$key] = is_array($val) ? $val : esc_attr($val);
            }
        }

        ob_start();
        ?>
        <div class="tfg-form-wrapper-wide">
         
        <form method="POST" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>" class="tfg-form">

                <input type="hidden" name="handler_id" value="university_form">

                <?php if (!empty($post_id)): ?>
                    <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
                <?php endif; ?>

                <div class="tfg-field">
                    <label for="contact_name">Contact Name <span class="tfg-required">*</span></label>
                    <input type="text" id="contact_name" name="contact_name" value="<?php echo $values['contact_name']; ?>" required>
                </div>

                <div class="tfg-field">
                    <label for="title_and_department">Title and Department <span class="tfg-required">*</span></label>
                    <input type="text" id="title_and_department" name="title_and_department" value="<?php echo $values['title_and_department']; ?>" required>
                </div>

                <div class="tfg-field">
                    <label for="contact_email">Contact Email <span class="tfg-required">*</span></label>
                    <input type="email" id="contact_email" name="contact_email" value="<?php echo $values['contact_email']; ?>" required>
                </div>

                <div class="tfg-field">
                    <label for="university_name">University Name <span class="tfg-required">*</span></label>
                    <input type="text" id="organization_name" name="organization_name" value="<?php echo $values['organization_name']; ?>" required>
                </div>

                <div class="tfg-field">
                    <label for="website">Website <span class="tfg-required">*</span></label>
                    <input type="text" id="website" name="website" value="<?php echo $values['website']; ?>" required>
                </div>

                <div class="tfg-field">
                    <label>Programs to be Represented (select all that apply) <span class="tfg-required">*</span></label>
                    <?php
                    $all_programs = [
                        "Bachelor's Degree", "Graduate", "Online", "Pathway",
                        "English as a Second Language", "Professional", "Other"
                    ];
                    foreach ($all_programs as $prog) {
                        $checked = in_array($prog, $values['programs']) ? 'checked' : '';
                        echo "<label><input type='checkbox' name='programs[]' value='$prog' $checked> $prog</label>";
                    }
                    ?>
                </div>

                <div class="tfg-field">
                    <label for="other_programs">If other, please list</label>
                    <input type="text" id="other_programs" name="other_programs" value="<?php echo $values['other_programs']; ?>">
                </div>

                <div class="tfg-field">
                    <label for="comment">Comment or Message</label>
                    <textarea id="comment" name="comment"><?php echo $values['comment']; ?></textarea>
                </div>

            <?php if (!$post_id): ?>
                <?php echo TFG_Member_Form_Utilities::render_gdpr_agreement(); ?>
                <?php echo TFG_Member_Form_Utilities::insert_recaptcha(); ?>

                <div class="tfg-field">
                    <button type="submit" class="tfg-submit">Submit</button>
                </div>

                <?php echo TFG_Member_Form_Utilities::whitelist_note(); ?>
            <?php else: ?>
                <div class="tfg-field">
                    <button type="submit" class="tfg-button">Save and Return</button>
                </div>
            <?php endif; ?>
                            
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

public static function render_university_profile_display_form() {
    error_log("[TFG UNI PROFILE] Entering render_university_profile_display_form()");
    $fields = [
        'contact_name' => 'Contact Name',
        'title_and_department' => 'Title and Department',
        'contact_email' => 'Contact Email',
        'organization_name' => 'University Name',
        'website' => 'Website',
        'programs' => 'Programs',
        'other_programs' => 'Other Programs',
        'comment' => 'Comment',
    ];

    $values = array_fill_keys(array_keys($fields), '');

    if (isset($_COOKIE['member_id']) && $_COOKIE['member_authenticated'] === '1') {
        $member_id = TFG_Utils::normalize_member_id($_COOKIE['member_id']);
        $profile = get_posts([
            'post_type' => 'member_profile',
            'meta_key' => 'member_id',
            'meta_value' => $member_id,
            'posts_per_page' => 1,
        ]);

        if (!empty($profile)) {
            $post_id = $profile[0]->ID;
            foreach ($fields as $key => $label) {
                $field_value = get_field($key, $post_id);
                if (is_array($field_value)) {
                    $field_value = implode(', ', $field_value);
                }
                $values[$key] = $field_value;
            }
        }
    }

   ob_start();
    ?>
    <div class="tfg-form-wrapper-wide">
        <form class="tfg-form" method="get" action="">
            <?php foreach ($fields as $key => $label): ?>
                <div class="tfg-field">
                    <label for="<?php echo $key; ?>"><?php echo $label; ?></label>
                    <?php if ($key === 'comment'): ?>
                        <textarea id="<?php echo $key; ?>" name="<?php echo $key; ?>" readonly><?php echo esc_textarea($values[$key]); ?></textarea>
                    <?php else: ?>
                        <input type="text" id="<?php echo $key; ?>" name="<?php echo $key; ?>" value="<?php echo $values[$key]; ?>" readonly>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

public static function render_edit_form_old($atts = []) {
    $atts = shortcode_atts(['post_id' => ''], $atts);
    $post_id = absint($atts['post_id']);
    if (!$post_id) return '<p>Invalid post ID.</p>';

    // Return the correct form depending on post type
    $post_type = get_post_type($post_id);

    if ($post_type === 'member_profile') {
        return TFG_University_Form::render_university_interest_form();
    }

    // Add logic here for agency_profile and affiliate_profile if needed
    return '<p>Edit form not available for this member type.</p>';
}


}