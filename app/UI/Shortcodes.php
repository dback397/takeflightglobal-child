<?php

namespace TFG\UI;

/**
 * Shortcodes & Link Button CPT
 *
 * - Registers `link_button` CPT
 * - Provides shortcodes:
 *   [tfge_matrix_buttons]
 *   [tfge_program_link_codes]
 *   [tfge_list_program_link_codes]  (compatibility)
 *   [tfge_update_brochures]         (admin-only batch)
 */
final class Shortcodes
{
    public static function init(): void
    {
        \add_action('init', function () {
            self::registerLinkButtonCpt();
            (new self())->register_hooks();
        });
    }

    private function register_hooks(): void
    {
        \add_shortcode('tfge_matrix_buttons',         [$this, 'renderMatrixButtons']);
        \add_shortcode('tfge_program_link_codes',     [$this, 'renderProgramLinkCodes']);
        \add_shortcode('tfge_list_program_link_codes',[__CLASS__, 'listProgramLinkCodes']);
        \add_shortcode('tfge_update_brochures',       [$this, 'updateBrochureUrls']);
    }

    /* ==============================
       CPT: link_button
       ============================== */
    public static function registerLinkButtonCpt(): void
    {
        \register_post_type('link_button', [
            'labels' => [
                'name'          => __('Link Buttons', 'tfg'),
                'singular_name' => __('Link Button', 'tfg'),
            ],
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'supports'           => ['title', 'custom-fields'],
            'menu_position'      => 30,
            'menu_icon'          => 'dashicons-external',
            'map_meta_cap'       => true,
            'capability_type'    => 'post',
        ]);
    }

    /* ==============================
       [tfge_matrix_buttons]
       Renders buttons for current institution via ACF 'institution_code'
       ============================== */
    public function renderMatrixButtons(): string
    {
        if (!\function_exists('get_field')) {
            return '';
        }

        $code = \get_field('institution_code');
        $code = is_string($code) ? trim($code) : '';
        if ($code === '') {
            return '';
        }

        $programs = \get_posts([
            'post_type'        => 'link_button',
            'posts_per_page'   => -1,
            'meta_key'         => 'button_order',
            'orderby'          => 'meta_value_num',
            'order'            => 'ASC',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'meta_query'       => [[
                'key'     => 'institution_code',
                'value'   => $code,
                'compare' => '=',
            ]],
        ]);

        if (empty($programs)) {
            return '';
        }

        \ob_start();
        echo '<div class="wp-block-buttons is-layout-flex wp-block-buttons-is-layout-flex" style="justify-content:center;gap:1rem;flex-wrap:wrap;">';

        foreach ($programs as $p) {
            $label = (string) \get_field('button_label', $p->ID);
            $url   = (string) \get_field('url_reference', $p->ID);

            if ($url !== '') {
                $label = $label !== '' ? $label : 'Program Link';
                echo '<div class="wp-block-button">';
                echo '<a class="wp-block-button__link wp-element-button" href="', \esc_url($url), '" target="_self" rel="noopener noreferrer">', \esc_html($label), '</a>';
                echo '</div>';
            }
        }

        echo '</div>';
        return (string) \ob_get_clean();
    }

    /* ==============================
       [tfge_program_link_codes]
       ============================== */
    public function renderProgramLinkCodes(): string
    {
        if (!\function_exists('get_field')) {
            return '<p><em>ACF is not active.</em></p>';
        }

        $posts = \get_posts([
            'post_type'        => 'program',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'meta_query'       => [['key' => 'link_code', 'compare' => 'EXISTS']],
        ]);

        if (empty($posts)) {
            return '<p><em>No program link codes found.</em></p>';
        }

        $codes = [];
        foreach ($posts as $pid) {
            $code = (string) \get_field('link_code', $pid);
            $code = trim($code);
            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        if (empty($codes)) {
            return '<p><em>No program link codes found.</em></p>';
        }

        $codes = array_keys($codes);
        sort($codes, SORT_NATURAL | SORT_FLAG_CASE);

        $out = '<div class="tfge-link-code-list"><strong>Valid Program <code>link_code</code> values:</strong><ul>';
        foreach ($codes as $c) {
            $out .= '<li>' . \esc_html($c) . '</li>';
        }
        $out .= '</ul></div>';
        return $out;
    }

    /* ==============================
       [tfge_list_program_link_codes] (legacy)
       ============================== */
    public static function listProgramLinkCodes(): string
    {
        $programs = \get_posts([
            'post_type'        => 'program',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);

        if (empty($programs)) {
            return '<ul></ul>';
        }

        $out = '<ul>';
        foreach ($programs as $pid) {
            $code = (string) \get_field('link_code', $pid);
            $code = trim($code);
            if ($code !== '') {
                $out .= '<li>' . \esc_html($code) . '</li>';
            }
        }
        $out .= '</ul>';
        return $out;
    }

    /* ==============================
       [tfge_update_brochures]
       ============================== */
    public function updateBrochureUrls(): string
    {
        if (!\current_user_can('manage_options')) {
            \TFG\Core\Utils::info('[Update Brochure URLs] Unauthorized access attempt.');
            return '<strong>Unauthorized access.</strong>';
        }
        if (!\function_exists('get_field')) {
            return '<strong>ACF plugin is not active.</strong>';
        }

        $do_update = isset($_GET['do_update']) && $_GET['do_update'] === '1';
        if ($do_update) {
            $nonce = $_GET['_wpnonce'] ?? '';
            if (!\wp_verify_nonce($nonce, 'tfg_update_brochures')) {
                return '<strong>Security check failed.</strong>';
            }
        }

        $downloads = \get_posts([
            'post_type'        => 'download',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);

        $map = [];
        foreach ($downloads as $d) {
            $code = trim((string) \get_field('institution_code', $d));
            $url  = trim((string) \get_field('file', $d));
            if ($code !== '' && $url !== '') {
                $map[$code] = $url;
            }
        }

        if (empty($map)) {
            return '<pre>❌ No downloads with both institution_code and file.</pre>';
        }

        $profiles = \get_posts([
            'post_type'        => 'profile',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);

        $output = [];
        foreach ($profiles as $pid) {
            $code = trim((string) \get_field('institution_code', $pid));
            if ($code !== '' && isset($map[$code])) {
                if ($do_update) {
                    \update_field('file', $map[$code], $pid);
                }
                $output[] = "✅ " . ($do_update ? 'Updated' : 'Would update') . " Profile ID {$pid} — {$code} → {$map[$code]}";
            } else {
                $output[] = "❌ No match for Profile ID {$pid} — {$code}";
            }
        }

        $run_link = '';
        if (!$do_update) {
            $url = \add_query_arg([
                'do_update' => '1',
                '_wpnonce'  => \wp_create_nonce('tfg_update_brochures'),
            ], \esc_url_raw($_SERVER['REQUEST_URI'] ?? \home_url('/')));
            $run_link = '<p><a class="button button-primary" href="' . \esc_url($url) . '">Run Update</a></p>';
        }

        return $run_link . '<pre>' . \esc_html(\implode("\n", $output)) . '</pre>';
    }
}

// Legacy alias for backwards compatibility
\class_alias(\TFG\UI\Shortcodes::class, 'TFG_Shortcodes');
