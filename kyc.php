<?php
if (function_exists('opcache_invalidate')) { opcache_invalidate(__FILE__, true); }
require_once __DIR__ . '/config.php';
requireLogin();
ensureKycSchema();
require_once __DIR__ . '/includes/kyc_upload.php';
require_once __DIR__ . '/includes/client_context.php';
require_once __DIR__ . '/includes/kyc_verify.php';
require_once __DIR__ . '/includes/onboarding_state_machine.php';
require_once __DIR__ . '/includes/partner_forward_queue.php';
require_once __DIR__ . '/includes/partner_payload.php';
if (!function_exists('kycRejectionDisplay') && is_file(__DIR__ . '/includes/kyc_entity.php')) {
    require_once __DIR__ . '/includes/kyc_entity.php';
}
$merchant = getMerchant();
$db = getDB();

// D6: Get onboarding state and forward queue status for merchant visibility
$onboardingState = getMerchantOnboardingState((int)$merchant['id']);
$onboardingLabel = onboardingStateLabel($onboardingState);
$forwardStatus = getMerchantForwardStatus((int)$merchant['id']);
$kycFailCount = function_exists('getKycFailureCount') ? getKycFailureCount((int)$merchant['id']) : 0;

$entityType = normalizeKycEntityType($merchant['business_entity_type'] ?? 'sole_proprietorship');
$requiredDocs = getKycRequirements($entityType);
$docLabels = getKycDocLabels();
$prefills = getMerchantKycPrefills($merchant);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Your session expired. Refresh the page and upload again.');
        redirect('kyc.php');
    }
    $docType = $_POST['doc_type'] ?? '';
    if (!in_array($docType, $requiredDocs, true)) {
        flash('error', 'This document is not required for your business type.');
    } else {
        $saved = saveMerchantKycUpload(
            (int)$merchant['id'],
            $docType,
            $_FILES['document'] ?? [],
            ['jpg', 'jpeg', 'png', 'pdf'],
            15 * 1024 * 1024,
            parseGeoFromRequest()
        );
        if (empty($saved['ok'])) {
            flash('error', $saved['error'] ?? 'Upload failed. Please retry.');
        } else {
            try {
                $db->prepare("UPDATE merchants SET kyc_status='submitted',onboarding_state='submitted',onboarding_submitted_at=COALESCE(onboarding_submitted_at,NOW()),account_mode='test' WHERE id=?")
                    ->execute([$merchant['id']]);
            } catch (Throwable $e) {
                logPlatformError('warning', 'KYC submit status update failed: ' . $e->getMessage(), ['merchant_id' => (int)$merchant['id']]);
                try {
                    $db->prepare("UPDATE merchants SET kyc_status='submitted',account_mode='test' WHERE id=?")->execute([$merchant['id']]);
                } catch (Throwable $e2) { /* keep upload success even if status columns lag */ }
            }
            try {
                notifyAdminKycDocumentUploaded((int)$merchant['id'], $docType);
            } catch (Throwable $e) {
                logPlatformError('warning', 'KYC upload notify failed: ' . $e->getMessage(), ['merchant_id' => (int)$merchant['id']]);
            }
            $msg = ($saved['scan_status'] ?? 'pending') === 'clean'
                ? 'Document uploaded and security-scanned successfully.'
                : 'Document uploaded. Security scan and compliance review are pending.';
            if (!empty($saved['masked'])) {
                $msg .= ' Aadhaar number has been auto-masked (first 8 digits hidden) per UIDAI compliance.';
            }
            flash('success', $msg);
        }
    }
    redirect('kyc.php');
}

$documents = [];
$docFilter = trim($_GET['doc'] ?? '');
$listParams = listPageParams(15);
$docTotal = 0;
$pagedDocuments = [];
try {
    $docs = $db->prepare('SELECT * FROM kyc_documents WHERE merchant_id = ? ORDER BY created_at DESC');
    $docs->execute([$merchant['id']]);
    $documents = $docs->fetchAll() ?: [];
} catch (Throwable $e) {
    try {
        $docs = $db->prepare('SELECT id, merchant_id, doc_type, file_name, file_path, status, created_at FROM kyc_documents WHERE merchant_id = ? ORDER BY created_at DESC');
        $docs->execute([$merchant['id']]);
        $documents = $docs->fetchAll() ?: [];
    } catch (Throwable $e2) {
        $documents = [];
    }
}
$uploadedTypes = array_unique(array_column($documents, 'doc_type'));
$approvedTypes = array_unique(array_column(array_filter($documents, fn($d) => ($d['status'] ?? '') === 'approved'), 'doc_type'));

/** Latest document row per type (already sorted newest-first). */
$latestByType = [];
foreach ($documents as $doc) {
    $t = (string)($doc['doc_type'] ?? '');
    if ($t !== '' && !isset($latestByType[$t])) {
        $latestByType[$t] = $doc;
    }
}

/** Upload history shows only the latest upload per document type (no old re-upload rows). */
$historyDocs = array_values(array_filter($latestByType, static fn(array $d): bool => ($d['doc_type'] ?? '') !== 'video_kyc'));
if ($docFilter !== '') {
    $historyDocs = array_values(array_filter($historyDocs, static fn($d) => ($d['doc_type'] ?? '') === $docFilter));
}
$docTotal = count($historyDocs);
$pagedDocuments = array_slice($historyDocs, $listParams['offset'], $listParams['perPage']);
$rejectedDocs = array_values(array_filter($latestByType, static fn(array $d): bool => ($d['status'] ?? '') === 'rejected' && ($d['doc_type'] ?? '') !== 'video_kyc'));
usort($rejectedDocs, static fn(array $a, array $b): int => strcmp((string)($b['reviewed_at'] ?? $b['created_at'] ?? ''), (string)($a['reviewed_at'] ?? $a['created_at'] ?? '')));
$rejectedDocs = array_slice($rejectedDocs, 0, 1);

