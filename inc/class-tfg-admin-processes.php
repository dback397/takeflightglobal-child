<?php
class TFG_Admin_Processes {

    public static function init(): void {
        add_action('init', [self::class, 'tfg_message_loader']);
        add_shortcode('test_error_modals', [__CLASS__, 'tfg_test_error_modals']);
        add_shortcode('load_table', [__CLASS__, 'load_cpt_table']);
        add_shortcode('debug_verification_token', [__CLASS__, 'debug_verification_token_shortcode']);
        add_shortcode('debug_magic_token', [__CLASS__, 'debug_magic_token_shortcode']);
        add_action('wp_dashboard_setup', [static::class, 'register_dashboard_widget']);
        add_action('admin_menu', [self::class, 'register_member_id_menu']);
    }

    public static function init_member_id_tracker(): void {
        error_log('[TFG] 🔧 init_member_id_tracker() running');
        add_action('admin_menu', [static::class, 'register_member_id_menu']);
    }

    /* ---------------- Dashboard widget ---------------- */

    public static function register_dashboard_widget(): void {
        wp_add_dashboard_widget(
            'tfg_member_id_tracker_widget',
            'Member ID Counters',
            [static::class, 'render_dashboard_widget']
        );
    }

    public static function check_access_permission($capability = 'manage_options'): bool {
        if (!current_user_can($capability)) {
            $uri   = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            $user  = wp_get_current_user();
            $uname = (is_a($user, 'WP_User') && $user->exists()) ? $user->user_login : '(anon)';
            error_log("Unauthorized access to {$uri} by user: {$uname}");
            wp_die(esc_html__('Access denied', 'tfg'));
        }
        return true;
    }

    public static function render_dashboard_widget(): void {
        if (isset($_GET['set_member_id'])) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Member ID counters updated successfully.', 'tfg') . '</p></div>';
        }

        $uni = (int) get_option('tfg_last_uni_id', 0);
        $age = (int) get_option('tfg_last_age_id', 0);
        $aff = (int) get_option('tfg_last_aff_id', 0);

        $rows = [
            ['label' => 'University', 'prefix' => 'UNI', 'value' => $uni],
            ['label' => 'Agency',     'prefix' => 'AGY', 'value' => $age],
            ['label' => 'Affiliate',  'prefix' => 'AFF', 'value' => $aff],
        ];

        echo '<form method="post">';
        wp_nonce_field('tfg_set_counter_widget');
        echo '<input type="hidden" name="set_widget_counter" value="1" />';
        echo '<table class="form-table"><tbody>';

