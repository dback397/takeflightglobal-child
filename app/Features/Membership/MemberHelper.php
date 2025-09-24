<?php

namespace TFG\Features\Membership;

use TFG\Core\Utils;

/**
 * Lookup helpers for stubs and member profiles by member_id.
 */
final class MemberHelper
{
    /**
     * Get a profile stub by member_id.
     */
    public static function getStubByMemberId(string $member_id): ?\WP_Post
    {
        $member_id = Utils::normalizeMemberId($member_id);
        if ($member_id === '') {
            return null;
        }

        $id = self::findSinglePostId([
            'post_type'  => 'profile_stub',
            'meta_key'   => 'member_id',
            'meta_value' => $member_id,
        ]);

        return $id ? \get_post($id) : null;
    }

    /**
     * Get the final profile (CPT) by member_id.
     * Example $post_type: 'member_profile' | 'university_profile' | 'agency_profile'
     */
    public static function getFinalProfile(string $post_type, string $member_id): ?\WP_Post
    {
        $post_type = \sanitize_key($post_type);
        $member_id = Utils::normalizeMemberId($member_id);
        if ($post_type === '' || $member_id === '') {
            return null;
        }

        $id = self::findSinglePostId([
            'post_type'  => $post_type,
            'meta_key'   => 'member_id',
            'meta_value' => $member_id,
        ]);

        return $id ? \get_post($id) : null;
    }

    /**
     * (Optional) Get profile ID directly (faster when you only need the ID).
     */
    public static function getProfileId(string $post_type, string $member_id): ?int
    {
        $post_type = \sanitize_key($post_type);
        $member_id = Utils::normalizeMemberId($member_id);
        if ($post_type === '' || $member_id === '') {
            return null;
        }

        return self::findSinglePostId([
            'post_type'  => $post_type,
            'meta_key'   => 'member_id',
            'meta_value' => $member_id,
        ]);
    }

    /* ---------------- Internals ---------------- */

    /**
     * Find a single post ID by simple meta key/value on a CPT.
     */
    private static function findSinglePostId(array $args): ?int
    {
        $defaults = [
            'post_type'        => 'post',
            'posts_per_page'   => 1,
            'post_status'      => 'any',
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ];
        $qargs = \wp_parse_args($args, $defaults);

        // Force strict meta compare
        if (!empty($qargs['meta_key']) && \array_key_exists('meta_value', $qargs)) {
            $qargs['meta_query'] = [[
                'key'     => $qargs['meta_key'],
                'value'   => $qargs['meta_value'],
                'compare' => '=',
            ]];
            unset($qargs['meta_key'], $qargs['meta_value']);
        }

        $ids = \get_posts($qargs);
        if (empty($ids)) {
            return null;
        }

        return (int) $ids[0];
    }
}

/* ---- Legacy alias for smooth migration ---- */
\class_alias(\TFG\Features\Membership\MemberHelper::class, 'TFG_Member_Helper');
