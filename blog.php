<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/blog_content.php';
ensureProfessionalBlogContent();
$pageTitle = __('blog');
$posts = getDB()->query("SELECT * FROM blog_posts WHERE status='published' ORDER BY created_at DESC")->fetchAll();
require_once __DIR__ . '/header.php';
?>
<main class="company-page">
    <section class="company-hero"><div class="company-shell">
        <div class="company-eyebrow">UniWeb Knowledge Centre</div>
        <h1>Practical payment guidance for merchants.</h1>
        <p>Clear articles on UPI operations, KYC, settlement, refunds, security and integration—written to help merchants make safer operational decisions.</p>
    </div></section>
    <section class="company-section"><div class="company-shell">
        <div class="flex flex-wrap items-end justify-between gap-5 mb-7">
            <div><div class="company-kicker">Latest guidance</div><h2 class="company-title" style="margin-bottom:0">Merchant operations library</h2></div>
            <a href="faq.php" class="text-sm text-brand-400">Browse quick answers →</a>
        </div>
        <div class="blog-grid">
            <?php if (empty($posts)): ?>
            <div class="company-card"><h2>Guides are being prepared</h2><p>Visit the FAQ and Compliance Framework for current operational information.</p></div>
            <?php else: foreach ($posts as $p): ?>
            <a href="blog_post.php?slug=<?= urlencode((string)$p['slug']) ?>" class="blog-card">
                <div class="company-kicker">Merchant guide</div>
                <h2><?= e($p['title_en']) ?></h2>
                <p><?= e($p['excerpt_en'] ?? '') ?></p>
                <div class="blog-card-meta"><?= e(formatDate($p['created_at'])) ?> · Read guide →</div>
            </a>
            <?php endforeach; endif; ?>
        </div>
    </div></section>
    <section class="company-section" style="padding-top:0"><div class="company-shell">
        <div class="company-grid">
            <div class="company-card"><h3>Payments</h3><p>Confirmation states, QR types, webhooks, reconciliation and customer payment safety.</p></div>
            <div class="company-card"><h3>Compliance</h3><p>Entity-specific KYC, website readiness, risk review and secure document handling.</p></div>
            <div class="company-card"><h3>Operations</h3><p>Settlement calculations, refund evidence, disputes, account security and staff controls.</p></div>
        </div>
    </div></section>
    <section class="company-section" style="padding-top:0"><div class="company-shell"><div class="company-cta">
        <h2>Need an answer about your account?</h2><p>Articles provide general guidance. Activated pricing, limits, payment methods and settlement schedules appear in your Merchant Portal and commercial setup.</p><a href="contact.php" class="btn-primary inline-block px-6 py-3">Contact support</a>
    </div></div></section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
