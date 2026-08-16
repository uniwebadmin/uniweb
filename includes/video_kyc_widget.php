<?php
/**
 * Reusable Video KYC recording widget — live camera capture with IP + timestamp
 * overlay. Self-contained: expects $merchant (array) and $db (PDO) to already
 * be in scope from the including page. Set $vkwRedirectTo before including
 * this file to control where the page reloads after a successful upload
 * (defaults to kyc.php).
 */
$vkwRedirectTo = $vkwRedirectTo ?? 'kyc.php';
if (!function_exists('kycRejectionDisplay') && is_file(__DIR__ . '/kyc_entity.php')) {
    require_once __DIR__ . '/kyc_entity.php';
}

$vkwStatus = (string)($merchant['video_kyc_status'] ?? 'pending');
$vkwRejectionReason = '';
try {
    $vkwStmt = $db->prepare("SELECT status, rejection_reason, created_at, ip_address, recorded_at FROM kyc_documents WHERE merchant_id=? AND doc_type='video_kyc' ORDER BY created_at DESC LIMIT 1");
    $vkwStmt->execute([(int)$merchant['id']]);
    $vkwLatest = $vkwStmt->fetch() ?: null;
    if ($vkwLatest && ($vkwLatest['status'] ?? '') === 'rejected') {
        $vkwRejectionReason = trim((string)($vkwLatest['rejection_reason'] ?? ''));
        if ($vkwStatus !== 'rejected') {
            $vkwStatus = 'rejected';
        }
    }
} catch (Throwable $e) {
    $vkwLatest = null;
}

