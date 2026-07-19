<?php
/**
 * UNIWEB Database Migration v5 — international address fields
 * Run once: https://uniweb.co.in/update_v5.php then DELETE.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');
echo '<pre style="background:#111;color:#0f0;padding:20px;font-family:monospace">';
try {
    $db = getDB();
    $cols = [
        "ALTER TABLE merchants ADD COLUMN country VARCHAR(100) DEFAULT 'India'",
        "ALTER TABLE merchants ADD COLUMN district VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE merchants MODIFY phone VARCHAR(20) NOT NULL",
        "ALTER TABLE merchants MODIFY pincode VARCHAR(12) DEFAULT NULL",
    ];
    foreach ($cols as $sql) {
        try { $db->exec($sql); echo "OK: $sql\n"; } catch (Throwable $e) { echo "SKIP: " . $e->getMessage() . "\n"; }
    }
    $db->exec("UPDATE merchants SET country = 'India' WHERE country IS NULL OR country = ''");
    echo "\n✅ Migration v5 done. DELETE update_v5.php now.\n";
} catch (Throwable $e) {
    echo '❌ ' . $e->getMessage();
}
echo '</pre>';
