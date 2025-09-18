<?php
// class-tfg-member-stub-access.php
// Handles “stub access” page rendering for new/edit/display modes.

class TFG_Member_Stub_Access {

    /** Allowed modes and post types (tighten to what you actually use). */
    private const ALLOWED_MODES      = ['new', 'edit', 'display'];
    private const ALLOWED_POST_TYPES = ['profile_stub', 'member_profile']; // adjust to your needs

    public static function init(): void {
        add_shortcode('tfg_stub_access', [self::class, 'render_stub_access_page']);
        add_filter('query_vars',        [self::class, 'register_query_vars']);
    }

    public static function register_query_vars(array $vars): array {
        foreach (['token', 'temp_pass', 'test_id', 'type', 'mode'] as $qv) {
            $vars[] = $qv;
        }
        return $vars;
    }

    public static function render_stub_access_page(): string {
        // Prefer get_query_var when you’ve added query vars; otherwise read/sanitize GET.
        $mode      = self::sanitize_mode(get_query_var('mode', $_GET['mode'] ?? 'new'));
        $post_id   = absint(get_query_var('test_id', $_GET['test_id'] ?? 0));
        $post_type = sanitize_key(get_query_var('type', $_GET['type'] ?? ''));

        // Normalize invalid input
        if (!in_array($mode, self::ALLOWED_MODES, true)) {
            $mode = 'new';
        }
        if ($post_type && !in_array($post_type, self::ALLOWED_POST_TYPES, true)) {
            return '<p class="tfg-error">Invalid profile type.</p>';
        }

        // EDIT/DISPLAY modes require a valid post
        if (in_array($mode, ['edit', 'display'], true)) {
            if (!$post_id || !$post_type) {
                return '<p class="tfg-error">Missing profile ID or type for this mode.</p>';
            }
            if (get_post_type($post_id) !== $post_type) {
                error_log("[TFG StubAccess] Post type mismatch for ID {$post_id}. Expected {$post_type}.");
                return '<p class="tfg-error">Invalid or mismatched profile ID.</p>';
            }
        } else {
            // new
            $post_id = 0;
            $post_type = 'profile_stub'; // sensible default for new stubs (adjust if needed)
        }

        // Header: show email if present, otherwise fall back.
        $email = self::get_field_safe('contact_email', $post_id) ?: '[Email not found]';

        ob_start();
        TFG_Member_Form_Utilities::stub_access_header($email);

        // Delegate to the manager (edit gets form, display gets readonly)
        if ($mode === 'display') {
            if (!class_exists('TFG_Member_Stub_Manager')) {
                echo '<p class="tfg-error">Stub manager not available.</p>';
            } else {
                echo TFG_Member_Stub_Manager::render_stub_display_form($post_id, $post_type);
            }
        } else {
            if (!class_exists('TFG_Member_Stub_Manager')) {
                echo '<p class="tfg-error">Stub manager not available.</p>';
            } else {
                echo TFG_Member_Stub_Manager::render_stub_edit_form($post_id, $post_type);
            }
        }

        return (string) ob_get_clean();
    }

    /* ---------- Internals ---------- */

    private static function sanitize_mode($raw): string {
        $val = is_string($raw) ? strtolower(sanitize_key($raw)) : 'new';
        return in_array($val, self::ALLOWED_MODES, true) ? $val : 'new';
    }

    private static function get_field_safe(string $key, int $post_id) {
        if (!$post_id) return '';
        if (function_exists('get_field')) {
            return get_field($key, $post_id);
        }
        return get_post_meta($post_id, $key, true);
    }

    /** Renders the small header card shown above the forms. */
    private static function render_stub_header(string $email = ''): void {
        ?>
        <h2>Member Registration</h2>
        <div style="margin-top:1em; padding:1em; border:1px solid #ccc; border-radius:8px;">
            <div class="tfg-font-base">
                <strong>Member ID:</strong> <span class="tfg-font-light">Pending</span>
            </div>
            <div class="tfg-font-base">
                <strong>Email:</strong> <span class="tfg-font-light"><?php echo esc_html($email); ?></span>
            </div>
        </div>
        <div class="tfg-section-divider"></div>
        <?php
    }

    /**
     * Create a new stub (draft) if needed.
     * NOTE: This is unused in the main render flow, but kept for parity with your original.
     */
    private static function create_new_stub(string $post_type, string $email, string $token, string $member_type): int {
        if (!in_array($post_type, self::ALLOWED_POST_TYPES, true)) {
            return 0;
        }

        if (!class_exists('TFG_Member_Stub_Manager')) {
            error_log('[TFG StubAccess] TFG_Member_Stub_Manager not found.');
            return 0;
        }

        $prefix = TFG_Member_Stub_Manager::get_prefix_for_type($post_type);
        if (!$prefix) return 0;

        $member_id  = TFG_Member_Stub_Manager::generate_member_id($prefix);
        $email      = TFG_Utils::normalize_email($email);
        $member_type = sanitize_text_field($member_type);

        $new_id = wp_insert_post([
            'post_type'   => $post_type,
            'post_status' => 'draft',
            'post_title'  => $email ?: 'Stub',
        ]);
        if (is_wp_error($new_id) || !$new_id) {
            return 0;
        }

        self::update_field_safe('member_id',          $member_id,       $new_id);
        self::update_field_safe('member_type',        $member_type,     $new_id);
        self::update_field_safe('contact_email',      $email,           $new_id);
        self::update_field_safe('submitted_by_user',  get_current_user_id(), $new_id);
        update_post_meta($new_id, 'stub_token', $token);

        return (int) $new_id;
    }

    private static function find_or_create_stub(string $post_type, string $email, string $token, string $member_type): int {
        if (!in_array($post_type, self::ALLOWED_POST_TYPES, true)) {
            return 0;
        }
        $email = TFG_Utils::normalize_email($email);
        if (!$email) return 0;

        $existing = get_posts([
            'post_type'      => $post_type,
            'meta_key'       => 'contact_email',
            'meta_value'     => $email,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        if (!empty($existing)) {
            return (int) $existing[0];
        }
        return self::create_new_stub($post_type, $email, $token, $member_type);
    }

    private static function update_field_safe(string $key, $value, int $post_id): void {
        if (function_exists('update_field')) {
            update_field($key, $value, $post_id);
        } else {
            update_post_meta($post_id, $key, $value);
        }
    }

    /** Example token check; replace with your real validator. */
    private static function mock_token_validation(string $token, string $temp_pass) {
        $known = [
            'abc123' => [
                'temp_pass'   => 'temp999',
                'email'       => 'stubuser@example.com',
                'member_type' => 'university',
            ],
        ];
        if (!isset($known[$token])) return false;
        if ($known[$token]['temp_pass'] !== $temp_pass) return false;

        return [
            'email'       => $known[$token]['email'],
            'member_type' => $known[$token]['member_type'],
        ];
    }
}
