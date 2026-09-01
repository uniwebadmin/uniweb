<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
if (!function_exists('requireMerchantTeamCapability') && is_file(__DIR__ . '/includes/merchant_team.php')) {
    require_once __DIR__ . '/includes/merchant_team.php';
}
if (function_exists('requireMerchantTeamCapability')) {
    requireMerchantTeamCapability('settings');
}
ensureKycSchema();
require_once __DIR__ . '/includes/kyc_upload.php';
require_once __DIR__ . '/includes/client_context.php';
$merchant = requireMerchantAccount();

$clientIp = getRealClientIp();

$validDocTypes = ['merchant_photo', 'shop_signboard', 'shop_outside', 'shop_inside_1', 'shop_inside_2'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== csrfToken()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid session token. Please refresh the page.']);
        exit;
    }
    $docType = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['doc_type'] ?? ''));
    if (!in_array($docType, $validDocTypes, true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid photo type.']);
        exit;
    }
    $file = $_FILES['photo'] ?? null;
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Photo did not reach the server. Please retry.']);
        exit;
    }
    $saved = saveMerchantKycUpload(
        (int)$merchant['id'],
        $docType,
        $file,
        ['jpg', 'jpeg', 'png'],
        10 * 1024 * 1024,
        parseGeoFromRequest()
    );
    if (empty($saved['ok'])) {
        echo json_encode(['ok' => false, 'error' => $saved['error'] ?? 'Upload failed.']);
        exit;
    }
    echo json_encode(['ok' => true, 'scan_status' => $saved['scan_status'] ?? 'pending']);
    exit;
}

$pageTitle = 'Shop Photos KYC';
require_once __DIR__ . '/header.php';

$steps = [
    ['type' => 'merchant_photo', 'title' => 'Merchant Photo', 'desc' => 'Take a live selfie of the merchant.'],
    ['type' => 'shop_signboard', 'title' => 'Signboard', 'desc' => 'Capture a clear photo of your shop signboard.'],
    ['type' => 'shop_outside', 'title' => 'Outside shop', 'desc' => 'Capture the full shop from outside so the signboard and entrance are clearly visible.'],
    ['type' => 'shop_inside_1', 'title' => 'Inside shop 1', 'desc' => 'Capture the inside of your shop.'],
    ['type' => 'shop_inside_2', 'title' => 'Inside shop 2', 'desc' => 'Capture another inside view of your shop.'],
];
?>
<div class="max-w-2xl">
    <div class="glass rounded-2xl p-6 mb-6 border border-violet-500/20">
        <div class="flex items-center gap-4">
            <span class="w-14 h-14 rounded-xl bg-violet-500/15 text-violet-400 flex items-center justify-center shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </span>
            <div class="flex-1 min-w-0">
                <p class="text-xs text-violet-400 uppercase tracking-wider mb-1">Shop verification</p>
                <h1 class="text-lg font-bold">Shop Photos KYC</h1>
                <p class="text-xs text-gray-500 mt-0.5">5 live photos with IP, location and timestamp watermark</p>
            </div>
        </div>
    </div>

    <div class="glass rounded-xl p-6 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h2 id="step-title" class="font-semibold text-sm sm:text-base">Step 1 of 5: Merchant Photo</h2>
            <span id="step-counter" class="text-xs text-gray-500">1 / 5</span>
        </div>
        <p id="step-desc" class="text-sm text-gray-400 mb-4">Take a live selfie of the merchant.</p>

        <div id="camera-error" class="hidden bg-red-500/10 border border-red-500/30 text-red-300 text-sm px-4 py-3 rounded-xl mb-4"></div>

        <div class="relative bg-black rounded-xl overflow-hidden mb-4 aspect-[3/4] sm:aspect-video">
            <video id="video-source" class="hidden" autoplay playsinline muted></video>
            <canvas id="photo-canvas" class="w-full h-full object-cover bg-black"></canvas>
            <div id="captured-preview" class="hidden absolute inset-0 w-full h-full bg-black flex items-center justify-center">
                <img id="captured-img" class="w-full h-full object-cover" alt="Captured photo">
            </div>
        </div>

        <div id="capture-controls" class="flex flex-wrap gap-3 mb-4">
            <button id="btn-capture" type="button" class="btn-primary px-6 py-2.5 rounded-lg text-sm">Capture Photo</button>
            <button id="btn-retry-camera" type="button" class="hidden bg-amber-600 hover:bg-amber-500 text-white px-6 py-2.5 rounded-lg text-sm">Retry Camera</button>
        </div>

        <div id="review-controls" class="hidden flex flex-wrap gap-3 mb-4">
            <button id="btn-retake" type="button" class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-2.5 rounded-lg text-sm">Retake</button>
            <button id="btn-next" type="button" class="btn-primary px-6 py-2.5 rounded-lg text-sm">Next</button>
        </div>

        <div id="upload-progress" class="hidden">
            <div class="h-2 rounded-full bg-gray-800 overflow-hidden"><div id="upload-progress-bar" class="h-full bg-brand-500 transition-all" style="width:0%"></div></div>
            <p id="upload-status" class="text-xs text-gray-500 text-center mt-2">Uploading securely…</p>
        </div>

        <p class="text-xs text-gray-600 mt-2">Each photo will carry your IP address, location and the exact capture time/date for compliance.</p>
    </div>

    <p class="text-xs text-gray-600 text-center"><a href="kyc.php" class="text-brand-400 hover:underline">← Back to KYC Documents</a></p>
