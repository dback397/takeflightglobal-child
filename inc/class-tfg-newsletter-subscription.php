<?php

class TFG_Newsletter_Subscription {

    public static function init(): void {
        add_shortcode('tfg_newsletter_form', [__CLASS__, 'render_newsletter_signup_form']);
        add_action('init', [__CLASS__, 'handle_newsletter_signup'], 99);
    }

    // === 1) Render the newsletter signup form ===
    public static function render_newsletter_signup_form($atts = [], $content = null, $tag = ''): string {
        $atts = shortcode_atts([
            'prefill_email' => '',
        ], $atts);

        // Prefill priority: shortcode attr > prefill cookie
        $prefill_email = TFG_Utils::normalize_email($atts['prefill_email']);
        if (!$prefill_email && !empty($_COOKIE['tfg_prefill_email'])) {
            $prefill_email = TFG_Utils::normalize_email(wp_unslash($_COOKIE['tfg_prefill_email']));
            // Clear the cookie so it doesn't linger
            if (class_exists('TFG_Cookies') && method_exists('TFG_Cookies', 'delete_ui_cookie')) {
                TFG_Cookies::delete_ui_cookie('tfg_prefill_email');
            }
        }

        $success = (isset($_GET['subscribed']) && $_GET['subscribed'] === '1');
        $error   = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';

        ob_start();

        //if ($success) {
            //echo '<div class="tfg-success-banner">Thank you for subscribing! Please check your email to confirm.</div>';
        //} elseif ($error) {
            //echo '<div class="tfg-error-banner">' . esc_html($error) . '</div>';
        //}
        ?>
        <form method="POST" class="tfg-newsletter-form" action="">
            <?php wp_nonce_field('tfg_newsletter_signup'); ?>
            <div style="display:flex;flex-direction:column;gap:0.25em;max-width:500px;margin:auto;">

                <div style="height:1.25em;"></div>
                <input type="hidden" name="handler_id" value="newsletter_signup">

                <label for="subscriber_name" class="tfg-font-base">
                    <strong>Name <span class="tfg-required">*</span></strong>
                </label>
                <input type="text"
                       name="subscriber_name"
                       placeholder="Your Name"
                       class="tfg-input tfg-font-base"
                       autocomplete="off"
                       required
                       style="margin-bottom:1em;">

                <label for="subscriber_email" class="tfg-font-base">
                    <strong>Email <span class="tfg-required">*</span></strong>
                </label>
                <input type="email"
                       name="subscriber_email"
                       placeholder="Your Email"
                       class="tfg-input tfg-font-base"
                       autocomplete="email"
                       required
                       style="margin-bottom:1em;"
                       value="<?php echo esc_attr($prefill_email); ?>">

                <label for="gdpr_consent" class="tfg-font-base">
                    <strong>GDPR Agreement <span class="tfg-required">*</span></strong>
                </label>
                <div class="tfg-alt-gdpr-box" style="margin-top:10px;">
                    <label style="margin:0;">
                        <input type="checkbox" id="gdpr_consent" name="gdpr_consent" value="1" required style="margin-bottom:0;">
                        By checking this box, you affirm that you have read and agree to our TERMS OF USE regarding storage of the data submitted through this form.
                    </label>
                </div>

                <!-- Filled by your JS that calls the REST endpoint -->
                <input type="hidden" name="verification_code" id="verification_code_field" value="">

                <input type="hidden" name="source" value="newsletter_form">

                <?php echo self::insert_recaptcha(); ?>

                <button type="submit"
                        name="submit_newsletter_signup"
                        value="1"
                        class="tfg-button tfg-font-base">Subscribe</button>

                <div style="height:1.25em;"></div>
            </div>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    // === 2) Handle the POST ===
public static function handle_newsletter_signup(): void {
    // --- ROUTE GUARD (hardened) ---
    $is_post = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
    $hid     = isset($_POST['handler_id']) ? (string) wp_unslash($_POST['handler_id']) : '';

    $matches = ($is_post && $hid === 'newsletter_signup'); // fallback path
    if (class_exists('TFG_Form_Router') && method_exists('TFG_Form_Router', 'matches')) {
        // if your router is present, let it decide
        $matches = TFG_Form_Router::matches('newsletter_signup');
    }

    if (!$matches) {
        return;
    }

    // Debug: prove we reached here and see what PHP actually received
    error_log('[TFG NL] router_ok; keys=' . implode(',', array_keys($_POST)));

    // ── NONCE ─────────────────────────────────────────────────────────────────
    $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'tfg_newsletter_signup')) {
        self::redirect_with_error('Security check failed.');
    }

