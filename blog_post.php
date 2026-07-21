<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/blog_content.php';
ensureProfessionalBlogContent();
$slug = substr(trim((string)($_GET['slug'] ?? '')), 0, 200);
$stmt = getDB()->prepare("SELECT * FROM blog_posts WHERE slug = ? AND status = 'published'");
$stmt->execute([$slug]);
$post = $stmt->fetch();
if (!$post) {
    http_response_code(404);
    $pageTitle = 'Article not found';
    require_once __DIR__ . '/header.php';
    echo '<main class="company-page"><section class="company-section"><div class="company-shell" style="max-width:640px">'
        . '<div class="glass rounded-2xl p-8 text-center">'
        . '<h1 class="text-xl font-semibold mb-2">Article not found</h1>'
        . '<p class="text-sm text-gray-400 mb-6">This guide may have moved or been unpublished. Browse the Knowledge Centre for current articles.</p>'
        . '<a href="blog.php" class="inline-block btn-primary px-5 py-2.5 text-sm">All guides</a>'
        . ' <a href="index.php" class="inline-block ml-2 text-sm text-gray-400 hover:text-white">Home</a>'
        . '</div></div></section></main>';
    require_once __DIR__ . '/footer.php';
    exit;
}
$pageTitle = $post['title_en'];
$content = $post['content_en'];
require_once __DIR__ . '/header.php';
?>
<main class="company-page">
    <section class="company-hero"><div class="company-shell" style="max-width:900px">
        <a href="blog.php" class="company-eyebrow">← Knowledge Centre</a>
        <h1 style="font-size:clamp(2rem,5vw,3.5rem)"><?= e($pageTitle) ?></h1>
        <p><?= e($post['excerpt_en'] ?? '') ?></p>
        <div class="public-doc-meta"><span><?= e(formatDate($post['created_at'])) ?></span><span>Merchant education</span><span>General guidance</span></div>
    </div></section>
    <section class="company-section"><div class="company-shell" style="max-width:900px">
        <article class="public-doc-article public-doc-company blog-article" style="margin-top:0">
            <?= $content ?>
        </article>
        <div class="public-doc-notice mt-6"><strong>Note:</strong> This article is general operational guidance, not legal or financial advice. Your activated commercial schedule, partner rules and applicable law control your specific account.</div>
        <div class="company-cta mt-6"><h2>Continue learning</h2><p>Explore more merchant guides or contact support for an account-specific question.</p><div class="flex flex-wrap gap-3"><a href="blog.php" class="btn-primary px-6 py-3">All guides</a><a href="contact.php" class="px-6 py-3 rounded-lg border border-gray-700">Contact support</a></div></div>
    </div></section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
