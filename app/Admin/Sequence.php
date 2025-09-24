<?php

namespace TFG\Admin;

/**
 * Atomic sequence generator.
 * Stores counters in wp_options as "tfg_seq__{$safe_name}".
 */
final class Sequence
{
    /**
     * Atomically increments and returns the next integer in a named sequence.
     * Stored in wp_options as option_name = "tfg_seq__{$safe_name}", autoload=no.
     *
     * @param string $name  Sequence name (any string; sanitized to a safe key)
     * @param int    $start Starting number when the sequence is first created (default 1)
     * @return int          The next sequence number
     */

    public static function init(): void {} // no hooks; utility only

    public static function next(string $name, int $start = 1): int
    {
        global $wpdb;

        $safe  = 'tfg_seq__' . sanitize_key($name);
        $start = max(0, $start);
        $table = $wpdb->options;

        // 1) Ensure row exists (value = start-1). INSERT IGNORE is safe for races.
        $insert_sql = $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (option_name, option_value, autoload)
             VALUES (%s, %s, 'no')",
            $safe,
            (string) ($start - 1)
        );
        $ins = $wpdb->query($insert_sql);
        if ($ins === false) {
            error_log("[TFG_Sequence] INSERT failed for {$safe}: " . $wpdb->last_error);
        }

        // 2) Atomic increment and capture using LAST_INSERT_ID(expr)
        $update_sql = $wpdb->prepare(
            "UPDATE {$table}
             SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1)
             WHERE option_name = %s",
            $safe
        );
        $upd = $wpdb->query($update_sql);
        if ($upd === false) {
            error_log("[TFG_Sequence] UPDATE failed for {$safe}: " . $wpdb->last_error);
            return 0;
        }

        // 3) Return the value we set via LAST_INSERT_ID(expr)
        $val = (int) $wpdb->get_var("SELECT LAST_INSERT_ID()");
        return $val;
    }

    /**
     * Formats a number with optional prefix and zero-padding.
     *
     * @param int         $n
     * @param string|null $prefix e.g. 'N' -> N000123
     * @param int         $width  minimum digits (>=1)
     * @return string
     */
    public static function format(int $n, ?string $prefix = '', int $width = 6): string
    {
        $width  = max(1, $width);
        $prefix = (string) $prefix;
        return $prefix . str_pad((string) $n, $width, '0', STR_PAD_LEFT);
    }
}

// Legacy alias for backwards compatibility
\class_alias(\TFG\Admin\Sequence::class, 'TFG_Sequence');
