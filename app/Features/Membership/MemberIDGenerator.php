<?php

namespace TFG\Features\Membership;

/**
 * Sequential member ID generator.
 *
 * Map of type → prefix + option key.
 * Uses an atomic SQL increment for thread safety.
 */
final class MemberIDGenerator
{
    /**
     * Map member type => [prefix, option_key].
     * NOTE: 'age' in the option key is legacy; prefix is correctly 'AGY'.
     */
    private const MAP = [
        'university' => ['prefix' => 'UNI', 'option' => 'tfg_last_uni_id'],
        'agency'     => ['prefix' => 'AGY', 'option' => 'tfg_last_age_id'],
        'affiliate'  => ['prefix' => 'AFF', 'option' => 'tfg_last_aff_id'],
    ];

    /**
     * Get the next ID (thread-safe) for a type.
     */
    public static function getNextId(string $member_type): string|false
    {
        $cfg = self::config($member_type);
        if (!$cfg) {
            return false;
        }

        $n = self::atomicNext($cfg['option'], 0);
        if ($n === false) {
            return false;
        }

        return self::format($cfg['prefix'], $n);
    }

    /**
     * Preview the next ID without incrementing.
     */
    public static function previewNextId(string $member_type): string|false
    {
        $cfg = self::config($member_type);
        if (!$cfg) {
            return false;
        }

        $current = (int) \get_option($cfg['option'], 0);
        return self::format($cfg['prefix'], $current + 1);
    }

    /**
     * (Optional) Set the counter explicitly.
     * Pass the raw integer "last used" value (the next call will return +1).
     */
    public static function setCounter(string $member_type, int $value): bool
    {
        $cfg = self::config($member_type);
        if (!$cfg) {
            return false;
        }
        return \update_option($cfg['option'], max(0, $value), false);
    }

    /**
     * (Optional) Get the raw counter (last used).
     */
    public static function getCounter(string $member_type): int|false
    {
        $cfg = self::config($member_type);
        if (!$cfg) {
            return false;
        }
        return (int) \get_option($cfg['option'], 0);
    }

    /* =========================
       Internals
       ========================= */

    private static function config(string $member_type): array|false
    {
        $key = \sanitize_key($member_type);
        return self::MAP[$key] ?? false;
    }

    private static function format(string $prefix, int $n, int $width = 5): string
    {
        return \sprintf('%s%0' . $width . 'd', $prefix, $n);
    }

    /**
     * Atomic increment of an option value using LAST_INSERT_ID trick.
     * Returns the new integer value, or false on failure.
     */
    private static function atomicNext(string $option_name, int $start = 0): int|false
    {
        global $wpdb;

        $table = $wpdb->options;
        $start = max(0, (int) $start);

        // Ensure the row exists (autoload=no)
        $ins = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (option_name, option_value, autoload)
                 VALUES (%s, %s, 'no')",
                $option_name,
                (string) $start
            )
        );
        if ($ins === false) {
            return false;
        }

        // Atomic + fetch
        $upd = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                   SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1)
                 WHERE option_name = %s",
                $option_name
            )
        );
        if ($upd === false) {
            return false;
        }

        $val = $wpdb->get_var('SELECT LAST_INSERT_ID()');
        return \is_null($val) ? false : (int) $val;
    }
}

/* ---- Legacy alias for smooth migration ---- */
\class_alias(\TFG\Features\Membership\MemberIDGenerator::class, 'TFG_Member_ID_Generator');
