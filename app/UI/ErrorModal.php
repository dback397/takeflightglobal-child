<?php

namespace TFG\UI;

final class ErrorModal
{
    /**
     * Usage: echo do_shortcode('[tfg_error_modal code="104"]');
     */
    public static function init(): void
    {
        \add_shortcode('tfg_error_modal', [self::class, 'render_shortcode']);
        self::registerCodeShortcode();
        \add_action('wp_footer', [self::class, 'inject_modal_to_footer']);
    }

    /** Build a per-visitor transient key to avoid collisions across users. */
    private static function keyForUser(): string
    {
        $parts = [
            $_SERVER['REMOTE_ADDR']     ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];
        return 'tfg_magic_error_' . \md5(\implode('|', $parts));
    }

    public static function injectModalToFooter(): void
    {
        $t_key = self::keyForUser();
        $code  = \get_transient($t_key);
        if ($code) {
            \delete_transient($t_key);
            echo self::renderShortcode(['code' => $code]); // inert HTML; JS fills content
        } else {
            echo self::renderShortcode(['code' => null]);
        }
    }

    public static function registerCodeShortcode(): void
    {
        \add_shortcode('tfg_error_code', function ($atts) {
            $atts = \shortcode_atts(['code' => null], $atts);
            return \esc_attr($atts['code']);
        });
    }

    public static function logMessagesDebug(): void
    {
        if (!\is_user_logged_in()) return;

        $posts = \get_posts([
            'post_type'        => 'messages',
            'posts_per_page'   => -1,
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'fields'           => 'ids',
        ]);

        \error_log('🔍 Found ' . \count($posts) . ' message posts');
        foreach ($posts as $pid) {
            $code = (string) \get_field('msg_code', $pid);
            $msg  = (string) \get_field('message', $pid);
            \error_log("🔢 Code: {$code} | Message: {$msg}");
        }
    }

    /** Return the modal markup (do not echo). */
    public static function show($code): string
    {
        if (empty($code)) return '';
        return \do_shortcode('[tfg_error_modal code="' . \esc_attr($code) . '"]');
    }

    /** @return array<string,array{title:string,message:string,text_color:string,body_color:string,dashicon:string}> */
    public static function get_error_messages(): array
    {
        $posts = \get_posts([
            'post_type'        => 'messages',
            'posts_per_page'   => -1,
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ]);

        $messages = [];
        foreach ($posts as $post) {
            $code = (string) \get_field('msg_code', $post->ID);
            if ($code === '') continue;

            $messages[$code] = [
                'title'      => (string) \get_field('title',      $post->ID),
                'message'    => (string) \get_field('message',    $post->ID),
                'text_color' => (string) \get_field('text_color', $post->ID),
                'body_color' => (string) \get_field('body_color', $post->ID),
                'dashicon'   => (string) \get_field('dashicon',   $post->ID),
            ];
        }

        return $messages;
    }

    public static function renderShortcode($atts = []): string
    {
        $atts = \shortcode_atts(['code' => null], $atts);

        $code      = isset($atts['code']) ? \esc_attr($atts['code']) : '';
        $code_attr = $code !== '' ? 'data-error-code="' . $code . '"' : '';

        \ob_start(); ?>
        <div id="tfg-error-overlay" style="display: none;">
            <div id="tfg-error-modal-body" class="tfg-error-content" <?php echo $code_attr; ?>>
                <button id="tfg-error-close" type="button" aria-label="<?php echo \esc_attr__('Close', 'tfg'); ?>">✕</button>
                <div style="text-align: center;">
                    <span id="tfg-error-icon" class="dashicons" style="font-size: 36px;"></span>
                    <h3 id="tfg-error-title"></h3>
                    <p id="tfg-error-message"></p>
                </div>
            </div>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    /** Store the code transient for this visitor and redirect. */
    public static function show_r($code, int $seconds = 30, ?string $redirect_url = null): void
    {
        if (empty($code)) return;

        $redirect_url = $redirect_url ?: \home_url('/');

        \set_transient(self::keyForUser(), $code, \max(5, $seconds));

        if (!\headers_sent()) {
            \nocache_headers();
            \wp_safe_redirect($redirect_url);
            exit;
        }

        echo self::show($code);
    }
}

/* ---- Legacy alias for old references (remove when done) ---- */
\class_alias(\TFG\UI\ErrorModal::class, 'TFG_Error_Modal');