$rejectionCounts = [];
foreach ($documents as $doc) {
    $t = (string)($doc['doc_type'] ?? '');
    if (($doc['status'] ?? '') === 'rejected' && $t !== '' && $t !== 'video_kyc') {
        $rejectionCounts[$t] = ($rejectionCounts[$t] ?? 0) + 1;
    }
}
$maxRetries = 3;

// Number fields shown only if that doc type is required for this entity
$verifyFields = [];
if (in_array('pan', $requiredDocs, true)) {
    $verifyFields['pan'] = 'PAN Number';
}
if (in_array('gst', $requiredDocs, true)) {
    $verifyFields['gst'] = 'GSTIN';
}
if (in_array('aadhaar', $requiredDocs, true)) {
    $verifyFields['aadhaar'] = 'Aadhaar Number';
}
if (in_array('incorporation_certificate', $requiredDocs, true) || in_array('llp_certificate', $requiredDocs, true)) {
    $verifyFields['cin'] = 'CIN / LLPIN';
}

$need = count($requiredDocs);
$have = count(array_intersect($requiredDocs, $uploadedTypes));
$approvedCount = count(array_intersect($requiredDocs, $approvedTypes));

$pageTitle = 'KYC Verification LIVE-v20260725-H';
require_once __DIR__ . '/header.php';

$step1Done = !empty($verifyFields)
    ? count(array_filter(array_keys($verifyFields), static fn($k) => !empty($prefills[$k] ?? ''))) >= min(1, count($verifyFields))
    : true;
$step2Done = $need > 0 && $have >= $need;
$step3Ready = ($merchant['kyc_status'] ?? '') === 'verified' || $approvedCount >= $need;
$currentStep = 1;
if ($step1Done && !$step2Done) {
    $currentStep = 2;
} elseif ($step2Done) {
    $currentStep = 3;
}
$kycSteps = [
    1 => ['title' => 'Verify identity numbers', 'hint' => kycStepOneHint($verifyFields)],
    2 => ['title' => 'Upload documents', 'hint' => 'Only files required for ' . entityTypeLabel($entityType)],
    3 => ['title' => 'Video KYC & agreement', 'hint' => 'Selfie video + contract'],
];

