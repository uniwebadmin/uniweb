<?php
declare(strict_types=1);

/**
 * Branded 404 for missing URLs (wired via .htaccess ErrorDocument).
 * Keeps customers on UniWeb instead of the host default error page.
 */
require_once __DIR__ . '/config.php';

http_response_code(404);
header('Cache-Control: no-store');

$pageTitle = 'Page not found';
$pageDescription = 'The page you requested could not be found on UniWeb.';
require_once __DIR__ . '/header.php';
?>
<section class="pt-28 pb-20 px-4">
    <div class="max-w-lg mx-auto glass rounded-2xl p-8 text-center">
        <p class="text-xs uppercase tracking-wider text-gray-500 mb-2">Error 404</p>
        <h1 class="text-xl font-semibold mb-2">Page not found</h1>
        <p class="text-sm text-gray-400 mb-6">This URL does not exist or was moved. Use the links below to continue.</p>
        <div class="flex flex-wrap items-center justify-center gap-3">
            <a href="index.php" class="inline-block btn-primary px-5 py-2.5 text-sm">Home</a>
            <a href="demo.php" class="inline-block text-sm text-gray-400 hover:text-white px-3 py-2">Demo</a>
            <a href="signup.php" class="inline-block text-sm text-gray-400 hover:text-white px-3 py-2">Sign up</a>
            <a href="login.php" class="inline-block text-sm text-gray-400 hover:text-white px-3 py-2">Login</a>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
