<?php

namespace TFG\Features\Newsletter;

use TFG\Core\Utils;
use TFG\Core\Cookies;

/**
 * SubscriberUtilities
 * Helper methods for checking subscriber status.
 */
final class SubscriberUtilities
{
    /**
     * Return true if the email corresponds to a subscriber that is
     * both verified AND subscribed.
     */
    public static function isVerifiedSubscriber($email): bool
    {
        $email = Utils::normalizeEmail($email);
        if (!$email) {
            return false;
        }

        // 1) Optional fast-path: if HttpOnly cookie matches this email, trust it.
        if (\class_exists(Cookies::class) && Cookies::isSubscribed($email)) {
            return (bool) \apply_filters('tfg_is_verified_subscriber', true, $email, 'cookie');
        }

        // 2) Transient cache to avoid repeated DB hits
        $cache_key = 'tfg_verified_' . md5($email);
        $cached    = \get_transient($cache_key);
        if ($cached !== false) {
            return (bool) $cached;
        }

        // 3) DB lookup
        $ids = \get_posts([
            'post_type'        => 'subscriber',
            'post_status'      => 'publish',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'meta_query'       => [
                'relation' => 'AND',
                [ 'key' => 'email',         'value' => $email, 'compare' => '=' ],
                [ 'key' => 'is_verified',   'value' => 1, 'compare' => '=', 'type' => 'NUMERIC' ],
                [ 'key' => 'is_subscribed', 'value' => 1, 'compare' => '=', 'type' => 'NUMERIC' ],
            ],
        ]);

        $ok = !empty($ids);

        \set_transient($cache_key, $ok ? 1 : 0, 5 * MINUTE_IN_SECONDS);

        return (bool) \apply_filters('tfg_is_verified_subscriber', $ok, $email, 'query');
    }

    /**
     * Fetch subscriber post ID by email (or 0 if not found).
     */
    public static function getSubscriberIdByEmail($email): int
    {
        $email = Utils::normalizeEmail($email);
        if (!$email) {
            return 0;
        }

        $ids = \get_posts([
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
    public static function getStatus($email): array
    {
        $id = self::getSubscriberIdByEmail($email);
        if (!$id) {
            return ['exists' => false, 'verified' => false, 'subscribed' => false, 'id' => 0];
        }

        $verified   = (int) \get_post_meta($id, 'is_verified', true) === 1;
        $subscribed = (int) \get_post_meta($id, 'is_subscribed', true) === 1;

        return [
            'exists'     => true,
            'verified'   => $verified,
            'subscribed' => $subscribed,
            'id'         => $id,
        ];
    }
}

// Legacy alias for backwards compatibility
\class_alias(\TFG\Features\Newsletter\SubscriberUtilities::class, 'TFG_Subscriber_Utilities');
