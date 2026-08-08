<?php
require_once __DIR__ . '/../config.php';
$rows = getRecentPlatformErrors(10, true);
if (empty($rows)) {
    echo "No unresolved errors found.\n";
} else {
    foreach ($rows as $r) {
        echo "ID: {$r['id']} | Level: {$r['level']} | File: {$r['file']}:{$r['line']} | Time: {$r['created_at']}\n";
        echo "Message: {$r['message']}\n";
        echo "---\n";
    }
}
