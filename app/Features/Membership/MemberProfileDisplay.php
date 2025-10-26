<?php

namespace TFG\Features\Membership;

final class MemberProfileDisplay
{
    /** Keys we display (ACF or plain meta). */
    private const FIELD_KEYS = [
        'contact_name',
        'title_and_department',
        'contact_email',
        'organization_name',
        'website',
        'member_type',
    ];

    /* -------- Shortcodes (optional) --------
     * If you want: \add_shortcode('tfg_profile_summary', fn($a)=>self::renderProfileSummary((int)($a['id']??0)));
     *              \add_shortcode('tfg_profile_columns', fn($a)=>self::renderProfileColumns((int)($a['id']??0)));
     */
    public static function init(): void
    {
        // \add_shortcode('tfg_profile_summary', fn($atts) => self::renderProfileSummary((int)\shortcode_atts(['id'=>0], $atts)['id']));
        // \add_shortcode('tfg_profile_columns', fn($atts) => self::renderProfileColumns((int)\shortcode_atts(['id'=>0], $atts)['id']));
    }

    public static function renderProfileSummary(int $post_id): string
    {
        if (!$post_id || !\get_post_status($post_id)) {
            return '<p>Profile not found.</p>';
        }

        $data = self::fetchFields($post_id);
        if (!$data) {
            return '<p>No profile data available.</p>';
        }

        $website_label = self::cleanUrlLabel($data['website'] ?? '');
        $website_href  = self::cleanUrlHref($data['website'] ?? '');

        \ob_start(); ?>
        <div class="tfg-profile-summary-box">
            <p>Profile Preview</p>
            <ul class="tfg-profile-list">
                <li><strong>Contact Name:</strong> <?php echo \esc_html($data['contact_name'] ?? ''); ?></li>
                <li><strong>Title &amp; Department:</strong> <?php echo \esc_html($data['title_and_department'] ?? ''); ?></li>
                <li><strong>Email:</strong> <?php echo \esc_html($data['contact_email'] ?? ''); ?></li>
                <li><strong>Organization:</strong> <?php echo \esc_html($data['organization_name'] ?? ''); ?></li>
                <li><strong>Website:</strong>
                    <?php if ($website_href): ?>
                        <a href="<?php echo \esc_url($website_href); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo \esc_html($website_label); ?>
                        </a>
                    <?php endif; ?>
                </li>
                <li><strong>Member Type:</strong> <?php echo \esc_html($data['member_type'] ?? ''); ?></li>
            </ul>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    public static function renderProfileColumns(int $post_id): string
    {
        if (!$post_id || !\get_post_status($post_id)) {
            return '<p>Profile not found.</p>';
        }

        $data = self::fetchFields($post_id);
        if (!$data) {
            return '<p>No profile data available.</p>';
        }

        $website_label = self::cleanUrlLabel($data['website'] ?? '');
        $website_href  = self::cleanUrlHref($data['website'] ?? '');
        $member_type   = $data['member_type'] ?? '';
        $member_type   = $member_type !== '' ? \ucfirst($member_type) : '';

        \ob_start(); ?>
        <div class="tfg-profile-summary-cols">
            <h3 class="tfg-h3">Submitted Profile Preview</h3>
            <div class="tfg-profile-grid">
                <div>
                    <strong>Contact Name:</strong><br>
                    <?php echo \esc_html($data['contact_name'] ?? ''); ?>
                </div>
                <div>
                    <strong>Title &amp; Department:</strong><br>
                    <?php echo \esc_html($data['title_and_department'] ?? ''); ?>
                </div>

                <div>
                    <strong>Email:</strong><br>
                    <?php echo \esc_html($data['contact_email'] ?? ''); ?>
                </div>
                <div>
                    <strong>Organization:</strong><br>
                    <?php echo \esc_html($data['organization_name'] ?? ''); ?>
                </div>

                <div>
                    <strong>Website:</strong><br>
                    <?php if ($website_href): ?>
                        <a href="<?php echo \esc_url($website_href); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo \esc_html($website_label); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <div>
                    <strong>Member Type:</strong><br>
                    <?php echo \esc_html($member_type); ?>
                </div>
            </div>
        </div>
        <?php
        return (string) \ob_get_clean();
    }

    /* ---------------- Internals ---------------- */

    /** Fetch the minimal set of fields efficiently (ACF or raw meta). */
    private static function fetchFields(int $post_id): array
    {
        $out     = [];
        $use_acf = \function_exists('get_field');

        foreach (self::FIELD_KEYS as $key) {
            $val = $use_acf ? \get_field($key, $post_id) : \get_post_meta($post_id, $key, true);
            if (\is_array($val)) {
                $val = \implode(', ', \array_map('sanitize_text_field', $val));
            } else {
                $val = \is_string($val) ? $val : '';
            }
            $out[$key] = $val;
        }

        $has_any = \array_reduce($out, fn ($c, $v) => $c || ($v !== '' && $v !== null), false);
        return $has_any ? $out : [];
    }

    /** Normalize a URL for href (adds scheme if missing). */
    private static function cleanUrlHref(string $raw): string
    {
        $raw = \trim($raw);
        if ($raw === '') {
            return '';
        }
        if (!\preg_match('~^https?://~i', $raw)) {
            $raw = 'https://' . $raw;
        }
        return $raw;
    }

    /** Short display version of URL (strip scheme + trailing slash). */
    private static function cleanUrlLabel(string $raw): string
    {
        $href = self::cleanUrlHref($raw);
        if ($href === '') {
            return '';
        }
        $label = \preg_replace('~^https?://~i', '', $href);
        return \rtrim($label, '/');
    }

    /* ---- Legacy method aliases (optional) ---- */
    /** @deprecated */ public static function render_profile_summary(int $id): string
    {
        return self::renderProfileSummary($id);
    }
    /** @deprecated */ public static function render_profile_columns(int $id): string
    {
        return self::renderProfileColumns($id);
    }
}

/* ---- Legacy class alias for transition ---- */
\class_alias(\TFG\Features\Membership\MemberProfileDisplay::class, 'TFG_Member_Profile_Display');
