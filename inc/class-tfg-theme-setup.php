<?php
// class-tfg-theme-setup.php
// Enqueues core theme stylesheets (Kadence + child), and adds footer text

final class TFG_Theme_Setup
{
    public static function init(): void
    {
        (new self())->register_hooks();
    }

    public function register_hooks(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer',          [$this, 'custom_footer_text']);
    }

    public function enqueue_assets(): void
    {
        // Front-end only; skip wp-admin, feeds, REST
        if (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        // Parent (Kadence) version fallback
        $parent_theme   = wp_get_theme(get_template());
        $kadence_ver    = defined('KADENCE_VERSION') ? KADENCE_VERSION : $parent_theme->get('Version');

        // Child theme version fallback
        $child_theme    = wp_get_theme();
        $tfg_ver        = defined('TFG_VERSION') ? TFG_VERSION : $child_theme->get('Version');

        // Parent CSS
        wp_enqueue_style(
            'kadence-style',
            get_template_directory_uri() . '/style.css',
            [],
            $kadence_ver
        );

        // Child CSS
        wp_enqueue_style(
            'tfg-style',
            get_stylesheet_uri(),
            ['kadence-style'],
            $tfg_ver
        );
    }

    public function custom_footer_text(): void
    {
        // Keep it simple + escaped
        $year = gmdate('Y');
        echo '<p style="text-align:center;font-size:0.8em;">' .
             esc_html(sprintf('© %s Take Flight Global. All rights reserved.', $year)) .
             '</p>';
    }
}
