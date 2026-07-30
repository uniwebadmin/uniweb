<?php
require_once __DIR__ . '/config.php';
header('Location: ' . APP_URL . '/tour_videos.php');
exit;
?>

<style>
.tour-stage{position:relative;background:#0a0f1a;border:1px solid rgba(255,255,255,.08);border-radius:1rem;overflow:hidden}
.tour-slide{display:none;animation:fadeIn .35s ease}
.tour-slide.active{display:block}
.tour-img-wrap{aspect-ratio:16/9;max-height:min(52vh,420px);background:#111827;display:flex;align-items:center;justify-content:center}
.tour-img-wrap img{width:100%;height:100%;object-fit:contain;object-position:center}
.tour-embed{aspect-ratio:16/10;max-height:min(58vh,480px);background:#000}
.tour-embed iframe{width:100%;height:100%;border:0}
.tour-progress{height:3px;background:rgba(255,255,255,.08)}
.tour-progress-bar{height:100%;background:linear-gradient(90deg,#0ea5e9,#10b981);transition:width .3s}
.tour-thumb{opacity:.55;transition:.2s;cursor:pointer;border:2px solid transparent}
.tour-thumb.active,.tour-thumb:hover{opacity:1;border-color:#38bdf8}
.tour-thumbs{display:flex;gap:.5rem;width:100%;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain;padding-bottom:.5rem}
.tour-thumbs::-webkit-scrollbar{height:4px}
@keyframes pulse-ring{0%,100%{box-shadow:0 0 0 0 rgba(14,165,233,.4)}50%{box-shadow:0 0 0 8px rgba(14,165,233,0)}}
.tour-playing .play-btn{animation:pulse-ring 1.5s infinite}
</style>

<section class="pt-24 pb-16 px-4 w-full max-w-5xl mx-auto min-w-0" id="tour-app">
    <div class="text-center mb-6">
        <p class="text-sky-400 text-xs font-bold uppercase tracking-widest mb-2">Guided Video Tour · English voice narration</p>
        <h1 class="text-2xl sm:text-4xl font-extrabold">UniWeb Platform Demo</h1>
        <p class="text-gray-500 text-sm mt-2">Play the slideshow and voice narration. Slide 1 contains a <strong class="text-sky-400">₹1 Test Mode checkout</strong>.</p>
    </div>

    <div class="tour-stage mb-4 shadow-2xl" id="tour-stage">
        <div class="tour-progress"><div class="tour-progress-bar" id="tour-bar" style="width:0%"></div></div>

        <?php foreach ($slides as $i => $s): ?>
        <div class="tour-slide <?= $i === $startSlide ? 'active' : '' ?>" data-index="<?= $i ?>" data-id="<?= e($s['id']) ?>">
            <?php if (!empty($s['embed']) && $i === 0): ?>
            <div class="tour-embed" id="live-embed-wrap">
                <iframe id="live-checkout-frame" src="about:blank" data-src="<?= e($s['embed']) ?>" title="Test Mode checkout" loading="lazy"></iframe>
            </div>
            <?php else: ?>
            <div class="tour-img-wrap">
                <?php if (!empty($s['img']) && is_file(__DIR__ . '/' . $s['img'])): ?>
                <img src="<?= e($s['img']) ?>" alt="<?= e($s['title']) ?>">
                <?php else: ?>
                <div class="text-center px-8"><p class="text-sky-400 text-xs uppercase tracking-widest mb-3"><?= e($s['subtitle']) ?></p><p class="text-2xl font-bold text-white"><?= e($s['title']) ?></p><p class="text-sm text-gray-500 mt-3"><?= e($s['desc']) ?></p></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="p-5 sm:p-6 border-t border-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
        <p class="text-sky-400 text-xs font-semibold mb-1"><?= e($s['subtitle']) ?></p>
                        <h2 class="text-xl sm:text-2xl font-bold"><?= e($s['title']) ?></h2>
                        <p class="text-gray-400 text-sm sm:text-base mt-2 leading-relaxed"><?= e($s['desc']) ?></p>
                    </div>
                    <span class="text-xs text-gray-600 shrink-0"><?= $i + 1 ?> / <?= count($slides) ?></span>
                </div>
                <?php if (!empty($s['action'])): ?>
                <a href="<?= e($s['action'][1]) ?>" <?= str_starts_with($s['action'][1], 'http') || str_contains($s['action'][1], 'checkout') || str_contains($s['action'][1], 'demo') ? 'target="_blank"' : '' ?> class="inline-block mt-4 text-sm bg-sky-600/20 text-sky-400 hover:bg-sky-600/30 px-4 py-2 rounded-lg font-medium"><?= e($s['action'][0]) ?> →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="flex flex-wrap items-center justify-center gap-2 sm:gap-3 mb-6">
        <button type="button" id="btn-prev" class="glass px-4 py-2.5 rounded-xl text-sm hover:bg-white/5">← Prev</button>
        <button type="button" id="btn-play" class="play-btn bg-sky-600 hover:bg-sky-500 text-white px-6 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2">
            <span id="play-icon">▶</span> <span id="play-label">Play Tour</span>
        </button>
        <button type="button" id="btn-next" class="glass px-4 py-2.5 rounded-xl text-sm hover:bg-white/5">Next →</button>
        <button type="button" id="btn-mute" class="glass px-3 py-2.5 rounded-xl text-xs text-gray-400" title="Mute voice">🔊</button>
    </div>

    <div class="tour-thumbs">
        <?php foreach ($slides as $i => $s): ?>
        <button type="button" class="tour-thumb rounded-lg overflow-hidden w-20 sm:w-24 shrink-0 <?= $i === $startSlide ? 'active' : '' ?>" data-goto="<?= $i ?>">
            <span class="w-full aspect-video flex items-center justify-center bg-dark-900 text-[10px] text-gray-400 px-2"><?= e($s['title']) ?></span>
        </button>
        <?php endforeach; ?>
    </div>

    <div class="text-center mt-8">
        <a href="<?= e($demo['pay_url']) ?>" target="_blank" rel="noopener" class="inline-block bg-brand-600 hover:bg-brand-500 text-white px-8 py-3 rounded-xl font-semibold">Open ₹1 Test Checkout →</a>
    </div>
</section>

<script>
(function(){
    const slides = <?= json_encode(array_map(fn($s) => [
        'narration_en' => $s['narration_en'],
        'id' => $s['id'],
    ], $slides), JSON_UNESCAPED_UNICODE) ?>;
    let idx = <?= $startSlide ?>;
    let playing = false;
    let muted = false;
    let lang = 'en';
    let timer = null;
    const SLIDE_MS = 14000;

    const els = document.querySelectorAll('.tour-slide');
    const thumbs = document.querySelectorAll('.tour-thumb');
    const bar = document.getElementById('tour-bar');
    const app = document.getElementById('tour-app');

    function loadEmbed() {
        const frame = document.getElementById('live-checkout-frame');
        if (frame && frame.src === 'about:blank' && frame.dataset.src) {
            frame.src = frame.dataset.src;
        }
    }

    function goTo(i, speak) {
        idx = Math.max(0, Math.min(slides.length - 1, i));
        els.forEach((el, n) => el.classList.toggle('active', n === idx));
        thumbs.forEach((t, n) => t.classList.toggle('active', n === idx));
        bar.style.width = ((idx + 1) / slides.length * 100) + '%';
        if (idx === 0) loadEmbed();
        if (speak && !muted) speakCurrent();
        history.replaceState(null, '', '?slide=' + idx);
    }

    function speakCurrent() {
        if (!window.speechSynthesis) return;
        window.speechSynthesis.cancel();
        const text = slides[idx].narration_en || '';
        const u = new SpeechSynthesisUtterance(text);
        u.lang = 'en-IN';
        u.rate = 0.95;
        const voices = speechSynthesis.getVoices();
        const prefer = voices.find(v => v.lang.startsWith('en') && /female|google|natural/i.test(v.name))
            || voices.find(v => v.lang.startsWith('en'));
        if (prefer) u.voice = prefer;
        speechSynthesis.speak(u);
    }

    function stopTour() {
        playing = false;
        clearInterval(timer);
        speechSynthesis.cancel();
        app.classList.remove('tour-playing');
        document.getElementById('play-icon').textContent = '▶';
        document.getElementById('play-label').textContent = 'Play Tour';
    }

    function startTour() {
        playing = true;
        app.classList.add('tour-playing');
        document.getElementById('play-icon').textContent = '⏸';
        document.getElementById('play-label').textContent = 'Pause';
        if (idx === 0) loadEmbed();
        speakCurrent();
        clearInterval(timer);
        timer = setInterval(() => {
            if (idx >= slides.length - 1) { stopTour(); return; }
            goTo(idx + 1, true);
        }, SLIDE_MS);
    }

    document.getElementById('btn-play').onclick = () => playing ? stopTour() : startTour();
    document.getElementById('btn-prev').onclick = () => { stopTour(); goTo(idx - 1, false); };
    document.getElementById('btn-next').onclick = () => { stopTour(); goTo(idx + 1, false); };
    document.getElementById('btn-mute').onclick = function() {
        muted = !muted;
        this.textContent = muted ? '🔇' : '🔊';
        if (muted) speechSynthesis.cancel();
    };
    thumbs.forEach(t => t.onclick = () => { stopTour(); goTo(parseInt(t.dataset.goto, 10), false); });

    if (speechSynthesis.onvoiceschanged !== undefined) {
        speechSynthesis.onvoiceschanged = () => speechSynthesis.getVoices();
    }
    goTo(idx, false);
    if (new URLSearchParams(location.search).get('autoplay') === '1') setTimeout(startTour, 600);
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
