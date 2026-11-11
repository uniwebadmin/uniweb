<?php
declare(strict_types=1);

/**
 * Check if a doc type is an Aadhaar document.
 */
function isAadhaarDocType(string $docType): bool
{
    $canonical = strtolower(trim($docType));
    return in_array($canonical, ['aadhaar', 'aadhaar_card', 'aadhar'], true);
}

/**
 * Do not claim a document is masked until digit-level detection is available.
 *
 * Fixed-position black bars cannot reliably locate Aadhaar digits across
 * document layouts, scans, rotations, and image quality. Aadhaar uploads are
 * rejected by the upload handler until a verified redaction pipeline exists.
 *
 * @return array{masked: bool, method: string}
 */
function processAadhaarMask(string $filePath, string $docType, string $extension): array
{
    if (!isAadhaarDocType($docType)) {
        return ['masked' => false, 'method' => 'not_aadhaar'];
    }

    return ['masked' => false, 'method' => 'digit_detection_unavailable'];
}
