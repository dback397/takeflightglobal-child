<?php

class TFG_Member_Stub_Manager {

    const VALID_MEMBER_TYPES = ['university', 'agency', 'affiliate'];

    public static function init(): void {
        add_shortcode('tfg_stub_form', [self::class, 'render_stub_form']);

        // Frontend-only handler (avoid running during admin/ajax/rest)
        if (!is_admin() && !wp_doing_ajax() && !defined('REST_REQUEST')) {
            add_action('template_redirect', [self::class, 'handle_stub_submission']);
        }
    }

    public static function render_stub_form($atts): string {
        error_log('[TFG RENDER STUB] Entering render_stub_form()');

        // 1) Resolve member_type (shortcode attr → POST on submit)
        $atts        = shortcode_atts(['type' => ''], $atts);
        $member_type = sanitize_text_field($atts['type']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['member_type'])) {
            $member_type = sanitize_text_field(wp_unslash($_POST['member_type']));
        }

        if ($member_type !== '' && !in_array($member_type, self::VALID_MEMBER_TYPES, true)) {
            error_log("Invalid member type resolved in render: {$member_type}");
            return '<p class="tfg-error">Invalid member type.</p>';
        }

        // 2) Edit vs New
        $post_id = absint($_GET['post_id'] ?? 0);
        $mode    = $post_id ? 'edit' : 'new';
        error_log("Rendering stub form: mode={$mode}, post_id={$post_id}, member_type={$member_type}");

        // 3) Prefill values on edit
        $values = [
            'contact_name'        => '',
            'title_and_department'=> '',
            'contact_email'       => '',
            'organization_name'   => '',
            'website'             => '',
        ];

        if ($mode === 'edit' && $post_id) {
            foreach ($values as $key => &$val) {
                $val = function_exists('get_field')
                    ? (string) get_field($key, $post_id)
                    : (string) get_post_meta($post_id, $key, true);
                error_log("Editing value {$key} = {$val}");
            }
            unset($val);
        }

        ob_start();

        // Header helper *echoes*; don’t concatenate it
        if (class_exists('TFG_Member_Form_Utilities')) {
            $email_for_header = $post_id
                ? (function_exists('get_field') ? (get_field('contact_email', $post_id) ?: '') : (get_post_meta($post_id, 'contact_email', true) ?: ''))
                : '';
            TFG_Member_Form_Utilities::stub_access_header($email_for_header);
        }

        ?>
        <div class="tfg-form-wrapper-wide">
            <form method="POST" class="tfg-form" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="handler_id" value="stub_profile">
                <?php wp_nonce_field('tfg_stub_form', 'tfg_stub_nonce'); ?>

                <!-- Persist member type + post_id -->
                <input type="hidden" name="member_type" value="<?php echo esc_attr($member_type); ?>">
                <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">

                <?php if ($mode === 'edit' && $post_id): ?>
                    <input type="hidden" name="save_stub_edit" value="1">
                <?php else: ?>
                    <input type="hidden" name="save_profile_stub" value="1">
                <?php endif; ?>

                <div class="tfg-field">
                    <label for="contact_name">Contact Name <span class="tfg-required">*</span></label>
                    <input type="text" id="contact_name" name="contact_name" required value="<?php echo esc_attr($values['contact_name']); ?>">
                </div>

                <div class="tfg-field">
                    <label for="title_and_department">Title and Department <span class="tfg-required">*</span></label>
                    <input type="text" id="title_and_department" name="title_and_department" required value="<?php echo esc_attr($values['title_and_department']); ?>">
                </div>

                <div class="tfg-field">
                    <label for="contact_email">Contact Email <span class="tfg-required">*</span></label>
                    <input type="email" id="contact_email" name="contact_email" required value="<?php echo esc_attr($values['contact_email']); ?>">
                </div>

                <div class="tfg-field">
                    <label for="organization_name">Organization Name <span class="tfg-required">*</span></label>
                    <input type="text" id="organization_name" name="organization_name" required value="<?php echo esc_attr($values['organization_name']); ?>">
                </div>

                <div class="tfg-membership-type-box" style="margin-bottom:25px;">
                    <p style="margin-bottom:5px;"><strong>Select Membership Type:</strong></p>
                    <label style="display:block; margin-bottom:2px;">
                        <input type="radio" name="member_type" value="university" <?php checked($member_type, 'university'); ?> required> College or University
                    </label>
                    <label style="display:block; margin-bottom:2px;">
                        <input type="radio" name="member_type" value="agency" <?php checked($member_type, 'agency'); ?> required> Recruiting Agency
                    </label>
                    <label style="display:block;">
                        <input type="radio" name="member_type" value="affiliate" <?php checked($member_type, 'affiliate'); ?> required> Advertiser / Service Provider
                    </label>
                </div>

                <div class="tfg-form" style="margin-top:20px;">
                    <label for="website">Website <span class="tfg-required">*</span></label>
                    <input type="url" id="website" name="website" required value="<?php echo esc_attr($values['website'] ?: 'https://'); ?>" class="tfg-input-wide">
                </div>