$docStatusMeta = static function (string $status): array {
    return match ($status) {
        'approved' => ['label' => 'Approved', 'tone' => 'emerald', 'icon' => '✓'],
        'rejected' => ['label' => 'Rejected — re-upload required', 'tone' => 'red', 'icon' => '!'],
        'pending' => ['label' => 'Under review', 'tone' => 'amber', 'icon' => '…'],
        default => ['label' => ucfirst($status ?: 'Unknown'), 'tone' => 'gray', 'icon' => '○'],
    };
};
?>
<!-- v20260725-G -->
<style>
.kyc-root{display:flex !important;flex-wrap:wrap !important;gap:1.5rem !important;align-items:flex-start !important;align-self:stretch !important;width:100% !important;max-width:100% !important;box-sizing:border-box !important;padding:0 1rem !important}
.kyc-main{flex:1 1 100% !important;min-width:0 !important;width:100% !important;max-width:100% !important}
.kyc-side{flex:1 1 100% !important;min-width:0 !important;width:100% !important;max-width:100% !important}
.kyc-main>*{max-width:100% !important}
.kyc-side>*{max-width:100% !important}
@media (max-width:1023px){.kyc-main,.kyc-side{flex:1 1 100% !important}}
@media (max-width:640px){
    .kyc-root{padding:0 0.75rem !important}
    .kyc-main .glass.p-6{padding:1rem !important}
    .kyc-main .glass.p-5{padding:0.875rem !important}
}
</style>
<div class="kyc-root">
    <div class="kyc-main space-y-6">
    <div class="glass rounded-2xl p-5 border border-sky-500/20">
        <p class="text-xs text-sky-400 uppercase tracking-wider mb-1">KYC Verification</p>
        <h1 class="text-xl font-bold mb-3">Complete your compliance checklist</h1>
        <p class="text-sm text-gray-400 mb-4">Each document shows its live status. If a file is rejected, the exact reason appears so you can fix and re-upload.</p>
        <div class="grid sm:grid-cols-3 gap-3">
            <?php foreach ($kycSteps as $num => $step):
                $done = $num === 1 ? $step1Done : ($num === 2 ? $step2Done : $step3Ready);
                $active = $currentStep === $num;
            ?>
            <div class="rounded-xl border p-3 <?= $done ? 'border-emerald-500/40 bg-emerald-500/5' : ($active ? 'border-sky-500/50 bg-sky-500/5' : 'border-gray-800') ?>">
                <p class="text-[10px] uppercase text-gray-500 mb-1">Step <?= $num ?></p>
                <p class="text-sm font-semibold <?= $done ? 'text-emerald-300' : ($active ? 'text-sky-300' : 'text-gray-300') ?>"><?= e($step['title']) ?></p>
                <p class="text-xs text-gray-500 mt-1"><?= e($step['hint']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="text-xs text-gray-500 mt-3">Complete all steps for faster Live Mode activation. Real-time registry checks run when verification API keys are configured.</p>
    </div>

    <?php if (!empty($rejectedDocs)): ?>
    <div class="rounded-xl border border-red-500/40 bg-red-500/10 p-4 mb-6">
        <p class="text-sm font-semibold text-red-300 mb-2">Action needed — <?= count($rejectedDocs) ?> document<?= count($rejectedDocs) === 1 ? '' : 's' ?> rejected</p>
        <ul class="space-y-3">
            <?php foreach ($rejectedDocs as $rej):
                $rejType = (string)($rej['doc_type'] ?? '');
                $rejCount = $rejectionCounts[$rejType] ?? 0;
                $retriesLeft = max(0, $maxRetries - $rejCount);
            ?>
            <li class="text-sm text-red-200/90">
                <div class="flex items-start justify-between gap-2 flex-wrap">
                    <div class="min-w-0">
                        <span class="font-medium"><?= e($docLabels[$rejType] ?? $rejType) ?>:</span>
                        <?= e(kycRejectionDisplay((string)($rej['rejection_reason'] ?? ''))) ?>
                        <span class="block text-xs text-gray-400 mt-1">Attempt <?= $rejCount ?> of <?= $maxRetries ?> · <?= $retriesLeft > 0 ? $retriesLeft . ' retry left' : 'No retries left' ?></span>
                    </div>
                    <?php if ($retriesLeft <= 0): ?>
                    <a href="mailto:<?= e(getSetting('support_email', 'support@uniweb.pay')) ?>?subject=KYC%20Help%20-%20<?= e($docLabels[$rejType] ?? $rejType) ?>&body=My%20<?= e($docLabels[$rejType] ?? $rejType) ?>%20was%20rejected%20<?= $rejCount ?>%20times.%20Merchant%20code:%20<?= e($merchant['merchant_code'] ?? '') ?>" class="text-xs bg-amber-600/20 text-amber-400 px-3 py-1.5 rounded-lg whitespace-nowrap shrink-0">Need Help?</a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php
        $anyExhausted = false;
        foreach ($rejectedDocs as $rej) {
            if (($rejectionCounts[$rej['doc_type'] ?? ''] ?? 0) >= $maxRetries) {
                $anyExhausted = true;
                break;
            }
        }
        ?>
        <?php if ($anyExhausted): ?>
        <div class="mt-3 p-3 rounded-lg bg-amber-500/10 border border-amber-500/20">
            <p class="text-xs text-amber-300">Max retry limit reached for one or more documents. You can still upload again, but we recommend using "Need Help?" to contact support. Our team will manually review your documents within 24 hours.</p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php
    $pct = $need > 0 ? (int)round($have / $need * 100) : 0;
    $ringCirc = 2 * 3.14159 * 26;
    $ringOffset = $ringCirc - ($ringCirc * $pct / 100);
    ?>
    <div class="glass rounded-2xl p-6 mb-6 border border-gray-800">
        <div class="flex flex-wrap items-center gap-6">
            <div class="relative shrink-0" style="width:64px;height:64px;">
                <svg width="64" height="64" viewBox="0 0 64 64" class="-rotate-90">
                    <circle cx="32" cy="32" r="26" fill="none" stroke="#334155" stroke-width="6"/>
                    <circle cx="32" cy="32" r="26" fill="none" stroke="<?= $pct === 100 ? '#10b981' : '#0ea5e9' ?>" stroke-width="6" stroke-linecap="round"
                        stroke-dasharray="<?= $ringCirc ?>" stroke-dashoffset="<?= $ringOffset ?>"/>
                </svg>
                <span class="absolute inset-0 flex items-center justify-center text-sm font-bold"><?= $pct ?>%</span>
            </div>
            <div class="flex-1 min-w-[200px]">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <?= statusBadge($merchant['kyc_status']) ?>
                    <?= accountModeBadge($merchant) ?>
                    <span class="text-xs font-mono text-sky-400">MID: <?= e($merchant['merchant_code'] ?? '') ?></span>
                </div>
                <p class="text-sm text-gray-500">Entity: <strong class="text-gray-200"><?= e(entityTypeLabel($entityType)) ?></strong> · <?= $have ?>/<?= $need ?> documents<?= $approvedCount > 0 ? " · {$approvedCount} approved" : '' ?></p>
                <p class="text-xs text-gray-600 mt-1">Only documents needed for your business type are listed below.</p>
            </div>
        </div>
    </div>

    <div class="glass rounded-xl p-6 mb-6">
        <div class="flex flex-wrap items-end justify-between gap-2 mb-4">
            <div>
                <h2 class="font-semibold">Required Documents — <?= e(entityTypeLabel($entityType)) ?></h2>
                <p class="text-xs text-gray-500 mt-1">Status and rejection reasons update after compliance review.</p>
            </div>
        </div>
        <div class="grid sm:grid-cols-2 gap-3">
            <?php foreach ($requiredDocs as $docKey):
                $latest = $latestByType[$docKey] ?? null;
                $status = $latest['status'] ?? '';
                $uploaded = $latest !== null;
                $approved = $status === 'approved';
                $rejected = $status === 'rejected';
                $meta = $uploaded ? $docStatusMeta($status) : ['label' => 'Not uploaded', 'tone' => 'gray', 'icon' => '○'];
                $tone = $meta['tone'];
                $border = $approved ? 'border-emerald-500/30 bg-emerald-500/5' : ($rejected ? 'border-red-500/40 bg-red-500/5' : ($uploaded ? 'border-amber-500/30 bg-amber-500/5' : 'border-gray-800'));
                $iconBg = $approved ? 'bg-emerald-500/15 text-emerald-400' : ($rejected ? 'bg-red-500/15 text-red-400' : ($uploaded ? 'bg-amber-500/15 text-amber-400' : 'bg-gray-800 text-gray-500'));
                $labelTone = $approved ? 'text-emerald-400' : ($rejected ? 'text-red-400' : ($uploaded ? 'text-amber-400' : 'text-gray-500'));
            ?>
            <div class="rounded-xl border p-3.5 <?= $border ?>">
                <div class="flex items-start gap-3">
                    <span class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0 text-sm font-bold <?= $iconBg ?>"><?= e($meta['icon']) ?></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium truncate"><?= e($docLabels[$docKey] ?? $docKey) ?></p>
                        <p class="text-xs mt-0.5 <?= $labelTone ?>"><?= e($meta['label']) ?></p>
                        <?php if ($rejected): ?>
                        <p class="text-xs text-red-300/90 mt-2 leading-relaxed">Reason: <?= e(kycRejectionDisplay((string)($latest['rejection_reason'] ?? ''))) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if (!$approved): ?>
                    <button type="button" onclick="openKycUploader('<?= e($docKey) ?>')" class="text-xs text-brand-400 hover:underline shrink-0 mt-1"><?= $rejected ? 'Re-upload' : 'Upload' ?></button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    $vkStatus = (string)($merchant['video_kyc_status'] ?? 'pending');
    $vkLatest = $latestByType['video_kyc'] ?? null;
    $vkRejected = $vkStatus === 'rejected' || (($vkLatest['status'] ?? '') === 'rejected');
    $vkOk = in_array($vkStatus, ['verified', 'approved'], true);
    $vkBorder = $vkOk ? 'border-emerald-500/30 bg-emerald-500/5' : ($vkRejected ? 'border-red-500/40 bg-red-500/5' : 'border-violet-500/30 bg-violet-500/5');
    $vkOpenByDefault = $vkRejected || ($_GET['section'] ?? '') === 'video' || (!empty($_GET['video_uploaded']));
    ?>
    <details id="video-kyc" class="glass rounded-xl mb-6 border <?= $vkBorder ?>" <?= $vkOpenByDefault ? 'open' : '' ?>>
        <summary class="flex items-center gap-4 p-5 cursor-pointer list-none select-none">
            <span class="w-12 h-12 rounded-xl bg-violet-500/15 text-violet-400 flex items-center justify-center shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </span>
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="font-semibold text-violet-200">Video KYC</p>
                    <?= statusBadge($vkStatus) ?>
                </div>
                <p class="text-xs text-gray-500 mt-0.5">Live camera recording with your name, shop name and address</p>
                <?php if ($vkRejected): ?>
                <p class="text-xs text-red-300 mt-2">Reason: <?= e(kycRejectionDisplay((string)($vkLatest['rejection_reason'] ?? ''))) ?></p>
                <?php endif; ?>
            </div>
            <span class="text-violet-400 shrink-0">▾</span>
        </summary>
        <div class="p-5 pt-0">
            <?php $vkwRedirectTo = 'kyc.php'; require __DIR__ . '/includes/video_kyc_widget.php'; ?>
        </div>
    </details>

    <?php
    $livePhotoTypes = ['merchant_photo', 'shop_signboard', 'shop_outside', 'shop_inside_1', 'shop_inside_2'];
    $shopUploadedCount = count(array_intersect($livePhotoTypes, $uploadedTypes));
    $shopApprovedCount = count(array_intersect($livePhotoTypes, $approvedTypes));
    $shopStatus = 'pending';
    if ($shopApprovedCount >= 5) {
        $shopStatus = 'approved';
    } elseif ($shopUploadedCount >= 5) {
        $shopStatus = 'submitted';
    }
    $shopBorder = $shopStatus === 'approved' ? 'border-emerald-500/30 bg-emerald-500/5' : ($shopStatus === 'submitted' ? 'border-amber-500/30 bg-amber-500/5' : 'border-violet-500/30 bg-violet-500/5');
    ?>
    <a href="merchant_shop_photos.php" class="block glass rounded-xl p-5 mb-6 border <?= $shopBorder ?> hover:opacity-95 transition group">
        <div class="flex items-center gap-4">
            <span class="w-12 h-12 rounded-xl bg-violet-500/15 text-violet-400 flex items-center justify-center shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </span>
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="font-semibold text-violet-200 group-hover:text-white">Shop Photos</p>
                    <?= statusBadge($shopStatus) ?>
                </div>
                <p class="text-xs text-gray-500 mt-0.5">5 live photos: merchant selfie, signboard, outside shop, 2 inside views</p>
            </div>
            <span class="text-violet-400 shrink-0">→</span>
        </div>
    </a>

    <?php if (!empty($verifyFields)): ?>
    <div class="glass rounded-xl p-6 mb-6 border border-emerald-500/20">
        <h2 class="font-semibold mb-1">⚡ Fast KYC — DigiLocker / Registry e-KYC</h2>
        <p class="text-xs text-gray-500 mb-4">Verify PAN/Aadhaar/GST instantly against the government registry — <strong class="text-emerald-400">no document upload or admin wait needed</strong> once verified. Auto-filled from your profile; edit if needed.</p>
        <div class="grid sm:grid-cols-2 gap-4" id="verify-forms">
            <?php foreach ($verifyFields as $type => $label):
                $val = $prefills[$type] ?? '';
                $isAadhaar = $type === 'aadhaar';
                $displayLabel = $isAadhaar ? $label . ' (DigiLocker e-KYC)' : $label;
            ?>
            <div class="bg-dark-900/50 rounded-lg p-4">
                <label class="text-xs text-gray-400"><?= e($displayLabel) ?></label>
                <div class="flex gap-2 mt-1">
                    <input type="text" id="verify-<?= e($type) ?>" value="<?= e($val) ?>"
                        class="input-field text-sm flex-1 <?= $isAadhaar ? '' : 'uppercase' ?>" <?= $isAadhaar ? '' : 'style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()"' ?>
                        placeholder="<?= e($label) ?>">
                    <button type="button" onclick="verifyDoc('<?= e($type) ?>')" class="btn-primary px-3 py-2 text-xs whitespace-nowrap"><?= $isAadhaar ? 'Send OTP' : 'Verify' ?></button>
                </div>
                <?php if ($isAadhaar): ?>
                <div class="flex gap-2 mt-2" id="aadhaar-otp-row">
                    <input type="text" id="aadhaar-otp" maxlength="6" inputmode="numeric" class="input-field text-sm flex-1" placeholder="6-digit OTP">
                    <button type="button" onclick="confirmAadhaarOtp()" class="btn-primary px-3 py-2 text-xs whitespace-nowrap">Confirm OTP</button>
                </div>
                <input type="hidden" id="aadhaar-reference-id" value="">
                <?php endif; ?>
                <p id="result-<?= e($type) ?>" class="text-xs mt-2 text-gray-500"><?= $val !== '' ? 'Synced from profile' : '' ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $verifications = getVerifications((int)$merchant['id']);
    if ($verifications):
    ?>
    <div class="glass rounded-xl p-6 mb-6">
        <h2 class="font-semibold mb-3">Verification Status</h2>
        <div class="space-y-2 text-sm">
            <?php foreach ($verifications as $v): ?>
            <div class="flex justify-between py-1 border-b border-gray-800/50">
                <span class="text-gray-400 uppercase text-xs"><?= e($v['doc_type']) ?>: <?= e(sensitiveUiPlain($v['doc_number'] ?? '') !== '' ? sensitiveUiPlain($v['doc_number']) : sensitiveMask($v['doc_number'], (string)$v['doc_type'])) ?></span>
                <?= statusBadge($v['status']) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div id="upload-doc" class="glass rounded-xl p-6 mb-6">
        <h2 class="font-semibold mb-4">Upload Document</h2>
        <form id="kyc-upload-form" method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="geo_lat" id="geo-lat" value="0">
            <input type="hidden" name="geo_lng" id="geo-lng" value="0">
            <input type="hidden" name="geo_accuracy" id="geo-accuracy" value="0">
            <input type="hidden" name="geo_source" id="geo-source" value="">
            <input type="hidden" name="geo_denied" id="geo-denied" value="0">
            <div>
                <label class="text-sm text-gray-400 block mb-1">Document Type *</label>
                <select name="doc_type" id="doc_type" required class="input-field">
                    <option value="">Select document</option>
                    <?php
                    $uploadableDocs = array_values(array_filter(
                        $requiredDocs,
                        static fn(string $docKey): bool => !in_array($docKey, $approvedTypes, true)
                    ));
                    if ($uploadableDocs === []): ?>
                    <option value="" disabled>All required documents are already approved</option>
                    <?php else:
                        foreach ($uploadableDocs as $docKey): ?>
                    <option value="<?= e($docKey) ?>"><?= e($docLabels[$docKey] ?? $docKey) ?></option>
                    <?php endforeach; endif; ?>
                </select>
                <?php if ($uploadableDocs === []): ?>
                <p class="text-xs text-emerald-400 mt-2">Nothing left to upload for this entity type. If admin rejected a file, it will reappear here for re-upload.</p>
                <?php endif; ?>
            </div>
            <div>
                <label class="text-sm text-gray-400 block mb-1">Choose File (JPG, PNG, PDF — Max 15MB) *</label>
                <label class="file-drop">
                    <span class="block text-sm text-gray-400 mb-1">Click here or use the button below to select your file</span>
                    <input type="file" name="document" id="document-file" required accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                        onchange="submitChosenKycFile(this)">
                </label>
                <p id="file-name-label" class="text-xs text-brand-400 mt-2"></p>
            </div>
            <button type="submit" class="btn-primary px-6 py-2.5">Upload Document</button>
        </form>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold">Upload history</h2>
            <form method="GET" class="flex gap-2 items-center">
                <label class="sr-only" for="kyc-doc-filter">Filter by document type</label>
                <select id="kyc-doc-filter" name="doc" class="input-field text-sm">
                    <option value="">All documents</option>
                    <?php foreach ($requiredDocs as $dk): ?><option value="<?= e($dk) ?>" <?= ($docFilter ?? '')===$dk?'selected':'' ?>><?= e($docLabels[$dk] ?? $dk) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn-primary text-sm px-3 py-1.5">Filter</button>
            </form>
        </div>
        <?php if (empty($pagedDocuments)): ?>
        <?= renderMerchantEmptyState(
            'No documents uploaded yet' . ($docFilter !== '' ? ' for this filter' : ''),
            'Use the upload form on this page. Live collections wait until required files are verified.',
            '#upload-doc',
            'Upload a document →'
        ) ?>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm table-auto">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr><th class="px-5 py-3 text-left min-w-[140px]">Document</th><th class="px-5 py-3 text-left min-w-[180px]">File</th><th class="px-5 py-3 text-left min-w-[100px]">Status</th><th class="px-5 py-3 text-left min-w-[160px]">Notes</th><th class="px-5 py-3 text-left min-w-[120px]">Date</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($pagedDocuments as $doc):
                    if (($doc['doc_type'] ?? '') === 'video_kyc') continue;
                ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3 break-words"><?= e($docLabels[$doc['doc_type']] ?? $doc['doc_type']) ?><?php if (!empty($doc['is_masked'])): ?> <span class="inline-block px-1.5 py-0.5 bg-emerald-600/20 text-emerald-400 rounded text-[10px] font-medium">Masked</span><?php endif; ?><?php if ((int)($doc['version_number'] ?? 1) > 1): ?> <span class="inline-block px-1.5 py-0.5 bg-sky-600/20 text-sky-400 rounded text-[10px] font-medium">v<?= (int)$doc['version_number'] ?></span><?php endif; ?></td>
                    <td class="px-5 py-3 text-xs break-all"><?= e($doc['file_name']) ?></td>
                    <td class="px-5 py-3"><?= statusBadge($doc['status']) ?></td>
                    <td class="px-5 py-3 text-xs break-words <?= ($doc['status'] ?? '') === 'rejected' ? 'text-red-300' : 'text-gray-500' ?>">
                        <?= ($doc['status'] ?? '') === 'rejected'
                            ? e(kycRejectionDisplay((string)($doc['rejection_reason'] ?? '')))
                            : '—' ?>
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap"><?= formatDate($doc['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if (!empty($docTotal)): ?><?= renderListPagination($listParams['page'], $docTotal, $listParams['perPage'], ['doc' => $docFilter ?? '']) ?><?php endif; ?>
        <?php endif; ?>
    </div>

    <?php
    // Document version history — show all versions grouped by doc_type
    $versionHistory = [];
    foreach ($documents as $doc) {
        $t = (string)($doc['doc_type'] ?? '');
        if ($t === '' || $t === 'video_kyc') continue;
        if (!isset($versionHistory[$t])) $versionHistory[$t] = [];
        $versionHistory[$t][] = $doc;
    }
    $hasVersions = false;
    foreach ($versionHistory as $versions) {
        if (count($versions) > 1) { $hasVersions = true; break; }
    }
    ?>
    <?php if ($hasVersions): ?>
    <div class="glass rounded-xl p-6 mb-6">
        <h2 class="font-semibold mb-1">Document Version History</h2>
        <p class="text-xs text-gray-500 mb-4">All previous versions of your documents are preserved for compliance audit.</p>
        <div class="space-y-4">
            <?php foreach ($versionHistory as $docType => $versions):
                if (count($versions) <= 1) continue;
                usort($versions, fn($a, $b) => (int)($b['version_number'] ?? 1) - (int)($a['version_number'] ?? 1));
            ?>
            <div class="border border-gray-800 rounded-xl overflow-hidden">
                <div class="px-4 py-2 bg-dark-900/50 border-b border-gray-800">
                    <p class="text-sm font-medium text-gray-200"><?= e($docLabels[$docType] ?? $docType) ?> <span class="text-xs text-gray-500">(<?= count($versions) ?> versions)</span></p>
                </div>
                <div class="divide-y divide-gray-800">
                    <?php foreach ($versions as $vDoc): ?>
                    <div class="px-4 py-2.5 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <span class="inline-block px-1.5 py-0.5 bg-sky-600/15 text-sky-400 rounded text-[10px] font-medium mr-2">v<?= (int)($vDoc['version_number'] ?? 1) ?></span>
                            <span class="text-xs text-gray-400 break-all"><?= e($vDoc['file_name'] ?? '') ?></span>
                        </div>
                        <div class="flex items-center gap-3 flex-shrink-0">
                            <?= statusBadge($vDoc['status'] ?? 'unknown') ?>
                            <span class="text-[10px] text-gray-600 whitespace-nowrap"><?= formatDate($vDoc['created_at']) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl p-6 mb-6">
        <h2 class="font-semibold mb-4">KYC Timeline</h2>
        <?php
        $timelineEvents = [];
        foreach ($documents as $doc) {
            if (($doc['doc_type'] ?? '') === 'video_kyc') continue;
            $label = $docLabels[$doc['doc_type']] ?? $doc['doc_type'];
            $status = $doc['status'] ?? 'unknown';
            $timelineEvents[] = [
                'date' => $doc['created_at'],
                'icon' => '⬆',
                'tone' => 'sky',
                'text' => e($label) . ' uploaded',
                'sub' => e($doc['file_name'] ?? ''),
            ];
            if ($status === 'approved' && !empty($doc['reviewed_at'])) {
                $timelineEvents[] = [
                    'date' => $doc['reviewed_at'],
                    'icon' => '✓',
                    'tone' => 'emerald',
                    'text' => e($label) . ' approved',
                    'sub' => '',
                ];
            } elseif ($status === 'rejected' && !empty($doc['reviewed_at'])) {
                $timelineEvents[] = [
                    'date' => $doc['reviewed_at'],
                    'icon' => '!',
                    'tone' => 'red',
                    'text' => e($label) . ' rejected',
                    'sub' => e(kycRejectionDisplay((string)($doc['rejection_reason'] ?? ''))),
                ];
            }
        }
        usort($timelineEvents, fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
        $timelineEvents = array_slice($timelineEvents, 0, 1);
        ?>
        <?php if (empty($timelineEvents)): ?>
            <p class="text-sm text-gray-500">No KYC activity yet.</p>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($timelineEvents as $ev): ?>
            <div class="flex items-start gap-3">
                <span class="w-6 h-6 rounded-full bg-<?= e($ev['tone']) ?>-500/15 text-<?= e($ev['tone']) ?>-400 border border-<?= e($ev['tone']) ?>-500/40 flex items-center justify-center text-xs font-bold shrink-0 mt-0.5"><?= e($ev['icon']) ?></span>
                <div class="min-w-0">
                    <p class="text-sm text-gray-200 break-words"><?= e($ev['text']) ?></p>
                    <?php if ($ev['sub'] !== ''): ?><p class="text-xs text-gray-500 break-all"><?= e($ev['sub']) ?></p><?php endif; ?>
                    <p class="text-[10px] text-gray-600"><?= !empty($ev['date']) ? formatDate($ev['date']) : '' ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    </div>

    <aside class="kyc-side space-y-4 lg:sticky lg:top-24 min-w-0">
        <div class="glass rounded-2xl p-5 border border-gray-800">
            <h3 class="font-semibold text-sm mb-2">Status summary</h3>
            <p class="text-xs text-gray-500 mb-3">Entity: <strong class="text-gray-300"><?= e(entityTypeLabel($entityType)) ?></strong></p>
            <p class="text-3xl font-bold text-sky-400"><?= (int)$have ?>/<?= (int)$need ?></p>
            <p class="text-xs text-gray-500 mt-1">documents uploaded · <?= (int)$approvedCount ?> approved</p>
            <p class="text-xs mt-4 <?= ($merchant['kyc_status'] ?? '') === 'verified' ? 'text-emerald-400' : 'text-amber-300' ?>">
                KYC: <?= e(ucfirst((string)($merchant['kyc_status'] ?? 'pending'))) ?>
            </p>
            <div class="mt-3 pt-3 border-t border-gray-800/50 space-y-1">
                <p class="text-xs text-gray-400">Onboarding: <span class="font-medium text-sky-400"><?= e($onboardingLabel) ?></span></p>
                <?php if ($kycFailCount > 0 && $onboardingState !== 'kyc_verified'): ?>
                <p class="text-xs text-amber-400">Verification attempts: <?= $kycFailCount ?> of <?= function_exists('getKycMaxFailures') ? getKycMaxFailures() : 3 ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($forwardStatus)): ?>
        <div class="glass rounded-2xl p-5 border border-gray-800">
            <h3 class="font-semibold text-sm mb-3">Partner Submission Status</h3>
            <div class="space-y-2">
                <?php foreach ($forwardStatus as $fwd): ?>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-400"><?= e(ucfirst($fwd['partner_key'] ?? '')) ?></span>
                    <?php
                    $statusColors = [
                        'queued' => 'text-blue-400',
                        'processing' => 'text-purple-400',
                        'success' => 'text-emerald-400',
                        'retry' => 'text-amber-400',
                        'failed' => 'text-red-400',
                    ];
                    $statusLabel = $fwd['status'] ?? 'pending';
                    $statusLabel = $statusLabel === 'success' ? 'Sent' : ucfirst($statusLabel);
                    ?>
                    <span class="font-medium <?= e($statusColors[$fwd['status']] ?? 'text-gray-400') ?>"><?= e($statusLabel) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="text-xs text-gray-500 mt-3">Documents are scheduled for partner submission. You will be notified on progress.</p>
        </div>
        <?php elseif ($onboardingState === 'kyc_verified' || $onboardingState === 'queue_forward'): ?>
        <div class="glass rounded-2xl p-5 border border-gray-800">
            <h3 class="font-semibold text-sm mb-2">Partner Submission</h3>
            <p class="text-xs text-gray-400">Your documents are being prepared for partner submission. You will be notified when forwarded.</p>
        </div>
        <?php endif; ?>

        <div class="glass rounded-2xl p-5 border border-gray-800 text-xs text-gray-500 space-y-2">
            <p class="font-medium text-gray-300">Tips</p>
            <p>Upload clear JPG/PNG/PDF under 15MB. Rejected files show the exact reason and reappear in the upload list.</p>
            <p>Video KYC is a separate step after documents when Live Mode is requested.</p>
        </div>
    </aside>
</div>

<script>
// Point 1: Capture geolocation on page load
(function(){
    if(!navigator.geolocation) {
        var gs=document.getElementById('geo-source');
        if(gs) gs.value='ip_fallback';
        return;
    }
    navigator.geolocation.getCurrentPosition(
        function(pos){
            var lat=document.getElementById('geo-lat');
            var lng=document.getElementById('geo-lng');
            var acc=document.getElementById('geo-accuracy');
            var src=document.getElementById('geo-source');
            if(lat) lat.value=pos.coords.latitude;
            if(lng) lng.value=pos.coords.longitude;
            if(acc) acc.value=pos.coords.accuracy||0;
            if(src) src.value='html5';
        },
        function(err){
            var src=document.getElementById('geo-source');
            var denied=document.getElementById('geo-denied');
            if(src) src.value='denied';
            if(denied) denied.value='1';
        },
        {enableHighAccuracy:true, timeout:10000, maximumAge:60000}
    );
})();
(function(){
    try{
        const saveKey='uniweb_kyc_verify_'+<?= (int)($merchant['id'] ?? 0) ?>;
        document.querySelectorAll('#verify-forms input[type="text"]').forEach(input=>{
            if(localStorage.getItem(saveKey+'_'+input.id)){
                input.value=localStorage.getItem(saveKey+'_'+input.id);
            }
            input.addEventListener('input',()=>{
                localStorage.setItem(saveKey+'_'+input.id,input.value);
            });
        });
    }catch(e){}
})();
function openKycUploader(type){
    const select=document.getElementById('doc_type');
    const file=document.getElementById('document-file');
    if(!select||!file)return;
    select.value=type;
    file.click();
}
async function submitChosenKycFile(input){
    const label=document.getElementById('file-name-label');
    const form=document.getElementById('kyc-upload-form');
    if(!input.files||!input.files[0]||!form)return;
    const file=input.files[0];
    label.textContent='Checking: '+file.name+'...';
    if(file.type.startsWith('image/')){
        const check=await checkKycImageQuality(file);
        if(!check.ok){
            label.textContent='';
            if(!confirm('Image issue: '+check.msg+'\nUpload anyway?')){input.value='';return;}
        }
    }
    label.textContent='Uploading: '+file.name+'...';
    form.querySelectorAll('button').forEach(btn=>btn.disabled=true);
    form.submit();
}
function checkKycImageQuality(file){
    return new Promise((resolve)=>{
        const img=new Image(), url=URL.createObjectURL(file);
        img.onload=()=>{
            URL.revokeObjectURL(url);
            if(img.width<600||img.height<400){resolve({ok:false,msg:'Image is too small. Please upload a clearer photo.'});return;}
            const canvas=document.createElement('canvas'), ctx=canvas.getContext('2d');
            canvas.width=100; canvas.height=100;
            ctx.drawImage(img,0,0,100,100);
            try{
                const data=ctx.getImageData(0,0,100,100).data;
                let grey=[], sum=0;
                for(let i=0;i<data.length;i+=4){const g=0.299*data[i]+0.587*data[i+1]+0.114*data[i+2]; grey.push(g); sum+=g;}
                const mean=sum/grey.length;
                let variance=0;
                grey.forEach(g=>variance+=Math.pow(g-mean,2));
                variance/=grey.length;
                const sharpness=Math.sqrt(variance);
                if(sharpness<8){resolve({ok:false,msg:'Image looks blurry. Please upload a sharper photo.'});return;}
            }catch(e){}
            resolve({ok:true,msg:''});
        };
        img.onerror=()=>{URL.revokeObjectURL(url);resolve({ok:true,msg:''});};
        img.src=url;
    });
}
async function verifyDoc(type){
    const num=document.getElementById('verify-'+type)?.value;
    const res=document.getElementById('result-'+type);
    if(!num){res.textContent='Enter number';res.className='text-xs mt-2 text-red-400';return;}
    res.textContent=type==='aadhaar'?'Sending OTP...':'Verifying...';res.className='text-xs mt-2 text-cyan-400';
    const fd=new FormData();fd.append('type',type);fd.append('number',num);fd.append('csrf_token','<?= csrfToken() ?>');
    const r=await fetch('verify_api.php',{method:'POST',body:fd});
    const d=await r.json();
    res.textContent=d.message||d.status||'Done';
    res.className='text-xs mt-2 '+(d.success?'text-brand-400':'text-red-400');
    if(type==='aadhaar'&&d.reference_id){
        const ref=document.getElementById('aadhaar-reference-id');
        if(ref) ref.value=d.reference_id;
    }
    if(d.status==='verified'){
        res.textContent='Verified! Reloading to show your fast-tracked KYC status...';
        setTimeout(()=>location.reload(),1200);
    }
}
async function confirmAadhaarOtp(){
    const num=document.getElementById('verify-aadhaar')?.value;
    const otp=document.getElementById('aadhaar-otp')?.value;
    const ref=document.getElementById('aadhaar-reference-id')?.value||'';
    const res=document.getElementById('result-aadhaar');
    if(!num||!otp){res.textContent='Enter Aadhaar and OTP';res.className='text-xs mt-2 text-red-400';return;}
    res.textContent='Confirming OTP...';res.className='text-xs mt-2 text-cyan-400';
    const fd=new FormData();
    fd.append('action','aadhaar_otp');fd.append('type','aadhaar');fd.append('number',num);
    fd.append('otp',otp);fd.append('reference_id',ref);fd.append('csrf_token','<?= csrfToken() ?>');
    const r=await fetch('verify_api.php',{method:'POST',body:fd});
    const d=await r.json();
    res.textContent=d.message||d.status||'Done';
    res.className='text-xs mt-2 '+(d.success?'text-brand-400':'text-red-400');
    if(d.status==='verified'){
        res.textContent='Verified! Reloading to show your fast-tracked KYC status...';
        setTimeout(()=>location.reload(),1200);
    }
}
document.querySelectorAll('#verify-forms input[id^="verify-"]').forEach(function(inp){
    inp.addEventListener('blur', function(){
        var type=this.id.replace('verify-','');
        if(type==='aadhaar') return;
        var val=this.value.trim();
        if(val==='') return;
        var res=document.getElementById('result-'+type);
        if(!res) return;
        fetch('kyc_validate_ajax.php?field='+encodeURIComponent(type)+'&value='+encodeURIComponent(val)+'&token=<?= csrfToken() ?>')
            .then(function(r){return r.json();})
            .then(function(d){
                if(d.valid){
                    res.innerHTML='✓ Valid format';
                    res.className='text-xs mt-2 text-emerald-400';
                } else {
                    res.innerHTML='✗ '+(d.reason||'Invalid');
                    res.className='text-xs mt-2 text-red-400';
                }
            })
            .catch(function(){});
    });
});
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
