<?php
/**
 * Auth portal light/dark theme toggle — synced with main portal (uniweb_theme).
 * Default: dark mode.
 */
?>
<button type="button" class="ap-theme-toggle" id="apThemeToggle" aria-label="Toggle light / dark mode">🌙</button>
<script>
(function(){
    const body = document.body;
    const key = 'uniweb_theme';
    const isLight = localStorage.getItem(key) === 'light';
    if (!isLight) {
        body.setAttribute('data-ap-theme', 'dark');
    }
    const btn = document.getElementById('apThemeToggle');
    const syncIcon = () => {
        if (!btn) return;
        btn.textContent = body.getAttribute('data-ap-theme') === 'dark' ? '🌙' : '☀️';
    };
    syncIcon();
    if (btn) {
        btn.addEventListener('click', function(){
            if (body.getAttribute('data-ap-theme') === 'dark') {
                body.removeAttribute('data-ap-theme');
                localStorage.setItem(key, 'light');
            } else {
                body.setAttribute('data-ap-theme', 'dark');
                localStorage.setItem(key, 'dark');
            }
            syncIcon();
        });
    }
})();
</script>