    // ── INTAKE / NORMALIZE ───────────────────────────────────────────────────
    $vcode   = TFG_Utils::normalize_token($_POST['verification_code'] ?? '');
    $email   = TFG_Utils::normalize_email(wp_unslash($_POST['subscriber_email'] ?? ''));
    $name    = sanitize_text_field(wp_unslash($_POST['subscriber_name'] ?? ''));
    $gdpr_ok = !empty($_POST['gdpr_consent']) && wp_unslash($_POST['gdpr_consent']) === '1';
    $source  = 'newsletter_form';

    // Fallback: if the hidden verification_code didn't arrive (JS glitch) ---
    if (!$vcode && $email) {
    $vt = get_posts([
        'post_type'        => 'verification_tokens',
        'posts_per_page'   => 1,
        'post_status'      => 'any',
        'suppress_filters' => true,
        'no_found_rows'    => true,
        'fields'           => 'ids',
        'orderby'          => 'date',
        'order'            => 'DESC',
        'meta_query'       => [
            'relation' => 'AND',
            // Match by email as saved at token creation time
            [
                'relation' => 'OR',
                [ 'key' => 'subscriber_email', 'value' => $email, 'compare' => '=' ],
                [ 'key' => 'email',            'value' => $email, 'compare' => '=' ],
            ],
            // Unused token: either explicitly 0 or meta not created yet
            [
                'relation' => 'OR',
                [ 'key' => 'is_used', 'value' => '0', 'compare' => '=', 'type' => 'NUMERIC' ],
                [ 'key' => 'is_used', 'compare' => 'NOT EXISTS' ],
            ],
        ],
    ]);

    if (!empty($vt)) {
        $vt_id = (int) $vt[0];
        $fallback_code = (string) get_post_meta($vt_id, 'verification_code', true);
        if ($fallback_code !== '') {
            $vcode = $fallback_code;
            error_log("[TFG NL] ⚙️ Fallback VT applied for {$email} (vt_id={$vt_id}).");
        }
    }
    }

    // ── BASIC VALIDATION ──────────────────────────────────────────────────────
    if (!$email || !is_email($email) || !$gdpr_ok || !$vcode) {
        self::redirect_with_error('Missing required fields.');
    }

     // ── reCAPTCHA (optional) ─────────────────────────────────────────────────
    $recap_ok = true;
    if (class_exists('TFG_Recaptcha') && method_exists('TFG_Recaptcha', 'verify')) {
        $recap_ok = (bool) TFG_Recaptcha::verify($_POST['g-recaptcha-response'] ?? '');
    } elseif (class_exists('TFG_ReCAPTCHA') && method_exists('TFG_ReCAPTCHA', 'verify')) {
        $recap_ok = (bool) TFG_ReCAPTCHA::verify($_POST['g-recaptcha-response'] ?? '');
    }
    if (!$recap_ok) {
        self::redirect_with_error('reCAPTCHA validation failed.');
    }

    // ── ALREADY SUBSCRIBED? ──────────────────────────────────────────────────
    $existing = get_posts([
        'post_type'        => 'subscriber',
        'posts_per_page'   => 1,
        'post_status'      => 'publish',
        'fields'           => 'ids',
        'suppress_filters' => true,
        'no_found_rows'    => true,
        'meta_query'       => [
            ['key' => 'email',         'value' => $email, 'compare' => '='],
            ['key' => 'is_subscribed', 'value' => 1,      'compare' => '=', 'type' => 'NUMERIC'],
        ],
    ]);
    if ($existing) {
        error_log("[TFG NL] ℹ️ Already subscribed: {$email}");
        self::redirect_success();
    }

