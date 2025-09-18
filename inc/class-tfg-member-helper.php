<?php

class TFG_Member_Helper {

    /**
     * Get a profile stub by member_id.
     */
    public static function get_stub_by_member_id(string $member_id): ?WP_Post {
        $member_id = TFG_Utils::normalize_member_id($member_id);
        if ($member_id === '') return null;

        $id = self::find_single_post_id([
            'post_type'  => 'profile_stub',
            'meta_key'   => 'member_id',
            'meta_value' => $member_id,
        ]);

        return $id ? get_post($id) : null;
    }

    /**
     * Get the final profile (CPT) by member_id.
     * Example: $member_type = 'university_profile' | 'agency_profile' | 'member_profile'
     */
    public static function get_final_profile(string $member_type, string $member_id): ?WP_Post {
        $member_type = sanitize_key($member_type);
        $member_id   = TFG_Utils::normalize_member_id($member_id);
        if ($member_type === '' || $member_id === '') return null;

        $id = self::find_single_post_id([
            'post_type'  => $member_type,
            'meta_key'   => 'member_id',
            'meta_value' => $member_id,
        ]);

        return $id ? get_post($id) : null;
    }

    /**
     * (Optional) Get profile ID directly (faster when you only need the ID).
     */
    public static function get_profile_id(string $member_type, string $member_id): ?int {
        $member_type = sanitize_key($member_type);
        $member_id   = TFG_Utils::normalize_member_id($member_id);
        if ($member_type === '' || $member_id === '') return null;

        return self::find_single_post_id([
            'post_type'  => $member_type,
            'meta_key'   => 'member_id',
            'meta_value' => $member_id,
        ]);
    }

    /* ---------------- Internals ---------------- */

    /**
     * Find a single post ID by simple meta key/value on a CPT.
     */
    private static function find_single_post_id(array $args): ?int {
        $defaults = [
            'post_type'        => 'post',
            'posts_per_page'   => 1,
            'post_status'      => 'any',
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ];
        $qargs = wp_parse_args($args, $defaults);

        // Force a meta_query so we don’t accidentally miss strict compare
        if (!empty($qargs['meta_key']) && array_key_exists('meta_value', $qargs)) {
            $qargs['meta_query'] = [[
                'key'     => $qargs['meta_key'],
                'value'   => $qargs['meta_value'],
                'compare' => '=',
            ]];
            unset($qargs['meta_key'], $qargs['meta_value']);
        }

        $ids = get_posts($qargs);
        if (empty($ids)) return null;

        return (int) $ids[0];
    }
}
