<?php
// === class-tfg-log.php ===
// Structured logging system for subscribers, tokens, and access events.

class TFG_Log {

    // Basic flood control: max N events per IP per minute (0 = disabled)
    private const RATE_LIMIT_PER_MINUTE = 30;

    public static function init(): void {
        // Optionally hook into system events later
    }

    /**
     * Add a structured log entry.
     *
     * @param array $args {
     *   @type string $email
     *   @type string $event_type
     *   @type string $ip_address
     *   @type string $timestamp  MySQL format; defaults to current_time('mysql')
     *   @type string $user_agent
     *   @type string $related_token
     *   @type string $status
     *   @type string $notes
     * }
     * @return int|false Post ID or false on failure
     */
    public static function add_log_entry(array $args = []) {
        // Normalize defaults from environment
        $ip  = isset($_SERVER['REMOTE_ADDR'])     ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))     : '';
        $ua  = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        $defaults = [
            'email'         => '',
            'event_type'    => '',
            'ip_address'    => $ip,
            'timestamp'     => current_time('mysql'),
            'user_agent'    => $ua,
            'related_token' => '',
            'status'        => '',
            'notes'         => '',
        ];

        $data = wp_parse_args($args, $defaults);

        // Sanitize/normalize fields
        $data['email']         = TFG_Utils::normalize_email($data['email'] ?? '');
        $data['event_type']    = self::clean_str($data['event_type'] ?? '', 64);
        $data['ip_address']    = self::clean_str($data['ip_address'] ?? '', 64);
        $data['timestamp']     = self::clean_str($data['timestamp'] ?? '', 32);
        $data['user_agent']    = self::clean_str($data['user_agent'] ?? '', 255);
        $data['related_token'] = self::clean_str($data['related_token'] ?? '', 128);
        $data['status']        = self::clean_str($data['status'] ?? '', 64);
        $data['notes']         = self::clean_textarea($data['notes'] ?? '', 2000); // cap notes size

        /**
         * Allow last-minute edits or redactions before persisting.
         * Return the (possibly) modified $data.
         */
        $data = apply_filters('tfg_log_prepared_data', $data);

        // 🛑 Basic guard: skip totally empty events
        if ($data['event_type'] === '' && $data['notes'] === '') {
            error_log('⚠️ TFG_Log: Skipped empty log entry (no event_type or notes)');
            return false;
        }

        // Optional: very small flood control by IP + event type
        if (self::RATE_LIMIT_PER_MINUTE > 0 && $data['ip_address'] !== '') {
            $rl_key = 'tfg_log_rl_' . md5($data['ip_address'] . '|' . $data['event_type']);
            $hits   = (int) get_transient($rl_key);
            if ($hits >= self::RATE_LIMIT_PER_MINUTE) {
                // Don’t error—silently drop to protect DB
                return false;
            }
            set_transient($rl_key, $hits + 1, MINUTE_IN_SECONDS);
        }

        // Ensure CPT exists (fail soft if not registered)
        if (!post_type_exists('tfg_log_entry')) {
            error_log('⚠️ TFG_Log: CPT "tfg_log_entry" not registered.');
            return false;
        }

        // Title: "event @ timestamp" (short, non-sensitive)
        $title = trim($data['event_type'] . ' @ ' . $data['timestamp']);

        $post_args = [
            'post_type'   => 'tfg_log_entry',
            'post_title'  => $title !== '' ? $title : 'log',
            'post_status' => 'publish',
        ];

        // Allow callers to tweak the post args
        $post_args = apply_filters('tfg_log_post_args', $post_args, $data);

        $post_id = wp_insert_post($post_args, true);
        if (is_wp_error($post_id) || !$post_id) {
            $msg = is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown';
            error_log('❌ TFG_Log: Failed to create log entry: ' . $msg);
            return false;
        }

        // Save fields (ACF if present, else post meta)
        self::save_field($post_id, 'email',         $data['email']);
        self::save_field($post_id, 'event_type',    $data['event_type']);
        self::save_field($post_id, 'ip_address',    $data['ip_address']);
        self::save_field($post_id, 'timestamp',     $data['timestamp']);
        self::save_field($post_id, 'user_agent',    $data['user_agent']);
        self::save_field($post_id, 'related_token', $data['related_token']);
        self::save_field($post_id, 'status',        $data['status']);
        self::save_field($post_id, 'notes',         $data['notes']);

        return (int) $post_id;
    }

    /* =================== Internals =================== */

    private static function save_field(int $post_id, string $key, $value): void {
        if (function_exists('update_field')) {
            // ACF field
            update_field($key, $value, $post_id);
            return;
        }
        // Fallback to standard post meta
        update_post_meta($post_id, $key, $value);
    }

    private static function clean_str($val, int $max): string {
        $val = is_string($val) ? sanitize_text_field($val) : '';
        if ($val === '') return '';
        // Trim to max bytes (mb safe)
        return mb_substr($val, 0, $max);
    }

    private static function clean_textarea($val, int $max): string {
        $val = is_string($val) ? wp_kses_post($val) : '';
        if ($val === '') return '';
        return mb_substr($val, 0, $max);
    }
}
