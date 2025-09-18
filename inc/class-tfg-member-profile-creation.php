<?php

class TFG_Member_Profile_Creation {

public static function handle_profile_transfer() {
    error_log('[DEBUG] 🎯 This is the active class file being loaded.');
}
}
/*

class TFG_Member_Profile_Creation {

//    public static function init() {
//        add_action('init', [__CLASS__, 'handle_profile_transfer']);
//    }

public static function handle_profile_transfer() {
        //if (!TFG_Form_Router::matches('profile_transfer')) return;
        error_log("[TFG Profile Transfer] Entering handle_profile_transfer()");
    
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $password = $_POST['institution_password'] ?? '';
        $confirm  = $_POST['institution_password_confirm'] ?? '';

        if (!$post_id || empty($password) || $password !== $confirm) {
            error_log("[TFG Profile Creation] ❌ Missing post ID or password mismatch");
            return;
        }

        $stub = get_post($post_id);
        if (!$stub || $stub->post_type !== 'profile_stub') {
            error_log("[TFG Profile Creation] ❌ Invalid stub post_type for ID: $post_id");
            return;
        }

        // ✅ Get member_type strictly from the stub
        $member_type = get_field('member_type', $post_id);
        if (!$member_type) {
            error_log("[TFG Profile Creation] ❌ Missing member_type in stub: $post_id");
            return;
        }

        $member_id = get_field('member_id', $post_id);
        if (!$member_id) {
            error_log("[TFG Profile Creation] ❌ Missing member_id in stub: $post_id");
            return;
        }

        $hash = password_hash($password, MEMBER_PASSWORD_DEFAULT);
        update_field('institution_password_hash', $hash, $post_id); // ✅ Save to stub

        // ✅ Create new profile of correct post type
        $new_post_id = wp_insert_post([
            'post_type'   => $member_type,
            'post_status' => 'pending',
            'post_title'  => get_field('organization_name', $post_id),
        ]);

        if (!$new_post_id || is_wp_error($new_post_id)) {
            error_log("[TFG Profile Creation] ❌ Failed to create final profile from stub $post_id");
            return;
        }

        // ✅ Copy all ACF fields from stub to final profile
        $fields = get_fields($post_id);
        foreach ($fields as $key => $value) {
            update_field($key, $value, $new_post_id);
            error_log("[TFG Profile Creation] $key, $value, $new_post_id transferred");
        }
        
        // ✅ Manually set key profile metadata
        update_field('is_active', true, $new_post_id);
        $is_active='is_active';
        update_field('registration_date', current_time('mysql'), $new_post_id);
        $registration_date='registration_date';
        error_log("[TFG Profile Creation] ✅ record toggled active $is_active and timestamped $registration_date");

        error_log("[TFG Profile Creation] ✅ Final profile created: ID=$new_post_id from stub ID=$post_id");

        // ✅ Set login cookies
        setcookie('member_authenticated', '1', time() + 3600, '/takeflightglobal/');
        setcookie('member_id', $member_id, time() + 3600, '/takeflightglobal/');
        setcookie('member_type', $member_type, time() + 3600, '/takeflightglobal/');

        // ✅ Redirect to dashboard with success query
        wp_safe_redirect(site_url('/member-dashboard/?created=' . $new_post_id));
        exit;
    }

}*/