<?php

namespace TFG\Features\Membership;

use TFG\Core\Utils;

/**
 * ACFValidator
 * Lightweight helper to validate form fields before update_field().
 */
final class ACFValidator
{
    /** @var array<string,string> */
    private array $errors = [];

    public function __construct()
    {
    }

    public static function init(): void
    {
        // Optional: hook into form router if you want automatic validation
        // Example:
        // \add_action('tfg_validate_form', [self::class, 'validate']);
    }

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /** @return array<string,string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function require(string $key, $value, string $label = ''): void
    {
        $label = $label ?: $key;
        if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
            $this->errors[$key] = sprintf(__('%s is required.', 'tfg'), $label);
        }
    }

    public function email(string $key, $value, string $label = ''): void
    {
        $label = $label ?: $key;
        $v     = is_string($value) ? Utils::normalizeEmail($value) : '';
        if (!$v) {
            $this->errors[$key] = sprintf(__('%s must be a valid email address.', 'tfg'), $label);
        }
    }

    public function url(string $key, $value, string $label = ''): void
    {
        $label = $label ?: $key;
        $v     = is_string($value) ? trim($value) : '';
        if ($v === '' || !filter_var($v, FILTER_VALIDATE_URL)) {
            $this->errors[$key] = sprintf(__('%s must be a valid URL.', 'tfg'), $label);
        }
    }

    public function minLength(string $key, $value, int $min, string $label = ''): void
    {
        $label = $label ?: $key;
        $v     = is_string($value) ? trim($value) : '';
        if (mb_strlen($v) < $min) {
            $this->errors[$key] = sprintf(__('%s must be at least %d characters.', 'tfg'), $label, $min);
        }
    }

    public function maxLength(string $key, $value, int $max, string $label = ''): void
    {
        $label = $label ?: $key;
        $v     = is_string($value) ? trim($value) : '';
        if (mb_strlen($v) > $max) {
            $this->errors[$key] = sprintf(__('%s must be at most %d characters.', 'tfg'), $label, $max);
        }
    }

    public function regex(string $key, $value, string $pattern, string $label = ''): void
    {
        $label = $label ?: $key;
        $v     = is_string($value) ? $value : '';
        if (@preg_match($pattern, '') === false || !preg_match($pattern, $v)) {
            $this->errors[$key] = sprintf(__('%s has an invalid format.', 'tfg'), $label);
        }
    }

    /**
     * Require the value to be in the allowlist (strict).
     * @param string[] $allow
     */
    public function inEnum(string $key, $value, array $allow, string $label = ''): void
    {
        $label = $label ?: $key;
        if (!in_array($value, $allow, true)) {
            $this->errors[$key] = sprintf(__('%s has an invalid selection.', 'tfg'), $label);
        }
    }

    public function addCustomError(string $key, string $message): void
    {
        $this->errors[$key] = $message;
    }
}
