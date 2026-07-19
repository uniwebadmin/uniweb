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
    int $maxBytes
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

    $dir = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'kyc' . DIRECTORY_SEPARATOR . $merchantId . DIRECTORY_SEPARATOR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Server could not create your secure upload folder. Please contact support.'];
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0755);
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

    try {
        getDB()->prepare('INSERT INTO kyc_documents (merchant_id, doc_type, file_name, file_path) VALUES (?,?,?,?)')
            ->execute([$merchantId, $docType, $fileName, $target]);
    } catch (Throwable $e) {
        @unlink($target);
        logPlatformError('error', 'KYC database insert failed: ' . $e->getMessage(), [
            'merchant_id' => $merchantId,
            'doc_type' => $docType,
        ]);
        return ['ok' => false, 'error' => 'File reached the server but could not be registered. Support has been notified.'];
    }

    return ['ok' => true, 'file_name' => $fileName, 'file_path' => $target];
}
