<?php

namespace TFG\UI;

final class Carousel
{
    public static function init(): void
    {
        \add_action('kadence_blocks_post_loop_start', [self::class, 'injectCarousel'], 10, 1);
        \add_action('acf/save_post', [self::class, 'updateDeadlinePermalink'], 20, 1);
    }

    /**
     * If the Kadence block loop attributes include `special-carousel`, inject the element.
     *
     * @param array|string $attributes
     */
    public static function injectCarousel($attributes): void
    {
        if (\is_string($attributes)) {
            $decoded = \json_decode($attributes, true);
            if (\json_last_error() === \JSON_ERROR_NONE) {
                $attributes = $decoded;
            }
        }

        if (!\is_array($attributes) || empty($attributes['className'])) {
            return;
        }

        if (\strpos((string)$attributes['className'], 'special-carousel') === false) {
            return;
        }

        $element_id = (int) \apply_filters('tfg_carousel_element_id', 5139);

        if (\shortcode_exists('kadence_element') && $element_id > 0) {
            echo \do_shortcode('[kadence_element id="' . $element_id . '"]');
        }
    }

    /**
     * When a `deadline` post saves, copy the related program permalink into ACF field.
     *
     * @param int|string $post_id
     */
    public static function updateDeadlinePermalink($post_id): void
    {
        if (!\is_numeric($post_id)) {
            return;
        }
        $post_id = (int) $post_id;

        if (\wp_is_post_autosave($post_id) || \wp_is_post_revision($post_id)) {
            return;
        }

        if (\get_post_type($post_id) !== 'deadline') {
            return;
        }

        $link_code = \get_field('link_code', $post_id);
        if (!$link_code) {
            return;
        }

        $program_ids = \get_posts([
            'post_type'        => 'program',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'meta_query'       => [[
                'key'     => 'link_code',
                'value'   => $link_code,
                'compare' => '='
            ]],
        ]);

        if (!$program_ids) {
            return;
        }

        $program_id = (int) $program_ids[0];
        $permalink  = \get_permalink($program_id);

        if ($permalink) {
            \update_field('program_permalink', $permalink, $post_id);
        }
    }
}
