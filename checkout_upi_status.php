<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$linkId = trim($_GET['link'] ?? '');
if ($linkId === '') {
    jsonResponse(['error' => 'link required'], 400);
}

jsonResponse(getCheckoutPaymentStatus($linkId));
