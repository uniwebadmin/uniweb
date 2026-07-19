<?php
/** One-time wallet repair v18 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (!isAdminLoggedIn() && ($_GET['key'] ?? '') !== 'uniweb-v18') {
    http_response_code(403);
    die('Forbidden');
}
forceWalletDataReset();
$db = getDB();
$merchants = $db->query('SELECT id FROM merchants')->fetchAll();
foreach ($merchants as $m) {
    refreshMerchantWalletBalance((int)$m['id']);
}
echo "V18 OK\nplatform: " . getPlatformWalletBalance() . "\n";
echo "DELETE this file after run.\n";
