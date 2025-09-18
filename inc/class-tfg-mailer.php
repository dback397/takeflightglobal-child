<?php
/**
 * ==========================================================
 * TFG_Mailer
 * Centralized HTML email sender with templating support
 * ==========================================================
 *
 * ✅ Features:
 * - Normalizes email addresses before sending
 * - Loads PHP-based HTML templates from /email-templates
 * - Automatically injects variables from $data into template
 * - Logs all sends via TFG_Log (success & failure)
 * - Adds UTF-8 HTML headers by default
 *
 * 📄 Example:
 * TFG_Mailer::send(
 *     'recipient@example.com',
 *     'Confirm Your Email',
 *     'magic_link',
 *     [
 *         'name' => 'John Doe',
 *         'verification_link' => 'https://example.com/verify?code=XYZ123'
 *     ]
 * );
 */

class TFG_Mailer {

    /**
     * Send an HTML email using a template
     *
     * @param string $to            Recipient email address
     * @param string $subject       Email subject line
     * @param string $template_slug Slug of the template file (without .php)
     * @param array  $data          Variables available to the template
     * @param array  $headers       Optional headers (additional to default)
     * @return bool                 True if sent, false otherwise
     */
    public static function send($to, $subject, $template_slug, $data = [], $headers = []) {
        $to = TFG_Utils::normalize_email($to);

        // 🛑 Validate recipient
        if (!is_email($to)) {
            self::log_failure($to, $subject, 'Invalid email address');
            return false;
        }

        // 📄 Render email template
        $body = self::render_template($template_slug, $data);
        if (!$body) {
            self::log_failure($to, $subject, "Template not found: {$template_slug}");
            return false;
        }

        // 📬 Ensure proper content type
        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        // ✉ Send email
        $sent = wp_mail($to, $subject, $body, $headers);

        if ($sent) {
            self::log_success($to, $subject);
        } else {
            self::log_failure($to, $subject, 'wp_mail() returned false');
        }

        return $sent;
    }

    /**
     * Render an email template from /email-templates
     */
    public static function render_template($slug, $data = []) {
        // Detect base path (plugin or theme)
        $base_path = defined('TFG_PLUGIN_PATH') 
            ? TFG_PLUGIN_PATH 
            : get_stylesheet_directory() . '/';

        $template_path = trailingslashit($base_path) . "email-templates/{$slug}.php";

        if (!file_exists($template_path)) {
            error_log("[TFG_Mailer] 🚫 Template not found: $template_path");
            return false;
        }

        ob_start();
        extract($data, EXTR_SKIP);
        include $template_path;
        return ob_get_clean();
    }

    /**
     * Log successful send
     */
    protected static function log_success($to, $subject) {
        error_log("[TFG_Mailer] ✅ Sent email to {$to} with subject: {$subject}");
        if (class_exists('TFG_Log')) {
            TFG_Log::add_log_entry([
                'email'      => $to,
                'event_type' => 'email_sent',
                'status'     => 'success',
                'notes'      => "Subject: {$subject}"
            ]);
        }
    }

    /**
     * Log failed send
     */
    protected static function log_failure($to, $subject, $reason) {
        error_log("[TFG_Mailer] ❌ Failed to send to {$to} — {$reason}");
        if (class_exists('TFG_Log')) {
            TFG_Log::add_log_entry([
                'email'      => $to,
                'event_type' => 'email_sent',
                'status'     => 'failed',
                'notes'      => "Subject: {$subject} | Reason: {$reason}"
            ]);
        }
    }
}