     // ── BURN VERIFICATION TOKEN + GET SEQUENCE ───────────────────────────────
    if (!class_exists('TFG_Verification_Token') || !method_exists('TFG_Verification_Token', 'find_by_code')) {
        self::redirect_with_error('Verification service unavailable.');
    }
    error_log('[TFG NL] enter handle_newsletter_signup');

    $verif = TFG_Verification_Token::mark_used($vcode, $vcode, $email, ['check_expiry' => false]);
    if (is_wp_error($verif)) {
        error_log('[TFG NL] ❌ mark_used: ' . $verif->get_error_message());
        self::redirect_with_error('Invalid or expired verification code.');
    }
    error_log('[TFG NL] ✅ mark_used OK; vt_post_id=' . ($verif['post_id'] ?? 0) . ' seq=' . ($verif['sequence_code'] ?? 'n/a'));

    $vt_post_id   = (int)   ($verif['post_id']       ?? 0);
    $sequence_id  = (string)($verif['sequence_id']   ?? '');
    $sequence_code= (string)($verif['sequence_code'] ?? '');
    error_log('[TFG NL] ✅ mark_used OK; vt_post_id=' . $vt_post_id . ' seq=' . ($sequence_code ?: 'n/a'));

    // Canonical title built now that we have sequence_code
    $desired_title = $sequence_code !== ''
    ? sprintf('SUB: [%s] %s', $sequence_code, $email)
    : sprintf('SUB: %s', $email);

    // --- UPSERT SUBSCRIBER STUB (NO subscribed_on / NO is_subscribed here) ---
    $sub_id = (int) ((get_posts([
        'post_type'        => 'subscriber',
        'posts_per_page'   => 1,
        'post_status'      => 'publish',
        'fields'           => 'ids',
        'suppress_filters' => true,
        'no_found_rows'    => true,
        'meta_query'       => [[ 'key' => 'email', 'value' => $email, 'compare' => '=' ]],
    ])[0] ?? 0));

    if (!$sub_id) {
        $insert = wp_insert_post([
        'post_type'   => 'subscriber',
        'post_status' => 'publish',
        'post_title'  => $desired_title,
        ], true);

        if (is_wp_error($insert) || !$insert) {
            self::redirect_with_error('Could not create subscriber.');
        }
        $sub_id = (int) $insert;
    } else {
        // Normalize title *here* (not in finalize)
        if (get_post_field('post_title', $sub_id) !== $desired_title) {
            wp_update_post(['ID' => $sub_id, 'post_title' => $desired_title]);
        }
    }
    
    // ACF-friendly setter
    $set = function(string $key, $val) use ($sub_id) {
        if (function_exists('update_field')) { update_field($key, $val, $sub_id); }
        else { update_post_meta($sub_id, $key, $val); }
    };

    // Persist stub fields together
    $set('vt_post_id',        $vt_post_id);
    $set('email',             $email);
    if ($name !== '') { $set('name', $name); }
    $set('verification_code', $vcode);
    if ($sequence_id !== '')   { $set('sequence_id',   $sequence_id); }
    if ($sequence_code !== '') { $set('sequence_code', $sequence_code); }
    $set('gdpr_consent',      '1');
    $set('source',            $source);
    $set('is_verified',   0);
    $set('is_subscribed', 0);
    
     // ── CREATE MAGIC TOKEN & SEND ────────────────────────────────────────────
    error_log('[TFG NL] enter handle_magic token process');
    $magic = TFG_Magic_Utilities::create_magic_token($email, [
        'sequence_id'   => $verif['sequence_id'],
        'sequence_code' => $verif['sequence_code'],
        'expires_in'    => 900,
        'confirm_url'   => home_url('/subscription-confirmed'),
    ]);

    $magic_url     = is_array($magic) ? (string)($magic['url']     ?? '') : '';
    $magic_post_id = is_array($magic) ? (int)   ($magic['post_id'] ?? 0)  : 0;
    $magic_expires = is_array($magic) ? (int)   ($magic['expires'] ?? 0)  : 0;

    if ($magic_url === '' || $magic_post_id === 0) {
        self::redirect_with_error('Could not create confirmation link.');
    }

    // Store breadcrumbs (optional but useful for audits/support)
    update_post_meta($magic_post_id, 'verification_code', $vcode); // not in URL
    $set('magic_post_id',    $magic_post_id);
    if ($magic_expires) { $set('magic_expires_at', gmdate('c', $magic_expires)); }
    $set('last_magic_url',   $magic_url);

