<?php
/** Fix MDR rates on live DB — run once then delete */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$db = getDB();
$updates = [
    'platform_margin_pct' => '0.10',
    'card_mdr' => '1.90',
    'netbanking_mdr' => '1.90',
];
$stmt = $db->prepare('UPDATE gateway_settings SET setting_value = ? WHERE setting_key = ?');
foreach ($updates as $k => $v) {
    $stmt->execute([$v, $k]);
    if ($stmt->rowCount() === 0) {
        $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?)')->execute([$k, $v]);
    }
    echo "OK $k = $v\n";
}
echo "Done. Delete this file.\n";
