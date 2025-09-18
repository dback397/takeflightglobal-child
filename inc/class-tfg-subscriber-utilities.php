<?php
final class TFG_Subscriber_Utilities
{
    /**
     * Return true if the email corresponds to a subscriber that is
     * both verified AND subscribed.
     */
public static function is_verified_subscriber($email): bool
{
    $email = TFG_Utils::normalize_email($email);
    if (!$email) {
        return false;
    }

    // 1) Optional fast-path: if your HttpOnly cookie matches this email, trust it.
    if (class_exists('TFG_Cookies') && TFG_Cookies::is_subscribed($email)) {
        /** Allow plugins/themes to veto if needed */
        return (bool) apply_filters('tfg_is_verified_subscriber', true, $email, 'cookie');
    }

    // 2) Small cache to avoid repeated DB hits
    $cache_key = 'tfg_verified_' . md5($email);
    $cached    = get_transient($cache_key);
    if ($cached !== false) {
        return (bool) $cached;
    }

    // 3) DB lookup
    $ids = get_posts([
        'post_type'        => 'subscriber',
        'post_status'      => 'publish',
        'posts_per_page'   => 1,
        'fields'           => 'ids',
        'suppress_filters' => true,
        'no_found_rows'    => true,
        'meta_query'       => [
            'relation' => 'AND',
            [ 'key' => 'email',         'value' => $email, 'compare' => '=' ],
            // ACF true_false stores "1"/"0" – cast as NUMERIC for safety
            [ 'key' => 'is_verified',   'value' => 1, 'compare' => '=', 'type' => 'NUMERIC' ],
            [ 'key' => 'is_subscribed', 'value' => 1, 'compare' => '=', 'type' => 'NUMERIC' ],
        ],
    ]);

    $ok = !empty($ids);

    // Cache for a few minutes (tweak as you like)
    set_transient($cache_key, $ok ? 1 : 0, 5 * MINUTE_IN_SECONDS);

    return (bool) apply_filters('tfg_is_verified_subscriber', $ok, $email, 'query');
}


    /**
     * Fetch subscriber post ID by email (or 0 if not found).
     */
    public static function get_subscriber_id_by_email($email): int
    {
        $email = TFG_Utils::normalize_email($email);
        if (!$email) return 0;

        $ids = get_posts([
            'post_type'        => 'subscriber',
            'post_status'      => 'publish',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'meta_query'       => [
                [ 'key' => 'email', 'value' => $email, 'compare' => '=' ],
            ],
        ]);

        return $ids ? (int) $ids[0] : 0;
    }

    /**
     * Get a compact status snapshot.
     * Returns: ['exists'=>bool,'verified'=>bool,'subscribed'=>bool,'id'=>int]
     */
    public static function get_status($email): array
    {
        $id = self::get_subscriber_id_by_email($email);
        if (!$id) {
            return ['exists' => false, 'verified' => false, 'subscribed' => false, 'id' => 0];
        }

        // Use get_post_meta for speed; ACF get_field() is fine too if you prefer.
        $verified   = (int) get_post_meta($id, 'is_verified', true) === 1;
        $subscribed = (int) get_post_meta($id, 'is_subscribed', true) === 1;

        return [
            'exists'     => true,
            'verified'   => $verified,
            'subscribed' => $subscribed,
            'id'         => $id,
        ];
    }
}
