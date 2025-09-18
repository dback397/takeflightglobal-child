<?php
// File: class-tfg-magic-utilities.php

if (!class_exists('TFG_Magic_Utilities')) {
final class TFG_Magic_Utilities {

    /* ========================
     * Logging helpers
     * ====================== */
    protected static function log($msg): void {
        error_log('[Magic] ' . $msg);
    }
    protected static function tfg_log($msg): void {
        error_log('[TFG MAGIC] ' . $msg);
    }

    /* ========================
     * Normalizers
     * ====================== */
    public static function normalize_email(string $email): string {
        return strtolower(sanitize_email(wp_unslash($email)));
    }
    public static function normalize_token(string $token): string {
        return preg_replace('/[^A-Za-z0-9]/', '', (string) $token);
    }
    public static function normalize_signature(string $sig): string {
        return preg_replace('/[^a-f0-9]/i', '', (string) $sig);
    }

    /* ========================
     * IP helpers
     * ====================== */
    public static function get_user_ip_address(): string {
        $candidates = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CF_CONNECTING_IP',
            'REMOTE_ADDR',
        ];
        $ip = '';
        foreach ($candidates as $key) {
            if (empty($_SERVER[$key])) continue;
            $raw = (string) $_SERVER[$key];
            $list = $key === 'HTTP_X_FORWARDED_FOR'
                ? array_map('trim', explode(',', $raw))
                : [trim($raw)];
            foreach ($list as $cand) {
                if (filter_var($cand, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $ip = $cand; break 2;
                }
                if (!$ip && filter_var($cand, FILTER_VALIDATE_IP)) { // fallback
                    $ip = $cand;
                }
            }
        }
        if (!$ip && !empty($_SERVER['REMOTE_ADDR'])) {
            $ip = (string) $_SERVER['REMOTE_ADDR'];
        }
        if ($ip === '::1') { $ip = '127.0.0.1'; }
        return $ip ?: 'unknown';
    }
    public static function client_ip(): string { return self::get_user_ip_address(); }

    /* ========================
     * HMAC + URL builders
     * ====================== */
    protected static function hmac_secret(): string {
        // Use your defined secret; fall back to AUTH_SALT only if TFG_HMAC_SECRET missing.
        if (defined('TFG_HMAC_SECRET') && TFG_HMAC_SECRET) return (string) TFG_HMAC_SECRET;
        if (defined('AUTH_SALT') && AUTH_SALT) return (string) AUTH_SALT;
        return '';
    }
    protected static function build_signature(string $token, string $email): string {
        $secret = self::hmac_secret();
        if (!$secret) return '';
        $qs = http_build_query(['token' => $token, 'email' => $email]);
        return hash_hmac('sha256', $qs, $secret);
    }
    protected static function build_magic_url(string $token, string $email, string $base_path='/subscription-confirmed'): string {
        $email_qs = rawurlencode($email); // in URL; HMAC is computed on raw email
        $base = home_url($base_path);
        $url  = add_query_arg(['token' => $token, 'email' => $email_qs], $base);
        $sig  = self::build_signature($token, $email);
        if ($sig) {
            $url = add_query_arg('sig', $sig, $url);
        } else {
            self::tfg_log('⚠️ TFG_HMAC_SECRET not defined; verify_magic_token will fail');
        }
        return $url;
    }

    /* ========================
     * Render button (uses transient set on creation)
     * ====================== */
    public static function render_magic_login_button($email = null): string {
        if (!$email && isset($_POST['magic_email'])) {
            $email = self::normalize_email(wp_unslash($_POST['magic_email']));
            self::log("📥 Email via POST: $email");
        }
        if (!$email) {
            // Don’t pop errors from shortcodes; just render nothing
            return '';
        }

        $site  = function_exists('get_current_blog_id') ? (string) get_current_blog_id() : '1';
        $t_key = 'last_magic_url_' . md5($site . '|' . strtolower($email));
        $magic_url = get_transient($t_key);
        if (!$magic_url) {
            self::log("⚠️ No magic URL found in transient for $email");
            return '';
        }

        $is_subscribed = isset($_COOKIE['is_subscribed']) && $_COOKIE['is_subscribed'] === '1';
        $bg    = $is_subscribed ? '#28a745' : '#0066cc';
        $label = $is_subscribed ? '✅ You’re Subscribed' : '🔑 Use Magic Link Now';

        ob_start(); ?>
        <div style="text-align:center; margin-top:20px;">
            <a href="<?php echo esc_url($magic_url); ?>"
               class="button"
               style="padding:10px 20px; background:<?php echo esc_attr($bg); ?>; color:#fff; text-decoration:none; border-radius:5px;">
               <?php echo esc_html($label); ?>
            </a>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /* ========================
     * Core: Create magic token
     * ====================== */
    /**
     * Create a magic token row and return payload for emailing.
     * $args:
     *   - sequence_id, sequence_code, verification_code
     *   - expires_in (seconds, default 900)
     *   - base_path (default '/subscription-confirmed')
     */
    public static function create_magic_token(string $email, array $args = []) {
        $email = self::normalize_email($email);

        // IMPORTANT: use the SAME clock everywhere (UTC) to avoid immediate-expire bugs.
        $now        = time(); // UTC
        $expires_in = isset($args['expires_in']) ? (int) $args['expires_in'] : 900;
        $exp        = $now + max(60, $expires_in);

        $seq_id     = $args['sequence_id']       ?? null;
        $seq_code   = $args['sequence_code']     ?? null;
        $verif_code = $args['verification_code'] ?? null;

        $token = wp_generate_password(24, false);
        $hash  = hash('sha256', $token);

        $base_path = isset($args['base_path']) ? (string) $args['base_path'] : '/subscription-confirmed';
        $url       = self::build_magic_url($token, $email, $base_path);
        $sig       = self::build_signature($token, $email);

        if (!post_type_exists('magic_tokens')) {
            self::tfg_log('❌ CPT magic_tokens not registered when creating token for ' . $email);
            return false;
        }

        $postarr = [
            'post_type'   => 'magic_tokens',
            'post_title'  => "MAG: {$seq_code} {$email}",
            'post_status' => 'publish',
            'meta_input'  => [
                'email'             => $email,
                'token'             => $token,               // keep for admin visibility; hash is the real key
                'token_hash'        => $hash,
                'sequence_id'       => $seq_id,
                'sequence_code'     => $seq_code,
                'verification_code' => $verif_code,
                'issued_on'         => gmdate('c', $now),
                'expires_at'        => $exp,                 // store UTC epoch
                'expires_on'        => gmdate('c', $exp),    // ISO8601
                'used'              => 0,
                'is_used'           => 0,                    // ACF boolean compatibility
                'used_at'           => 0,
                'magic_url'         => $url,
                'ip_address'        => self::get_user_ip_address(),
                'signature'         => $sig,
            ],
        ];

        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) {
            self::tfg_log('❌ wp_insert_post error: ' . $post_id->get_error_message());
            return false;
        }

        // Cache URL for [magic_login_button]
        $site = function_exists('get_current_blog_id') ? (string) get_current_blog_id() : '1';
        set_transient('last_magic_url_' . md5($site . '|' . strtolower($email)), $url, $expires_in);

        self::tfg_log('✅ Created magic token #' . $post_id . " for $email seq=" . ($seq_code ?? ''));
        return [
            'post_id'    => (int) $post_id,
            'token'      => $token,
            'token_hash' => $hash,
            'url'        => $url,
            'expires_at' => $exp,   // UTC epoch
            'expires'    => $exp,
        ];
    }

    /* ========================
     * Verify magic token
     * ====================== */
    /**
     * @param mixed $token
     * @param mixed $email
     * @param mixed $signature
     * @return array|false
     */
   public static function verify_magic_token($token, $email, $signature) {
    // Normalize (do NOT sanitize token/sig beyond format constraints)
    $email     = self::normalize_email(is_string($email) ? wp_unslash($email) : '');
    $token     = self::normalize_token(is_string($token) ? wp_unslash($token) : '');
    $signature = self::normalize_signature(is_string($signature) ? wp_unslash($signature) : '');

    if (!$email || !$token || !$signature) {
        self::log('❌ Missing/invalid params for verify_magic_token');
        return false;
    }

    $secret = self::hmac_secret();
    if (!$secret) {
        self::log('❌ TFG_HMAC_SECRET not defined');
        return false;
    }

    // HMAC compare must match how the link was signed
    $qs       = http_build_query(['token' => $token, 'email' => $email]);
    $expected = hash_hmac('sha256', $qs, $secret);
    self::log("🔐 HMAC cmp: qsA={$qs} expA=" . substr($expected,0,12) . "… sig=" . substr($signature,0,12) . "…");
    if (!hash_equals($expected, $signature)) {
        self::log("❌ Invalid signature for $email");
        return false;
    }

    // Find magic-token record
    $hash = hash('sha256', $token);
    self::log("🔎 looking up magic_token by token_hash={$hash} email={$email}");

    $q = new WP_Query([
        'post_type'        => 'magic_tokens',
        'posts_per_page'   => 1,
        'post_status'      => 'publish',
        'suppress_filters' => true,
        'no_found_rows'    => true,
        'meta_query'       => [
            ['key' => 'token_hash', 'value' => $hash,  'compare' => '='],
            ['key' => 'email',      'value' => $email, 'compare' => '='],
        ],
        'fields' => 'ids',
    ]);
    if (!$q->have_posts()) {
        self::log("❌ Token record not found for $email");
        return false;
    }
    $post_id = (int) $q->posts[0];

    // Debounce double-handling
    $lock_key = 'magic_verify_lock_' . $post_id;
    if (get_transient($lock_key)) {
        self::log("🔁 Duplicate verify suppressed for $email");
        // ✅ Treat as success to make link idempotent
        return [
            'success'       => true,
            'duplicate'     => true,
            'email'         => $email,
            'post_id'       => $post_id,
            'sequence_id'   => (int) get_post_meta($post_id, 'sequence_id', true),
            'sequence_code' => (string) get_post_meta($post_id, 'sequence_code', true),
        ];
    }
    set_transient($lock_key, 1, 5);

    $used       = (int) get_post_meta($post_id, 'used', true);
    $now        = time(); // UTC
    $expires_at = (int) get_post_meta($post_id, 'expires_at', true);
    self::log("✅ record id={$post_id} used={$used} expires_at={$expires_at} now={$now}");

    // ✅ If already used, return success instead of failing (idempotent link)
    if ($used === 1) {
        self::log("ℹ️ Token already used for $email — treating as success.");
        return [
            'success'       => true,
            'already_used'  => true,
            'email'         => $email,
            'post_id'       => $post_id,
            'sequence_id'   => (int) get_post_meta($post_id, 'sequence_id', true),
            'sequence_code' => (string) get_post_meta($post_id, 'sequence_code', true),
        ];
    }

    if ($expires_at && $now > $expires_at) {
        self::log("❌ Token expired for $email");
        return false;
    }

    // Soft-burn the related VT; “already used” is OK
    $seq_code = (string) get_post_meta($post_id, 'sequence_code', true);
    $vcode    = (string) get_post_meta($post_id, 'verification_code', true); // was copied when sending

    if ($seq_code && class_exists('TFG_Verification_Token') && method_exists('TFG_Verification_Token','mark_used')) {
        $vt_mark = TFG_Verification_Token::mark_used($seq_code, ($vcode ?: $seq_code), $email, ['check_expiry' => false]);
        if (is_wp_error($vt_mark)) {
            $err_code = $vt_mark->get_error_code();
            $already_used_codes = ['already_used','tfg_vt_already_used','vt_used'];
            if (in_array($err_code, $already_used_codes, true)) {
                self::log('ℹ️ Verification token already used; proceeding.');
            } else {
                self::log('❌ VT mark_used failed: ' . $vt_mark->get_error_message());
                return false;
            }
        } else {
            self::log('✅ VT mark_used ok; vt_id=' . ($vt_mark['post_id'] ?? 0) . ' seq=' . ($vt_mark['sequence_code'] ?? 'n/a'));
        }
    }

    // Mark the magic token as used now
    update_post_meta($post_id, 'used',    1);
    update_post_meta($post_id, 'is_used', 1);
    update_post_meta($post_id, 'used_at', $now);
    update_post_meta($post_id, 'ip_used', self::get_user_ip_address());

    return [
        'success'       => true,
        'email'         => $email,
        'post_id'       => $post_id,
        'sequence_id'   => (int) get_post_meta($post_id, 'sequence_id', true),
        'sequence_code' => (string) get_post_meta($post_id, 'sequence_code', true),
    ];
}

    /* ========================
     * Mailer (back-compat + dedupe)
     * ====================== */
    /**
     * Backward-compatible sender.
     * Accepts either (email, url, args) or (url, email, args).
     * $args: subject, headers, message_prefix, message_suffix, from, cc, bcc
     */
public static function send_magic_link($a, $b, $ignore = null): bool {
    // Accept either order. Third arg ignored (no code in email).
    $email = (function_exists('is_email') && is_email($a)) ? $a : (function_exists('is_email') && is_email($b) ? $b : null);
    $url   = filter_var($a, FILTER_VALIDATE_URL) ? $a : (filter_var($b, FILTER_VALIDATE_URL) ? $b : null);

    if (!$email || !$url) {
        error_log('[TFG MAGIC] ❌ send_magic_link: bad parameters');
        return false;
    }

    // Solid From: fixes "Invalid address: (From):"
    $host = function_exists('home_url') ? (wp_parse_url(home_url(), PHP_URL_HOST) ?: '') : '';
    if (!$host || $host === 'localhost') { $host = 'example.com'; }
    $from_email = 'no-reply@' . $host;
    $from_name  = function_exists('get_bloginfo') ? (get_bloginfo('name') ?: 'WordPress') : 'WordPress';

    $subject = 'Confirm your subscription';
    $body  = '<p>Click to confirm your subscription:</p>';
    $body .= '<p><a href="'.esc_url($url).'">'.esc_html($url).'</a></p>';

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: '.$from_name.' <'.$from_email.'>',
    ];

    // PHPMailer safety for Windows/XAMPP
    @ini_set('sendmail_from', $from_email);
    $pm_from = function($phpmailer) use ($from_email, $from_name) {
        try { $phpmailer->setFrom($from_email, $from_name, false); } catch (\Throwable $e) {}
    };
    add_action('phpmailer_init', $pm_from);

    $fail_cb = function($err) use ($email, $url) {
        $msg = (is_object($err) && method_exists($err, 'get_error_message')) ? $err->get_error_message() : 'unknown';
        error_log(sprintf('[TFG MAGIC] ❌ wp_mail_failed for %s url=%s :: %s', $email, $url, $msg));
    };
    add_action('wp_mail_failed', $fail_cb, 10, 1);

    $ok = wp_mail($email, $subject, $body, $headers);

    remove_action('phpmailer_init', $pm_from);
    remove_action('wp_mail_failed', $fail_cb);

    if ($ok) {
        error_log(sprintf('[Magic] 📤 Magic link sent to %s url=%s', $email, $url));
        return true;
    }
    error_log(sprintf('[TFG MAGIC] ❌ wp_mail returned false for %s', $email));
    return false;
}




} // end class
} // class_exists guard
