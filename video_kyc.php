<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureKycSchema();
require_once __DIR__ . '/includes/kyc_upload.php';
$merchant = getMerchant();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Your session expired. Refresh the page and upload again.');
        redirect('merchant_video_verification.php');
    }
    $saved = saveMerchantKycUpload(
        (int)$merchant['id'],
        'video_kyc',
        $_FILES['video'] ?? [],
        ['mp4', 'webm', 'mov'],
        50 * 1024 * 1024
    );
    if (empty($saved['ok'])) {
        flash('error', $saved['error'] ?? 'Video upload failed. Please retry.');
    } else {
        try {
            $db->prepare("UPDATE merchants SET video_kyc_status = 'submitted' WHERE id = ?")->execute([$merchant['id']]);
        } catch (Throwable $e) {
            logPlatformError('warning', 'Video KYC status update failed: ' . $e->getMessage(), ['merchant_id' => (int)$merchant['id']]);
        }
        notifyAdminKycDocumentUploaded((int)$merchant['id'], 'video_kyc');
        flash('success', 'Video KYC uploaded successfully. Review usually completes within 48 hours.');
    }
    redirect('merchant_video_verification.php');
}

$vkStatus = (string)($merchant['video_kyc_status'] ?? 'pending');
$rejectionReason = '';
try {
    $st = $db->prepare("SELECT status, rejection_reason, created_at FROM kyc_documents WHERE merchant_id=? AND doc_type='video_kyc' ORDER BY created_at DESC LIMIT 1");
    $st->execute([(int)$merchant['id']]);
    $latestVideo = $st->fetch() ?: null;
    if ($latestVideo && ($latestVideo['status'] ?? '') === 'rejected') {
        $rejectionReason = trim((string)($latestVideo['rejection_reason'] ?? ''));
        if ($vkStatus !== 'rejected') {
            $vkStatus = 'rejected';
        }
    }
} catch (Throwable $e) {
    $latestVideo = null;
}

$pageTitle = 'Video KYC';
require_once __DIR__ . '/header.php';
$verified = in_array($vkStatus, ['verified', 'approved'], true);
$rejected = $vkStatus === 'rejected';
?>
<div class="max-w-2xl">
    <div class="glass rounded-2xl p-6 mb-6 border <?= $verified ? 'border-emerald-500/30' : ($rejected ? 'border-red-500/40' : 'border-violet-500/20') ?>">
        <div class="flex items-center gap-4">
            <span class="w-14 h-14 rounded-xl bg-violet-500/15 text-violet-400 flex items-center justify-center shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </span>
            <div class="flex-1 min-w-0">
                <p class="text-xs text-violet-400 uppercase tracking-wider mb-1">Identity check</p>
                <h1 class="text-lg font-bold">Video KYC</h1>
                <p class="text-xs text-gray-500 mt-0.5">15–30 second selfie video with your Aadhaar or PAN visible</p>
            </div>
            <?= statusBadge($vkStatus) ?>
        </div>
    </div>

    <?php if ($verified): ?>
    <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm px-4 py-3 rounded-xl mb-6">Your Video KYC is verified. No further action needed.</div>
    <?php elseif ($vkStatus === 'submitted'): ?>
    <div class="bg-sky-500/10 border border-sky-500/30 text-sky-300 text-sm px-4 py-3 rounded-xl mb-6">Your video is with our compliance team. You can upload a replacement below if needed.</div>
    <?php elseif ($rejected): ?>
    <div class="bg-red-500/10 border border-red-500/40 rounded-xl px-4 py-3 mb-6">
        <p class="text-sm font-semibold text-red-300">Video rejected — please re-record</p>
        <p class="text-sm text-red-200/90 mt-1">Reason: <?= e($rejectionReason !== '' ? $rejectionReason : 'Please record again with a clearer face and document.') ?></p>
    </div>
    <?php endif; ?>

    <?php if (!$verified): ?>

    <div class="glass rounded-xl p-6 mb-6">
        <h2 class="font-semibold mb-1">Recording checklist</h2>
        <p class="text-xs text-gray-500 mb-4">Say and show each item on camera. Automated face-match is handled via a certified partner when enabled — we do not store biometric templates on UniWeb servers.</p>
        <div class="space-y-3">
            <?php foreach ([
                'Hold your Aadhaar or PAN card clearly next to your face',
                'State your full name as per the document',
                'State your business name: "' . ($merchant['business_name'] ?? 'your business') . '"',
                'Say today\'s date and "I am recording this for UniWeb KYC verification"',
                'Keep your face well-lit and clearly visible throughout',
            ] as $i => $step): ?>
            <div class="flex items-start gap-3">
                <span class="w-6 h-6 rounded-full bg-violet-500/15 text-violet-400 text-xs font-bold flex items-center justify-center shrink-0 mt-0.5"><?= $i + 1 ?></span>
                <p class="text-sm text-gray-300"><?= e($step) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="glass rounded-xl p-6 mb-6">
        <h2 class="font-semibold mb-2"><?= $rejected ? 'Re-upload your Video KYC' : 'Upload your video' ?></h2>
        <p class="text-sm text-gray-400 mb-4">Max 50MB · MP4, WebM, or MOV · 15–30 seconds is enough.</p>
        <form id="video-kyc-upload-form" method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <label class="file-drop">
                <span class="block text-sm font-medium text-gray-300 mb-1">Choose video file</span>
                <span class="block text-xs text-gray-500 mb-2">Tap below to open your gallery / files</span>
                <input type="file" name="video" id="video-file" required
                    accept="video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov"
                    onchange="document.getElementById('video-name').textContent = this.files[0] ? ('Selected: ' + this.files[0].name) : ''">
            </label>
            <p id="video-name" class="text-xs text-brand-400 text-center"></p>
            <div id="video-upload-progress-wrap" class="hidden">
                <div class="h-2 rounded-full bg-gray-800 overflow-hidden"><div id="video-upload-progress" class="h-full bg-brand-500 transition-all" style="width:0%"></div></div>
                <p id="video-upload-status" class="text-xs text-gray-500 text-center mt-2">Preparing secure upload…</p>
            </div>
            <p id="video-upload-error" class="hidden text-sm text-red-500 text-center"></p>
            <button id="video-upload-button" type="submit" class="w-full btn-primary py-3"><?= $rejected ? 'Re-upload Video KYC' : 'Upload Video KYC' ?></button>
        </form>
    </div>
    <?php endif; ?>

    <p class="text-xs text-gray-600 text-center"><a href="kyc.php" class="text-brand-400 hover:underline">← Back to KYC Documents</a></p>
