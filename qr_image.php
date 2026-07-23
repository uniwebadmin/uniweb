<?php
require_once __DIR__ . '/config.php';

$data = $_GET['d'] ?? '';
if ($data === '') {
    http_response_code(400);
    exit('Missing data');
}
$pad = str_repeat('=', (4 - strlen($data) % 4) % 4);
$decoded = base64_decode(strtr($data, '-_', '+/') . $pad, true);
if ($decoded === false) {
    $decoded = $data;
}
$size = max(80, min(512, (int)($_GET['s'] ?? 200)));
$wantLogo = ($_GET['logo'] ?? '1') !== '0' && $size >= 120;

$lib = __DIR__ . '/includes/phpqrcode/qrlib.php';
if (is_file($lib)) {
    require_once $lib;
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    // High error-correction (H, ~30% recoverable) so a center logo cutout stays scannable.
    // @-suppress: the vendored phpqrcode lib throws PHP 8.1+ "implicit float->int
    // conversion" deprecations. With display_errors on, those get echoed straight
    // into this same output buffer and corrupt the PNG bytes (imagecreatefromstring
    // then fails on the mixed HTML+binary blob). Not our code to fix; suppress it.
    if ($wantLogo && function_exists('imagecreatetruecolor')) {
        ob_start();
        @QRcode::png($decoded, false, QR_ECLEVEL_H, max(2, (int)round($size / 50)), 2, false);
        $png = ob_get_clean();
        $img = $png ? imagecreatefromstring($png) : false;
        if ($img) {
            echo imageQrWithBrandLogo($img, $size);
            exit;
        }
        if ($png) { echo $png; exit; }
    }
    @QRcode::png($decoded, false, QR_ECLEVEL_H, max(2, (int)round($size / 50)), 2, false);
    exit;
}

require_once __DIR__ . '/includes/qr_svg.php';
header('Content-Type: image/svg+xml');
header('Cache-Control: public, max-age=86400');
echo qrSvg($decoded, $size);

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
