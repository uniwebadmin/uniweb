<?php
declare(strict_types=1);

function qrSvg(string $text, int $size = 200): string
{
    $lib = __DIR__ . '/phpqrcode/qrlib.php';
    if (!is_file($lib)) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"><text x="10" y="20" fill="#666" font-size="12">QR unavailable</text></svg>';
    }
    require_once $lib;
    if (!class_exists('QRcode', false)) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"><text x="10" y="20" fill="#666" font-size="12">QR library missing</text></svg>';
    }
    ob_start();
    // @-suppress: vendored phpqrcode's PHP 8.1+ deprecations would otherwise leak
    // into this output buffer and corrupt the captured PNG (see qr_image.php).
    @QRcode::png($text, false, QR_ECLEVEL_L, max(1, (int)round($size / 40)), 2, false);
    $png = ob_get_clean();
    if (!$png) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"></svg>';
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"><image href="data:image/png;base64,' . base64_encode($png) . '" width="' . $size . '" height="' . $size . '"/></svg>';
}

function qrImageUrl(string $text, int $size = 200): string
{
    return APP_URL . '/qr_image.php?d=' . rtrim(strtr(base64_encode($text), '+/', '-_'), '=') . '&s=' . $size;
}
