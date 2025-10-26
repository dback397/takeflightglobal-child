<?php

namespace TFG\Core;

/**
 * Centralized HTML email sender with templating support.
 *
 * Example:
 * Mailer::send(
 *   'recipient@example.com',
 *   'Confirm Your Email',
 *   'magic_link',
 *   ['name' => 'John', 'verification_link' => 'https://…']
 * );
 */
final class Mailer
{
    /**
     * Send an HTML email using a PHP template.
     *
     * @param string       $to            Recipient email
     * @param string       $subject       Email subject
     * @param string       $template_slug Template basename (no .php)
     * @param array<mixed> $data          Vars available to the template
     * @param string[]     $headers       Extra headers
     */
    public static function send(string $to, string $subject, string $template_slug, array $data = [], array $headers = []): bool
    {
        $to = Utils::normalizeEmail($to);

        // Validate recipient
        if (!$to || !\is_email($to)) {
            self::logFailure($to, $subject, 'Invalid email address');
            return false;
        }

        // Render template
        $body = self::renderTemplate($template_slug, $data);
        if ($body === false || $body === '') {
            self::logFailure($to, $subject, "Template not found: {$template_slug}");
            return false;
        }

        // Ensure HTML content-type
        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        $sent = \wp_mail($to, $subject, $body, $headers);

        if ($sent) {
            self::logSuccess($to, $subject);
        } else {
            self::logFailure($to, $subject, 'wp_mail() returned false');
        }

        return $sent;
    }

    /**
     * Render an email template from /email-templates.
     * Looks under TFG_PLUGIN_PATH if defined, else the active child theme dir.
     *
     * @return string|false
     */
    public static function renderTemplate(string $slug, array $data = [])
    {
        $base_path = \defined('TFG_PLUGIN_PATH')
            ? (string) \TFG_PLUGIN_PATH
            : \get_stylesheet_directory();

        $template_path = \trailingslashit($base_path) . "email-templates/{$slug}.php";
        if (!\file_exists($template_path)) {
            \TFG\Core\Utils::info("[TFG\\Core\\Mailer] 🚫 Template not found: {$template_path}");
            return false;
        }

        \ob_start();
        // Provide $data as individual variables safely
        foreach ($data as $k => $v) {
            if (\is_string($k) && $k !== '') {
                ${$k} = $v;
            }
        }
        include $template_path;
        return (string) \ob_get_clean();
    }

    /* ---------------- Logging ---------------- */

    protected static function logSuccess(string $to, string $subject): void
    {
        \TFG\Core\Utils::info("[TFG\\Core\\Mailer] ✅ Sent email to {$to} | {$subject}");
        if (\class_exists(Log::class)) {
            Log::addLogEntry([
                'email'      => $to,
                'event_type' => 'email_sent',
                'status'     => 'success',
                'notes'      => "Subject: {$subject}",
            ]);
        }
    }

    protected static function logFailure(string $to, string $subject, string $reason): void
    {
        \TFG\Core\Utils::info("[TFG\\Core\\Mailer] ❌ Failed to send to {$to} — {$reason}");
        if (\class_exists(Log::class)) {
            Log::addLogEntry([
                'email'      => $to,
                'event_type' => 'email_sent',
                'status'     => 'failed',
                'notes'      => "Subject: {$subject} | Reason: {$reason}",
            ]);
        }
    }

    /* ---- Legacy compatibility (remove when all references updated) ---- */

}

/* Legacy class alias for transition */
\class_alias(\TFG\Core\Mailer::class, 'TFG_Mailer');
