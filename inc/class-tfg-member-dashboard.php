<?php
class TFG_Member_Dashboard {

    private const LOGOUT_QS = 'tfg_member_logout';
    private const UNSUB_QS  = 'tfg_action';
    private const UNSUB_VAL = 'unsubscribe';
    private const NONCE_KEY = 'tfg_member_action';
    private const NONCE_QS  = '_tfg_nonce';

    public static function init(): void {
        add_shortcode('tfg_member_dashboard',      [__CLASS__, 'render_dashboard']);
        add_shortcode('tfg_edit_member_profile',   [__CLASS__, 'render_edit_form']);

        add_action('init', [__CLASS__, 'logout_trigger']);
        add_action('init', [__CLASS__, 'unsubscribe_trigger']);
    }

    # ---------------------------
    # Edit Form
    # ---------------------------
    public static function render_edit_form($atts): string {
        $atts    = shortcode_atts(['post_id' => 0], $atts);
        $post_id = absint($atts['post_id']);

        if (!$post_id || !get_post_status($post_id)) {
            return '<p>Invalid or missing post ID for edit form.</p>';
        }

        $member_id = function_exists('get_field') ? get_field('member_id', $post_id) : get_post_meta($post_id, 'member_id', true);
        if (!$member_id) return '<p>Missing Member ID on this profile.</p>';

        $stub = get_posts([
            'post_type'      => 'profile_stub',
            'meta_key'       => 'member_id',
            'meta_value'     => $member_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        if (!$stub) return '<p>Stub not found for this profile.</p>';

        $stub_id     = (int) $stub[0];
        $member_type = function_exists('get_field') ? get_field('member_type', $stub_id) : get_post_meta($stub_id, 'member_type', true);

        ob_start();
        switch ($member_type) {
            case 'university':
                echo TFG_University_Form::render_university_interest_form($post_id);
                break;
            case 'agency':
                echo TFG_Agency_Form::render_agency_interest_form($post_id);
                break;
            case 'affiliate':
                echo TFG_Affiliate_Form::render_affiliate_interest_form($post_id);
                break;
            default:
                echo '<p>Unsupported or missing member type.</p>';
        }
        return ob_get_clean();
    }

    # ---------------------------
    # Dashboard
    # ---------------------------
    public static function render_dashboard(): string
{
    // 1) Read UI cookie values
    $member_id = TFG_Cookies::get_member_id();         // UI cookie (string or null)
    $email     = TFG_Cookies::get_member_email() ?: ''; // optional; strengthens HMAC

    // 2) Verify trusted membership via HttpOnly cookie HMAC
    $trusted = $member_id ? TFG_Cookies::is_member($member_id, $email) : false;
    if (!$trusted) {
        return '<p>You must be logged in to view this page.</p>';
    }

    // 3) Load profile by member_id
    $profile_post = self::get_member_profile_by_id($member_id);
    if (!$profile_post) {
        return '<p>No profile found for Member ID: ' . esc_html($member_id) . '</p>';
    }

    $post_id = (int) $profile_post->ID;
    $active  = function_exists('get_field')
        ? (bool) get_field('is_active', $post_id)
        : (bool) get_post_meta($post_id, 'is_active', true);

    if (!$active) {
        return '<p>This profile has been deactivated.</p>';
    }

    // 4) Member type (from stub)
    $stub = get_posts([
        'post_type'        => 'profile_stub',
        'meta_key'         => 'member_id',
        'meta_value'       => $member_id,
        'posts_per_page'   => 1,
        'fields'           => 'ids',
        'suppress_filters' => true,
        'no_found_rows'    => true,
    ]);
    $member_type = $stub
        ? (function_exists('get_field') ? get_field('member_type', (int) $stub[0]) : get_post_meta((int) $stub[0], 'member_type', true))
        : '';
    $type_name = $member_type ? ucfirst($member_type) : 'Member';

    // 5) Action URLs (+ nonces). Make sure these constants exist on this class.
    $base       = remove_query_arg([self::UNSUB_QS, self::NONCE_QS, self::LOGOUT_QS]);
    $nonce      = wp_create_nonce(self::NONCE_KEY);
    $edit_url   = add_query_arg(['tfg_action' => 'edit'],   $base);
    $expand_url = add_query_arg(['tfg_action' => 'expand'], $base);
    $reset_url  = esc_url(site_url('/reset-password'));
    $deact_url  = add_query_arg([self::UNSUB_QS => 'deactivate', self::NONCE_QS => $nonce], $base);
    $logout_url = add_query_arg([self::LOGOUT_QS => '1',          self::NONCE_QS => $nonce], $base);

    ob_start();

    echo '<h2>Welcome, ' . esc_html($type_name) . '</h2>';
    echo '<div><strong>Member ID:</strong> ' . esc_html($member_id) . '</div>';

    // Basic fields
    echo "<div style='margin-top:1em; padding:1em; border:1px solid #ccc; border-radius:8px;'>";
    $fields_to_show = [
        'contact_name'  => 'Contact Name',
        'contact_email' => 'Contact Email',
        'website'       => 'Website',
        'location'      => 'Location',
    ];
    foreach ($fields_to_show as $key => $label) {
        $value = function_exists('get_field') ? get_field($key, $post_id) : get_post_meta($post_id, $key, true);
        if (is_array($value)) $value = implode(', ', $value);
        if ($value !== '' && $value !== null) {
            echo '<div style="margin-bottom:0.5em;"><strong>' . esc_html($label) . ':</strong> ' . esc_html((string) $value) . '</div>';
        }
    }
    echo '</div>';

    // Buttons
    echo "<div style='margin-top:1.5em; display:flex; flex-wrap:wrap; gap:1em;'>";
    echo '<a href="' . esc_url($edit_url)   . '" class="tfg-button">Edit Your Profile</a>';
    echo '<a href="' . esc_url($expand_url) . '" class="tfg-button">Expand Your Profile</a>';
    echo '<a href="' . esc_url($reset_url)  . '" class="tfg-button">Reset Your Password</a>';
    echo '<a href="' . esc_url($deact_url)  . '" class="tfg-button">Deactivate Your Profile</a>';
    echo '<a href="' . esc_url($logout_url) . '" class="tfg-button">Logout</a>';
    echo '</div>';

    echo '<div class="tfg-section-divider"></div>';

    // Inline actions
    if (isset($_GET['tfg_action'])) {
        $action = sanitize_key($_GET['tfg_action']);
        echo '<hr style="margin:2em 0;">';

        if ($action === 'edit') {
            echo '<h3>Edit Your Profile</h3>';
            echo do_shortcode('[tfg_edit_member_profile post_id="' . $post_id . '"]');
        } elseif ($action === 'expand') {
            echo '<h3>Expand Your Profile</h3><p>This section is under development.</p>';
        } elseif ($action === 'reset') {
            echo '<h3>Reset Your Password</h3>' . do_shortcode('[tfg_member_reset_form]');
        } elseif ($action === 'deactivate') {
            echo '<h3>Deactivate Your Profile</h3><p>Use the Deactivate button above.</p>';
        }
    }

    return ob_get_clean();
}

    # ---------------------------
    # Unsubscribe / Deactivate
    # ---------------------------
    public static function unsubscribe_trigger(): void {
        if (!isset($_GET[self::UNSUB_QS]) || $_GET[self::UNSUB_QS] !== 'deactivate') return;
        if (empty($_GET[self::NONCE_QS]) || !wp_verify_nonce($_GET[self::NONCE_QS], self::NONCE_KEY)) return;

        $member_id = TFG_Cookies::get_member_id();
        $email     = TFG_Cookies::get_member_email();
        if (!$member_id || !TFG_Cookies::is_member($member_id, $email ?? '')) return;

        $profile = self::get_member_profile_by_id($member_id);
        if ($profile) {
            if (function_exists('update_field')) {
                update_field('is_active', false, $profile->ID);
            } else {
                update_post_meta($profile->ID, 'is_active', 0);
            }
            error_log("[TFG Dashboard] Profile {$member_id} marked as deactivated.");
        }

        // Clear member session + redirect home
        TFG_Cookies::unset_member_cookie();
        if (!headers_sent()) {
            nocache_headers();
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }

    # ---------------------------
    # Logout
    # ---------------------------
    public static function logout_trigger(): void {
        if (!isset($_GET[self::LOGOUT_QS])) return;
        if (empty($_GET[self::NONCE_QS]) || !wp_verify_nonce($_GET[self::NONCE_QS], self::NONCE_KEY)) return;

        TFG_Cookies::unset_member_cookie();
        if (!headers_sent()) {
            nocache_headers();
            wp_safe_redirect(home_url('/'));
            exit;
        }
        error_log('[TFG Dashboard] Headers already sent; could not redirect after logout.');
    }

    # ---------------------------
    # Helpers
    # ---------------------------
    public static function get_member_profile_by_id(string $member_id) {
        $member_id = TFG_Utils::normalize_member_id($member_id);
        if ($member_id === '') return null;

        $results = get_posts([
            'post_type'      => 'member_profile',
            'meta_key'       => 'member_id',
            'meta_value'     => $member_id,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        return $results[0] ?? null;
    }
}
