<?php

namespace TFG\Core;

/**
 * Structured logging system for subscribers, tokens, and access events.
 */
final class Log
{
    // Basic flood control: max N events per IP per minute (0 = disabled)
    private const RATE_LIMIT_PER_MINUTE = 30;

    public static function init(): void
    {
        // Reserved for future hooks
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
    public static function addLogEntry(array $args = [])
    {
        $ip = $_SERVER['REMOTE_ADDR']     ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $defaults = [
            'email'         => '',
            'event_type'    => '',
            'ip_address'    => \sanitize_text_field(\wp_unslash($ip)),
            'timestamp'     => \current_time('mysql'),
            'user_agent'    => \sanitize_text_field(\wp_unslash($ua)),
            'related_token' => '',
            'status'        => '',
            'notes'         => '',
        ];

        $data = \wp_parse_args($args, $defaults);

        // Sanitize/normalize fields
        $data['email']         = Utils::normalizeEmail($data['email'] ?? '');
        $data['event_type']    = self::cleanStr($data['event_type'] ?? '', 64);
        $data['ip_address']    = self::cleanStr($data['ip_address'] ?? '', 64);
        $data['timestamp']     = self::cleanStr($data['timestamp'] ?? '', 32);
        $data['user_agent']    = self::cleanStr($data['user_agent'] ?? '', 255);
        $data['related_token'] = self::cleanStr($data['related_token'] ?? '', 128);
        $data['status']        = self::cleanStr($data['status'] ?? '', 64);
        $data['notes']         = self::cleanTextarea($data['notes'] ?? '', 2000);

        $data = \apply_filters('tfg_log_prepared_data', $data);

        if ($data['event_type'] === '' && $data['notes'] === '') {
            \TFG\Core\Utils::info('⚠️ TFG\Core\Log: Skipped empty log entry (no event_type or notes)');
            return false;
        }

        // Optional flood control
        if (self::RATE_LIMIT_PER_MINUTE > 0 && $data['ip_address'] !== '') {
            $rl_key = 'tfg_log_rl_' . md5($data['ip_address'] . '|' . $data['event_type']);
            $hits   = (int) \get_transient($rl_key);
            if ($hits >= self::RATE_LIMIT_PER_MINUTE) {
                return false;
            }
            \set_transient($rl_key, $hits + 1, MINUTE_IN_SECONDS);
        }

        if (!\post_type_exists('tfg_log_entry')) {
            \TFG\Core\Utils::info('⚠️ TFG\Core\Log: CPT "tfg_log_entry" not registered.');
            return false;
        }

        $title = trim($data['event_type'] . ' @ ' . $data['timestamp']);

        $post_args = [
            'post_type'   => 'tfg_log_entry',
            'post_title'  => $title !== '' ? $title : 'log',
            'post_status' => 'publish',
        ];

        $post_args = \apply_filters('tfg_log_post_args', $post_args, $data);

        $post_id = \wp_insert_post($post_args, true);
        if (\is_wp_error($post_id) || !$post_id) {
            $msg = \is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown';
            \TFG\Core\Utils::info('❌ TFG\Core\Log: Failed to create log entry: ' . $msg);
            return false;
        }

        // Save fields
        self::saveField($post_id, 'email', $data['email']);
        self::saveField($post_id, 'event_type', $data['event_type']);
        self::saveField($post_id, 'ip_address', $data['ip_address']);
        self::saveField($post_id, 'timestamp', $data['timestamp']);
        self::saveField($post_id, 'user_agent', $data['user_agent']);
        self::saveField($post_id, 'related_token', $data['related_token']);
        self::saveField($post_id, 'status', $data['status']);
        self::saveField($post_id, 'notes', $data['notes']);

        return (int) $post_id;
    }

    /* ---------------- Internals ---------------- */

    private static function saveField(int $post_id, string $key, $value): void
    {
        if (\function_exists('update_field')) {
            \update_field($key, $value, $post_id);
            return;
        }
        \update_post_meta($post_id, $key, $value);
    }

    private static function cleanStr($val, int $max): string
    {
        $val = \is_string($val) ? \sanitize_text_field($val) : '';
        return $val === '' ? '' : mb_substr($val, 0, $max);
    }

    private static function cleanTextarea($val, int $max): string
    {
        $val = \is_string($val) ? \wp_kses_post($val) : '';
        return $val === '' ? '' : mb_substr($val, 0, $max);
    }
}

/* ---- Legacy alias for transition ---- */
\class_alias(\TFG\Core\Log::class, 'TFG_Log');
