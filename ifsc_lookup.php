<?php
require_once __DIR__ . '/config.php';

// Bank/branch directory lookup for the Add Bank form. Non-sensitive public data,
// but gated behind any logged-in session to avoid open proxy abuse.
if (!isLoggedIn() && !isAdminLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

header('Content-Type: application/json');
$ifsc = strtoupper(trim($_GET['ifsc'] ?? ''));
if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid IFSC format']);
    exit;
}

$info = lookupIfsc($ifsc);
if (!$info) {
    echo json_encode(['ok' => false, 'error' => 'IFSC not found']);
    exit;
}

echo json_encode(['ok' => true] + $info);