    $sent = (bool) TFG_Magic_Utilities::send_magic_link($email, $magic_url);
    if ($sent) {
        // Mark the Magic Token as used/sent
        if (function_exists('update_field')) { update_field('is_used', 1, $magic_post_id); }
        else { update_post_meta($magic_post_id, 'is_used', 1); }

        // Optional breadcrumbs
        update_post_meta($magic_post_id, 'sent_on', current_time('mysql'));
        update_post_meta($magic_post_id, 'sent_to', $email);
    } else {
        error_log('[NL] send_magic_link FAILED for ' . $email);
    }

    // ── DONE ─────────────────────────────────────────────────────────────────
    self::redirect_success();
}

/* 
// ✅ duplicate VT code into MT CPT meta (NOT in the URL)
    update_post_meta($magic_post_id, 'verification_code', $vcode);

    // ✅ send ONCE (correct argument order)
    // $magic_url should be the full absolute URL you already built
    $sent = (bool) TFG_Magic_Utilities::send_magic_link($email, $magic_url);
    if (!$sent) {
        error_log('[TFG NL] ❌ send_magic_link failed (not sent). Check [Magic] logs for details.');
    }
    // --- DONE ---
    self::redirect_success();

    // === Upsert subscriber (pending)
    $sub_id = 0;
    $existing_any = get_posts([
        'post_type'        => 'subscriber',
        'posts_per_page'   => 1,
        'post_status'      => 'publish',
        'fields'           => 'ids',
        'suppress_filters' => true,
        'no_found_rows'    => true,
        'meta_query'       => [[ 'key' => 'email', 'value' => $email, 'compare' => '=' ]],
    ]);
    if ($existing_any) {
        $sub_id = (int) $existing_any[0];
    } else {
        $sub_id = wp_insert_post([
            'post_type'   => 'subscriber',
            'post_status' => 'publish',
            'post_title'  => $email,
        ], true);
        if (is_wp_error($sub_id) || !$sub_id) {
            self::redirect_with_error('Could not create subscriber.');
        }
        $sub_id = (int) $insert;
    }

    // Setter that prefers ACF
    $set = function(string $key, $val) use ($sub_id) {
    if (function_exists('update_field')) { update_field($key, $val, $sub_id); }
    else { update_post_meta($sub_id, $key, $val); }
    };

    // Core stub fields (NO 'subscribed_on' here)
    $set('email',             $email);
    if ($name !== '') { $set('subscriber_name', $name); }
    $set('verification_code', $vcode);
    $set('gdpr_consent',      1);
    $set('source',            $source);
    $set('is_verified',       0);
    $set('is_subscribed',     0);

    // Sequences from mark_used() result
    $set('sequence_id',       (string)($verif['sequence_id']   ?? ''));
    $set('sequence_code',     (string)($verif['sequence_code'] ?? ''));

    // Useful breadcrumbs (optional)
    $set('vt_post_id',        (int)   ($verif['post_id'] ?? 0));

    // If you've already created the magic token at this point:
    if (!empty($magic_post_id)) {
        $set('magic_post_id',   (int) $magic_post_id);
        $set('magic_expires_at', gmdate('c', (int) ($magic_expires ?? 0)));
        if (!empty($magic_url)) {
            $set('last_magic_url', (string) $magic_url); // optional; keep if helpful for support
        }
    }


    // Store fields (prefer ACF if present)
    $now_mysql = current_time('mysql');
    if (function_exists('update_field')) {
        update_field('verification_code', $vcode,     $sub_id);
        update_field('email',             $email,     $sub_id);
        update_field('subscriber_name',   $name,      $sub_id);
        update_field('gdpr_consent',      1,          $sub_id);
        update_field('is_verified',       0,          $sub_id); // will flip on magic click
        update_field('is_subscribed',     0,          $sub_id); // flip on confirm
        update_field('source',            $source,    $sub_id);
        update_field('subscribed_on',     $now_mysql, $sub_id);
        update_field('last_magic_url',    $magic_url, $sub_id);
        update_field('magic_expires_at',  gmdate('c', $magic_expires), $sub_id);
    } else {
        update_post_meta($sub_id, 'verification_code', $vcode);
        update_post_meta($sub_id, 'email',             $email);
        update_post_meta($sub_id, 'subscriber_name',   $name);
        update_post_meta($sub_id, 'gdpr_consent',      1);
        update_post_meta($sub_id, 'is_verified',       0);
        update_post_meta($sub_id, 'is_subscribed',     0);
        update_post_meta($sub_id, 'source',            $source);
        update_post_meta($sub_id, 'subscribed_on',     $now_mysql);
        update_post_meta($sub_id, 'last_magic_url',    $magic_url);
        update_post_meta($sub_id, 'magic_expires_at',  gmdate('c', $magic_expires));
    }

    self::redirect_success();
}
*/

