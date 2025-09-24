<?php
namespace TFG\Core;

final class Utils
{
    public static function init(): void {}

    /**
     * Normalize member ID (uppercase, trimmed, A–Z/0–9/_/- only).
     */
    public static function normalizeMemberId($id): string
    {
        if (!\is_string($id)) return '';
        $id = \strtoupper(\trim($id));
        return \preg_match('/^[A-Z0-9_-]+$/', $id) ? $id : '';
    }

    /**
     * Normalize token (trim, base64url-ish, >= 8 chars).
     */
    public static function normalizeToken(?string $v): ?string
    {
        $v = \is_string($v) ? \trim($v) : '';
        return \preg_match('/^[A-Za-z0-9_-]{8,}$/', $v) ? $v : null;
    }

    /**
     * Normalize signature (trim, hex length 40–128, lowercased).
     */
    public static function normalizeSignature(?string $v): ?string
    {
        $v = \is_string($v) ? \trim($v) : '';
        return \preg_match('/^[A-Fa-f0-9]{40,128}$/', $v) ? \strtolower($v) : null;
    }

    /**
     * Normalize email (trim, lowercase, validate via is_email()).
     */
    public static function normalizeEmail(?string $v): ?string
    {
        $v = \is_string($v) ? \trim($v) : '';
        $v = \strtolower($v);
        return \is_email($v) ? $v : null;
    }

    /* ===== Legacy snake_case aliases (safe during migration) ===== */

    /** @deprecated Use normalizeMemberId() */
    public static function normalize_member_id($id): string
    {
        return self::normalizeMemberId($id);
    }

    /** @deprecated Use normalizeToken() */
    public static function normalize_token(?string $v): ?string
    {
        return self::normalizeToken($v);
    }

    /** @deprecated Use normalizeSignature() */
    public static function normalize_signature(?string $v): ?string
    {
        return self::normalizeSignature($v);
    }

    /** @deprecated Use normalizeEmail() */
    public static function normalize_email(?string $v): ?string
    {
        return self::normalizeEmail($v);
    }
}
