<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';
require_once __DIR__ . '/includes/agreement_pdf.php';
require_once __DIR__ . '/includes/esign.php';
require_once __DIR__ . '/includes/method_requests.php';
requireLogin();
ensureMerchantAgreementSchema();
ensureEsignTable();

$merchant = getMerchant();
$merchantId = (int)($merchant['id'] ?? 0);
$canAccept = ($merchant['kyc_status'] ?? '') === 'verified';
$version = merchantAgreementVersion();
$sections = merchantAgreementSections();
$documentHash = hash('sha256', json_encode($sections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$db = getDB();

$esignProvider = getEsignProvider();
$esignAction = $_GET['esign_action'] ?? '';
$esignRequestId = (int)($_GET['esign_request_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Your session token expired. Please review and submit again.');
        redirect('merchant_agreement.php');
    }

    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'initiate_esign') {
        if (!$canAccept) {
            flash('error', 'eSign available after KYC verification.');
            redirect('merchant_agreement.php');
        }
        $signerInfo = [
            'name' => trim($_POST['signer_name'] ?? ''),
            'aadhaar' => preg_replace('/\s+/', '', trim($_POST['signer_aadhaar'] ?? '')),
            'email' => trim($merchant['email'] ?? ''),
            'phone' => trim($merchant['phone'] ?? ''),
        ];
        if (strlen($signerInfo['name']) < 3) {
            flash('error', 'Signer name is required (minimum 3 characters).');
            redirect('merchant_agreement.php');
        }
        $res = initiateEsign($merchantId, $version, $documentHash, $signerInfo);
        if (!empty($res['ok'])) {
            flash('success', $res['message'] . ' eSign ID: ' . $res['esign_id']);
            redirect('merchant_agreement.php?esign_action=otp&esign_request_id=' . $res['request_id']);
        }
        flash('error', $res['error'] ?? 'eSign initiation failed.');
        redirect('merchant_agreement.php');
    }

    if ($postAction === 'verify_otp') {
        $otp = trim($_POST['otp'] ?? '');
        if (strlen($otp) < 4) {
            flash('error', 'Enter a valid OTP.');
            redirect('merchant_agreement.php?esign_action=otp&esign_request_id=' . (int)($_POST['esign_request_id'] ?? 0));
        }
        $res = verifyEsignOtp((int)($_POST['esign_request_id'] ?? 0), $otp);
        if (!empty($res['ok'])) {
            flash('success', $res['message']);
            redirect('merchant_agreement.php');
        }
        flash('error', $res['error'] ?? 'OTP verification failed.');
        redirect('merchant_agreement.php?esign_action=otp&esign_request_id=' . (int)($_POST['esign_request_id'] ?? 0));
    }

    if ($postAction === 'cancel_esign') {
        $res = cancelEsign((int)($_POST['esign_request_id'] ?? 0));
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Failed'));
        redirect('merchant_agreement.php');
    }

    if (empty($_POST['accept_agreement']) || empty($_POST['authority_confirmed'])) {
        flash('error', 'Both confirmations are required to accept the agreement.');
        redirect('merchant_agreement.php');
    }
    $signatureName = mb_substr(trim((string)($_POST['signature_name'] ?? '')), 0, 190);
    if ($signatureName === '' || mb_strlen($signatureName) < 3) {
        flash('error', 'Type your full legal name as electronic signature (minimum 3 characters).');
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
    $partnerNames = implode(', ', getMerchantApprovedPartners($merchantId));
    $geoLat = null;
    $geoLng = null;
    if (!empty($_POST['geo_consent'])) {
        $geoLat = (float)($_POST['geo_lat'] ?? 0) ?: null;
        $geoLng = (float)($_POST['geo_lng'] ?? 0) ?: null;
    }
    $stmt = $db->prepare("INSERT INTO merchant_agreement_acceptances
        (merchant_id, agreement_version, legal_name, merchant_code, document_hash, accepted_ip, user_agent, signature_name, partner_names, requires_resign, geo_lat, geo_lng, accepted_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
        legal_name=VALUES(legal_name), document_hash=VALUES(document_hash), accepted_ip=VALUES(accepted_ip),
        user_agent=VALUES(user_agent), signature_name=VALUES(signature_name), partner_names=VALUES(partner_names),
        requires_resign=0, geo_lat=VALUES(geo_lat), geo_lng=VALUES(geo_lng), accepted_at=NOW()");
    $stmt->execute([
        $merchantId,
        $version,
        $legalName,
        (string)($merchant['merchant_code'] ?? ''),
        $documentHash,
        $ip,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        $signatureName,
        $partnerNames,
        $geoLat,
        $geoLng,
    ]);
    $acceptanceId = (int)$db->lastInsertId();
    if ($acceptanceId <= 0) {
        $existing = $db->prepare('SELECT * FROM merchant_agreement_acceptances WHERE merchant_id=? AND agreement_version=? LIMIT 1');
        $existing->execute([$merchantId, $version]);
        $acceptance = $existing->fetch() ?: null;
        $acceptanceId = (int)($acceptance['id'] ?? 0);
    } else {
        $acceptance = [
            'id' => $acceptanceId,
            'agreement_version' => $version,
            'legal_name' => $legalName,
            'merchant_code' => (string)($merchant['merchant_code'] ?? ''),
            'signature_name' => $signatureName,
            'accepted_at' => date('Y-m-d H:i:s'),
            'accepted_ip' => $ip,
            'document_hash' => $documentHash,
            'partner_names' => $partnerNames,
            'geo_lat' => $geoLat,
            'geo_lng' => $geoLng,
        ];
    }
    if ($acceptance) {
        generateAndStoreMerchantAgreementPdf($merchant, $acceptance, $sections);
        emailMerchantAgreementAccepted($merchant, $acceptance);
        createNotification($merchantId, 'Agreement accepted', 'Merchant Services Agreement v' . $version . ' recorded. Download your PDF copy anytime.', 'agreement_accepted_' . $version);
    }
    flash('success', 'Merchant Agreement accepted. A PDF copy has been emailed to you.');
    redirect('merchant_agreement.php');
}

$stmt = $db->prepare('SELECT * FROM merchant_agreement_acceptances WHERE merchant_id=? AND agreement_version=? LIMIT 1');
$stmt->execute([$merchantId, $version]);
$acceptance = $stmt->fetch();

$esignRequests = getMerchantEsignRequests($merchantId, 5);
$approvedPartners = getMerchantApprovedPartners($merchantId);
$requiresResign = !empty($acceptance['requires_resign']);
$esignPending = null;
if ($esignAction === 'otp' && $esignRequestId > 0) {
    $esignPending = getEsignRequest($esignRequestId);
}

ob_start();
?>
<section class="public-doc-company" style="margin-top:30px">
    <?php if ($esignAction === 'otp' && $esignPending && in_array($esignPending['status'], ['initiated', 'otp_sent'], true)): ?>
    <div class="company-kicker">eSign — OTP Verification</div>
    <h2>Enter OTP to complete Aadhaar eSign</h2>
    <p>Provider: <strong><?= e($esignPending['provider']) ?></strong> | eSign ID: <strong><?= e($esignPending['esign_id']) ?></strong></p>
    <p class="text-sm text-gray-500">An OTP has been sent to the Aadhaar-linked mobile number.</p>
    <form method="POST" class="space-y-4 mt-5">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="verify_otp">
        <input type="hidden" name="esign_request_id" value="<?= (int)$esignPending['id'] ?>">
        <div>
            <label class="text-sm text-gray-400 block mb-1">Enter OTP *</label>
            <input type="text" name="otp" required pattern="[0-9]{4,8}" maxlength="8" class="input-field" placeholder="6-digit OTP" inputmode="numeric">
        </div>
        <div class="flex gap-3">
            <button type="submit" class="btn-primary px-6 py-3">Verify OTP & Sign</button>
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="cancel_esign">
                <input type="hidden" name="esign_request_id" value="<?= (int)$esignPending['id'] ?>">
                <button type="submit" class="px-6 py-3 text-sm text-red-400 border border-red-500/40 rounded-lg">Cancel</button>
            </form>
        </div>
    </form>

    <?php elseif ($acceptance && $requiresResign && $canAccept): ?>
    <div class="company-kicker text-amber-400">Re-signature required</div>
    <h2>Agreement needs re-signature — new partner added</h2>
    <p>Your previous acceptance (UWA-<?= (int)$acceptance['id'] ?>, <?= e(date('d M Y', strtotime($acceptance['accepted_at']))) ?>) is recorded but a new partner has been approved for your account.</p>
    <?php if (!empty($approvedPartners)): ?>
    <div class="bg-amber-500/5 border border-amber-500/20 rounded-lg p-4 my-4">
        <p class="text-sm text-amber-300 mb-2"><strong>Active partners on your account:</strong></p>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($approvedPartners as $p): ?>
            <span class="px-3 py-1 bg-amber-500/10 border border-amber-500/30 rounded-full text-xs text-amber-200"><?= e($p) ?></span>
            <?php endforeach; ?>
        </div>
        <p class="text-xs text-gray-500 mt-2">Previous signature covered: <?= e($acceptance['partner_names'] ?: 'none') ?></p>
    </div>
    <?php endif; ?>
    <p class="text-sm text-gray-400">Please re-sign the agreement below to include all active partners. Your previous signed PDF remains available.</p>
    <p class="mt-3"><a href="merchant_agreement_pdf.php?id=<?= (int)$acceptance['id'] ?>" class="text-sm text-sky-400 hover:underline">Download previous signed PDF →</a></p>

    <div class="mt-6 pt-4 border-t border-gray-800">
        <h3 class="text-sm font-bold text-gray-400 mb-2">Re-sign Agreement</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="initiate_esign">
            <div>
                <label class="text-sm text-gray-400 block mb-1">Signer Full Legal Name *</label>
                <input type="text" name="signer_name" required minlength="3" maxlength="190" class="input-field" placeholder="As on PAN / incorporation records" value="<?= e($merchant['business_name'] ?? $merchant['name'] ?? '') ?>">
            </div>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="accept_agreement" value="1" required class="mt-1"><span>I have read and agree to the Merchant Services Agreement with all active partners (<?= e(implode(', ', $approvedPartners)) ?>).</span></label>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="authority_confirmed" value="1" required class="mt-1"><span>I confirm that I am authorized to accept this Agreement for the registered merchant entity.</span></label>
            <button type="submit" class="btn-primary px-6 py-3">Re-sign Agreement</button>
        </form>
        <div class="mt-4 pt-3 border-t border-gray-700/50">
            <h4 class="text-xs text-gray-500 mb-2">Or use typed electronic signature:</h4>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="accept_agreement" value="1" required class="mt-1"><span>I have read and agree to the Merchant Services Agreement with all active partners.</span></label>
                <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="authority_confirmed" value="1" required class="mt-1"><span>I confirm that I am authorized to accept this Agreement.</span></label>
                <div>
                    <label class="text-sm text-gray-400 block mb-1">Electronic signature — type your full legal name *</label>
                    <input type="text" name="signature_name" required minlength="3" maxlength="190" class="input-field" placeholder="As on PAN / incorporation records" value="<?= e($merchant['business_name'] ?? $merchant['name'] ?? '') ?>">
                </div>
                <button type="submit" class="btn-primary px-6 py-3">Re-sign with typed signature</button>
            </form>
        </div>
    </div>

    <?php if (!empty($esignRequests)): ?>
    <div class="mt-6 pt-4 border-t border-gray-800">
        <h3 class="text-sm font-bold text-gray-400 mb-2">eSign History</h3>
        <table class="w-full text-sm">
            <thead class="text-gray-500"><tr>
                <th class="px-2 py-1 text-left">eSign ID</th>
                <th class="px-2 py-1 text-left">Provider</th>
                <th class="px-2 py-1 text-left">Status</th>
                <th class="px-2 py-1 text-left">Date</th>
            </tr></thead>
            <tbody>
                <?php foreach ($esignRequests as $er): ?>
                <tr class="border-t border-gray-800">
                    <td class="px-2 py-1 font-mono text-xs text-gray-400"><?= e($er['esign_id']) ?></td>
                    <td class="px-2 py-1 text-gray-400"><?= e($er['provider']) ?></td>
                    <td class="px-2 py-1">
                        <?php if ($er['status'] === 'signed'): ?><span class="text-emerald-400">Signed</span>
                        <?php elseif ($er['status'] === 'failed'): ?><span class="text-red-400">Failed</span>
                        <?php elseif ($er['status'] === 'cancelled'): ?><span class="text-gray-500">Cancelled</span>
                        <?php else: ?><span class="text-amber-400">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-2 py-1 text-gray-500 text-xs"><?= e($er['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php elseif ($acceptance): ?>
    <div class="company-kicker">Acceptance recorded</div>
    <h2>Agreement version <?= e($version) ?> is active</h2>
    <p>Accepted for <strong><?= e($acceptance['legal_name']) ?></strong> on <?= e(date('d M Y, h:i:s A', strtotime($acceptance['accepted_at']))) ?> IST. Audit reference: <strong>UWA-<?= (int)$acceptance['id'] ?></strong>.</p>
    <?php if (!empty($acceptance['accepted_ip'])): ?><p class="text-xs text-gray-500 mt-1">IP address: <span class="font-mono"><?= e($acceptance['accepted_ip']) ?></span></p><?php endif; ?>
    <?php if (!empty($acceptance['geo_lat']) && !empty($acceptance['geo_lng'])): ?><p class="text-xs text-gray-500 mt-1">Location: <span class="font-mono"><?= e($acceptance['geo_lat']) ?>, <?= e($acceptance['geo_lng']) ?></span> <a href="https://www.google.com/maps?q=<?= e($acceptance['geo_lat']) ?>,<?= e($acceptance['geo_lng']) ?>" target="_blank" class="text-sky-400 hover:underline">View on map</a></p><?php endif; ?>
    <?php if (!empty($acceptance['signature_name'])): ?><p class="text-sm text-gray-500">Electronic signature: <strong><?= e($acceptance['signature_name']) ?></strong></p><?php endif; ?>
    <?php if (!empty($acceptance['partner_names'])): ?><p class="text-sm text-gray-500">Partners covered: <strong><?= e($acceptance['partner_names']) ?></strong></p><?php endif; ?>
    <?php if (!empty($approvedPartners) && !empty($acceptance['partner_names'])): ?>
        <?php
        $signedPartners = array_filter(array_map('trim', explode(',', (string)$acceptance['partner_names'])));
        $newPartners = array_diff($approvedPartners, $signedPartners);
        if (!empty($newPartners)):
        ?>
        <div class="bg-amber-500/5 border border-amber-500/20 rounded-lg p-3 mt-3">
            <p class="text-sm text-amber-300">New partner(s) approved: <strong><?= e(implode(', ', $newPartners)) ?></strong> — re-signature may be required.</p>
        </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($acceptance['pdf_filename']) || !empty($acceptance['id'])): ?>
    <p class="mt-3"><a href="merchant_agreement_pdf.php?id=<?= (int)$acceptance['id'] ?>" class="btn-primary inline-block px-5 py-2.5 text-sm">Download signed PDF</a></p>
    <?php endif; ?>
    <p>This record remains available for compliance review. A materially updated agreement will require fresh acceptance.</p>

    <?php if (!empty($esignRequests)): ?>
    <div class="mt-6 pt-4 border-t border-gray-800">
        <h3 class="text-sm font-bold text-gray-400 mb-2">eSign History</h3>
        <table class="w-full text-sm">
            <thead class="text-gray-500"><tr>
                <th class="px-2 py-1 text-left">eSign ID</th>
                <th class="px-2 py-1 text-left">Provider</th>
                <th class="px-2 py-1 text-left">Status</th>
                <th class="px-2 py-1 text-left">Date</th>
            </tr></thead>
            <tbody>
                <?php foreach ($esignRequests as $er): ?>
                <tr class="border-t border-gray-800">
                    <td class="px-2 py-1 font-mono text-xs text-gray-400"><?= e($er['esign_id']) ?></td>
                    <td class="px-2 py-1 text-gray-400"><?= e($er['provider']) ?></td>
                    <td class="px-2 py-1">
                        <?php if ($er['status'] === 'signed'): ?><span class="text-emerald-400">Signed</span>
                        <?php elseif ($er['status'] === 'failed'): ?><span class="text-red-400">Failed</span>
                        <?php elseif ($er['status'] === 'cancelled'): ?><span class="text-gray-500">Cancelled</span>
                        <?php else: ?><span class="text-amber-400">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-2 py-1 text-gray-500 text-xs"><?= e($er['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php elseif (!$canAccept): ?>
    <div class="company-kicker">Available after KYC</div>
    <h2>Agreement acceptance is not open yet</h2>
    <p>You may review the complete agreement now. The electronic acceptance action will be enabled after the merchant KYC status is Verified.</p>
    <a href="kyc.php" class="btn-primary inline-block px-6 py-3 mt-4">Open KYC verification</a>
    <?php else: ?>
    <div class="company-kicker">Aadhaar eSign</div>
    <h2>Digitally sign via Aadhaar eSign</h2>
    <p>Sign your agreement using Aadhaar-based OTP authentication. Provider: <strong><?= e($esignProvider) ?></strong><?= $esignProvider === 'internal' ? ' (internal mode — typed signature with enhanced audit)' : '' ?></p>
    <form method="POST" class="space-y-4 mt-5" id="esign-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="initiate_esign">
        <input type="hidden" name="geo_lat" id="geo_lat" value="">
        <input type="hidden" name="geo_lng" id="geo_lng" value="">
        <div>
            <label class="text-sm text-gray-400 block mb-1">Signer Full Legal Name *</label>
            <input type="text" name="signer_name" required minlength="3" maxlength="190" class="input-field" placeholder="As on PAN / incorporation records" value="<?= e($merchant['business_name'] ?? $merchant['name'] ?? '') ?>">
        </div>
        <?php if ($esignProvider !== 'internal'): ?>
        <div>
            <label class="text-sm text-gray-400 block mb-1">Aadhaar Number (12-digit) *</label>
            <input type="text" name="signer_aadhaar" required pattern="[0-9]{12}" maxlength="14" class="input-field" placeholder="XXXX XXXX XXXX" inputmode="numeric">
            <p class="text-xs text-gray-600 mt-1">OTP will be sent to the mobile number linked with this Aadhaar.</p>
        </div>
        <?php endif; ?>
        <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="accept_agreement" value="1" required class="mt-1"><span>I have read and agree to the Merchant Services Agreement, Terms and Privacy Policy.</span></label>
        <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="authority_confirmed" value="1" required class="mt-1"><span>I confirm that I am authorized to accept this Agreement for the registered merchant entity.</span></label>
        <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="geo_consent" value="1" class="mt-1" id="geo-consent-esign"><span>I consent to capturing my location (lat/long) for audit stamp on this agreement. Optional — skip if you prefer.</span></label>
        <button type="submit" class="btn-primary px-6 py-3">Initiate Aadhaar eSign</button>
    </form>

    <div class="mt-6 pt-4 border-t border-gray-800">
        <h3 class="text-sm font-bold text-gray-400 mb-2">Or use typed electronic signature</h3>
        <form method="POST" class="space-y-4" id="typed-sig-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="geo_lat" id="geo_lat_typed" value="">
            <input type="hidden" name="geo_lng" id="geo_lng_typed" value="">
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="accept_agreement" value="1" required class="mt-1"><span>I have read and agree to the Merchant Services Agreement, Terms and Privacy Policy.</span></label>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="authority_confirmed" value="1" required class="mt-1"><span>I confirm that I am authorized to accept this Agreement for the registered merchant entity.</span></label>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="geo_consent" value="1" class="mt-1" id="geo-consent-typed"><span>I consent to capturing my location (lat/long) for audit stamp. Optional.</span></label>
            <div>
                <label class="text-sm text-gray-400 block mb-1">Electronic signature — type your full legal name *</label>
                <input type="text" name="signature_name" required minlength="3" maxlength="190" class="input-field" placeholder="As on PAN / incorporation records" value="<?= e($merchant['business_name'] ?? $merchant['name'] ?? '') ?>">
                <p class="text-xs text-gray-600 mt-1">This typed name, your IP address and timestamp are stored as your electronic acceptance record.</p>
            </div>
            <button type="submit" class="btn-primary px-6 py-3">Accept, sign and download PDF</button>
        </form>
    </div>
    <?php endif; ?>
</section>
<?php
$acceptanceBlock = ob_get_clean();
$pageTitle = 'Merchant Agreement';
require_once __DIR__ . '/header.php';
echo renderPrintStylesheet();
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
?>
<script>
document.querySelectorAll('input[name="geo_consent"]').forEach(function(cb) {
    cb.addEventListener('change', function() {
        if (!this.checked) return;
        var form = this.closest('form');
        var latField = form.querySelector('[name="geo_lat"]');
        var lngField = form.querySelector('[name="geo_lng"]');
        if (!latField || !lngField) return;
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(function(pos) {
            latField.value = pos.coords.latitude.toFixed(7);
            lngField.value = pos.coords.longitude.toFixed(7);
        }, function() {
            cb.checked = false;
            alert('Location access denied or unavailable. You can still sign without location.');
        }, { timeout: 10000, enableHighAccuracy: false });
    });
});
</script>
<?php
require_once __DIR__ . '/footer.php';
