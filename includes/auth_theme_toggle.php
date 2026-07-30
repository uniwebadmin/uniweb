<?php
/**
 * Auth portal light/dark theme toggle.
 * Include once inside .ap-wrap on login/signup pages.
 */
?>
<button type="button" class="ap-theme-toggle" id="apThemeToggle" aria-label="Toggle dark mode">🌙</button>
<script>
(function(){
    const body = document.body;
    const key = 'uniweb-auth-theme';
    if (localStorage.getItem(key) === 'dark') {
        body.setAttribute('data-ap-theme', 'dark');
    }
    const btn = document.getElementById('apThemeToggle');
    if (btn) {
        btn.addEventListener('click', function(){
            if (body.getAttribute('data-ap-theme') === 'dark') {
                body.removeAttribute('data-ap-theme');
                localStorage.setItem(key, 'light');
            } else {
                body.setAttribute('data-ap-theme', 'dark');
                localStorage.setItem(key, 'dark');
            }
        });
    }
})();
</script>
