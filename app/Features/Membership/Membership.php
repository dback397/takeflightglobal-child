<?php

namespace TFG\Features\Membership;

final class Membership
{
    public static function init(): void
    {
        \add_action('init',      [self::class, 'registerRoles']);
        \add_action('init',      [self::class, 'registerCpts']);
        \add_action('acf/init',  [self::class, 'registerAcfFields']);
        \add_action('admin_menu',[self::class, 'addAdminMenu']);

        // Avoid duplicating the shortcode implemented by MemberDashboard
        \add_shortcode('tfg_member_dashboard', [self::class, 'renderMemberDashboard']);
    }

    /* =========================
       Roles
       ========================= */
    public static function registerRoles(): void
    {
        // Only add if missing (prevents DB churn on every page load)
        if (!\get_role('university_member')) {
            \add_role('university_member', 'University Member', [
                'read'          => true,
                'upload_files'  => true,
                'publish_posts' => true,
                'edit_posts'    => true,
            ]);
        }
        if (!\get_role('agency_member')) {
            \add_role('agency_member', 'Agency Member', [
                'read' => true,
            ]);
        }
        if (!\get_role('affiliate_member')) {
            \add_role('affiliate_member', 'Affiliate Member', [
                'read'          => true,
                'publish_posts' => true,
            ]);
        }
    }

    /* =========================
       CPTs
       ========================= */
    public static function registerCpts(): void
    {
        if (\post_type_exists('member_profile')) {
            return;
        }

        \register_post_type('member_profile', [
            'label'         => 'Member Profiles',
            'labels'        => [
                'name'          => 'Member Profiles',
                'singular_name' => 'Member Profile',
            ],
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => true,
            'supports'      => ['title', 'author', 'custom-fields'],
            'menu_icon'     => 'dashicons-id',
            'has_archive'   => false,
            'show_in_rest'  => false,
            'rewrite'       => false,
        ]);
    }

    /* =========================
       ACF Fields (optional)
       ========================= */
    public static function registerAcfFields(): void
    {
        // ACF not active → bail.
        if (!\function_exists('acf_add_local_field_group')) {
            return;
        }

        // Per-request guard to avoid double-registration from multiple inits.
        static $added = false;
        if ($added) {
            return;
        }
        $added = true;

        \acf_add_local_field_group([
            'key'    => 'group_member_profile_core',
            'title'  => 'Member Profile Core',
            'fields' => [
                ['key' => 'field_member_type',   'name' => 'member_type',    'label' => 'Member Type',    'type' => 'text'],
                ['key' => 'field_website',       'name' => 'website',        'label' => 'Website',        'type' => 'url'],
                ['key' => 'field_location',      'name' => 'location',       'label' => 'Location',       'type' => 'text'],
                ['key' => 'field_contact_email', 'name' => 'contact_email',  'label' => 'Contact Email',  'type' => 'email'],
            ],
            'location' => [[[
                'param'    => 'post_type',
                'operator' => '==',
                'value'    => 'member_profile',
            ]]],
        ]);
    }

    /* =========================
       Admin Menu
       ========================= */
    public static function addAdminMenu(): void
    {
        \add_menu_page(
            'Membership Admin',
            'Membership Admin',
            'manage_options',
            'tfg-membership-admin',
            [self::class, 'renderAdminPage'],
            'dashicons-groups',
            30
        );
    }

    public static function renderAdminPage(): void
    {
        if (!\current_user_can('manage_options')) {
            \wp_die(\esc_html__('You do not have sufficient permissions to access this page.', 'tfg'));
        }

        $roles = ['university_member', 'agency_member', 'affiliate_member'];

        echo '<div class="wrap"><h1>Membership Overview</h1>';
        foreach ($roles as $role) {
            $users = \get_users(['role' => $role]);
            echo '<h2>' . \esc_html(\ucfirst(\str_replace('_', ' ', $role))) . 's</h2>';

            if (!$users) {
                echo '<p><em>No users found.</em></p>';
                continue;
            }

            echo '<table class="widefat fixed striped"><thead><tr><th>Name</th><th>Email</th><th>Actions</th></tr></thead><tbody>';
            foreach ($users as $user) {
                $edit_url = \add_query_arg('user_id', (int) $user->ID, \admin_url('user-edit.php'));
                echo '<tr>';
                echo '<td>' . \esc_html($user->display_name ?: $user->user_login) . '</td>';
                echo '<td>' . \esc_html($user->user_email) . '</td>';
                echo '<td><a href="' . \esc_url($edit_url) . '">Edit</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table><br>';
        }
        echo '</div>';
    }

    /* =========================
       Frontend Dashboard (delegates)
       ========================= */
    public static function renderMemberDashboard(): string
    {
        // If the new dashboard exists, delegate to it to avoid drift.
        if (\class_exists(MemberDashboard::class) && \method_exists(MemberDashboard::class, 'renderDashboard')) {
            return MemberDashboard::renderDashboard();
        }

        // Fallback to a minimal message to prevent duplicate logic.
        return '<p>The member dashboard is unavailable right now.</p>';
    }
}

// bootstrap
\TFG\Features\Membership\Membership::init();
