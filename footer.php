<?php if (!empty($isMerchant) || !empty($isAdmin)): ?>
        </div>
    </main>
</div>
<?php endif; ?>

<?php if (!empty($footerVariant) && $footerVariant === 'checkout'): ?>
<?php /* checkout footer rendered inline on checkout.php */ ?>
<?php elseif (!empty($footerVariant) && $footerVariant === 'auth'): ?>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
<?php elseif (empty($hideFooter)):
    $isPanel = !empty($isMerchant) || !empty($isAdmin);
    $footerMargin = $isPanel ? 'lg:ml-64' : '';
?>
<footer class="<?= $footerMargin ?> w-full max-w-full border-t border-gray-800/70 bg-dark-950 mt-auto">
    <?php if ($isPanel): ?>
    <div class="w-full max-w-6xl mx-auto px-4 sm:px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-400">
        <span>&copy; <?= date('Y') ?> <?= COMPANY_LEGAL_NAME ?></span>
        <nav class="flex flex-wrap justify-center gap-x-4 gap-y-1">
            <?php if (!empty($isAdmin)): ?>
            <a href="admin_support.php" class="hover:text-brand-400 transition">Support</a>
            <?php if (isSuperAdmin()): ?>
            <a href="admin_platform_status.php" class="hover:text-brand-400 transition">Status</a>
            <?php else: ?>
            <a href="status.php" class="hover:text-brand-400 transition">Status</a>
            <?php endif; ?>
            <?php else: ?>
            <a href="support.php" class="hover:text-brand-400 transition">Support</a>
            <?php endif; ?>
            <a href="faq.php" class="hover:text-brand-400 transition">FAQ</a>
            <a href="terms.php" class="hover:text-brand-400 transition">Terms</a>
            <a href="privacy.php" class="hover:text-brand-400 transition">Privacy</a>
        </nav>
        <span class="font-mono text-gray-500"><?= !empty($isAdmin) ? '30' : '60' ?> min idle · IST · v<?= APP_VERSION ?></span>
    </div>
    <?php else: ?>
    <div class="site-footer-wrap w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <div class="site-footer-grid">
            <div class="site-footer-card">
                <?php $logoHref = 'index.php'; $logoSize = 'sm'; require __DIR__ . '/includes/brand_logo.php'; ?>
                <p class="text-sm text-gray-400 mt-3 leading-relaxed max-w-xs">B2B payments for Indian businesses — UPI, cards, links &amp; settlements.</p>
            </div>
            <div class="site-footer-card">
                <p class="site-footer-heading text-sm font-semibold text-gray-300 mb-3">Product</p>
                <ul class="space-y-2 text-sm text-gray-400">
                    <li><a href="tour_videos.php" class="hover:text-brand-400 transition">Platform Tour</a></li>
                    <li><a href="demo.php" class="hover:text-brand-400 transition">Live Demo</a></li>
                    <li><a href="solutions.php" class="hover:text-brand-400 transition">Solutions</a></li>
                    <li><a href="pricing.php" class="hover:text-brand-400 transition">Pricing</a></li>
                    <li><a href="api_docs.php" class="hover:text-brand-400 transition">API Docs</a></li>
                    <li><a href="status.php" class="hover:text-brand-400 transition">System Status</a></li>
                </ul>
            </div>
            <div class="site-footer-card">
                <p class="site-footer-heading text-sm font-semibold text-gray-300 mb-3">Company</p>
                <ul class="space-y-2 text-sm text-gray-400">
                    <li><a href="about.php" class="hover:text-brand-400 transition">About</a></li>
                    <li><a href="solutions.php" class="hover:text-brand-400 transition">Solutions</a></li>
                    <li><a href="roadmap.php" class="hover:text-brand-400 transition">Roadmap</a></li>
                    <li><a href="blog.php" class="hover:text-brand-400 transition">Blog</a></li>
                    <li><a href="contact.php" class="hover:text-brand-400 transition">Contact</a></li>
                    <li><a href="faq.php" class="hover:text-brand-400 transition">FAQ</a></li>
                    <li><a href="payment_status.php" class="hover:text-brand-400 transition">Track Payment</a></li>
                    <li><a href="customer_login.php" class="hover:text-brand-400 transition">Customer Login</a></li>
                    <li><a href="admin_login.php" class="hover:text-brand-400 transition">Admin Login</a></li>
                </ul>
            </div>
            <div class="site-footer-card">
                <p class="site-footer-heading text-sm font-semibold text-gray-300 mb-3">Legal</p>
                <ul class="space-y-2 text-sm text-gray-400">
                    <li><a href="terms.php" class="hover:text-brand-400 transition">Terms</a></li>
                    <li><a href="privacy.php" class="hover:text-brand-400 transition">Privacy</a></li>
                    <li><a href="refund_policy.php" class="hover:text-brand-400 transition">Refund Policy</a></li>
                    <li><a href="grievance.php" class="hover:text-brand-400 transition">Grievance Redressal</a></li>
                    <li><a href="business_agreement.php" class="hover:text-brand-400 transition">Merchant Agreement</a></li>
                    <li><a href="compliance.php" class="hover:text-brand-400 transition">Compliance</a></li>
                    <li><a href="pci_dss.php" class="hover:text-brand-400 transition">PCI-DSS Readiness</a></li>
                    <li><a href="trust.php" class="hover:text-brand-400 transition">Trust &amp; Security</a></li>
                    <li><a href="case_studies.php" class="hover:text-brand-400 transition">By the Numbers</a></li>
                </ul>
            </div>
        </div>
        <div class="site-footer-bottom mt-5 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-gray-500">
            <span>&copy; <?= date('Y') ?> <?= COMPANY_LEGAL_NAME ?></span>
            <span class="hidden sm:inline">GST <?= COMPANY_GST ?> · CIN <?= COMPANY_CIN ?></span>
            <span>IST · v<?= APP_VERSION ?></span>
        </div>
    </div>
    <?php endif; ?>
</footer>
<?php endif; ?>
<?php
// Universal clickable IDs on every page (admin / merchant / customer / public)
if (empty($skipIdClickableJs)):
?>
<script src="<?= defined('APP_URL') ? e(APP_URL) : '' ?>/assets/js/id-clickable.js?v=20260724c" defer></script>
<?php endif; ?>
</body>
</html>
