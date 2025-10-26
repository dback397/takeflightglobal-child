<?php

namespace TFG\Core;

/**
 * Safely initializes dynamic ACF/WPForms prefill logic.
 * - Skips execution in WP-CLI.
 * - Defers init until after pluggable functions are available.
 */
class Prefill
{
    private const FORM_ID_PREFILL_CHECKBOXES = 6170;
    private const FIELD_ID_PROGRAMS_CHECKBOX = 9;
    private const ACF_FIELD_PROGRAMS         = 'programs';
    private const ACF_FIELD_GDPR             = 'gdpr_consent';

    private static $cache = [];

    public static function init(): void
    {
        // 🧱 1. Skip entirely when running under WP-CLI
        if (defined('WP_CLI') && constant('WP_CLI')) {
            if (function_exists('tfg_log')) {
                tfg_log('[Prefill] Skipped initialization under WP-CLI.');
            }
            return;
        }


        // 🧩 2. Defer initialization until 'init' to ensure pluggable functions (like is_user_logged_in) exist
        add_action('init', [__CLASS__, 'maybe_init'], 20);
    }

    /**
     * Executes only after WordPress has loaded pluggable functions.
     */
    public static function maybe_init(): void
    {
        // ✅ Safely check login status after pluggable functions are ready
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return;
        }

        // Register dynamic WPForms population filters
        add_filter('wpforms_field_value_tfg_acf_programs', [self::class, 'prefillPrograms'], 10, 3);
        add_filter('wpforms_field_value_tfg_acf_gdpr', [self::class, 'prefillGdpr'], 10, 3);

        // Checkbox default ticking for one form
        add_filter('wpforms_frontend_form_data', [self::class, 'prefillCheckboxes'], 10, 3);
    }

    /* ---------- Core helpers ---------- */

    private static function acfAvailable(): bool
    {
        return function_exists('get_field');
    }

    private static function currentPostId(): ?int
    {
        $pid = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        return $pid > 0 ? $pid : null;
    }

    private static function canReadPost(int $post_id): bool
    {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        if (current_user_can('edit_post', $post_id)) {
            return true;
        }
        return current_user_can('read_post', $post_id) || $post->post_status === 'publish';
    }

    private static function acfGet(string $key, int $post_id)
    {
        if (isset(self::$cache[$post_id][$key])) {
            return self::$cache[$post_id][$key];
        }
        $val                         = self::acfAvailable() ? get_field($key, $post_id) : get_post_meta($post_id, $key, true);
        self::$cache[$post_id][$key] = $val;
        return $val;
    }

    private static function normalizeScalar($value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map('sanitize_text_field', $value));
        }
        return $value ? sanitize_text_field((string)$value) : '';
    }

    /* ---------- Specific dynamic value helpers ---------- */

    public static function prefillPrograms($value, $field_id = null, $form_data = null)
    {
        $post_id = self::currentPostId();
        if (!$post_id || !self::canReadPost($post_id)) {
            return $value;
        }
        $acf = self::acfGet(self::ACF_FIELD_PROGRAMS, $post_id);
        return self::normalizeScalar($acf) ?: $value;
    }

    public static function prefillGdpr($value, $field_id = null, $form_data = null)
    {
        $post_id = self::currentPostId();
        if (!$post_id || !self::canReadPost($post_id)) {
            return $value;
        }
        $acf = self::acfGet(self::ACF_FIELD_GDPR, $post_id);
        return !empty($acf) ? '1' : '';
    }

    /* ---------- Checkbox auto-check for a specific form ---------- */
    public static function prefillCheckboxes($form_data, $fields = null, $form_id = null)
    {
        if (!is_array($form_data) || empty($form_data['id'])) {
            return $form_data;
        }
        if ((int)$form_data['id'] !== self::FORM_ID_PREFILL_CHECKBOXES) {
            return $form_data;
        }

        $post_id = self::currentPostId();
        if (!$post_id || !self::canReadPost($post_id)) {
            return $form_data;
        }

        $acf = self::acfGet(self::ACF_FIELD_PROGRAMS, $post_id);
        if (!is_array($acf) || empty($acf)) {
            return $form_data;
        }

        if (empty($form_data['fields'][ self::FIELD_ID_PROGRAMS_CHECKBOX ])) {
            return $form_data;
        }

        $field = & $form_data['fields'][ self::FIELD_ID_PROGRAMS_CHECKBOX ];
        if (!is_array($field['choices'] ?? null)) {
            return $form_data;
        }

        foreach ($field['choices'] as &$choice) {
            $label = isset($choice['label']) ? (string)$choice['label'] : '';
            if ($label !== '' && in_array($label, $acf, true)) {
                $choice['default'] = 1;
            }
        }

        return $form_data;
    }
}
