<?php
class TFG_Carousel {

    public static function init(): void {
        add_action('kadence_blocks_post_loop_start', [__CLASS__, 'inject_carousel'], 10, 1);
        add_action('acf/save_post', [__CLASS__, 'update_deadline_permalink'], 20, 1);
    }

    /**
     * If the Kadence block loop attributes include `special-carousel`, inject the element.
     *
     * @param array|string $attributes
     */
    public static function inject_carousel($attributes): void {
        // Be defensive: Kadence may pass array or JSON string
        if (is_string($attributes)) {
            // Try to decode JSON-ish strings; if not JSON just bail
            $decoded = json_decode($attributes, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $attributes = $decoded;
            }
        }

        if (!is_array($attributes) || empty($attributes['className'])) {
            return;
        }

        // Look for our marker class
        if (strpos((string)$attributes['className'], 'special-carousel') === false) {
            return;
        }

        // Allow overrides of the element ID via a filter
        $element_id = (int) apply_filters('tfg_carousel_element_id', 5139);

        // Only render if the shortcode exists (avoid echoing unknown shortcodes)
        if (shortcode_exists('kadence_element') && $element_id > 0) {
            echo do_shortcode('[kadence_element id="' . $element_id . '"]');
        }
    }

    /**
     * When a `deadline` post saves, copy the related program permalink into ACF field.
     * Runs after ACF has saved its fields (priority 20).
     *
     * @param int|string $post_id
     */
    public static function update_deadline_permalink($post_id): void {
        // Skip ACF options pages and non-numeric IDs
        if (!is_numeric($post_id)) {
            return;
        }
        $post_id = (int) $post_id;

        // Skip autosaves/revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Ensure correct post type
        if (get_post_type($post_id) !== 'deadline') {
            return;
        }

        // Must have a link_code on the deadline
        $link_code = get_field('link_code', $post_id);
        if (!$link_code) {
            return;
        }

        // Find the FIRST matching program by link_code, efficiently
        $program_ids = get_posts([
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
        $permalink  = get_permalink($program_id);
        if ($permalink) {
            // Keep using ACF field name to stay consistent with your data model
            update_field('program_permalink', $permalink, $post_id);
        }
    }
}