private static function direct_create_magic_token(string $email, int $verif_id = 0, int $seq_id = 0, string $seq_code = '', int $ttl = 900) : array {
    error_log('[TFG NL] direct_create_magic_token start');

    // CPT is registered by MU-plugin, but double-check
    if (!post_type_exists('magic_tokens')) {
        error_log('[TFG NL] magic_tokens CPT missing at call-time, registering inline');
        register_post_type('magic_tokens', [
            'label'               => 'Magic Tokens',
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'supports'            => ['title'],
        ]);
    }

    try {
        $token = bin2hex(random_bytes(18));
    } catch (Exception $e) {
        $token = wp_generate_password(36, false, false);
    }
    $expires_at = time() + max(60, (int) $ttl);
    $title      = $seq_code ? "magic: {$seq_code}" : 'magic token';

    $post_id = wp_insert_post([
        'post_type'   => 'magic_tokens',
        'post_status' => 'publish',
        'post_title'  => $title,
    ], true);

    if (is_wp_error($post_id) || !$post_id) {
        error_log('[TFG NL] ❌ wp_insert_post failed for magic_tokens: ' . (is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown'));
        return [];
    }

    update_post_meta($post_id, 'token',           $token);
    update_post_meta($post_id, 'email',           sanitize_email($email));
    update_post_meta($post_id, 'verification_id', (int) $verif_id);
    update_post_meta($post_id, 'sequence_id',     (int) $seq_id);
    update_post_meta($post_id, 'sequence_code',   (string) $seq_code);
    update_post_meta($post_id, 'expires_at',      (int) $expires_at);
    update_post_meta($post_id, 'used_on',         '');

    $url = add_query_arg('tfg_magic', rawurlencode($token), site_url('/'));

    error_log('[TFG NL] direct_create_magic_token end; post_id=' . (int)$post_id);
    return [
        'post_id' => (int) $post_id,
        'url'     => $url,
        'expires' => $expires_at,
    ];
}



    private static function redirect_success(): void {
        if (!headers_sent()) {
            nocache_headers();
            wp_safe_redirect(add_query_arg('subscribed', '1', remove_query_arg(['error'])));
            exit;
        }
        error_log("[TFG NL] Redirect Success: headers already sent");
    }

    private static function redirect_with_error(string $msg): void {
        if (!headers_sent()) {
            nocache_headers();
            wp_safe_redirect(add_query_arg('error', rawurlencode($msg), remove_query_arg(['subscribed'])));
            exit;
        }
        error_log("[TFG NL] Redirect Error: $msg (headers already sent)");
    }

    // === 3) Helper: reCAPTCHA insertion (supports either class name) ===
    public static function insert_recaptcha(): string {
     
        ob_start();
        $site_key = '';
        if (class_exists('TFG_ReCAPTCHA') && method_exists('TFG_ReCAPTCHA', 'get_keys')) {
            $keys = TFG_ReCAPTCHA::get_keys();
            $site_key = (string) ($keys['site'] ?? '');
        } elseif (class_exists('TFG_Recaptcha') && method_exists('TFG_Recaptcha', 'get_keys')) {
            $keys = TFG_Recaptcha::get_keys();
            $site_key = (string) ($keys['site'] ?? '');
        }

        if ($site_key !== '') {
            ?>
            <div class="recaptcha-flex">
                <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($site_key); ?>"></div>
            </div>
            <?php
        }
        return (string) ob_get_clean();
    }
}
