<?php
require_once __DIR__ . '/config.php';
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
// Defense in depth: ensure qr_svg helpers exist if live config.php omitted 'qr_svg'.
if (!function_exists('qrImageUrl')) {
    require_once __DIR__ . '/includes/qr_svg.php';
}

function qrFlushAllBuffers(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function qrUpiPaFromPayload(string $payload): string
{
    if (!str_starts_with(strtolower($payload), 'upi://pay')) {
        return '';
    }
    $query = parse_url($payload, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return '';
    }
    $params = [];
    parse_str($query, $params);
    return trim((string)($params['pa'] ?? ''));
}

function qrEmitMessagePng(string $message, int $size, string $hint = ''): void
{
    qrFlushAllBuffers();
    if (!headers_sent()) {
        header('Content-Type: image/png');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    $size = max(160, min(512, $size));
    if (!function_exists('imagecreatetruecolor')) {
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        exit;
    }
    $img = imagecreatetruecolor($size, $size);
    $bg = imagecolorallocate($img, 255, 255, 255);
    $fg = imagecolorallocate($img, 185, 28, 28);
    $muted = imagecolorallocate($img, 107, 114, 128);
    imagefilledrectangle($img, 0, 0, $size, $size, $bg);
    $font = 5;
    $tw = imagefontwidth($font) * strlen($message);
    $th = imagefontheight($font);
    $y = (int)(($size - $th) / 2);
    if ($hint !== '') {
        $y -= 8;
    }
    imagestring($img, $font, (int)max(8, ($size - $tw) / 2), $y, $message, $fg);
    if ($hint !== '') {
        $hw = imagefontwidth(3) * strlen($hint);
        imagestring($img, 3, (int)max(8, ($size - $hw) / 2), $y + $th + 6, $hint, $muted);
    }
    imagepng($img);
    imagedestroy($img);
    exit;
}

function qrEmitPngBytes(string $png): void
{
    qrFlushAllBuffers();
    if (!headers_sent()) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
    }
    echo $png;
    exit;
}

$data = $_GET['d'] ?? '';
if ($data === '') {
    qrEmitMessagePng('QR unavailable', (int)($_GET['s'] ?? 200));
}
$pad = str_repeat('=', (4 - strlen($data) % 4) % 4);
$decoded = base64_decode(strtr($data, '-_', '+/') . $pad, true);
if ($decoded === false) {
    $decoded = $data;
}
$size = max(80, min(512, (int)($_GET['s'] ?? 200)));
$wantLogo = ($_GET['logo'] ?? '1') !== '0' && $size >= 120;
$requireUpi = ($_GET['require_upi'] ?? '') === '1';
$isUpiPayload = str_starts_with(strtolower($decoded), 'upi://pay');
if ($isUpiPayload && qrUpiPaFromPayload($decoded) === '') {
    qrEmitMessagePng('UPI ID missing', $size, 'Add UPI ID first');
}
if ($requireUpi && !$isUpiPayload) {
    qrEmitMessagePng('UPI ID missing', $size, 'Add UPI ID first');
}

$lib = __DIR__ . '/includes/phpqrcode/qrlib.php';
if (is_file($lib)) {
    require_once $lib;
}
if (class_exists('QRcode', false)) {
    // High error-correction (H, ~30% recoverable) so a center logo cutout stays scannable.
    // @-suppress: the vendored phpqrcode lib throws PHP 8.1+ "implicit float->int
    // conversion" deprecations. With display_errors on, those get echoed straight
    // into this same output buffer and corrupt the PNG bytes (imagecreatefromstring
    // then fails on the mixed HTML+binary blob). Not our code to fix; suppress it.
    if ($wantLogo && function_exists('imagecreatetruecolor')) {
        ob_start();
        @QRcode::png($decoded, false, QR_ECLEVEL_H, max(2, (int)round($size / 50)), 2, false);
        $png = (string)ob_get_clean();
        $img = $png !== '' ? @imagecreatefromstring($png) : false;
        if ($img) {
            qrEmitPngBytes(imageQrWithBrandLogo($img, $size));
        }
        if ($png !== '') {
            qrEmitPngBytes($png);
        }
    }
    ob_start();
    @QRcode::png($decoded, false, QR_ECLEVEL_H, max(2, (int)round($size / 50)), 2, false);
    $png = (string)ob_get_clean();
    if ($png !== '') {
        qrEmitPngBytes($png);
    }
    qrEmitMessagePng('QR unavailable', $size);
}

require_once __DIR__ . '/includes/qr_svg.php';
qrFlushAllBuffers();
header('Content-Type: image/svg+xml');
header('Cache-Control: public, max-age=86400');
echo qrSvg($decoded, $size);
exit;

/**
 * Bake the UniWeb brand mark into the QR center (Razorpay-style): a rounded white
 * pad behind the real logo asset, sized ~20% of the QR so ECC-H keeps it scannable.
 * Falls back to an emerald "UW" mark if the brand image is missing.
 */
function imageQrWithBrandLogo($img, int $displaySize): string
{
    $w = imagesx($img);
    $h = imagesy($img);
    $cx = (int)round($w / 2);
    $cy = (int)round($h / 2);

    $logoBox = (int)round(min($w, $h) * 0.20);
    $pad = (int)round($logoBox * 1.34);

    $white = imagecolorallocate($img, 255, 255, 255);
    imagefilledellipse($img, $cx, $cy, $pad, $pad, $white);

    $logoPath = __DIR__ . '/assets/icons/icon-192.png';
    $logo = is_file($logoPath) ? @imagecreatefrompng($logoPath) : false;
    if ($logo) {
        imagealphablending($img, true);
        imagecopyresampled(
            $img, $logo,
            $cx - (int)round($logoBox / 2), $cy - (int)round($logoBox / 2),
            0, 0,
            $logoBox, $logoBox,
            imagesx($logo), imagesy($logo)
        );
        imagedestroy($logo);
    } else {
        $emerald = imagecolorallocate($img, 5, 150, 105);
        $emeraldDark = imagecolorallocate($img, 4, 120, 87);
        $innerR = (int)round($logoBox * 0.5);
        imagefilledrectangle($img, $cx - $innerR, $cy - $innerR, $cx + $innerR, $cy + $innerR, $emerald);
        imagerectangle($img, $cx - $innerR, $cy - $innerR, $cx + $innerR, $cy + $innerR, $emeraldDark);
        $text = 'UW';
        $tw = imagefontwidth(5) * strlen($text);
        $th = imagefontheight(5);
        imagestring($img, 5, $cx - (int)round($tw / 2), $cy - (int)round($th / 2), $text, $white);
    }

    ob_start();
    imagepng($img);
    return (string)ob_get_clean();
}
