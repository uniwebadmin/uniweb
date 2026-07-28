<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/qr_events.php';
ensureMerchantQrCodes();

/**
 * Tracking landing page for the "Instant UPI" QR (qr_upi_print.php).
 *
 * The printed QR encodes THIS url (not the raw upi://pay intent) so every scan
 * gets logged before the customer is bounced into their UPI app. The payment
 * itself still goes straight to the merchant's bank UPI — it is never routed
 * through UniWeb checkout/settlement.
 */
function renderUpiRedirectUnavailable(string $heading, string $detail, int $status = 404): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Cache-Control: no-store');
    }
    $pageTitle = $heading;
    $hideNav = true;
    $footerVariant = 'checkout';
    require_once __DIR__ . '/header.php';
    echo '<div class="min-h-screen flex items-center justify-center px-4 py-12"><div class="w-full max-w-md">'
        . '<div class="glass rounded-2xl p-8 text-center">'
        . '<h1 class="text-xl font-semibold mb-2">' . e($heading) . '</h1>'
        . '<p class="text-sm text-gray-400 mb-6">' . e($detail) . '</p>'
        . '<a href="index.php" class="inline-block btn-primary px-5 py-2.5 text-sm">Go to UniWeb</a>'
        . '</div></div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '' || !preg_match('/^QR[A-F0-9]{16}$/', $code)) {
    renderUpiRedirectUnavailable(
        'QR code not found',
        'This QR does not point to a valid UniWeb code. Please rescan the merchant QR.'
    );
}

$db = getDB();
$stmt = $db->prepare("SELECT q.id, q.qr_code, q.merchant_id, q.status,
        m.business_name, m.status AS merchant_status, m.upi_id, m.collection_mode, m.axis_va_upi
    FROM merchant_qr_codes q
    JOIN merchants m ON m.id = q.merchant_id
    WHERE q.qr_code = ? AND q.qr_type = 'instant_upi' LIMIT 1");
$stmt->execute([$code]);
$qr = $stmt->fetch();

if (!$qr || $qr['status'] !== 'active' || $qr['merchant_status'] !== 'active') {
    renderUpiRedirectUnavailable(
        'This QR code is inactive',
        'Please ask the merchant for a current QR code to complete your payment.',
        410
    );
}

$vpa = trim((string)($qr['upi_id'] ?? ''));
if ((string)($qr['collection_mode'] ?? '') === 'axis_va' && !empty($qr['axis_va_upi'])) {
    $vpa = trim((string)$qr['axis_va_upi']);
}
if ($vpa === '' || !str_contains($vpa, '@')) {
    renderUpiRedirectUnavailable(
        'UPI ID not available',
        'This merchant has not set up a UPI ID yet. Please ask them for another way to pay.',
        503
    );
}

$note = trim((string)($_GET['note'] ?? ''));
if (mb_strlen($note) > 60) {
    $note = mb_substr($note, 0, 60);
}

$db->prepare('UPDATE merchant_qr_codes SET scan_count = scan_count + 1 WHERE id = ?')->execute([(int)$qr['id']]);
logQrEvent($db, (int)$qr['id'], (int)$qr['merchant_id'], 'scan', [
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
]);

$businessName = trim((string)($qr['business_name'] ?? '')) ?: 'Merchant';
$intent = buildUpiPayIntent($vpa, $businessName, null, $note);

$pageTitle = 'Opening UPI App…';
$hideNav = true;
$footerVariant = 'checkout';
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md text-center">
        <?php $logoHref = 'index.php'; $logoSize = 'lg'; require __DIR__ . '/includes/brand_logo.php'; ?>
        <div class="glass rounded-2xl p-8 mt-5">
            <h1 class="text-lg font-semibold">Opening your UPI app…</h1>
            <p class="text-sm text-gray-400 mt-2">Pay <span class="text-gray-200 font-medium"><?= e($businessName) ?></span> directly via UPI.</p>
            <a href="<?= e($intent) ?>" id="upi-open-link" class="inline-block mt-6 w-full bg-brand-600 hover:bg-brand-500 text-white py-3.5 rounded-xl font-semibold">Open UPI App →</a>
            <p class="text-[11px] text-gray-600 mt-4">If nothing happens, tap the button above. GPay, PhonePe, Paytm &amp; BHIM all support this link.</p>
        </div>
    </div>
</div>
<script>
setTimeout(function () {
    window.location.href = <?= json_encode($intent) ?>;
}, 250);
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
