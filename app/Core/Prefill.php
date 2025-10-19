<?php

namespace TFG\Core;

class Prefill
{
    // Configure once
    private const FORM_ID_PREFILL_CHECKBOXES = 6170;
    private const FIELD_ID_PROGRAMS_CHECKBOX = 9; // Checkbox field ID in that form
    private const ACF_FIELD_PROGRAMS         = 'programs';
    private const ACF_FIELD_GDPR             = 'gdpr_consent';

    // Simple per-request cache: [post_id][key] => value
    private static $cache = [];

    public static function init(): void
    {
        // Only run for logged-in users (original intent)
        if (!\is_user_logged_in()) {
            return;
        }

        // Use **named** WPForms dynamic filters (reliable across versions)
        \add_filter('wpforms_field_value_tfg_acf_programs', [self::class, 'prefillPrograms'], 10, 3);
        \add_filter('wpforms_field_value_tfg_acf_gdpr',     [self::class, 'prefillGdpr'],     10, 3);

        // Checkbox default ticking for one form
        \add_filter('wpforms_frontend_form_data', [self::class, 'prefillCheckboxes'], 10, 3);
    }

    /* ---------- Core helpers ---------- */

    private static function acfAvailable(): bool
    {
        return \function_exists('get_field');
    }

    private static function currentPostId(): ?int
    {
        if (!isset($_GET['post_id'])) {
            return null;
        }
        $pid = \absint($_GET['post_id']);
        return $pid > 0 ? $pid : null;
    }

    // Minimal capability guard. Tighten to your needs.
    private static function canReadPost(int $post_id): bool
    {
        $post = \get_post($post_id);
        if (!$post) {
            return false;
        }

        // Editors/admins: fine
        if (\current_user_can('edit_post', $post_id)) {
            return true;
        }

        // Otherwise allow published content
        return \current_user_can('read_post', $post_id) || $post->post_status === 'publish';
    }

    private static function acfGet(string $key, int $post_id)
    {
        if (isset(self::$cache[$post_id][$key])) {
            return self::$cache[$post_id][$key];
        }

        $val = self::acfAvailable() ? \get_field($key, $post_id) : \get_post_meta($post_id, $key, true);
        self::$cache[$post_id][$key] = $val;
        return $val;
    }

    private static function normalizeScalar($value): string
    {
        if (\is_array($value)) {
            return \implode(', ', \array_map('sanitize_text_field', $value));
        }
        return $value ? \sanitize_text_field((string) $value) : '';
    }

    /* ---------- Specific dynamic value helpers ---------- */

    // For WPForms field with Dynamic Population: Filter = tfg_acf_programs
    public static function prefillPrograms($value, $field_id = null, $form_data = null)
    {
        $post_id = self::currentPostId();
        if (!$post_id || !self::canReadPost($post_id)) {
            return $value;
        }
        $acf = self::acfGet(self::ACF_FIELD_PROGRAMS, $post_id);
        return self::normalizeScalar($acf) ?: $value;
    }

    // For WPForms field with Dynamic Population: Filter = tfg_acf_gdpr
    public static function prefillGdpr($value, $field_id = null, $form_data = null)
    {
        $post_id = self::currentPostId();
        if (!$post_id || !self::canReadPost($post_id)) {
            return $value;
        }
        $acf = self::acfGet(self::ACF_FIELD_GDPR, $post_id);
        // WPForms typically treats "1" (or "on") as checked
        return !empty($acf) ? '1' : '';
    }

    /* ---------- Checkbox auto-check for a specific form ---------- */

    /**
     * Tick choices on a checkbox field (by label match) based on ACF 'programs' array.
     * Hook: wpforms_frontend_form_data (runs before render)
     */
    public static function prefillCheckboxes($form_data, $fields=null, $form_id=null)
    {
        // Structure guards
        if (!\is_array($form_data) || empty($form_data['id'])) {
            return $form_data;
        }
        if ((int) $form_data['id'] !== self::FORM_ID_PREFILL_CHECKBOXES) {
            return $form_data;
        }

        $post_id = self::currentPostId();
        if (!$post_id || !self::canReadPost($post_id)) {
            return $form_data;
        }

        $acf = self::acfGet(self::ACF_FIELD_PROGRAMS, $post_id);
        if (!\is_array($acf) || empty($acf)) {
            return $form_data;
        }

        if (!isset($form_data['fields'][ self::FIELD_ID_PROGRAMS_CHECKBOX ])) {
            return $form_data;
        }

        $field =& $form_data['fields'][ self::FIELD_ID_PROGRAMS_CHECKBOX ];
        if (!\is_array($field['choices'] ?? null)) {
            return $form_data;
        }

        // Mark default-checked choices when label matches one of the ACF values
        foreach ($field['choices'] as &$choice) {
            $label = isset($choice['label']) ? (string) $choice['label'] : '';
            if ($label !== '' && \in_array($label, $acf, true)) {
                $choice['default'] = 1;
            }
        }

        return $form_data;
    }
}
