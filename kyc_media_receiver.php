<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureKycSchema();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
$csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verifyCsrf($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Session expired. Refresh and retry.']);
    exit;
}

$action = (string)($_GET['action'] ?? '');
$uploadId = strtolower((string)($_GET['upload_id'] ?? ''));
$extension = strtolower((string)($_GET['ext'] ?? ''));
$index = (int)($_GET['index'] ?? -1);
$total = (int)($_GET['total'] ?? 0);
$allowed = ['mp4', 'webm', 'mov'];

if (!preg_match('/^[a-z0-9]{20,64}$/', $uploadId) || !in_array($extension, $allowed, true) || $total < 1 || $total > 60) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid upload request.']);
    exit;
}

$tempDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . '.kyc_chunks' . DIRECTORY_SEPARATOR . $merchantId;
if (!is_dir($tempDir) && !mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server upload folder is unavailable.']);
    exit;
}
$prefix = $tempDir . DIRECTORY_SEPARATOR . $uploadId . '_';

if ($action === 'part') {
    if ($index < 0 || $index >= $total) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid chunk number.']);
        exit;
    }
    $data = file_get_contents('php://input');
    if ($data === false || strlen($data) < 1 || strlen($data) > 1250000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A video chunk was empty or too large.']);
        exit;
    }
    $partPath = $prefix . str_pad((string)$index, 4, '0', STR_PAD_LEFT) . '.part';
    if (file_put_contents($partPath, $data, LOCK_EX) !== strlen($data)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Server could not save a video chunk.']);
        exit;
    }
    echo json_encode(['ok' => true, 'part' => $index]);
    exit;
}

if ($action !== 'finalize') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown upload action.']);
    exit;
}

$finalDir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'kyc' . DIRECTORY_SEPARATOR . $merchantId . DIRECTORY_SEPARATOR;
if (!is_dir($finalDir) && !mkdir($finalDir, 0755, true) && !is_dir($finalDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Secure KYC folder is unavailable.']);
    exit;
}
$fileName = 'video_kyc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
$target = $finalDir . $fileName;
$output = fopen($target, 'xb');
if (!$output) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not create the final video file.']);
    exit;
}

$size = 0;
$parts = [];
try {
    for ($i = 0; $i < $total; $i++) {
        $part = $prefix . str_pad((string)$i, 4, '0', STR_PAD_LEFT) . '.part';
        if (!is_file($part)) throw new InvalidArgumentException('Upload part ' . ($i + 1) . ' is missing.');
        $partSize = (int)filesize($part);
        $size += $partSize;
        if ($size > 50 * 1024 * 1024) throw new InvalidArgumentException('Video exceeds the 50MB limit.');
        $input = fopen($part, 'rb');
        if (!$input) throw new InvalidArgumentException('Could not read upload part ' . ($i + 1) . '.');
        stream_copy_to_stream($input, $output);
        fclose($input);
        $parts[] = $part;
    }
    fclose($output);
    $output = null;
    if ($size < 1) throw new InvalidArgumentException('Uploaded video is empty.');

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $target);
            finfo_close($finfo);
        }
    }
    $videoMimes = ['video/mp4', 'video/webm', 'video/quicktime', 'application/octet-stream'];
    if ($mime !== '' && !in_array($mime, $videoMimes, true)) {
        throw new InvalidArgumentException('Selected file is not a supported video.');
    }

    getDB()->prepare('INSERT INTO kyc_documents (merchant_id, doc_type, file_name, file_path) VALUES (?,?,?,?)')
        ->execute([$merchantId, 'video_kyc', $fileName, $target]);
    try {
        getDB()->prepare("UPDATE merchants SET video_kyc_status='submitted' WHERE id=?")->execute([$merchantId]);
    } catch (Throwable $e) {
        logPlatformError('warning', 'Video KYC status update failed: ' . $e->getMessage(), ['merchant_id' => $merchantId]);
    }
    foreach ($parts as $part) @unlink($part);
    notifyAdminKycDocumentUploaded($merchantId, 'video_kyc');
    flash('success', 'Video KYC uploaded successfully. Review usually completes within 48 hours.');
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if (is_resource($output)) fclose($output);
    @unlink($target);
    foreach (glob($prefix . '*.part') ?: [] as $part) @unlink($part);
    if (!($e instanceof InvalidArgumentException)) {
        logPlatformError('error', 'Chunked Video KYC upload failed: ' . $e->getMessage(), ['merchant_id' => $merchantId]);
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
