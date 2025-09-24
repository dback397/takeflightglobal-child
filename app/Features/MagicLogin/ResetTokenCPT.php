<?php

namespace TFG\Features\MagicLogin;

/**
 * Register and manage the "reset_tokens" CPT.
 * Used for member password resets or similar workflows.
 */
final class ResetTokenCPT
{
    public static function init(): void
    {
        \add_action('init',     [__CLASS__, 'register_cpt']);
        \add_action('acf/init', [__CLASS__, 'register_acf_fields']);
    }

    /**
     * Register the "reset_tokens" custom post type.
     */
    public static function register_cpt(): void
    {
        $caps = [
            'edit_post'              => 'manage_options',
            'read_post'              => 'manage_options',
            'delete_post'            => 'manage_options',
            'edit_posts'             => 'manage_options',
            'edit_others_posts'      => 'manage_options',
            'publish_posts'          => 'manage_options',
            'read_private_posts'     => 'manage_options',
            'delete_posts'           => 'manage_options',
            'delete_private_posts'   => 'manage_options',
            'delete_published_posts' => 'manage_options',
            'delete_others_posts'    => 'manage_options',
            'edit_private_posts'     => 'manage_options',
            'edit_published_posts'   => 'manage_options',
            'create_posts'           => 'manage_options',
        ];

        \register_post_type('reset_tokens', [
            'labels' => [
                'name'          => __('Reset Tokens', 'tfg'),
                'singular_name' => __('Reset Token', 'tfg'),
            ],
            'public'             => false,
            'publicly_queryable' => false,
            'exclude_from_search'=> true,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'show_in_rest'       => false,
            'supports'           => ['title'],
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            'capabilities'       => $caps,
            'rewrite'            => false,
        ]);
    }

    /**
     * Register ACF field group for reset_tokens CPT.
     */
    public static function register_acf_fields(): void
    {
        if (!\function_exists('acf_add_local_field_group')) {
            return;
        }

        \acf_add_local_field_group([
            'key'    => 'group_reset_token_fields',
            'title'  => __('Reset Token Fields', 'tfg'),
            'fields' => [
                [
                    'key'      => 'field_reset_code',
                    'label'    => __('Reset Code', 'tfg'),
                    'name'     => 'reset_code',
                    'type'     => 'text',
                    'required' => 1,
                ],
                [
                    'key'      => 'field_member_id',
                    'label'    => __('Member ID', 'tfg'),
                    'name'     => 'member_id',
                    'type'     => 'text',
                    'required' => 1,
                ],
                [
                    'key'            => 'field_expires_on',
                    'label'          => __('Expires On', 'tfg'),
                    'name'           => 'expires_on',
                    'type'           => 'date_time_picker',
                    'required'       => 1,
                    'display_format' => 'Y-m-d H:i:s',
                    'return_format'  => 'Y-m-d H:i:s',
                ],
                [
                    'key'           => 'field_is_used',
                    'label'         => __('Is Used', 'tfg'),
                    'name'          => 'is_used',
                    'type'          => 'true_false',
                    'ui'            => 1,
                    'default_value' => 0,
                ],
            ],
            'location' => [
                [[
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'reset_tokens',
                ]],
            ],
            'position' => 'normal',
            'style'    => 'default',
            'active'   => true,
        ]);
    }
}
\class_alias(\TFG\Features\MagicLogin\ResetTokenCPT::class, 'TFG_Reset_Token_CPT');