        foreach ($rows as $row) {
            $prefix  = $row['prefix'];
            $label   = $row['label'];
            $value   = (int) $row['value'];
            $next_id = $prefix . str_pad($value + 1, 5, '0', STR_PAD_LEFT);

            echo '<tr>';
            echo '<th><label for="counter_' . esc_attr($prefix) . '">' . esc_html($label) . '</label></th>';
            echo '<td>';
            echo '<input type="number" id="counter_' . esc_attr($prefix) . '" name="counter[' . esc_attr($prefix) . ']" value="' . esc_attr($value) . '" min="0" class="small-text" />';
            echo '<br><span style="color:#666; font-size:0.9em;">' . esc_html__('Next ID:', 'tfg') . ' <code>' . esc_html($next_id) . '</code></span>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Set Counters', 'tfg') . '</button></p>';
        echo '</form>';
    }

    /* ---------------- Admin menu + page ---------------- */

    public static function register_member_id_menu(): void {
    error_log('[TFG] 🧩 register_member_id_menu() running');

    // Optional: only log the cap state when debugging
    if (defined('TFG_DEBUG_CAPS') && TFG_DEBUG_CAPS) {
        error_log('[TFG] manage_options? ' . (current_user_can('manage_options') ? 'YES' : 'NO'));
    }

    add_menu_page(
        'Member ID Tracker',
        'Member ID Tracker',
        'manage_options',                 // WP will hide/deny if the user truly lacks it
        'tfg_member_id_tracker',
        [__CLASS__, 'render_member_id_page'],
        'dashicons-admin-network',
        57
    );

    error_log('[TFG] ✅ Menu page added: Member ID Tracker');
}


    public static function render_member_id_page(): void {
        self::check_access_permission('manage_options');

        if (
            isset($_POST['tfg_set_member_id'], $_POST['member_type'], $_POST['new_value']) &&
            check_admin_referer('tfg_set_member_id_action')
        ) {
            $type  = strtoupper(sanitize_text_field(wp_unslash($_POST['member_type'])));
            $value = (int) ($_POST['new_value']);

            switch ($type) {
                case 'UNI':
                    update_option('tfg_last_uni_id', $value);
                    break;
                case 'AGY': // ✅ matches widget prefix
                    update_option('tfg_last_age_id', $value);
                    break;
                case 'AFF':
                    update_option('tfg_last_aff_id', $value);
                    break;
            }

            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('Updated %s counter to %d', 'tfg'), $type, $value)) . '</p></div>';
        }

        $uni = (int) get_option('tfg_last_uni_id', 0);
        $age = (int) get_option('tfg_last_age_id', 0);
        $aff = (int) get_option('tfg_last_aff_id', 0);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Member ID Tracker', 'tfg'); ?></h1>
            <p><?php echo esc_html__('This tool lets you view and edit the starting number used to generate member IDs. The Current Value is the last used number. The Next ID is what will be generated next.', 'tfg'); ?></p>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Member Type', 'tfg'); ?></th>
                        <th><?php echo esc_html__('Prefix', 'tfg'); ?></th>
                        <th><?php echo esc_html__('Current Value', 'tfg'); ?></th>
                        <th><?php echo esc_html__('Next ID', 'tfg'); ?></th>
                        <th><?php echo esc_html__('Set Start Value', 'tfg'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ([
                    ['label' => 'University', 'prefix' => 'UNI', 'value' => $uni],
                    ['label' => 'Agency',     'prefix' => 'AGY', 'value' => $age],
                    ['label' => 'Affiliate',  'prefix' => 'AFF', 'value' => $aff],
                ] as $row):
                    $next_id = $row['prefix'] . str_pad(((int)$row['value']) + 1, 5, '0', STR_PAD_LEFT);
                    ?>
                    <tr>
                        <td><?php echo esc_html($row['label']); ?></td>
                        <td><?php echo esc_html($row['prefix']); ?></td>
                        <td><?php echo esc_html((string) $row['value']); ?></td>
                        <td><code><?php echo esc_html($next_id); ?></code></td>
                        <td>
                            <form method="post">
                                <?php wp_nonce_field('tfg_set_member_id_action'); ?>
                                <input type="hidden" name="member_type" value="<?php echo esc_attr($row['prefix']); ?>" />
                                <input type="number" name="new_value" value="<?php echo esc_attr((string) $row['value']); ?>" min="0" class="small-text" />
                                <button type="submit" name="tfg_set_member_id" class="button button-primary"><?php echo esc_html__('Update', 'tfg'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ---------------- Message seeding ---------------- */

    public static function tfg_message_loader(): void {
        if (isset($_GET['seed_messages']) && $_GET['seed_messages'] === '1') {
            self::check_access_permission('manage_options');
            if (!get_option('tfg_messages_seeded')) {
                self::tfg_add_error_messages_from_array();
                update_option('tfg_messages_seeded', true);
                error_log('✅ Message seed executed and option saved.');
            } else {
                error_log('⏭️ Message seed already completed. Skipping.');
            }
        }
    }

    public static function tfg_add_error_messages_from_array(): void {
        $modal_messages = [
            ['msg_class'=>'ERROR','msg_code'=>'101','title'=>'Invalid Email','message'=>'Please enter a valid email address to receive your login link.','dashicon'=>'no-alt','text_color'=>'#FFFFFF','body_color'=>'#F51E24','called_by'=>'Magic Login','notes'=>'Invalid format submitted in the magic login form.'],
            ['msg_class'=>'INFO','msg_code'=>'102','title'=>'Already Subscribed','message'=>'You are already subscribed. Welcome back.','dashicon'=>'yes-alt','text_color'=>'#FFFFFF','body_color'=>'#487A47','called_by'=>'Magic Login','notes'=>'Email exists and is marked as subscribed.'],
            ['msg_class'=>'ERROR','msg_code'=>'103','title'=>'Token Reused','message'=>'This login link has already been used. Please request a new one.','dashicon'=>'update','text_color'=>'#FFFFFF','body_color'=>'#F51E24','called_by'=>'Magic Login','notes'=>'Reused token detected.'],
            ['msg_class'=>'ERROR','msg_code'=>'104','title'=>'Invalid Token or Email','message'=>'This login link is invalid or missing required information.','dashicon'=>'dismiss','text_color'=>'#FFFFFF','body_color'=>'#F51E24','called_by'=>'Magic Login','notes'=>'Token or email not present or malformed in URL.'],
            ['msg_class'=>'ERROR','msg_code'=>'105','title'=>'Link Expired','message'=>'This login link has expired. Please submit your email again to get a new one.','dashicon'=>'clock','text_color'=>'#FFFFFF','body_color'=>'#F51E24','called_by'=>'Magic Login','notes'=>'Token expiration time has passed.'],
            ['msg_class'=>'ERROR','msg_code'=>'106','title'=>'Link Not Found','message'=>'No matching login link found. Please check the link or try again.','dashicon'=>'search','text_color'=>'#FFFFFF','body_color'=>'#F51E24','called_by'=>'Magic Login','notes'=>'Token/email combo not found in magic_token CPT.'],
            ['msg_class'=>'ERROR','msg_code'=>'107','title'=>'Verification Failed','message'=>'Your email could not be verified. Please try again or request a new link.','dashicon'=>'warning','text_color'=>'#FFFFFF','body_color'=>'#F51E24','called_by'=>'Magic Login','notes'=>'Verification_token lookup failed or mismatch.'],
            ['msg_class'=>'INFO','msg_code'=>'108','title'=>'Subscription Confirmed','message'=>'Your email is now verified and subscriber access enabled.','dashicon'=>'yes','text_color'=>'#FFFFFF','body_color'=>'#487A47','called_by'=>'Magic Login','notes'=>'Token was valid, subscriber created or updated.'],
            ['msg_class'=>'INFO','msg_code'=>'109','title'=>'Link Sent','message'=>'Your subscriber login link has been sent! Please check your inbox (and spam folder).','dashicon'=>'email','text_color'=>'#FFFFFF','body_color'=>'#487A47','called_by'=>'Magic Login','notes'=>'After successful token generation and email dispatch.'],
            ['msg_class'=>'WARNING','msg_code'=>'110','title'=>'Technical Error','message'=>'A technical issue occurred. Please try again later.','dashicon'=>'admin-network','text_color'=>'#FFFFFF','body_color'=>'#F15624','called_by'=>'Magic Login','notes'=>'wp_insert_post failure, or field update fails.'],
            ['msg_class'=>'INFO','msg_code'=>'111','title'=>'IP Address Logged','message'=>'Your IP address has been logged for security.','dashicon'=>'location','text_color'=>'#FFFFFF','body_color'=>'#487A47','called_by'=>'Magic Login','notes'=>'After successful login and tracking. (Optional display)'],
            ['msg_class'=>'WARNING','msg_code'=>'112','title'=>'Link Already Sent','message'=>'A login link has already been sent to your email. Please check your inbox or spam folder.','dashicon'=>'email-alt','text_color'=>'#FFFFFF','body_color'=>'#F15624','called_by'=>'Magic Login','notes'=>'Same user requested again before acting on the first link.'],
        ];

        foreach ($modal_messages as $msg) {
            if (empty($msg['msg_code'])) { continue; }

            $existing = get_posts([
                'post_type'        => 'messages',
                'posts_per_page'   => 1,
                'fields'           => 'ids',
                'suppress_filters' => true,
                'no_found_rows'    => true,
                'meta_query'       => [[
                    'key'     => 'msg_code',
                    'value'   => $msg['msg_code'],
                    'compare' => '=',
                ]],
            ]);
            if (!empty($existing)) { continue; }

            $post_id = wp_insert_post([
                'post_title'  => $msg['title'],
                'post_type'   => 'messages',
                'post_status' => 'publish',
            ]);

            if (is_wp_error($post_id)) {
                error_log("❌ Failed to insert message: {$msg['msg_code']}");
                continue;
            }

            update_field('msg_class',  $msg['msg_class'],  $post_id);
            update_field('msg_code',   $msg['msg_code'],   $post_id);
            update_field('title',      $msg['title'],      $post_id);
            update_field('message',    $msg['message'],    $post_id);
            update_field('dashicon',   $msg['dashicon'],   $post_id);
            update_field('text_color', $msg['text_color'], $post_id);
            update_field('body_color', $msg['body_color'], $post_id);
            update_field('called_by',  $msg['called_by'],  $post_id);
            update_field('notes',      $msg['notes'],      $post_id);

            error_log("✅ Created message post: {$msg['msg_code']}");
        }
    }

    /* ---------------- Shortcodes ---------------- */

    public static function tfg_test_error_modals(): string {
        self::check_access_permission('manage_options');

        $query = new WP_Query([
            'post_type'      => 'messages',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'meta_value_num',
            'meta_key'       => 'msg_code',
            'order'          => 'ASC',
        ]);

        if (!$query->have_posts()) {
            return '<div style="color:red;"><strong>⚠️ ' . esc_html__('No modal messages found.', 'tfg') . '</strong></div>';
        }

        ob_start();
        echo '<div class="tfg-test-modals">';
        echo '<h3>' . esc_html__('📋 Testing All Error Modals', 'tfg') . '</h3><ul>';
        foreach ($query->posts as $post) {
            $code  = (string) get_field('msg_code', $post->ID);
            $title = get_the_title($post);
            $safe_code = esc_js($code);
            echo '<li><a href="#" onclick="tfgShowErrorModal(\'' . $safe_code . '\');return false;">'
               . esc_html(sprintf('Modal %s: %s', $code, $title))
               . '</a></li>';
        }
        echo '</ul></div>';

        return (string) ob_get_clean();
    }

    public static function load_cpt_table($atts): string {
        self::check_access_permission('manage_options');

        $atts = shortcode_atts([
            'post_type' => '',
            'status'    => 'publish',
            'limit'     => -1, // unlimited
        ], $atts);

        $post_type = sanitize_key($atts['post_type']);
        $status    = sanitize_key($atts['status']);
        $limit     = (int) $atts['limit'];

        if ($post_type === '') {
            return '<div style="color:red;"><strong>⚠️ ' . esc_html__('Missing post_type attribute.', 'tfg') . '</strong></div>';
        }

        $query = new WP_Query([
            'post_type'      => $post_type,
            'post_status'    => $status,
            'posts_per_page' => $limit,
        ]);

        if (!$query->have_posts()) {
            return '<div>' . sprintf(
                /* translators: %s: post type key */
                esc_html__('No posts found for post_type %s.', 'tfg'),
                '<strong>' . esc_html($post_type) . '</strong>'
            ) . '</div>';
        }

        ob_start();
        echo '<pre>';
        foreach ($query->posts as $post) {
            $row = [
                'ID'     => (int) $post->ID,
                'title'  => get_the_title($post),
                'date'   => $post->post_date,
                'status' => $post->post_status,
                'type'   => $post->post_type,
            ];
            echo esc_html(print_r($row, true)) . "\n";
        }
        echo '</pre>';
        return (string) ob_get_clean();
    }

    public static function debug_magic_token_shortcode($atts): string {
        self::check_access_permission('manage_options');

        $atts    = shortcode_atts(['id' => 0], $atts);
        $post_id = (int) $atts['id'];
        if (!$post_id) { return '⚠️ ' . esc_html__('Missing post_id', 'tfg'); }

        // Use canonical post meta keys from magic utilities
        $fields = [
            'email'         => get_post_meta($post_id, 'email', true),
            'token_hash'    => get_post_meta($post_id, 'token_hash', true),
            'used'          => (int) get_post_meta($post_id, 'used', true),
            'expires_at'    => (int) get_post_meta($post_id, 'expires_at', true),
            'sequence_id'   => (int) get_post_meta($post_id, 'sequence_id', true),
            'sequence_code' => get_post_meta($post_id, 'sequence_code', true),
        ];

        error_log('🔎 magic_tokens meta: ' . print_r($fields, true));
        return '<pre>' . esc_html(print_r($fields, true)) . '</pre>';
    }

    public static function debug_verification_token_shortcode($atts): string {
        self::check_access_permission('manage_options');

        $atts    = shortcode_atts(['id' => 0], $atts);
        $post_id = (int) $atts['id'];
        if (!$post_id) { return '⚠️ ' . esc_html__('Missing post_id', 'tfg'); }

        $fields = [
            'verification_code' => get_field('verification_code', $post_id),
            'is_used'           => get_field('is_used', $post_id),
            'is_used_copy'      => get_field('is_used_copy', $post_id),
            'email_used'        => get_field('email_used', $post_id),
        ];

        error_log('🔍 verification_tokens ACF: ' . print_r($fields, true));
        return '<pre>' . esc_html(print_r($fields, true)) . '</pre>';
    }
}
