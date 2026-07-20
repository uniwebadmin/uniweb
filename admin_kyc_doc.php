<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager', 'field_staff', 'ops', 'kyc']);

$id = (int)($_GET['id'] ?? 0);
if (!$id || !verifyCsrf($_GET['token'] ?? '')) {
    http_response_code(403);
    die('Forbidden');
}

$db = getDB();
$st = $db->prepare('SELECT file_path,file_name,merchant_id,mime_type,scan_status FROM kyc_documents WHERE id=?');
$st->execute([$id]);
$doc = $st->fetch();
if (!$doc) {
    http_response_code(404);
    die('Document not found');
}
requireMerchantAccess((int)$doc['merchant_id']);
if (($doc['scan_status'] ?? 'pending') !== 'clean') {
    http_response_code(423);
    die('Document is quarantined until malware scanning completes.');
}
if (!is_file($doc['file_path'])) {
    http_response_code(404);
    die('Document not found');
}

$path = realpath($doc['file_path']);
$privateRoot = realpath(KYC_PRIVATE_DIR);
$legacyRoot = realpath(rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'kyc');
$insidePrivate = $path && $privateRoot && str_starts_with($path, $privateRoot . DIRECTORY_SEPARATOR);
$insideLegacy = $path && $legacyRoot && str_starts_with($path, $legacyRoot . DIRECTORY_SEPARATOR);
if (!$path || (!$insidePrivate && !$insideLegacy)) {
    http_response_code(403);
    die('Invalid path');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'mov' => 'video/quicktime',
];
$mime = (string)($doc['mime_type'] ?: ($types[$ext] ?? 'application/octet-stream'));
$isVideo = str_starts_with($mime, 'video/');

// Video KYC: HTML player so admins can review in-browser
if ($isVideo && empty($_GET['raw'])) {
    $src = 'admin_kyc_doc.php?id=' . $id . '&token=' . rawurlencode((string)($_GET['token'] ?? '')) . '&raw=1';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Video KYC</title><style>body{margin:0;background:#0b1220;color:#e5e7eb;font-family:system-ui,sans-serif;display:flex;flex-direction:column;align-items:center;padding:24px}'
        . 'video{max-width:min(960px,100%);width:100%;border-radius:12px;background:#000}a{color:#38bdf8;margin-top:16px}</style></head><body>'
        . '<h1 style="font-size:16px;font-weight:600;margin:0 0 16px">Video KYC preview</h1>'
        . '<video controls playsinline src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"></video>'
        . '<a href="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" download>Download file</a></body></html>';
    exit;
}

header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; media-src 'self'; style-src 'unsafe-inline'");
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($doc['file_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
