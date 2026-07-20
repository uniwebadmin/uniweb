<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureKycSchema();
require_once __DIR__ . '/includes/kyc_upload.php';
$merchant = getMerchant();
$db = getDB();

$entityType = $merchant['business_entity_type'] ?? 'sole_proprietorship';
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

$docs = $db->prepare('SELECT * FROM kyc_documents WHERE merchant_id = ? ORDER BY created_at DESC');
$docs->execute([$merchant['id']]);
$documents = $docs->fetchAll();
$uploadedTypes = array_unique(array_column($documents, 'doc_type'));
$approvedTypes = array_unique(array_column(array_filter($documents, fn($d) => $d['status'] === 'approved'), 'doc_type'));

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

$step1Done = !empty($verifyFields) ? count(array_filter($verifyFields, static fn($k) => !empty($prefills[$k] ?? ''))) >= min(1, count($verifyFields)) : true;
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
?>

<div class="max-w-3xl">
    <div class="glass rounded-2xl p-5 mb-6 border border-sky-500/20">
        <p class="text-xs text-sky-400 uppercase tracking-wider mb-3">Paperless KYC — 3 steps</p>
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
    <?php
    $pct = $need > 0 ? (int)round($have / $need * 100) : 0;
    $ringCirc = 2 * 3.14159 * 26;
    $ringOffset = $ringCirc - ($ringCirc * $pct / 100);
    ?>
    <div class="glass rounded-2xl p-6 mb-6 border border-gray-800">
        <div class="flex flex-wrap items-center gap-6">
            <div class="relative shrink-0" style="width:64px;height:64px;">
                <svg width="64" height="64" viewBox="0 0 64 64" class="-rotate-90">
                    <circle cx="32" cy="32" r="26" fill="none" stroke="#cbd5e1" stroke-width="6"/>
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
        <h2 class="font-semibold mb-4">Required Documents — <?= e(entityTypeLabel($entityType)) ?></h2>
        <div class="grid sm:grid-cols-2 gap-3">
            <?php foreach ($requiredDocs as $docKey):
                $uploaded = in_array($docKey, $uploadedTypes, true);
                $approved = in_array($docKey, $approvedTypes, true);
            ?>
            <div class="flex items-center gap-3 rounded-xl border p-3.5 <?= $approved ? 'border-emerald-500/30 bg-emerald-500/5' : ($uploaded ? 'border-amber-500/30 bg-amber-500/5' : 'border-gray-800') ?>">
                <span class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0 <?= $approved ? 'bg-emerald-500/15 text-emerald-400' : ($uploaded ? 'bg-amber-500/15 text-amber-400' : 'bg-gray-800 text-gray-500') ?>">
                    <?php if ($approved): ?>✓<?php elseif ($uploaded): ?>◷<?php else: ?>○<?php endif; ?>
                </span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium truncate"><?= e($docLabels[$docKey] ?? $docKey) ?></p>
                    <p class="text-xs <?= $approved ? 'text-emerald-400' : ($uploaded ? 'text-amber-400' : 'text-gray-500') ?>"><?= $approved ? 'Approved' : ($uploaded ? 'Under Review' : 'Not uploaded') ?></p>
                </div>
                <?php if (!$approved): ?>
                <button type="button" onclick="openKycUploader('<?= e($docKey) ?>')" class="text-xs text-brand-400 hover:underline shrink-0">Upload</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <a href="merchant_video_verification.php" class="block glass rounded-xl p-5 mb-6 border border-violet-500/30 bg-violet-500/5 hover:border-violet-500/50 transition group">
        <div class="flex items-center gap-4">
            <span class="w-12 h-12 rounded-xl bg-violet-500/15 text-violet-400 flex items-center justify-center shrink-0 text-xl">📹</span>
            <div class="flex-1">
                <p class="font-semibold text-violet-300 group-hover:text-violet-200">Video KYC</p>
                <p class="text-xs text-gray-500 mt-0.5">Upload a short selfie video holding your Aadhaar/PAN</p>
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
                    <?php foreach ($requiredDocs as $docKey):
                        if (in_array($docKey, $approvedTypes, true)) {
                            continue;
                        }
                    ?>
                    <option value="<?= e($docKey) ?>"><?= e($docLabels[$docKey] ?? $docKey) ?></option>
                    <?php endforeach; ?>
                </select>
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
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Uploaded Documents</h2></div>
        <?php if (empty($documents)): ?>
        <p class="text-gray-500 text-sm text-center py-8">No documents uploaded yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr><th class="px-5 py-3 text-left">Document</th><th class="px-5 py-3 text-left">File</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Date</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($documents as $doc): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3"><?= e($docLabels[$doc['doc_type']] ?? $doc['doc_type']) ?></td>
                    <td class="px-5 py-3 text-xs"><?= e($doc['file_name']) ?></td>
                    <td class="px-5 py-3"><?= statusBadge($doc['status']) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($doc['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
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
