<?php
declare(strict_types=1);

function kycUploadErrorText(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The selected file is larger than the server upload limit.',
        UPLOAD_ERR_PARTIAL => 'The file uploaded only partially. Please retry on a stable connection.',
        UPLOAD_ERR_NO_FILE => 'Please choose a file before pressing Upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server upload folder is unavailable. Please contact support.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not save the file. Please contact support.',
        UPLOAD_ERR_EXTENSION => 'The server blocked this file type.',
        default => 'The upload could not be completed.',
    };
}

/**
 * Validate, move and register one KYC file. If the DB insert fails, remove the
 * moved file so storage and database cannot drift apart.
 */
function saveMerchantKycUpload(
    int $merchantId,
    string $docType,
    array $file,
    array $allowedExtensions,
    int $maxBytes,
    ?array $geo = null
): array {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => kycUploadErrorText($error)];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'The selected file was not received securely. Please choose it again.'];
    }
    if ($size < 1) {
        return ['ok' => false, 'error' => 'The selected file is empty.'];
    }
    if ($size > $maxBytes) {
        return ['ok' => false, 'error' => 'File is too large. Maximum allowed is ' . (int)floor($maxBytes / 1048576) . 'MB.'];
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions, true)) {
        return ['ok' => false, 'error' => 'Unsupported file type. Allowed: ' . strtoupper(implode(', ', $allowedExtensions)) . '.'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $mimeByExtension = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'pdf' => ['application/pdf'],
        'mp4' => ['video/mp4', 'application/mp4'],
        'webm' => ['video/webm'],
        'mov' => ['video/quicktime'],
    ];
    if ($mime === '' || !in_array($mime, $mimeByExtension[$ext] ?? [], true)) {
        return ['ok' => false, 'error' => 'File content does not match the selected file type.'];
    }
    $prefix = (string)file_get_contents($tmp, false, null, 0, min($size, 1048576));
    if (stripos($prefix, '<?php') !== false || stripos($prefix, '<script') !== false) {
        return ['ok' => false, 'error' => 'The file contains prohibited executable content.'];
    }

    $dir = rtrim(KYC_PRIVATE_DIR, '/\\') . DIRECTORY_SEPARATOR . $merchantId . DIRECTORY_SEPARATOR;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Server could not create your secure upload folder. Please contact support.'];
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0700);
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'error' => 'Secure upload folder is not writable. Please contact support.'];
    }

    $safeType = preg_replace('/[^a-z0-9_]/i', '', $docType) ?: 'document';
    $fileName = $safeType . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $dir . $fileName;
    if (!move_uploaded_file($tmp, $target)) {
        return ['ok' => false, 'error' => 'Server could not store the uploaded file. Please retry.'];
    }
    @chmod($target, 0600);
    $sha256 = hash_file('sha256', $target);
    $scanStatus = scanKycFileForMalware($target, $sha256);
    if ($scanStatus === 'infected') {
        @unlink($target);
        return ['ok' => false, 'error' => 'The file failed security scanning and was rejected.'];
    }
    $storageKey = $merchantId . '/' . $fileName;

    try {
        $realIp = function_exists('getRealClientIp') ? getRealClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $userAgent = function_exists('getClientUserAgent') ? getClientUserAgent() : '';
        $geoLat = $geo['lat'] ?? null;
        $geoLng = $geo['lng'] ?? null;
        $geoAcc = $geo['accuracy_m'] ?? null;
        $geoSrc = $geo['geo_source'] ?? null;

        getDB()->prepare(
            'INSERT INTO kyc_documents
             (merchant_id,doc_type,file_name,file_path,storage_key,sha256,mime_type,file_size,scan_status,ip_address,client_ip,user_agent,lat,lng,geo_accuracy_m,geo_source,retention_until)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(CURDATE(),INTERVAL 8 YEAR))'
        )->execute([$merchantId, $docType, $fileName, $target, $storageKey, $sha256, $mime, $size, $scanStatus, $realIp, $realIp, $userAgent, $geoLat, $geoLng, $geoAcc, $geoSrc]);
    } catch (Throwable $e) {
        @unlink($target);
        logPlatformError('error', 'KYC database insert failed: ' . $e->getMessage(), [
            'merchant_id' => $merchantId,
            'doc_type' => $docType,
        ]);
        return ['ok' => false, 'error' => 'File reached the server but could not be registered. Support has been notified.'];
    }

    if (!function_exists('bootstrapMerchantMethodAutomation')) {
        $mr = __DIR__ . '/method_requests.php';
        if (is_file($mr)) {
            require_once $mr;
        }
    }
    if (function_exists('bootstrapMerchantMethodAutomation')) {
        try {
            bootstrapMerchantMethodAutomation($merchantId, 'Auto-queued after KYC document upload');
        } catch (Throwable $e) {
            error_log('KYC bootstrap methods: ' . $e->getMessage());
        }
    }

    return ['ok' => true, 'file_name' => $fileName, 'storage_key' => $storageKey, 'scan_status' => $scanStatus];
}

function scanKycFileForMalware(string $path, string $sha256): string
{
    $scanUrl = trim((string)getenv('UNIWEB_MALWARE_SCAN_URL'));
    if ($scanUrl === '') {
        // Local MIME/magic checks already passed; external scanner optional.
        return 'clean';
    }
    $parts = parse_url($scanUrl);
    if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
        return 'pending';
    }
    $ch = curl_init($scanUrl);
    $post = [
        'sha256' => $sha256,
        'file' => new CURLFile($path, 'application/octet-stream', basename($path)),
    ];
    $headers = ['Accept: application/json'];
    $token = trim((string)getenv('UNIWEB_MALWARE_SCAN_TOKEN'));
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $body = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) {
        return 'pending';
    }
    $result = json_decode($body, true);
    return in_array($result['status'] ?? '', ['clean', 'infected'], true) ? $result['status'] : 'pending';
}

function processPendingKycScans(int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $rows = getDB()->query(
        "SELECT id,file_path,sha256 FROM kyc_documents WHERE scan_status='pending' ORDER BY id ASC LIMIT {$limit}"
    )->fetchAll();
    $clean = 0;
    $infected = 0;
    foreach ($rows as $row) {
        $path = (string)$row['file_path'];
        if (!is_file($path)) {
            getDB()->prepare("UPDATE kyc_documents SET scan_status='missing',status='rejected' WHERE id=?")->execute([(int)$row['id']]);
            continue;
        }
        $sha = (string)($row['sha256'] ?: hash_file('sha256', $path));
        $status = scanKycFileForMalware($path, $sha);
        if ($status === 'clean') {
            getDB()->prepare("UPDATE kyc_documents SET scan_status='clean',sha256=? WHERE id=?")->execute([$sha, (int)$row['id']]);
            $clean++;
        } elseif ($status === 'infected') {
            @unlink($path);
            getDB()->prepare("UPDATE kyc_documents SET scan_status='infected',status='rejected',file_path='' WHERE id=?")->execute([(int)$row['id']]);
            $infected++;
        }
    }
    return ['checked' => count($rows), 'clean' => $clean, 'infected' => $infected];
}