</div>
<script>
(function(){
    const videoSource = document.getElementById('video-source');
    const canvas = document.getElementById('photo-canvas');
    const ctx = canvas.getContext('2d');
    const capturedPreview = document.getElementById('captured-preview');
    const capturedImg = document.getElementById('captured-img');
    const btnCapture = document.getElementById('btn-capture');
    const btnRetake = document.getElementById('btn-retake');
    const btnNext = document.getElementById('btn-next');
    const btnRetryCamera = document.getElementById('btn-retry-camera');
    const errorBanner = document.getElementById('camera-error');
    const progressWrap = document.getElementById('upload-progress');
    const progressBar = document.getElementById('upload-progress-bar');
    const statusEl = document.getElementById('upload-status');
    const stepTitle = document.getElementById('step-title');
    const stepCounter = document.getElementById('step-counter');
    const stepDesc = document.getElementById('step-desc');
    const captureControls = document.getElementById('capture-controls');
    const reviewControls = document.getElementById('review-controls');

    const overlayIp = <?= json_encode($clientIp) ?>;
    let overlayLoc = '--';

    const steps = <?= json_encode($steps, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let currentStep = 0;
    let stream = null;
    let canvasDrawReq = null;
    let capturedBlob = null;
    let recordedAt = null;

    if (!navigator.mediaDevices || !window.HTMLCanvasElement) {
        showError('Your browser does not support live camera capture. Please use a modern browser.');
        btnCapture.disabled = true;
        return;
    }

    let geoLat = 0, geoLng = 0, geoAcc = 0, geoSrc = '';

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(pos) {
                geoLat = pos.coords.latitude;
                geoLng = pos.coords.longitude;
                geoAcc = pos.coords.accuracy || 0;
                geoSrc = 'html5';
            },
            function() {
                geoSrc = 'denied';
            },
            {enableHighAccuracy: true, timeout: 10000, maximumAge: 60000}
        );
    }

    const csrf = <?= json_encode(csrfToken()) ?>;

    function showError(msg){
        errorBanner.textContent = msg;
        errorBanner.classList.remove('hidden');
        btnCapture.classList.add('hidden');
        btnRetryCamera.classList.remove('hidden');
    }

    function updateStepUI(){
        const step = steps[currentStep];
        stepTitle.textContent = 'Step ' + (currentStep + 1) + ' of ' + steps.length + ': ' + step.title;
        stepCounter.textContent = (currentStep + 1) + ' / ' + steps.length;
        stepDesc.textContent = step.desc;
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

    async function startCamera(){
        errorBanner.classList.add('hidden');
        btnRetryCamera.classList.add('hidden');
        btnCapture.classList.remove('hidden');
        captureControls.classList.remove('hidden');
        reviewControls.classList.add('hidden');
        capturedPreview.classList.add('hidden');
        canvas.classList.remove('hidden');
        capturedBlob = null;
        recordedAt = null;
        capturedImg.src = '';
        try {
            if (stream) { stream.getTracks().forEach(t => t.stop()); }
            stopCanvasDraw();
            const isSelfie = steps[currentStep] && steps[currentStep].type === 'merchant_photo';
            let constraints = { video: { facingMode: { ideal: isSelfie ? 'user' : 'environment' } }, audio: false };
            try {
                stream = await navigator.mediaDevices.getUserMedia(constraints);
            } catch (err) {
                stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            }
            videoSource.srcObject = stream;
            videoSource.onloadedmetadata = function() {
                fitCanvasToVideo();
                startCanvasDraw();
            };
            await videoSource.play();
            if (videoSource.readyState >= 2) {
                fitCanvasToVideo();
                startCanvasDraw();
            }
        } catch (err) {
            let msg = 'Could not start camera. ';
            if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                msg = 'Camera permission was denied. Allow camera in browser settings and click Retry Camera.';
            } else if (err.name === 'NotFoundError') {
                msg = 'No camera found. Please use a device with a camera.';
            } else if (err.name === 'NotReadableError' || err.name === 'TrackStartError') {
                msg = 'Camera is already in use by another app. Close it and retry.';
            } else if (err.name === 'OverconstrainedError') {
                msg = 'Camera does not support the requested settings. Click Retry Camera.';
            } else {
                msg += (err.name || err.message) + '.';
            }
            showError(msg);
        }
    }

    function capturePhoto(){
        if (!canvas.width || !canvas.height) return;
        recordedAt = new Date().toISOString();
        canvas.toBlob(function(blob){
            if (!blob) {
                showError('Could not capture photo. Please retry.');
                return;
            }
            capturedBlob = blob;
            capturedImg.src = URL.createObjectURL(blob);
            canvas.classList.add('hidden');
            capturedPreview.classList.remove('hidden');
            captureControls.classList.add('hidden');
            reviewControls.classList.remove('hidden');
        }, 'image/jpeg', 0.92);
    }

    function retakePhoto(){
        capturedBlob = null;
        recordedAt = null;
        capturedImg.src = '';
        capturedPreview.classList.add('hidden');
        canvas.classList.remove('hidden');
        captureControls.classList.remove('hidden');
        reviewControls.classList.add('hidden');
        startCanvasDraw();
    }

    async function uploadAndNext(){
        if (!capturedBlob) return;
        progressWrap.classList.remove('hidden');
        errorBanner.classList.add('hidden');
        progressBar.style.width = '0%';
        statusEl.textContent = 'Uploading securely…';
        const step = steps[currentStep];
        const formData = new FormData();
        formData.append('csrf_token', csrf);
        formData.append('doc_type', step.type);
        formData.append('recorded_at', recordedAt || new Date().toISOString());
        formData.append('photo', capturedBlob, step.type + '.jpg');
        formData.append('geo_lat', geoLat);
        formData.append('geo_lng', geoLng);
        formData.append('geo_accuracy', geoAcc);
        formData.append('geo_source', geoSrc);
        try {
            progressBar.style.width = '50%';
            const res = await fetch('merchant_shop_photos.php', { method: 'POST', credentials: 'same-origin', body: formData });
            const text = await res.text();
            let data = {};
            try { data = JSON.parse(text); } catch (e) { throw new Error('Server returned an invalid response.'); }
            if (!res.ok || !data.ok) throw new Error(data.error || 'Upload failed.');
            progressBar.style.width = '100%';
            currentStep++;
            if (currentStep >= steps.length) {
                location.href = 'kyc.php?shop_photos=1&t=' + Date.now();
                return;
            }
            updateStepUI();
            await startCamera();
        } catch (err) {
            showError(err.message || 'Upload failed. Please retry.');
        } finally {
            progressWrap.classList.add('hidden');
        }
    }

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

    btnCapture.addEventListener('click', capturePhoto);
    btnRetake.addEventListener('click', retakePhoto);
    btnNext.addEventListener('click', uploadAndNext);
    btnRetryCamera.addEventListener('click', function(){ startCamera(); });

    updateOverlayLocation();
    updateStepUI();
    startCamera();
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
