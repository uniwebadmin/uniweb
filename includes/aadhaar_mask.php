<?php
declare(strict_types=1);

/**
 * Aadhaar Auto-Mask Pipeline
 *
 * Per UIDAI compliance: first 8 digits of Aadhaar number must be blacked out
 * before storing. Only last 4 digits may be visible for ops.
 *
 * This module provides image-level masking using PHP GD. When an Aadhaar
 * document image is uploaded, black bars are drawn over the typical positions
 * where the 12-digit Aadhaar number appears on the card (top front and bottom).
 * The original unmasked file is discarded.
 *
 * When OCR partner (Karza/Signzy/Digio) is integrated, precise digit-level
 * masking can replace this positional approach.
 */

/**
 * Check if a doc type is an Aadhaar document.
 */
function isAadhaarDocType(string $docType): bool
{
    $canonical = strtolower(trim($docType));
    return in_array($canonical, ['aadhaar', 'aadhaar_card', 'aadhar'], true);
}

/**
 * Check if a file is an image (GD-processable).
 */
function isMaskableImage(string $filePath): bool
{
    if (!function_exists('getimagesize')) {
        return false;
    }
    $info = @getimagesize($filePath);
    if ($info === false) {
        return false;
    }
    return in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_BMP, IMAGETYPE_WEBP], true);
}

/**
 * Mask an Aadhaar card image: black out areas where the Aadhaar number
 * typically appears. Returns true if masking was applied, false if skipped.
 *
 * The Aadhaar card layout (standard UIDAI format):
 * - Top area (~8-18% from top): "Government of India" + first Aadhaar number
 * - Bottom area (~85-95% from top): Second Aadhaar number line
 * - We black out both zones to ensure the number is not readable.
 */
function maskAadhaarImage(string $filePath): bool
{
    if (!isMaskableImage($filePath)) {
        return false;
    }

    $info = @getimagesize($filePath);
    if ($info === false) {
        return false;
    }
    $width = $info[0];
    $height = $info[1];
    $mimeType = $info['mime'] ?? '';

    $image = null;
    switch ($info[2]) {
        case IMAGETYPE_JPEG:
            $image = @imagecreatefromjpeg($filePath);
            break;
        case IMAGETYPE_PNG:
            $image = @imagecreatefrompng($filePath);
            break;
        case IMAGETYPE_GIF:
            $image = @imagecreatefromgif($filePath);
            break;
        case IMAGETYPE_BMP:
            if (function_exists('imagecreatefrombmp')) {
                $image = @imagecreatefrombmp($filePath);
            }
            break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagecreatefromwebp')) {
                $image = @imagecreatefromwebp($filePath);
            }
            break;
    }

    if ($image === false || $image === null) {
        return false;
    }

    $black = imagecolorallocate($image, 0, 0, 0);
    if ($black === false) {
        imagedestroy($image);
        return false;
    }

    $barHeightTop = (int)max(20, $height * 0.12);
    $barTopY = (int)($height * 0.06);
    imagefilledrectangle($image, 0, $barTopY, $width, $barTopY + $barHeightTop, $black);

    $barHeightBottom = (int)max(15, $height * 0.08);
    $barBottomY = (int)($height * 0.86);
    imagefilledrectangle($image, 0, $barBottomY, $width, $barBottomY + $barHeightBottom, $black);

    $textColor = imagecolorallocate($image, 80, 80, 80);
    if ($textColor !== false) {
        $stampText = 'AADHAAR MASKED';
        $fontSize = 3;
        $textWidth = imagefontwidth($fontSize) * strlen($stampText);
        $textHeight = imagefontheight($fontSize);
        $x = (int)(($width - $textWidth) / 2);
        $y = (int)($height * 0.50);
        imagestring($image, $fontSize, $x, $y, $stampText, $textColor);
    }

    $saved = false;
    switch ($info[2]) {
        case IMAGETYPE_JPEG:
            $saved = imagejpeg($image, $filePath, 90);
            break;
        case IMAGETYPE_PNG:
            $saved = imagepng($image, $filePath, 6);
            break;
        case IMAGETYPE_GIF:
            $saved = imagegif($image, $filePath);
            break;
        case IMAGETYPE_BMP:
            if (function_exists('imagebmp')) {
                $saved = imagebmp($image, $filePath);
            }
            break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagewebp')) {
                $saved = imagewebp($image, $filePath, 90);
            }
            break;
    }

    imagedestroy($image);
    return $saved === true;
}

/**
 * Process an uploaded Aadhaar document: mask the image then return status.
 * For PDF files, masking is deferred to OCR partner integration.
 *
 * @return array{masked: bool, method: string}
 */
function processAadhaarMask(string $filePath, string $docType, string $extension): array
{
    if (!isAadhaarDocType($docType)) {
        return ['masked' => false, 'method' => 'not_aadhaar'];
    }

    $ext = strtolower($extension);
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'], true)) {
        $masked = maskAadhaarImage($filePath);
        return ['masked' => $masked, 'method' => $masked ? 'gd_positional' : 'gd_failed'];
    }

    if ($ext === 'pdf') {
        return ['masked' => false, 'method' => 'pdf_deferred'];
    }

    return ['masked' => false, 'method' => 'unsupported_type'];
}
