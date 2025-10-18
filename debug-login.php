<?php
/**
 * WordPress Login Diagnostic Script
 * Place this file in your theme directory and access it via browser
 * URL: http://localhost/wp-content/themes/takeflightglobal-child/debug-login.php
 */

// Load WordPress
require_once('../../../wp-load.php');

echo '<h1>WordPress Login Diagnostic</h1>';
echo '<hr>';

// Check if user is logged in
echo '<h2>1. User Login Status</h2>';
echo 'is_user_logged_in(): ' . (is_user_logged_in() ? 'YES ✓' : 'NO ✗') . '<br>';
if (is_user_logged_in()) {
    $current_user = wp_get_current_user();
    echo 'User ID: ' . $current_user->ID . '<br>';
    echo 'Username: ' . $current_user->user_login . '<br>';
    echo 'Email: ' . $current_user->user_email . '<br>';
}
echo '<hr>';

// Check cookies
echo '<h2>2. WordPress Cookies</h2>';
echo '<pre>';
foreach ($_COOKIE as $name => $value) {
    if (strpos($name, 'wordpress') !== false || strpos($name, 'wp') !== false) {
        echo htmlspecialchars($name) . ' => ' . htmlspecialchars(substr($value, 0, 50)) . '...<br>';
    }
}
echo '</pre>';
echo '<hr>';

// Check custom TFG cookies
echo '<h2>3. TFG Custom Cookies</h2>';
echo '<pre>';
$tfg_cookies = ['is_subscribed', 'subscribed_ok', 'is_member', 'member_ok', 'member_id', 'member_email'];
foreach ($tfg_cookies as $name) {
    $value = $_COOKIE[$name] ?? 'NOT SET';
    echo htmlspecialchars($name) . ' => ' . htmlspecialchars($value) . '<br>';
}
echo '</pre>';
echo '<hr>';

// Check WordPress constants
echo '<h2>4. WordPress Constants</h2>';
echo 'COOKIE_DOMAIN: ' . (defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : 'NOT SET') . '<br>';
echo 'COOKIEPATH: ' . (defined('COOKIEPATH') ? COOKIEPATH : 'NOT SET') . '<br>';
echo 'SITECOOKIEPATH: ' . (defined('SITECOOKIEPATH') ? SITECOOKIEPATH : 'NOT SET') . '<br>';
echo 'ADMIN_COOKIE_PATH: ' . (defined('ADMIN_COOKIE_PATH') ? ADMIN_COOKIE_PATH : 'NOT SET') . '<br>';
echo 'FORCE_SSL_ADMIN: ' . (defined('FORCE_SSL_ADMIN') ? (FORCE_SSL_ADMIN ? 'TRUE' : 'FALSE') : 'NOT SET') . '<br>';
echo 'is_ssl(): ' . (is_ssl() ? 'YES' : 'NO') . '<br>';
echo '<hr>';

// Check redirects
echo '<h2>5. Redirect History</h2>';
if (class_exists('\TFG\Core\RedirectHelper')) {
    echo '<pre>';
    print_r(\TFG\Core\RedirectHelper::getRedirectHistory());
    echo '</pre>';
} else {
    echo 'RedirectHelper class not found<br>';
}
echo '<hr>';

// Check for active plugins that might interfere
echo '<h2>6. Active Plugins</h2>';
$active_plugins = get_option('active_plugins');
echo '<ul>';
foreach ($active_plugins as $plugin) {
    if (stripos($plugin, 'login') !== false || stripos($plugin, 'auth') !== false || stripos($plugin, 'security') !== false) {
        echo '<li style="color: red; font-weight: bold;">' . htmlspecialchars($plugin) . ' (might interfere)</li>';
    } else {
        echo '<li>' . htmlspecialchars($plugin) . '</li>';
    }
}
echo '</ul>';
echo '<hr>';

// Check request info
echo '<h2>7. Request Information</h2>';
echo 'REQUEST_URI: ' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'NOT SET') . '<br>';
echo 'HTTP_REFERER: ' . htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'NOT SET') . '<br>';
echo 'REQUEST_METHOD: ' . htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'NOT SET') . '<br>';
echo '<hr>';

// Test login link
echo '<h2>8. Test Links</h2>';
echo '<a href="' . wp_login_url() . '">WordPress Login Page</a><br>';
echo '<a href="' . admin_url() . '">WordPress Admin</a><br>';
echo '<a href="' . site_url('/member-login/') . '">TFG Member Login</a><br>';
echo '<hr>';

// Last 50 lines of error log
echo '<h2>9. Recent Error Log (Last 50 Lines)</h2>';
$log_file = ini_get('error_log');
if (file_exists($log_file)) {
    $lines = file($log_file);
    $recent_lines = array_slice($lines, -50);
    echo '<pre style="background: #f0f0f0; padding: 10px; max-height: 400px; overflow-y: scroll;">';
    foreach ($recent_lines as $line) {
        if (stripos($line, 'TFG') !== false || stripos($line, 'login') !== false) {
            echo '<span style="background: yellow;">' . htmlspecialchars($line) . '</span>';
        } else {
            echo htmlspecialchars($line);
        }
    }
    echo '</pre>';
} else {
    echo 'Error log file not found: ' . htmlspecialchars($log_file) . '<br>';
}
