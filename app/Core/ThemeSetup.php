<?php

namespace TFG\Core;

/**
 * ThemeSetup
 * - Enqueues Kadence parent + child theme stylesheets
 * - Adds simple footer text
 */
final class ThemeSetup
{
    public static function init(): void
    {
        (new self())->register_hooks();
    }

    private function register_hooks(): void
    {
        \add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        \add_action('wp_footer', [$this, 'customFooterText']);
        \add_action('wp_body_open', [$this, 'renderHomePageLoginButton']);
    }

    public function enqueueAssets(): void
    {
        // Front-end only; skip wp-admin, feeds, REST
        if (\is_admin() || \is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        // Parent (Kadence) version fallback
        $parent_theme = \wp_get_theme(\get_template());
        $kadence_ver  = defined('KADENCE_VERSION') ? KADENCE_VERSION : $parent_theme->get('Version');

        // Child theme version fallback
        $child_theme = \wp_get_theme();
        $tfg_ver     = defined('TFG_VERSION') ? TFG_VERSION : $child_theme->get('Version');

        // Parent CSS
        \wp_enqueue_style(
            'kadence-style',
            \get_template_directory_uri() . '/style.css',
            [],
            $kadence_ver
        );

        // Child CSS
        \wp_enqueue_style(
            'tfg-style',
            \get_stylesheet_uri(),
            ['kadence-style'],
            $tfg_ver
        );
    }

    public function customFooterText(): void
    {
        $year = \gmdate('Y');
        echo '<p style="text-align:center;font-size:0.8em;">' .
             \esc_html(sprintf('© %s Take Flight Global. All rights reserved.', $year)) .
             '</p>';
    }

    /**
     * Display member login button row at the top of the home page
     */
    public function renderHomePageLoginButton(): void
    {
        // Only show on home/front page
        if (!\is_front_page() && !\is_home()) {
            return;
        }

        $login_url = \site_url('/member-login/');
        ?>
        <div id="tfg-home-login-bar" style="background: linear-gradient(135deg, #0E94FF 0%, #295CFF 100%); padding: 12px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); position: relative; z-index: 1000;">
            <div style="max-width: 1200px; margin: 0 auto; padding: 0 20px; display: flex; justify-content: flex-end; align-items: center;">
                <a href="<?php echo \esc_url($login_url); ?>" class="tfg-home-login-button" style="display: inline-block; padding: 10px 24px; background-color: #fff; color: #0E94FF; border-radius: 25px; text-decoration: none; font-weight: 600; font-size: 16px; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    Member Login
                </a>
            </div>
        </div>
        <style>
            .tfg-home-login-button:hover {
                background-color: #f0f8ff !important;
                transform: translateY(-2px);
                box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important;
            }
        </style>
        <?php
    }
}

// Legacy alias for backwards compatibility
\class_alias(\TFG\Core\ThemeSetup::class, 'TFG_Theme_Setup');
