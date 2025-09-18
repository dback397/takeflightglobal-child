<?php
/**
 * Profile Button Generator
 * Shortcode: [tfge_matrix_buttons]
 * - Uses current institution_profile’s ACF "institution_code"
 * - Finds all link_button posts with matching code
 * - Sorts by ACF "button_order" (numeric)
 * - Renders as WP button group
 */
class TFG_Profile_Buttons {

    public static function init(): void {
        (new self())->register_hooks();
    }

    public function register_hooks(): void {
        add_shortcode('tfge_matrix_buttons', [$this, 'render_institution_program_buttons']);
    }

    public function render_institution_program_buttons(): string {
        // Require ACF and correct context
        if (!function_exists('get_field')) {
            return '';
        }
        if (!is_singular('institution_profile')) {
            return '';
        }

        $post_id = get_the_ID();
        if (!$post_id || get_post_type($post_id) !== 'institution_profile') {
            return '';
        }

        // Fetch institution code (normalized)
        $inst_code = get_field('institution_code', $post_id);
        $inst_code = $inst_code ? sanitize_text_field($inst_code) : '';
        if ($inst_code === '') {
            return '';
        }

        // Query matching buttons, sorted by numeric ACF field "button_order"
        $button_ids = get_posts([
            'post_type'        => 'link_button',
            'posts_per_page'   => -1,
            'post_status'      => 'publish',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'fields'           => 'ids',
            'meta_key'         => 'button_order',
            'orderby'          => 'meta_value_num',
            'order'            => 'ASC',
            'meta_query'       => [[
                'key'     => 'institution_code',
                'value'   => $inst_code,
                'compare' => '=',
            ]],
        ]);

        if (empty($button_ids)) {
            return '';
        }

        ob_start();
        echo '<div class="wp-block-buttons is-layout-flex wp-block-buttons-is-layout-flex" style="justify-content:center;gap:1rem;flex-wrap:wrap;">';

        foreach ($button_ids as $bid) {
            // ACF fields on the button CPT
            $label = get_field('button_label', $bid);
            $url   = get_field('url_reference', $bid);

            $label = $label ? sanitize_text_field($label) : 'Program Link';
            $url   = $url ? esc_url($url) : '';

            if ($url === '') {
                continue;
            }

            // Target: stay same-tab unless you have a reason to open new
            echo '<div class="wp-block-button">';
            echo '<a class="wp-block-button__link wp-element-button" href="', $url, '" target="_self" aria-label="', esc_attr($label), '">';
            echo esc_html($label);
            echo '</a></div>';
        }

        echo '</div>';
        return (string) ob_get_clean();
    }
}
