<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureKycSchema();
require_once __DIR__ . '/includes/kyc_upload.php';
$merchant = getMerchant();
$db = getDB();

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
            15 * 1024 * 1024
        );
        if (empty($saved['ok'])) {
            flash('error', $saved['error'] ?? 'Upload failed. Please retry.');
        } else {
            $db->prepare("UPDATE merchants SET kyc_status='submitted',onboarding_state='submitted',onboarding_submitted_at=COALESCE(onboarding_submitted_at,NOW()),account_mode='test' WHERE id=?")
                ->execute([$merchant['id']]);
            notifyAdminKycDocumentUploaded((int)$merchant['id'], $docType);
            flash('success', ($saved['scan_status'] ?? 'pending') === 'clean'
                ? 'Document uploaded and security-scanned successfully.'
                : 'Document uploaded. Security scan and compliance review are pending.');
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
$historyDocs = $documents;
if ($docFilter !== '') {
    $historyDocs = array_values(array_filter($historyDocs, static fn($d) => ($d['doc_type'] ?? '') === $docFilter));
}
$docTotal = count($historyDocs);
$pagedDocuments = array_slice($historyDocs, $listParams['offset'], $listParams['perPage']);
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
$rejectedDocs = array_values(array_filter($latestByType, static fn(array $d): bool => ($d['status'] ?? '') === 'rejected'));

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

$pageTitle = 'KYC Verification';
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

<div class="max-w-6xl grid lg:grid-cols-5 gap-6 items-start">
    <div class="lg:col-span-3 space-y-6 min-w-0">
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
        <ul class="space-y-2">
            <?php foreach ($rejectedDocs as $rej):
                if (($rej['doc_type'] ?? '') === 'video_kyc') continue;
            ?>
            <li class="text-sm text-red-200/90">
                <span class="font-medium"><?= e($docLabels[$rej['doc_type']] ?? $rej['doc_type']) ?>:</span>
                <?= e(trim((string)($rej['rejection_reason'] ?? '')) ?: 'Please re-upload a clearer copy.') ?>
            </li>
            <?php endforeach; ?>
        </ul>
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
                        <p class="text-xs text-red-300/90 mt-2 leading-relaxed">Reason: <?= e(trim((string)($latest['rejection_reason'] ?? '')) ?: 'Please re-upload a clearer copy.') ?></p>
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
    ?>
    <a href="merchant_video_verification.php" class="block glass rounded-xl p-5 mb-6 border <?= $vkBorder ?> hover:opacity-95 transition group">
        <div class="flex items-center gap-4">
            <span class="w-12 h-12 rounded-xl bg-violet-500/15 text-violet-400 flex items-center justify-center shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </span>
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="font-semibold text-violet-200 group-hover:text-white">Video KYC</p>
                    <?= statusBadge($vkStatus) ?>
                </div>
                <p class="text-xs text-gray-500 mt-0.5">Short selfie video holding your Aadhaar or PAN</p>
                <?php if ($vkRejected): ?>
                <p class="text-xs text-red-300 mt-2">Reason: <?= e(trim((string)($vkLatest['rejection_reason'] ?? '')) ?: 'Please record again with clearer face and document.') ?></p>
                <?php endif; ?>
            </div>
            <span class="text-violet-400">→</span>
        </div>
    </a>

    <?php if (!empty($verifyFields)): ?>
    <div class="glass rounded-xl p-6 mb-6">
        <h2 class="font-semibold mb-2">Document Numbers</h2>
        <p class="text-xs text-gray-500 mb-4">Auto-filled from your profile. Edit if needed — letters stay CAPITAL by default.</p>
        <div class="grid sm:grid-cols-2 gap-4" id="verify-forms">
            <?php foreach ($verifyFields as $type => $label):
                $val = $prefills[$type] ?? '';
                $isAadhaar = $type === 'aadhaar';
            ?>
            <div class="bg-dark-900/50 rounded-lg p-4">
                <label class="text-xs text-gray-400"><?= e($label) ?></label>
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
                <span class="text-gray-400 uppercase text-xs"><?= e($v['doc_type']) ?>: <?= e($v['doc_number']) ?></span>
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
        <p class="text-gray-500 text-sm text-center py-8">No documents uploaded yet<?= $docFilter !== '' ? ' for this filter' : '' ?>.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr><th class="px-5 py-3 text-left">Document</th><th class="px-5 py-3 text-left">File</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Notes</th><th class="px-5 py-3 text-left">Date</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($pagedDocuments as $doc):
                    if (($doc['doc_type'] ?? '') === 'video_kyc') continue;
                ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3"><?= e($docLabels[$doc['doc_type']] ?? $doc['doc_type']) ?></td>
                    <td class="px-5 py-3 text-xs"><?= e($doc['file_name']) ?></td>
                    <td class="px-5 py-3"><?= statusBadge($doc['status']) ?></td>
                    <td class="px-5 py-3 text-xs <?= ($doc['status'] ?? '') === 'rejected' ? 'text-red-300' : 'text-gray-500' ?>">
                        <?= ($doc['status'] ?? '') === 'rejected'
                            ? e(trim((string)($doc['rejection_reason'] ?? '')) ?: 'Re-upload required')
                            : '—' ?>
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($doc['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if (!empty($docTotal)): ?><?= renderListPagination($listParams['page'], $docTotal, $listParams['perPage'], ['doc' => $docFilter ?? '']) ?><?php endif; ?>
        <?php endif; ?>
    </div>
    </div>

    <aside class="lg:col-span-2 space-y-4 lg:sticky lg:top-24">
        <div class="glass rounded-2xl p-5 border border-gray-800">
            <h3 class="font-semibold text-sm mb-2">Status summary</h3>
            <p class="text-xs text-gray-500 mb-3">Entity: <strong class="text-gray-300"><?= e(entityTypeLabel($entityType)) ?></strong></p>
            <p class="text-3xl font-bold text-sky-400"><?= (int)$have ?>/<?= (int)$need ?></p>
            <p class="text-xs text-gray-500 mt-1">documents uploaded · <?= (int)$approvedCount ?> approved</p>
            <p class="text-xs mt-4 <?= ($merchant['kyc_status'] ?? '') === 'verified' ? 'text-emerald-400' : 'text-amber-300' ?>">
                KYC: <?= e(ucfirst((string)($merchant['kyc_status'] ?? 'pending'))) ?>
            </p>
        </div>
        <div class="glass rounded-2xl p-5 border border-gray-800 text-xs text-gray-500 space-y-2">
            <p class="font-medium text-gray-300">Tips</p>
            <p>Upload clear JPG/PNG/PDF under 15MB. Rejected files show the exact reason and reappear in the upload list.</p>
            <p>Video KYC is a separate step after documents when Live Mode is requested.</p>
        </div>
    </aside>
</div>

<script>
function openKycUploader(type){
    const select=document.getElementById('doc_type');
    const file=document.getElementById('document-file');
    if(!select||!file)return;
    select.value=type;
    file.click();
}
function submitChosenKycFile(input){
    const label=document.getElementById('file-name-label');
    const form=document.getElementById('kyc-upload-form');
    if(!input.files||!input.files[0]||!form)return;
    label.textContent='Uploading: '+input.files[0].name+'...';
    form.querySelectorAll('button').forEach(btn=>btn.disabled=true);
    form.submit();
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
}
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
