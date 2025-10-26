<?php

/**
 * Minimal ACF stubs for static analysis only.
 * Guarded so they DO NOT override real ACF in runtime.
 * Keep this file in the workspace; do NOT include/require it.
 */

namespace {
    if (!\function_exists('update_field')) {
        /**
         * @param mixed $selector
         * @param mixed $value
         * @param mixed $post_id
         */
        function update_field($selector, $value, $post_id = false): bool
        {
            return true;
        }
    }

    if (!\function_exists('get_field')) {
        /**
         * @param mixed $selector
         * @param mixed $post_id
         * @param bool  $format_value
         * @return mixed
         */
        function get_field($selector, $post_id = false, $format_value = true)
        {
            return null;
        }
    }

    if (!\function_exists('get_fields')) {
        /**
         * @param mixed $post_id
         * @return array<string,mixed>
         */
        function get_fields($post_id = false): array
        {
            return [];
        }
    }

    if (!\function_exists('acf_add_local_field_group')) {
        /**
         * @param array<string,mixed> $group
         */
        function acf_add_local_field_group(array $group): bool
        {
            return true;
        }
    }
}
