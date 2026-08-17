<?php
require_once __DIR__ . '/config.php';
if (!function_exists('computeUptimeStats')) {
    require_once __DIR__ . '/includes/ops_security.php';
}

// Public trust stats — real numbers only, never fabricated. Small counts are
// hidden behind a threshold so we never overclaim (and never publish a
// misleadingly tiny number) while the merchant base is still growing.
const CASE_STUDY_MERCHANT_THRESHOLD = 25;
const CASE_STUDY_TXN_THRESHOLD = 100;

$db = getDB();
$merchants = 0;
$txns = 0;
$volume = 0.0;
try {
    $merchants = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE status='active'")->fetchColumn();
    $txns = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='success'")->fetchColumn();
    $volume = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='success'")->fetchColumn();
} catch (Throwable $e) { /* fall through with zeros */ }

$showMerchants = $merchants >= CASE_STUDY_MERCHANT_THRESHOLD;
$showTxns = $txns >= CASE_STUDY_TXN_THRESHOLD;
$uptime = computeUptimeStats(90);

$pageTitle = 'UniWeb by the Numbers';
$pageDescription = 'Real, verifiable platform statistics — uptime, security posture and (once meaningful) merchant and transaction volume. No fabricated testimonials.';
$canonicalUrl = APP_URL . '/case_studies.php';
require_once __DIR__ . '/header.php';
?>
<main class="company-page">
    <section class="company-hero"><div class="company-shell">
        <div class="company-eyebrow">Track Record</div>
        <h1>UniWeb by the numbers.</h1>
        <p>We publish only what we can verify. No fabricated customer quotes — just real platform statistics, updated live from our own database.</p>
    </div></section>

    <section class="company-section"><div class="company-shell">
        <div class="company-grid">
            <div class="company-card">
                <h3>Platform uptime</h3>
                <p class="text-3xl font-bold text-emerald-400 mt-1"><?= e((string)$uptime['uptime_pct']) ?>%</p>
                <p class="text-xs text-gray-500 mt-1">Last 90 days · see full <a href="status.php" class="text-brand-400">status history</a></p>
            </div>
            <?php if ($showMerchants): ?>
            <div class="company-card">
                <h3>Active merchants</h3>
                <p class="text-3xl font-bold text-emerald-400 mt-1"><?= number_format($merchants) ?>+</p>
                <p class="text-xs text-gray-500 mt-1">Businesses collecting payments on UniWeb</p>
            </div>
            <?php endif; ?>
            <?php if ($showTxns): ?>
            <div class="company-card">
                <h3>Successful transactions</h3>
                <p class="text-3xl font-bold text-emerald-400 mt-1"><?= number_format($txns) ?>+</p>
                <p class="text-xs text-gray-500 mt-1">Processed to date across all merchants</p>
            </div>
            <div class="company-card">
                <h3>Volume processed</h3>
                <p class="text-3xl font-bold text-emerald-400 mt-1"><?= e(formatMoney($volume)) ?>+</p>
                <p class="text-xs text-gray-500 mt-1">Cumulative successful payment value</p>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!$showMerchants && !$showTxns): ?>
        <div class="company-card company-wide mt-6">
            <p class="text-sm text-gray-400">We're an early-stage platform actively onboarding merchants. We'll publish merchant and transaction volume here once the numbers are meaningful enough to be a useful signal — not before. In the meantime, here's what you can verify today:</p>
            <ul class="mt-3 space-y-1 text-sm text-gray-400 list-disc list-inside">
                <li>Live <a href="status.php" class="text-brand-400">platform status &amp; uptime history</a></li>
                <li>Published <a href="trust.php" class="text-brand-400">security &amp; compliance controls</a></li>
                <li>Named <a href="grievance.php" class="text-brand-400">grievance officer</a> with response-time commitments</li>
                <li>Full <a href="compliance.php" class="text-brand-400">compliance framework</a> disclosure</li>
            </ul>
        </div>
        <?php endif; ?>
    </div></section>

    <section class="company-section" style="padding-top:0"><div class="company-shell"><div class="company-cta">
        <h2>Want to see UniWeb in action?</h2>
        <div class="flex flex-wrap gap-3">
            <a href="merchant_register.php" class="btn-primary px-6 py-3">Try the live demo</a>
            <a href="contact.php" class="px-6 py-3 rounded-lg border border-gray-700">Talk to us</a>
        </div>
    </div></div></section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
