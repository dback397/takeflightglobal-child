<?php

namespace TFG\Features\MagicLogin;

final class DebugTools
{
    /**
     * Create a magic token CPT entry and return token data (DEV only).
     *
     * @return array{post_id:int,token:string,url:string,expires:int}|false
     */
    public static function directCreateMagicToken(string $email, ?string $seq_code, ?string $seq_id, int $expires_in = 900)
    {
        $email = \sanitize_email($email);
        if (!$email) {
            return false;
        }

        if (!\post_type_exists('magic_tokens')) {
            \TFG\Core\Utils::info('[TFG MAGIC] ❌ CPT magic_tokens NOT REGISTERED on this request');
            return false;
        }

        $token = \wp_generate_password(24, false);
        $now   = \time();                   // true UTC timestamp
        $exp   = $now + (15 * (int)$expires_in); // example expiration window

        $post_id = \wp_insert_post([
            'post_type'   => 'magic_tokens',
            'post_status' => 'publish',
            'post_title'  => $token,  // keep for debugging
            'meta_input'  => [
            'email'         => $email,
            'token_hash'    => \hash('sha256', $token),  // ✅ hashed for lookup
            'sequence_id'   => $seq_id,
            'sequence_code' => $seq_code,
            'issued_at'     => $now,                     // ✅ integer timestamp
            'expires_at'    => $exp,                     // ✅ integer timestamp
            'is_used'       => 0,
            ],
        ], true);

        if (\is_wp_error($post_id)) {
            \TFG\Core\Utils::info('[TFG MAGIC] ❌ wp_insert_post: ' . $post_id->get_error_message());
            return false;
        }

        $url = \add_query_arg(
            [
                'token' => $token,
                'email' => \rawurlencode($email),
            ],
            \home_url('/subscription-confirmed')
        );

        \TFG\Core\Utils::info('[TFG MAGIC] ✅ Direct token #' . $post_id . ' for ' . $email . ' seq=' . ($seq_code ?: 'n/a'));
        return ['post_id' => (int)$post_id, 'token' => $token, 'url' => $url, 'expires' => (int)$exp];
    }
}

\add_action('tfgPurgeExpiredTokens', function () {
    $query = new \WP_Query([
        'post_type'  => 'magic_tokens',
        'meta_query' => [
            [
                'key'     => 'expires_at',
                'value'   => \time(),
                'compare' => '<',
                'type'    => 'NUMERIC',
            ],
        ],
        'fields' => 'ids', // fetch only IDs to save memory
    ]);

    if (!empty($query->posts)) {
        foreach ($query->posts as $postId) {
            \wp_delete_post($postId, true);
        }
    }
}, 10, 0);
