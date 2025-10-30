<?php
require_once dirname(__FILE__, 4) . '/wp-load.php';

use TFG\Core\Cookies;

if (Cookies::isMember()) {
    echo "✅ Access granted (member verified)";
} else {
    echo "❌ Access denied (no valid member)";
}
