<?php

namespace TFG\Features\Membership;

use TFG\Core\Cookies;
use TFG\Core\Utils;

final class MemberDashboard
{
    private const LOGOUT_QS = 'tfg_member_logout';
    private const UNSUB_QS  = 'tfg_action';
    private const NONCE_KEY = 'tfg_member_action';
    private const NONCE_QS  = '_tfg_nonce';

    public static function init(): void
    {
        \add_shortcode('tfg_member_dashboard',    [self::class, 'renderDashboard']);
        \add_shortcode('tfg_edit_member_profile', [self::class, 'renderEditForm']);

        \add_action('init', [self::class, 'logoutTrigger']);
        \add_action('init', [self::class, 'unsubscribeTrigger']);
    }

    /* ---------------- Edit Form ---------------- */

    public static function renderEditForm($atts): string
    {
        $atts    = \shortcode_atts(['post_id' => 0], $atts);
        $post_id = \absint($atts['post_id']);

        if (!$post_id || !\get_post_status($post_id)) {
            return '<p>Invalid or missing post ID for edit form.</p>';
        }

        $member_id = \function_exists('get_field')
            ? \get_field('member_id', $post_id)
            : \get_post_meta($post_id, 'member_id', true);

        if (!$member_id) return '<p>Missing Member ID on this profile.</p>';

        $stub = \get_posts([
            'post_type'        => 'profile_stub',
            'meta_key'         => 'member_id',
            'meta_value'       => $member_id,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        if (!$stub) return '<p>Stub not found for this profile.</p>';

        $stub_id     = (int) $stub[0];
        $member_type = \function_exists('get_field')
            ? \get_field('member_type', $stub_id)
            : \get_post_meta($stub_id, 'member_type', true);

        \ob_start();
        switch ($member_type) {
            case 'university':
                // keep legacy call; replace with namespaced form class if you move it
                echo UniversityForm::renderUniversityInterestForm($post_id);
                break;
            case 'agency':
                //echo AgencyForm::render_agency_interest_form($post_id);
                break;
            case 'affiliate':
                //echo AffiliateForm::render_affiliate_interest_form($post_id);
                break;
            default:
                echo '<p>Unsupported or missing member type.</p>';
        }
        return (string) \ob_get_clean();
    }

    /* ---------------- Dashboard ---------------- */

    public static function renderDashboard(): string
    {
        // 1) Read UI cookie values
        $member_id = Cookies::getMemberId();
        $email     = Cookies::getMemberEmail() ?: '';

        // 2) Verify trusted membership via HttpOnly cookie HMAC
        $trusted = $member_id ? Cookies::isMember($member_id, $email) : false;
        if (!$trusted) {
            return '<p>You must be logged in to view this page.</p>';
        }

        // 3) Load profile by member_id
        $profile_post = self::getMemberProfileById($member_id);
        if (!$profile_post) {
            return '<p>No profile found for Member ID: ' . \esc_html($member_id) . '</p>';
        }

        $post_id = (int) $profile_post->ID;
        $active  = \function_exists('get_field')
            ? (bool) \get_field('is_active', $post_id)
            : (bool) \get_post_meta($post_id, 'is_active', true);

        if (!$active) {
            return '<p>This profile has been deactivated.</p>';
        }

        // 4) Member type (from stub)
        $stub = \get_posts([
            'post_type'        => 'profile_stub',
            'meta_key'         => 'member_id',
            'meta_value'       => $member_id,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        $member_type = $stub
            ? (\function_exists('get_field') ? \get_field('member_type', (int) $stub[0]) : \get_post_meta((int) $stub[0], 'member_type', true))
            : '';
        $type_name = $member_type ? \ucfirst($member_type) : 'Member';

        // 5) Action URLs (+ nonces)
        $base       = \remove_query_arg([self::UNSUB_QS, self::NONCE_QS, self::LOGOUT_QS]);
        $nonce      = \wp_create_nonce(self::NONCE_KEY);
        $edit_url   = \add_query_arg(['tfg_action' => 'edit'],   $base);
        $expand_url = \add_query_arg(['tfg_action' => 'expand'], $base);
        $reset_url  = \esc_url(\site_url('/reset-password'));
        $deact_url  = \add_query_arg([self::UNSUB_QS => 'deactivate', self::NONCE_QS => $nonce], $base);
        $logout_url = \add_query_arg([self::LOGOUT_QS => '1',          self::NONCE_QS => $nonce], $base);

        \ob_start();

        echo '<h2>Welcome, ' . \esc_html($type_name) . '</h2>';
        echo '<div><strong>Member ID:</strong> ' . \esc_html($member_id) . '</div>';

        echo "<div style='margin-top:1em; padding:1em; border:1px solid #ccc; border-radius:8px;'>";
        $fields_to_show = [
            'contact_name'  => 'Contact Name',
            'contact_email' => 'Contact Email',
            'website'       => 'Website',
            'location'      => 'Location',
        ];
        foreach ($fields_to_show as $key => $label) {
            $value = \function_exists('get_field') ? \get_field($key, $post_id) : \get_post_meta($post_id, $key, true);
            if (\is_array($value)) $value = \implode(', ', $value);
            if ($value !== '' && $value !== null) {
                echo '<div style="margin-bottom:0.5em;"><strong>' . \esc_html($label) . ':</strong> ' . \esc_html((string) $value) . '</div>';
            }
        }
        echo '</div>';

        echo "<div style='margin-top:1.5em; display:flex; flex-wrap:wrap; gap:1em;'>";
        echo '<a href="' . \esc_url($edit_url)   . '" class="tfg-button">Edit Your Profile</a>';
        echo '<a href="' . \esc_url($expand_url) . '" class="tfg-button">Expand Your Profile</a>';
        echo '<a href="' . \esc_url($reset_url)  . '" class="tfg-button">Reset Your Password</a>';
        echo '<a href="' . \esc_url($deact_url)  . '" class="tfg-button">Deactivate Your Profile</a>';
        echo '<a href="' . \esc_url($logout_url) . '" class="tfg-button">Logout</a>';
        echo '</div>';

        echo '<div class="tfg-section-divider"></div>';

        if (isset($_GET['tfg_action'])) {
            $action = \sanitize_key($_GET['tfg_action']);
            echo '<hr style="margin:2em 0;">';

            if ($action === 'edit') {
                echo '<h3>Edit Your Profile</h3>';
                echo \do_shortcode('[tfg_edit_member_profile post_id="' . $post_id . '"]');
            } elseif ($action === 'expand') {
                echo '<h3>Expand Your Profile</h3><p>This section is under development.</p>';
            } elseif ($action === 'reset') {
                echo '<h3>Reset Your Password</h3>' . \do_shortcode('[tfg_member_reset_form]');
            } elseif ($action === 'deactivate') {
                echo '<h3>Deactivate Your Profile</h3><p>Use the Deactivate button above.</p>';
            }
        }

        return (string) \ob_get_clean();
    }

    /* ---------------- Unsubscribe / Deactivate ---------------- */

    public static function unsubscribeTrigger(): void
    {
        if (!isset($_GET[self::UNSUB_QS]) || $_GET[self::UNSUB_QS] !== 'deactivate') return;
        if (empty($_GET[self::NONCE_QS]) || !\wp_verify_nonce($_GET[self::NONCE_QS], self::NONCE_KEY)) return;

        $member_id = Cookies::getMemberId();
        $email     = Cookies::getMemberEmail();
        if (!$member_id || !Cookies::isMember($member_id, $email ?? '')) return;

        $profile = self::getMemberProfileById($member_id);
        if ($profile) {
            if (\function_exists('update_field')) {
                \update_field('is_active', false, $profile->ID);
            } else {
                \update_post_meta($profile->ID, 'is_active', 0);
            }
            \error_log("[TFG Dashboard] Profile {$member_id} marked as deactivated.");
        }

        Cookies::unsetMemberCookie();
        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect(\home_url('/'));
            exit;
        }
    }

    /* ---------------- Logout ---------------- */

    public static function logoutTrigger(): void
    {
        if (!isset($_GET[self::LOGOUT_QS])) return;
        if (empty($_GET[self::NONCE_QS]) || !\wp_verify_nonce($_GET[self::NONCE_QS], self::NONCE_KEY)) return;

        Cookies::unsetMemberCookie();
        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect(\home_url('/'));
            exit;
        }
        \error_log('[TFG Dashboard] Headers already sent; could not redirect after logout.');
    }

    /* ---------------- Helpers ---------------- */

    public static function getMemberProfileById(string $member_id)
    {
        $member_id = Utils::normalizeMemberId($member_id);
        if ($member_id === '') return null;

        $results = \get_posts([
            'post_type'        => 'member_profile',
            'meta_key'         => 'member_id',
            'meta_value'       => $member_id,
            'posts_per_page'   => 1,
            'post_status'      => 'any',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);
        return $results[0] ?? null;
    }

    /* --------- Legacy aliases for shortcodes (optional) --------- */
    /** @deprecated */
    public static function render_dashboard(): string { return self::renderDashboard(); }
    /** @deprecated */
    public static function render_edit_form($atts): string { return self::renderEditForm($atts); }
    /** @deprecated */
    public static function unsubscribe_trigger(): void { self::unsubscribeTrigger(); }
    /** @deprecated */
    public static function logout_trigger(): void { self::logoutTrigger(); }
    /** @deprecated */
    public static function get_member_profile_by_id(string $member_id) { return self::getMemberProfileById($member_id); }
}

/* ---- Legacy class alias for transition ---- */
\class_alias(\TFG\Features\Membership\MemberDashboard::class, 'TFG_Member_Dashboard');
