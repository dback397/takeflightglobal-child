<?php
// === class-tfg-membership.php ===
class TFG_Membership {

    public static function init(): void {
        $self = new self();
        add_action('init',        [$self, 'register_roles']);
        add_action('init',        [$self, 'register_cpts']);
        add_action('acf/init',    [$self, 'register_acf_fields']);
        add_action('admin_menu',  [$self, 'add_admin_menu']);
        add_shortcode('tfg_member_dashboard', [$self, 'render_member_dashboard']);
    }

    /* =========================
       Roles
       ========================= */
    public function register_roles(): void {
        // Only add if missing (prevents DB churn on every page load)
        if (!get_role('university_member')) {
            add_role('university_member', 'University Member', [
                'read'          => true,
                'upload_files'  => true,
                'publish_posts' => true,
                'edit_posts'    => true,
            ]);
        }
        if (!get_role('agency_member')) {
            add_role('agency_member', 'Agency Member', [
                'read' => true,
            ]);
        }
        if (!get_role('affiliate_member')) {
            add_role('affiliate_member', 'Affiliate Member', [
                'read'          => true,
                'publish_posts' => true,
            ]);
        }
    }

    /* =========================
       CPTs
       ========================= */
    public function register_cpts(): void {
        // Minimal member_profile CPT (you can expand as needed)
        if (post_type_exists('member_profile')) {
            return;
        }

        register_post_type('member_profile', [
            'label'               => 'Member Profiles',
            'labels'              => [
                'name'          => 'Member Profiles',
                'singular_name' => 'Member Profile',
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'capability_type'     => 'post',
            'supports'            => ['title', 'author', 'custom-fields'],
            'menu_icon'           => 'dashicons-id',
            'has_archive'         => false,
            'show_in_rest'        => false,
        ]);
    }

    /* =========================
       ACF Fields (optional)
       ========================= */
    public function register_acf_fields(): void {
        if (!function_exists('acf_add_local_field_group')) {
            return; // ACF not active; skip
        }

        // Example minimal group (skip if you already manage via ACF JSON)
        if (function_exists('acf_get_local_field_groups')) {
            // You likely already ship ACF JSON; nothing required here.
            return;
        }

        acf_add_local_field_group([
            'key'    => 'group_member_profile_core',
            'title'  => 'Member Profile Core',
            'fields' => [
                [
                    'key'   => 'field_member_type',
                    'name'  => 'member_type',
                    'label' => 'Member Type',
                    'type'  => 'text',
                ],
                [
                    'key'   => 'field_website',
                    'name'  => 'website',
                    'label' => 'Website',
                    'type'  => 'url',
                ],
                [
                    'key'   => 'field_location',
                    'name'  => 'location',
                    'label' => 'Location',
                    'type'  => 'text',
                ],
                [
                    'key'   => 'field_contact_email',
                    'name'  => 'contact_email',
                    'label' => 'Contact Email',
                    'type'  => 'email',
                ],
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
    public function add_admin_menu(): void {
        add_menu_page(
            'Membership Admin',
            'Membership Admin',
            'manage_options',
            'tfg-membership-admin',
            [$this, 'render_admin_page'],
            'dashicons-groups',
            30
        );
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            error_log('[Render Admin Page] You do not have sufficient permissions to access this page.');
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'tfg'));
        }

        $roles = ['university_member', 'agency_member', 'affiliate_member'];

        echo '<div class="wrap"><h1>Membership Overview</h1>';
        foreach ($roles as $role) {
            $users = get_users(['role' => $role]);
            echo '<h2>' . esc_html(ucfirst(str_replace('_', ' ', $role))) . 's</h2>';

            if (empty($users)) {
                echo '<p><em>No users found.</em></p>';
                continue;
            }

            echo '<table class="widefat fixed striped"><thead><tr><th>Name</th><th>Email</th><th>Actions</th></tr></thead><tbody>';
            foreach ($users as $user) {
                $edit_url = add_query_arg('user_id', (int) $user->ID, admin_url('user-edit.php'));
                echo '<tr>';
                echo '<td>' . esc_html($user->display_name ?: $user->user_login) . '</td>';
                echo '<td>' . esc_html($user->user_email) . '</td>';
                echo '<td><a href="' . esc_url($edit_url) . '">Edit</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table><br>';
        }
        echo '</div>';
    }

    /* =========================
       Frontend Dashboard
       ========================= */
    public function render_member_dashboard(): string {
        // Support either WP-auth OR your cookie member flow
        $has_cookie_member = class_exists('TFG_Cookies') && TFG_Cookies::get_member_id();

        if (!is_user_logged_in() && !$has_cookie_member) {
            return '<p>You must be logged in to view your profile.</p>';
        }

        // If WP user exists, prefer their authored profile
        $user = is_user_logged_in() ? wp_get_current_user() : null;

        $profile_query = [
            'post_type'      => 'member_profile',
            'posts_per_page' => 1,
            'post_status'    => ['publish', 'pending', 'draft'],
        ];

        if ($user) {
            $profile_query['author'] = $user->ID;
        }

        // If no WP user profile by author, try by cookie member_id meta
        $profiles = get_posts($profile_query);

        if (!$profiles && $has_cookie_member) {
            $member_id = TFG_Cookies::get_member_id();
            $profiles  = get_posts([
                'post_type'      => 'member_profile',
                'posts_per_page' => 1,
                'post_status'    => ['publish', 'pending', 'draft'],
                'meta_key'       => 'member_id',
                'meta_value'     => $member_id,
            ]);
        }

        if (!$profiles) {
            return '<p>No profile found for your account.</p>';
        }

        $post_id     = (int) $profiles[0]->ID;
        $title       = get_the_title($post_id);
        $member_type = self::get_field_value('member_type', $post_id);
        $website     = self::get_field_value('website', $post_id);
        $location    = self::get_field_value('location', $post_id);
        $email       = self::get_field_value('contact_email', $post_id);

        $edit_url = add_query_arg('post_id', $post_id, site_url('/edit-profile'));

        ob_start(); ?>
        <div class="member-dashboard" style="border:1px solid #ccc; padding:1rem; border-radius:10px;">
            <h3><?php echo esc_html($title); ?></h3>
            <p><strong>Profile Type:</strong> <?php echo esc_html($member_type ?: '—'); ?></p>
            <p><strong>Status:</strong> <?php echo esc_html(get_post_status($post_id)); ?></p>
            <p><strong>Website:</strong> <?php echo $website ? '<a href="' . esc_url($website) . '" target="_blank" rel="noopener">' . esc_html($website) . '</a>' : '—'; ?></p>
            <p><strong>Location:</strong> <?php echo esc_html($location ?: '—'); ?></p>
            <p><strong>Contact Email:</strong> <?php echo esc_html($email ?: '—'); ?></p>
            <a href="<?php echo esc_url($edit_url); ?>"
               class="button"
               style="margin-top:1rem; display:inline-block; background:#0E94FF; color:#fff; padding:0.5em 1em; border-radius:20px;">
                Edit My Profile
            </a>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /* =========================
       Helpers
       ========================= */
    private static function get_field_value(string $key, int $post_id): string {
        if (function_exists('get_field')) {
            $val = get_field($key, $post_id);
        } else {
            $val = get_post_meta($post_id, $key, true);
        }
        if (is_array($val)) {
            $val = implode(', ', array_map('sanitize_text_field', $val));
        }
        return is_string($val) ? $val : '';
    }
}
