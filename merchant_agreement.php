<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';
requireLogin();
ensureMerchantAgreementSchema();

$merchant = getMerchant();
$merchantId = (int)($merchant['id'] ?? 0);
$canAccept = ($merchant['kyc_status'] ?? '') === 'verified';
$version = merchantAgreementVersion();
$sections = merchantAgreementSections();
$documentHash = hash('sha256', json_encode($sections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Your session token expired. Please review and submit again.');
        redirect('merchant_agreement.php');
    }
    if (empty($_POST['accept_agreement']) || empty($_POST['authority_confirmed'])) {
        flash('error', 'Both confirmations are required to accept the agreement.');
        redirect('merchant_agreement.php');
    }
    if (!$canAccept) {
        flash('error', 'Agreement acceptance becomes available after KYC verification.');
        redirect('merchant_agreement.php');
    }
    $legalName = trim((string)($merchant['business_name'] ?? $merchant['name'] ?? ''));
    $ip = substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    if (str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]);
    }
    $stmt = $db->prepare("INSERT IGNORE INTO merchant_agreement_acceptances
        (merchant_id, agreement_version, legal_name, merchant_code, document_hash, accepted_ip, user_agent, accepted_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $merchantId,
        $version,
        $legalName,
        (string)($merchant['merchant_code'] ?? ''),
        $documentHash,
        $ip,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ]);
    flash('success', 'Merchant Agreement accepted and recorded.');
    redirect('merchant_agreement.php');
}

$stmt = $db->prepare('SELECT * FROM merchant_agreement_acceptances WHERE merchant_id=? AND agreement_version=? LIMIT 1');
$stmt->execute([$merchantId, $version]);
$acceptance = $stmt->fetch();

ob_start();
?>
<section class="public-doc-company" style="margin-top:30px">
    <?php if ($acceptance): ?>
    <div class="company-kicker">Acceptance recorded</div>
    <h2>Agreement version <?= e($version) ?> is active</h2>
    <p>Accepted for <strong><?= e($acceptance['legal_name']) ?></strong> on <?= e(date('d M Y, h:i:s A', strtotime($acceptance['accepted_at']))) ?> IST. Audit reference: <strong>UWA-<?= (int)$acceptance['id'] ?></strong>.</p>
    <p>This record remains available for compliance review. A materially updated agreement will require fresh acceptance.</p>
    <?php elseif (!$canAccept): ?>
    <div class="company-kicker">Available after KYC</div>
    <h2>Agreement acceptance is not open yet</h2>
    <p>You may review the complete agreement now. The electronic acceptance action will be enabled after the merchant KYC status is Verified.</p>
    <a href="kyc.php" class="btn-primary inline-block px-6 py-3 mt-4">Open KYC verification</a>
    <?php else: ?>
    <div class="company-kicker">Electronic signature</div>
    <h2>Accept on behalf of <?= e($merchant['business_name'] ?? $merchant['name'] ?? 'your business') ?></h2>
    <p>Read every section above before accepting. Acceptance records the current agreement version, merchant identity, timestamp, IP address and document fingerprint.</p>
    <form method="POST" class="space-y-4 mt-5">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="accept_agreement" value="1" required class="mt-1"><span>I have read and agree to the Merchant Services Agreement, Terms and Privacy Policy.</span></label>
        <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="authority_confirmed" value="1" required class="mt-1"><span>I confirm that I am authorized to accept this Agreement for the registered merchant entity.</span></label>
        <button type="submit" class="btn-primary px-6 py-3">Accept and record agreement</button>
    </form>
    <?php endif; ?>
</section>
<?php
$acceptanceBlock = ob_get_clean();
$pageTitle = 'Merchant Agreement';
require_once __DIR__ . '/header.php';
renderPublicLegalPage([
    'eyebrow' => 'Authenticated Merchant Contract',
    'title' => 'Merchant Services Agreement',
    'summary' => 'Private acceptance copy for the merchant currently signed in to UniWeb.',
    'effective' => '19 July 2026',
    'version' => $version,
    'notice' => $acceptance
        ? '<strong>Status: accepted.</strong> Your acceptance for this agreement version is recorded.'
        : ($canAccept
            ? '<strong>Action required:</strong> Review the complete agreement and use the acceptance section at the end to create the merchant audit record.'
            : '<strong>Review copy:</strong> Acceptance will be enabled after KYC verification.'),
    'sections' => $sections,
    'after' => $acceptanceBlock,
]);
require_once __DIR__ . '/footer.php';
