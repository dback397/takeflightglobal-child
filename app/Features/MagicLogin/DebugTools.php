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
        if (!$email) { return false; }

        if (!\post_type_exists('magic_tokens')) {
            \error_log('[TFG MAGIC] ❌ CPT magic_tokens NOT REGISTERED on this request');
            return false;
        }

        $token = \wp_generate_password(24, false);
        $now   = \current_time('timestamp');
        $exp   = $now + \max(60, $expires_in);

        $postarr = [
            'post_type'   => 'magic_tokens',
            'post_title'  => 'MAG-' . ($seq_code ?: 'NA') . '-' . \substr($token, 0, 6),
            'post_status' => 'publish',
            'meta_input'  => [
                'email'         => $email,
                'token'         => $token,
                'sequence_id'   => $seq_id,
                'sequence_code' => $seq_code,
                'issued_on'     => \gmdate('c', $now),
                'expires_on'    => \gmdate('c', $exp),
                'is_used'       => 0,
            ],
        ];

        $post_id = \wp_insert_post($postarr, true);
        if (\is_wp_error($post_id)) {
            \error_log('[TFG MAGIC] ❌ wp_insert_post: ' . $post_id->get_error_message());
            return false;
        }

        $url = \add_query_arg(
            [
                'token' => $token,
                'email' => \rawurlencode($email),
            ],
            \home_url('/subscription-confirmed')
        );

        \error_log('[TFG MAGIC] ✅ Direct token #' . $post_id . ' for ' . $email . ' seq=' . ($seq_code ?: 'n/a'));
        return ['post_id' => (int)$post_id, 'token' => $token, 'url' => $url, 'expires' => (int)$exp];
    }



}
