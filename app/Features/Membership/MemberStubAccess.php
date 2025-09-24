<?php

namespace TFG\Features\Membership;

use TFG\Core\Utils;
use TFG\Features\Membership\MemberStubManager;


final class MemberStubAccess
{
    /** Allowed modes and post types (tighten as needed). */
    private const ALLOWED_MODES      = ['new', 'edit', 'display'];
    private const ALLOWED_POST_TYPES = ['profile_stub', 'member_profile'];

    public static function init(): void
    {
        \add_shortcode('tfg_stub_access', [self::class, 'renderStubAccessPage']);
        \add_filter('query_vars',        [self::class, 'registerQueryVars']);
    }

    public static function registerQueryVars(array $vars): array
    {
        foreach (['token', 'temp_pass', 'test_id', 'type', 'mode'] as $qv) {
            $vars[] = $qv;
        }
        return $vars;
    }

    public static function renderStubAccessPage(): string
    {
        $mode      = self::sanitizeMode(\get_query_var('mode', $_GET['mode'] ?? 'new'));
        $post_id   = \absint(\get_query_var('test_id', $_GET['test_id'] ?? 0));
        $post_type = \sanitize_key(\get_query_var('type', $_GET['type'] ?? ''));

        if (!\in_array($mode, self::ALLOWED_MODES, true)) {
            $mode = 'new';
        }
        if ($post_type && !\in_array($post_type, self::ALLOWED_POST_TYPES, true)) {
            return '<p class="tfg-error">Invalid profile type.</p>';
        }

        if (\in_array($mode, ['edit', 'display'], true)) {
            if (!$post_id || !$post_type) {
                return '<p class="tfg-error">Missing profile ID or type for this mode.</p>';
            }
            if (\get_post_type($post_id) !== $post_type) {
                \error_log("[TFG StubAccess] Post type mismatch for ID {$post_id}. Expected {$post_type}.");
                return '<p class="tfg-error">Invalid or mismatched profile ID.</p>';
            }
        } else {
            $post_id   = 0;
            $post_type = 'profile_stub';
        }

        $email = self::getFieldSafe('contact_email', $post_id) ?: '[Email not found]';

        \ob_start();
        MemberFormUtilities::stubAccessHeader($email);

        if ($mode === 'display') {
            if (!\class_exists(MemberStubManager::class)) {
                echo '<p class="tfg-error">Stub manager not available.</p>';
            } else {
                echo MemberStubManager::renderStubForm($post_id, $post_type);
            }
        } else {
            if (!\class_exists(MemberStubManager::class)) {
                echo '<p class="tfg-error">Stub manager not available.</p>';
            } else {
                echo MemberStubManager::renderStubForm($post_id, $post_type);
            }
        }

        return (string) \ob_get_clean();
    }

    /* ---------- Internals ---------- */

    private static function sanitizeMode($raw): string
    {
        $val = \is_string($raw) ? \strtolower(\sanitize_key($raw)) : 'new';
        return \in_array($val, self::ALLOWED_MODES, true) ? $val : 'new';
    }

    private static function getFieldSafe(string $key, int $post_id)
    {
        if (!$post_id) return '';
        if (\function_exists('get_field')) {
            return \get_field($key, $post_id);
        }
        return \get_post_meta($post_id, $key, true);
    }

    private static function updateFieldSafe(string $key, $value, int $post_id): void
    {
        if (\function_exists('update_field')) {
            \update_field($key, $value, $post_id);
        } else {
            \update_post_meta($post_id, $key, $value);
        }
    }

    /** Create a new stub (draft) if needed. (Unused in main flow; keep if you call it.) */
    private static function createNewStub(string $post_type, string $email, string $token, string $member_type): int
    {
        if (!\in_array($post_type, self::ALLOWED_POST_TYPES, true)) {
            return 0;
        }

        if (!\class_exists(MemberStubManager::class)) {
            \error_log('[TFG StubAccess] MemberStubManager not found.');
            return 0;
        }

        $prefix = MemberStubManager::getPrefixForType($post_type);
        if (!$prefix) return 0;

        $member_id   = MemberStubManager::generateMemberId($prefix);
        $email       = Utils::normalizeEmail($email);
        $member_type = \sanitize_text_field($member_type);

        $new_id = \wp_insert_post([
            'post_type'   => $post_type,
            'post_status' => 'draft',
            'post_title'  => $email ?: 'Stub',
        ]);
        if (\is_wp_error($new_id) || !$new_id) {
            return 0;
        }

        self::updateFieldSafe('member_id',         $member_id,           (int) $new_id);
        self::updateFieldSafe('member_type',       $member_type,         (int) $new_id);
        self::updateFieldSafe('contact_email',     $email,               (int) $new_id);
        self::updateFieldSafe('submitted_by_user', \get_current_user_id(), (int) $new_id);
        \update_post_meta((int) $new_id, 'stub_token', $token);

        return (int) $new_id;
    }

    private static function findOrCreateStub(string $post_type, string $email, string $token, string $member_type): int
    {
        if (!\in_array($post_type, self::ALLOWED_POST_TYPES, true)) {
            return 0;
        }
        $email = Utils::normalizeEmail($email);
        if (!$email) return 0;

        $existing = \get_posts([
            'post_type'      => $post_type,
            'meta_key'       => 'contact_email',
            'meta_value'     => $email,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        if (!empty($existing)) {
            return (int) $existing[0];
        }
        return self::createNewStub($post_type, $email, $token, $member_type);
    }

    /** Example token check; replace with your real validator. */
    private static function mockTokenValidation(string $token, string $temp_pass)
    {
        $known = [
            'abc123' => [
                'temp_pass'   => 'temp999',
                'email'       => 'stubuser@example.com',
                'member_type' => 'university',
            ],
        ];
        if (!isset($known[$token])) return false;
        if ($known[$token]['temp_pass'] !== $temp_pass) return false;

        return [
            'email'       => $known[$token]['email'],
            'member_type' => $known[$token]['member_type'],
        ];
    }
}

/* ---- Legacy class alias for transition ---- */
\class_alias(\TFG\Features\Membership\MemberStubAccess::class, 'TFG_Member_Stub_Access');