$vkwClientIp = function_exists('getRealClientIp') ? getRealClientIp() : (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$vkwVerified = in_array($vkwStatus, ['verified', 'approved'], true);
$vkwRejected = $vkwStatus === 'rejected';
?>
<div class="glass rounded-2xl p-6 mb-6 border <?= $vkwVerified ? 'border-emerald-500/30' : ($vkwRejected ? 'border-red-500/40' : 'border-violet-500/20') ?>">
    <div class="flex items-center gap-4">
        <span class="w-14 h-14 rounded-xl bg-violet-500/15 text-violet-400 flex items-center justify-center shrink-0">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
        </span>
        <div class="flex-1 min-w-0">
            <p class="text-xs text-violet-400 uppercase tracking-wider mb-1">Identity check</p>
            <h2 class="text-lg font-bold">Video KYC</h2>
            <p class="text-xs text-gray-500 mt-0.5">Live camera recording only (no gallery upload) · IP + date/time recorded with each session</p>
        </div>
        <?= statusBadge($vkwStatus) ?>
    </div>
</div>

<?php if ($vkwVerified): ?>
<div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm px-4 py-3 rounded-xl mb-6">Your Video KYC is verified. No further action needed.</div>
<?php elseif ($vkwStatus === 'submitted'): ?>
<div class="bg-sky-500/10 border border-sky-500/30 text-sky-300 text-sm px-4 py-3 rounded-xl mb-6">Your video is with our compliance team. You can record a replacement below if needed.</div>
<?php elseif ($vkwRejected): ?>
<div class="bg-red-500/10 border border-red-500/40 rounded-xl px-4 py-3 mb-6">
    <p class="text-sm font-semibold text-red-300">Video rejected — please re-record</p>
    <p class="text-sm text-red-200/90 mt-1">Reason: <?= e(function_exists('kycRejectionDisplay') ? kycRejectionDisplay($vkwRejectionReason) : ($vkwRejectionReason !== '' ? $vkwRejectionReason : 'Please record again with a clearer face and document.')) ?></p>
</div>
<?php endif; ?>

<?php if (!$vkwVerified): ?>

<div class="glass rounded-xl p-6 mb-6">
    <h3 class="font-semibold mb-1">Recording checklist</h3>
    <p class="text-xs text-gray-500 mb-4">Record yourself live on camera. Automated face-match runs only via a certified partner (Digio) when keys are configured — UniWeb never stores biometric templates.</p>
    <?php
    $vkwAddress = trim(implode(', ', array_filter([
        trim((string)($merchant['address'] ?? '')),
        trim((string)($merchant['city'] ?? '')),
        trim((string)($merchant['state'] ?? '')),
        trim((string)($merchant['pincode'] ?? '')),
    ])));
    if ($vkwAddress === '') {
        $vkwAddress = 'your complete address';
    }
    ?>
    <div class="space-y-3">
        <?php foreach ([
            'Say: "My name is ' . ($merchant['name'] ?? 'your full name') . ' and I am applying for a UniWeb merchant account."',
            'Say your shop/business name: "' . ($merchant['business_name'] ?? 'your business name') . '"',
            'Say your complete address: ' . $vkwAddress,
            'Keep your face well-lit and clearly visible throughout',
        ] as $vkwI => $vkwStep): ?>
        <div class="flex items-start gap-3">
            <span class="w-6 h-6 rounded-full bg-violet-500/15 text-violet-400 text-xs font-bold flex items-center justify-center shrink-0 mt-0.5"><?= $vkwI + 1 ?></span>
            <p class="text-sm text-gray-300"><?= e($vkwStep) ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="glass rounded-xl p-6 mb-6">
    <h3 class="font-semibold mb-2"><?= $vkwRejected ? 'Re-record your Video KYC' : 'Record your Video KYC' ?></h3>
    <p class="text-sm text-gray-400 mb-4">15–30 seconds · live camera + microphone required · no file picker · IP and date/time saved when you upload after stop.</p>

    <div id="vkw-camera-error" class="hidden bg-red-500/10 border border-red-500/30 text-red-300 text-sm px-4 py-3 rounded-xl mb-4"></div>

    <div class="relative bg-black rounded-xl overflow-hidden mb-4 aspect-[3/4] sm:aspect-video">
        <video id="vkw-video-source" class="hidden" autoplay playsinline muted></video>
        <canvas id="vkw-video-canvas" class="w-full h-full object-cover bg-black"></canvas>
        <div id="vkw-recording-badge" class="hidden absolute top-3 left-3 bg-red-600 text-white text-xs font-bold px-2 py-1 rounded-full animate-pulse">REC <span id="vkw-recording-timer">00:00</span></div>
    </div>

    <div id="vkw-playback-wrap" class="hidden mb-4">
        <p class="text-xs text-gray-500 mb-2">Preview your recording:</p>
        <video id="vkw-playback" class="w-full rounded-xl bg-black" controls playsinline></video>
    </div>

    <div class="flex flex-wrap gap-3 mb-4">
        <button id="vkw-btn-start" type="button" class="btn-primary px-6 py-2.5 rounded-lg text-sm">Start Recording</button>
        <button id="vkw-btn-retry-camera" type="button" class="hidden bg-amber-600 hover:bg-amber-500 text-white px-6 py-2.5 rounded-lg text-sm">Retry Camera</button>
        <button id="vkw-btn-stop" type="button" class="hidden bg-red-600 hover:bg-red-500 text-white px-6 py-2.5 rounded-lg text-sm">Stop Recording</button>
        <button id="vkw-btn-retake" type="button" class="hidden bg-gray-700 hover:bg-gray-600 text-white px-6 py-2.5 rounded-lg text-sm">Retake</button>
        <button id="vkw-btn-upload" type="button" class="hidden btn-primary px-6 py-2.5 rounded-lg text-sm">Upload Video KYC</button>
    </div>

    <div id="vkw-upload-progress-wrap" class="hidden">
        <div class="h-2 rounded-full bg-gray-800 overflow-hidden"><div id="vkw-upload-progress" class="h-full bg-brand-500 transition-all" style="width:0%"></div></div>
        <p id="vkw-upload-status" class="text-xs text-gray-500 text-center mt-2">Preparing secure upload…</p>
    </div>
    <p id="vkw-upload-error" class="hidden text-sm text-red-500 text-center mt-3"></p>

    <p class="text-xs text-gray-600 mt-2">We will record your IP address and the recording time/date for compliance.</p>
</div>
<?php endif; ?>
<script>
(function(){
    const videoSource = document.getElementById('vkw-video-source');
    const canvas = document.getElementById('vkw-video-canvas');
    if (!videoSource || !canvas) return;
    const ctx = canvas.getContext('2d');
    const playbackWrap = document.getElementById('vkw-playback-wrap');
    const playback = document.getElementById('vkw-playback');
    const badge = document.getElementById('vkw-recording-badge');
    const timerEl = document.getElementById('vkw-recording-timer');
    const btnStart = document.getElementById('vkw-btn-start');
    const btnStop = document.getElementById('vkw-btn-stop');
    const btnRetake = document.getElementById('vkw-btn-retake');
    const btnUpload = document.getElementById('vkw-btn-upload');
    const progressWrap = document.getElementById('vkw-upload-progress-wrap');
    const progressBar = document.getElementById('vkw-upload-progress');
    const statusEl = document.getElementById('vkw-upload-status');
    const errorBox = document.getElementById('vkw-upload-error');
    const errorBanner = document.getElementById('vkw-camera-error');
    const btnRetryCamera = document.getElementById('vkw-btn-retry-camera');
    const overlayIp = <?= json_encode($vkwClientIp) ?>;
    const redirectTo = <?= json_encode($vkwRedirectTo) ?>;
    let overlayLoc = '--';
    let audioTrack = null;
    let canvasDrawReq = null;
    let vkwGeoLat = 0, vkwGeoLng = 0, vkwGeoAcc = 0, vkwGeoSrc = '';

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                vkwGeoLat = pos.coords.latitude;
                vkwGeoLng = pos.coords.longitude;
                vkwGeoAcc = pos.coords.accuracy || 0;
                vkwGeoSrc = 'html5';
            },
            function() { vkwGeoSrc = 'denied'; },
            {enableHighAccuracy: true, timeout: 10000, maximumAge: 60000}
        );
    }

    if (!navigator.mediaDevices || !window.MediaRecorder || !canvas.captureStream) {
        showCameraError('Your browser does not support live camera recording. Please use a modern browser (Chrome, Firefox, Safari, Edge).');
        btnStart.disabled = true;
        return;
    }

    const csrf = <?= json_encode(csrfToken()) ?>;
    const chunkSize = 1024 * 1024;
    const maxSeconds = 30;
    const minSeconds = 5;
    let stream = null;
    let mediaRecorder = null;
    let recordedChunks = [];
    let recordedBlob = null;
    let recordedSeconds = 0;
    let timerInterval = null;
    let recordingStartedAt = null;

    function formatTime(sec){ const m=Math.floor(sec/60).toString().padStart(2,'0'); const s=(sec%60).toString().padStart(2,'0'); return m+':'+s; }

    function showCameraError(msg){
        errorBanner.textContent = msg;
        errorBanner.classList.remove('hidden');
        btnStart.classList.add('hidden');
        btnRetryCamera.classList.remove('hidden');
    }

    async function startCamera(){
        errorBanner.classList.add('hidden');
        btnRetryCamera.classList.add('hidden');
        try{
            if (stream) { stream.getTracks().forEach(t=>t.stop()); }
            stopCanvasDraw();
            stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:'user'}, audio:true});
            videoSource.srcObject = stream;
            audioTrack = stream.getAudioTracks()[0] || null;
            canvas.classList.remove('hidden');
            playbackWrap.classList.add('hidden');
            playback.src = '';
            btnStart.classList.remove('hidden');
            btnStop.classList.add('hidden');
            btnRetake.classList.add('hidden');
            btnUpload.classList.add('hidden');
            btnRetryCamera.classList.add('hidden');
            videoSource.onloadedmetadata = function() {
                fitCanvasToVideo();
                startCanvasDraw();
            };
            await videoSource.play();
            if (videoSource.readyState >= 2) {
                fitCanvasToVideo();
                startCanvasDraw();
            }
        }catch(err){
            let msg = 'Camera/microphone could not be started. ';
            if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                msg = 'Camera permission was denied. Allow camera and microphone in the browser address-bar/site settings, then click Retry Camera.';
            } else if (err.name === 'NotFoundError') {
                msg = 'No camera found. Please connect a camera or use a device with a front camera.';
            } else if (err.name === 'NotReadableError' || err.name === 'TrackStartError') {
                msg = 'Camera is already in use by another app or tab. Close that app/tab and click Retry Camera.';
            } else if (err.name === 'OverconstrainedError') {
                msg = 'Camera does not support the requested settings. Click Retry Camera.';
            } else {
                msg += 'Error: ' + (err.name || err.message) + '. Please use HTTPS or localhost and allow permissions.';
            }
            showCameraError(msg);
            console.error(err);
        }
    }

    function fitCanvasToVideo(){
        const track = stream ? stream.getVideoTracks()[0] : null;
        const settings = track ? track.getSettings() : {};
        let w = settings.width || videoSource.videoWidth || 1280;
        let h = settings.height || videoSource.videoHeight || 720;
        if (w < 320) { w = 640; }
        if (h < 240) { h = 480; }
        canvas.width = w;
        canvas.height = h;
    }

    function drawCover(){
        if (!videoSource.videoWidth || !videoSource.videoHeight || !canvas.width || !canvas.height) return;
        const cw = canvas.width;
        const ch = canvas.height;
        const vw = videoSource.videoWidth;
        const vh = videoSource.videoHeight;
        const scale = Math.max(cw / vw, ch / vh);
        const dw = vw * scale;
        const dh = vh * scale;
        const x = (cw - dw) / 2;
        const y = (ch - dh) / 2;
        ctx.drawImage(videoSource, x, y, dw, dh);
    }

    function drawOverlay(){
        const pad = 10;
        const fontSize = Math.max(14, Math.round(Math.min(canvas.width, canvas.height) * 0.025));
        ctx.font = 'bold ' + fontSize + 'px sans-serif';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'top';
        const now = new Date();
        const lines = ['IP: ' + overlayIp, 'Loc: ' + overlayLoc, 'Date: ' + now.toLocaleDateString('en-IN'), 'Time: ' + now.toLocaleTimeString('en-IN')];
        let maxW = 0;
        lines.forEach(function(l){ maxW = Math.max(maxW, ctx.measureText(l).width); });
        const boxW = maxW + pad * 2;
        const lineH = fontSize + 4;
        const boxH = lines.length * lineH + pad * 2;
        const boxX = canvas.width - pad - boxW;
        const boxY = pad;
        ctx.fillStyle = 'rgba(0,0,0,0.55)';
        ctx.fillRect(boxX, boxY, boxW, boxH);
        ctx.fillStyle = 'white';
        ctx.shadowColor = 'black';
        ctx.shadowBlur = 3;
        ctx.shadowOffsetX = 1;
        ctx.shadowOffsetY = 1;
        lines.forEach(function(l, i){ ctx.fillText(l, canvas.width - pad, boxY + pad + i * lineH); });
        ctx.shadowColor = 'transparent';
    }

    function drawFrame(){
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        drawCover();
        drawOverlay();
        canvasDrawReq = requestAnimationFrame(drawFrame);
    }

    function startCanvasDraw(){
        stopCanvasDraw();
        drawFrame();
    }

    function stopCanvasDraw(){
        if (canvasDrawReq) {
            cancelAnimationFrame(canvasDrawReq);
            canvasDrawReq = null;
        }
    }

    function selectMimeType(){
        const types = ['video/webm;codecs=vp9,opus','video/webm;codecs=vp8,opus','video/webm','video/mp4'];
        for (const t of types){ if (MediaRecorder.isTypeSupported(t)) return t; }
        return '';
    }

    function startRecording(){
        if (!stream || !canvas.width) return;
        recordedChunks = [];
        recordedBlob = null;
        recordedSeconds = 0;
        recordingStartedAt = new Date().toISOString();
        let canvasStream;
        try {
            canvasStream = canvas.captureStream(25);
            if (audioTrack) { canvasStream.addTrack(audioTrack); }
        } catch (e) {
            showCameraError('Your device does not support live video recording with overlay.');
            return;
        }
        const mimeType = selectMimeType();
        try {
            mediaRecorder = new MediaRecorder(canvasStream, mimeType ? {mimeType} : undefined);
        } catch (e) {
            try {
                mediaRecorder = new MediaRecorder(canvasStream);
            } catch (e2) {
                showCameraError('Your device does not support live video recording.');
                return;
            }
        }
        mediaRecorder.ondataavailable = e => { if (e.data && e.data.size > 0) recordedChunks.push(e.data); };
        mediaRecorder.onstop = () => {
            recordedBlob = new Blob(recordedChunks, {type: mediaRecorder.mimeType || 'video/webm'});
            showPlayback();
        };
        mediaRecorder.start(250);
        btnStart.classList.add('hidden');
        btnStop.classList.remove('hidden');
        badge.classList.remove('hidden');
        timerInterval = setInterval(()=>{
            recordedSeconds++;
            timerEl.textContent = formatTime(recordedSeconds);
            if (recordedSeconds >= maxSeconds){ stopRecording(); }
        }, 1000);
    }

    function stopRecording(){
        if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
        if (timerInterval){ clearInterval(timerInterval); timerInterval = null; }
        badge.classList.add('hidden');
        btnStop.classList.add('hidden');
        stopCanvasDraw();
        if (stream) { stream.getTracks().forEach(t=>t.stop()); stream = null; }
        audioTrack = null;
    }

    function showPlayback(){
        if (!recordedBlob) return;
        canvas.classList.add('hidden');
        playbackWrap.classList.remove('hidden');
        playback.src = URL.createObjectURL(recordedBlob);
        btnRetake.classList.remove('hidden');
        btnUpload.classList.remove('hidden');
        if (recordedSeconds < minSeconds){
            errorBox.textContent = 'Recording is too short. Please record at least '+minSeconds+' seconds.';
            errorBox.classList.remove('hidden');
            btnUpload.disabled = true;
            btnUpload.classList.add('opacity-50','cursor-not-allowed');
        } else {
            errorBox.classList.add('hidden');
            btnUpload.disabled = false;
            btnUpload.classList.remove('opacity-50','cursor-not-allowed');
        }
    }

    async function retake(){
        btnRetake.classList.add('hidden');
        btnUpload.classList.add('hidden');
        errorBox.classList.add('hidden');
        playbackWrap.classList.add('hidden');
        canvas.classList.remove('hidden');
        recordedBlob = null;
        recordedChunks = [];
        recordedSeconds = 0;
        await startCamera();
    }

    function blobExtension(blob){
        const type = (blob.type || '').toLowerCase();
        if (type.includes('mp4')) return 'mp4';
        return 'webm';
    }

    const send = async (url, body) => {
        const response = await fetch(url, {method:'POST', credentials:'same-origin', headers:{'X-CSRF-Token':csrf,'Content-Type':'application/octet-stream'}, body:body});
        const text = await response.text(); let data={};
        try{ data = JSON.parse(text); }catch(e){ throw new Error(response.status===403?'Server blocked this upload request. Please refresh and retry.':'Upload server returned an invalid response.'); }
        if (!response.ok || !data.ok) throw new Error(data.error || 'Upload failed.');
        return data;
    };

    async function uploadRecording(){
        if (!recordedBlob) return;
        if (recordedSeconds < minSeconds){ errorBox.textContent='Recording too short.'; errorBox.classList.remove('hidden'); return; }
        const ext = blobExtension(recordedBlob);
        const uploadId = Array.from(window.crypto.getRandomValues(new Uint8Array(16)), b=>b.toString(16).padStart(2,'0')).join('');
        const total = Math.max(1, Math.ceil(recordedBlob.size / chunkSize));
        const recordedAt = recordingStartedAt || new Date().toISOString();
        btnUpload.disabled = true;
        btnRetake.disabled = true;
        progressWrap.classList.remove('hidden');
        errorBox.classList.add('hidden');
        try{
            for (let i=0; i<total; i++){
                statusEl.textContent = 'Uploading securely… '+(i+1)+' of '+total;
                const start = i*chunkSize;
                const end = Math.min(recordedBlob.size, start+chunkSize);
                const geoParams = '&geo_lat='+vkwGeoLat+'&geo_lng='+vkwGeoLng+'&geo_accuracy='+vkwGeoAcc+'&geo_source='+encodeURIComponent(vkwGeoSrc);
                const url = 'kyc_media_receiver.php?action=part&upload_id='+encodeURIComponent(uploadId)+'&ext='+encodeURIComponent(ext)+'&index='+i+'&total='+total+'&recorded_at='+encodeURIComponent(recordedAt)+geoParams;
                await send(url, recordedBlob.slice(start, end));
                progressBar.style.width = Math.round(((i+1)/total)*95)+'%';
            }
            statusEl.textContent = 'Finalizing your Video KYC…';
            const geoParamsF = '&geo_lat='+vkwGeoLat+'&geo_lng='+vkwGeoLng+'&geo_accuracy='+vkwGeoAcc+'&geo_source='+encodeURIComponent(vkwGeoSrc);
            await send('kyc_media_receiver.php?action=finalize&upload_id='+encodeURIComponent(uploadId)+'&ext='+encodeURIComponent(ext)+'&index=0&total='+total+'&recorded_at='+encodeURIComponent(recordedAt)+geoParamsF, new Blob([]));
            progressBar.style.width = '100%';
            statusEl.textContent = 'Upload complete.';
            location.href = redirectTo + (redirectTo.includes('?') ? '&' : '?') + 'video_uploaded=1&t=' + Date.now();
        }catch(err){
            errorBox.textContent = err.message || 'Upload failed. Please retry.';
            errorBox.classList.remove('hidden');
            statusEl.textContent = 'Upload stopped.';
            btnUpload.disabled = false;
            btnRetake.disabled = false;
        }
    }

    btnStart.addEventListener('click', startRecording);
    btnStop.addEventListener('click', stopRecording);
    btnRetake.addEventListener('click', retake);
    btnUpload.addEventListener('click', uploadRecording);
    btnRetryCamera.addEventListener('click', startCamera);

    async function updateOverlayLocation(){
        overlayLoc = '--';
        if (navigator.geolocation) {
            try {
                const pos = await new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, reject, {timeout: 10000}));
                overlayLoc = pos.coords.latitude.toFixed(4) + ', ' + pos.coords.longitude.toFixed(4);
                return;
            } catch (e) { /* fall through */ }
        }
        try {
            const res = await fetch('https://ipapi.co/json/');
            if (res.ok) {
                const data = await res.json();
                overlayLoc = [data.city, data.region, data.country_name].filter(Boolean).join(', ') || 'unavailable';
            } else {
                overlayLoc = 'unavailable';
            }
        } catch (e) {
            overlayLoc = 'unavailable';
        }
    }
    updateOverlayLocation();

    // If this widget sits inside a collapsed <details> section (e.g. embedded
    // in kyc.php), don't request camera permission until the user opens it.
    const hostDetails = canvas.closest('details');
    if (hostDetails && !hostDetails.open) {
        hostDetails.addEventListener('toggle', function onHostToggle(){
            if (hostDetails.open) {
                hostDetails.removeEventListener('toggle', onHostToggle);
                startCamera();
            }
        });
    } else {
        startCamera();
    }
})();
</script>