</div>
<script>
(function(){
    const form=document.getElementById('video-kyc-upload-form');
    if(!form || !window.fetch || !window.crypto)return;
    const input=document.getElementById('video-file'), button=document.getElementById('video-upload-button');
    const wrap=document.getElementById('video-upload-progress-wrap'), bar=document.getElementById('video-upload-progress');
    const status=document.getElementById('video-upload-status'), errorBox=document.getElementById('video-upload-error');
    const csrf=<?= json_encode(csrfToken()) ?>, chunkSize=1024*1024;
    const uploadId=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
    const send=async(url,body)=>{
        const response=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/octet-stream'},body:body});
        const text=await response.text();let data={};
        try{data=JSON.parse(text)}catch(e){throw new Error(response.status===403?'Server blocked this upload request. Please refresh and retry.':'Upload server returned an invalid response.')}
        if(!response.ok||!data.ok)throw new Error(data.error||'Upload failed.');
        return data;
    };
    form.addEventListener('submit',async e=>{
        e.preventDefault();
        const file=input.files&&input.files[0];
        if(!file){errorBox.textContent='Please choose a video file.';errorBox.classList.remove('hidden');return}
        const ext=(file.name.split('.').pop()||'').toLowerCase();
        if(!['mp4','webm','mov'].includes(ext)){errorBox.textContent='Choose an MP4, WebM, or MOV video.';errorBox.classList.remove('hidden');return}
        if(file.size<1||file.size>50*1024*1024){errorBox.textContent='Video must be between 1 byte and 50MB.';errorBox.classList.remove('hidden');return}
        const id=uploadId(), total=Math.ceil(file.size/chunkSize);
        button.disabled=true;button.textContent='Uploading…';wrap.classList.remove('hidden');errorBox.classList.add('hidden');
        try{
            for(let i=0;i<total;i++){
                status.textContent='Uploading securely… '+(i+1)+' of '+total;
                const url='kyc_media_receiver.php?action=part&upload_id='+id+'&ext='+encodeURIComponent(ext)+'&index='+i+'&total='+total;
                await send(url,file.slice(i*chunkSize,Math.min(file.size,(i+1)*chunkSize)));
                bar.style.width=Math.round(((i+1)/total)*95)+'%';
            }
            status.textContent='Finalizing your Video KYC…';
            await send('kyc_media_receiver.php?action=finalize&upload_id='+id+'&ext='+encodeURIComponent(ext)+'&index=0&total='+total,new Blob([]));
            bar.style.width='100%';status.textContent='Upload complete.';
            location.href='merchant_video_verification.php?uploaded=1&t='+Date.now();
        }catch(err){
            errorBox.textContent=err.message||'Upload failed. Please retry.';
            errorBox.classList.remove('hidden');status.textContent='Upload stopped.';
            button.disabled=false;button.textContent='Retry Video KYC Upload';
        }
    });
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