                <div class="tfg-field" style="margin-top:25px;">
                    <button type="submit" class="tfg-submit">Save and Continue</button>
                    <?php if ($mode === 'edit' && $post_id): ?>
                        <button type="submit" name="submit_profile_stub" value="1" class="tfg-submit">Submit</button>
                        <button type="submit" name="return_to_edit" value="1" class="tfg-submit">Return</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public static function handle_stub_submission(): void {
        if (!TFG_Form_Router::matches('stub_profile')) return;
        error_log('[TFG HANDLE STUB] Entering handle_stub_submission()');

        // Confirm which submit path we’re on
        $is_new  = isset($_POST['save_profile_stub']);
        $is_edit = isset($_POST['save_stub_edit']);
        if (!$is_new && !$is_edit) {
            error_log('[TFG HANDLE STUB] ❌ No matching submit button.');
            return;
        }

        // CSRF
        if (empty($_POST['tfg_stub_nonce']) || !wp_verify_nonce($_POST['tfg_stub_nonce'], 'tfg_stub_form')) {
            error_log('[TFG HANDLE STUB] ❌ Nonce check failed.');
            return;
        }

        // Sanitize + validate payload
        $member_type = sanitize_text_field(wp_unslash($_POST['member_type'] ?? ''));
        if (!in_array($member_type, self::VALID_MEMBER_TYPES, true)) {
            error_log("[TFG HANDLE STUB] ❌ Invalid member_type: {$member_type}");
            echo '<p class="tfg-error">Invalid member type.</p>';
            return;
        }

        $contact_name        = sanitize_text_field(wp_unslash($_POST['contact_name'] ?? ''));
        $title_and_department= sanitize_text_field(wp_unslash($_POST['title_and_department'] ?? ''));
        $contact_email       = TFG_Utils::normalize_email(wp_unslash($_POST['contact_email'] ?? ''));
        $organization_name   = sanitize_text_field(wp_unslash($_POST['organization_name'] ?? ''));
        $website_raw         = wp_unslash($_POST['website'] ?? '');
        $website             = esc_url_raw($website_raw);

        if (!$contact_name || !$title_and_department || !$contact_email || !$organization_name || !$website) {
            echo '<p class="tfg-error">Please complete all required fields.</p>';
            return;
        }

        // Fallback wrappers for ACF
        $set = function(string $key, $value, int $pid) {
            if (function_exists('update_field')) {
                update_field($key, $value, $pid);
            } else {
                update_post_meta($pid, $key, $value);
            }
        };

        if ($is_new) {
            // === NEW STUB CREATION ===
            error_log('[TFG HANDLE STUB] Creating NEW profile_stub…');

            $new_post_id = wp_insert_post([
                'post_type'   => 'profile_stub',
                'post_status' => 'draft',
                'post_title'  => $organization_name,
            ]);

            if (is_wp_error($new_post_id) || !$new_post_id) {
                error_log('[TFG HANDLE STUB] ❌ wp_insert_post failed for profile_stub.');
                echo '<p class="tfg-error">There was an error creating your profile. Please try again.</p>';
                return;
            }

            $set('contact_name',          $contact_name,        $new_post_id);
            $set('title_and_department',  $title_and_department,$new_post_id);
            $set('contact_email',         $contact_email,       $new_post_id);
            $set('member_type',           $member_type,         $new_post_id);
            $set('organization_name',     $organization_name,   $new_post_id);
            $set('website',               $website,             $new_post_id);

            error_log("[TFG HANDLE STUB] ✅ Created profile_stub ID {$new_post_id} (type {$member_type})");

            $redirect_url = add_query_arg([
                'post_id' => $new_post_id,
                'mode'    => 'edit',
                'type'    => "{$member_type}_profile",
            ], site_url('/gdpr-consent/'));

            if (!headers_sent()) {
                nocache_headers();
                wp_safe_redirect($redirect_url);
                exit;
            }
        }

        if ($is_edit) {
            // === EXISTING STUB EDIT ===
            $post_id = absint($_POST['post_id'] ?? 0);
            if (!$post_id) {
                error_log('[TFG HANDLE STUB] ❌ Missing post_id for edit.');
                echo '<p class="tfg-error">Missing profile reference.</p>';
                return;
            }

            error_log("[TFG HANDLE STUB] Updating profile_stub ID {$post_id}");

            $set('member_type',           $member_type,         $post_id);
            $set('contact_name',          $contact_name,        $post_id);
            $set('title_and_department',  $title_and_department,$post_id);
            $set('contact_email',         $contact_email,       $post_id);
            $set('organization_name',     $organization_name,   $post_id);
            $set('website',               $website,             $post_id);

            $redirect_url = add_query_arg([
                'post_id' => $post_id,
                'mode'    => 'edit',
                'type'    => "{$member_type}_profile",
            ], site_url('/gdpr-consent/'));

            if (!headers_sent()) {
                nocache_headers();
                wp_safe_redirect($redirect_url);
                exit;
            }
        }
    }
}
