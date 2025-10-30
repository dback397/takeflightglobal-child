<?php
require_once dirname(__FILE__, 4) . '/wp-load.php';

use TFG\Core\Cookies;

header('Content-Type: text/html; charset=utf-8');

function dump_cookie_state() {
    echo "<h3>🧁 Current Cookies</h3><pre>";
    var_dump($_COOKIE);
    echo "</pre>";
}

function debug_hmac($memberId, $email) {
    $payload = $memberId . '|' . ($email ?: '-') . '|' . gmdate('Y-m');
    $computed = hash_hmac('sha256', $payload, defined('TFG_HMAC_SECRET') ? TFG_HMAC_SECRET : '(undefined)');
    echo "<h3>🔐 HMAC Debug</h3>";
    echo "<b>Payload:</b> {$payload}<br>";
    echo "<b>Expected (Cookies::memberHmac):</b> {$computed}<br>";

    if (isset($_COOKIE['member_ok'])) {
        echo "<b>Cookie value:</b> " . htmlspecialchars($_COOKIE['member_ok']) . "<br>";
        echo "<b>Match?</b> " . (hash_equals($computed, $_COOKIE['member_ok']) ? '✅ YES' : '❌ NO') . "<br>";
    } else {
        echo "<b>No member_ok cookie present.</b><br>";
    }
}

// -------------------------------------------------------------
// Main flow
// -------------------------------------------------------------
$memberId = 'UNI00001';
$email    = 'test@example.com';

echo "<h2>TFG Member Cookie Test</h2>";

if (isset($_GET['action']) && $_GET['action'] === 'set') {
    Cookies::setMemberCookie($memberId, $email);
    echo "✅ Cookies set.<br><a href='?action=check'>Check now</a>";
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'check') {
    $is = Cookies::isMember($memberId, $email);
    $vr = Cookies::verifyMember($memberId, $email);

    echo "<h3>🧠 Verification Results</h3>";
    echo "isMember(): " . ($is ? '✅ TRUE' : '❌ FALSE') . "<br>";
    echo "verifyMember(): " . ($vr ? '✅ TRUE' : '❌ FALSE') . "<br><br>";

    dump_cookie_state();
    debug_hmac($memberId, $email);

    echo "<hr><a href='?action=clear'>Clear cookies</a>";
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'clear') {
    Cookies::unsetMemberCookie();
    echo "✅ Cookies cleared.<br><a href='?action=check'>Check again</a>";
    exit;
}

echo "<a href='?action=set'>Start cookie test</a>";
