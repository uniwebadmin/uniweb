<?php
require_once __DIR__ . '/config.php';
$demo = ensureDemoMerchant();
$pageTitle = 'Try Demo Payment';
require_once __DIR__ . '/header.php';
?>

<section class="pt-28 pb-20 px-4">
    <div class="max-w-2xl mx-auto">
        <div class="glass rounded-2xl p-8 border border-sky-500/20">
            <div class="text-center mb-8">
                <span class="inline-block bg-amber-500/20 text-amber-400 text-xs font-bold px-3 py-1 rounded-full mb-4">INSTANT TEST — No signup needed</span>
                <h1 class="text-3xl font-bold mb-2">Demo Payment Link</h1>
                <p class="text-gray-400">₹1 test payment — UPI, Debit Card, Credit Card, EMI, Netbanking, Wallets</p>
            </div>

            <div class="bg-dark-900 rounded-xl p-6 mb-6 text-center">
                <p class="text-4xl font-bold text-sky-400 mb-2">₹1.00</p>
                <p class="text-xs text-gray-500 font-mono mb-4">Link: <?= e($demo['link_id']) ?></p>
                <a href="<?= e($demo['pay_url']) ?>" class="inline-block w-full bg-sky-600 hover:bg-sky-500 text-white py-4 rounded-xl font-bold text-lg transition">
                    Pay ₹1 Now — Test Checkout →
                </a>
                <a href="tour_videos.php" class="inline-block w-full mt-3 glass text-violet-300 hover:text-white py-3 rounded-xl font-semibold text-sm transition">▶ Watch Platform Tour</a>
                <button type="button" onclick="navigator.clipboard.writeText('<?= e($demo['pay_url']) ?>');this.textContent='Copied!'" class="mt-3 text-sm text-sky-400">Copy Payment Link</button>
            </div>

            <div class="space-y-4 text-sm">
                <h2 class="font-semibold text-sky-400">How to test</h2>
                <ol class="list-decimal list-inside text-gray-400 space-y-2">
                    <li>Click <strong class="text-white">Pay ₹1 Now</strong> above</li>
                    <li>On checkout, choose a method: UPI / Debit Card / Credit Card / EMI / Netbanking / Wallet</li>
                    <li><strong class="text-amber-400">PayU Test:</strong> Card <code class="text-xs bg-dark-900 px-1 rounded">5123456789012346</code> · CVV <code class="text-xs">123</code> · any future expiry</li>
                    <li>Or use the UPI tab and enter a test UTR to confirm</li>
                </ol>

                <h2 class="font-semibold text-sky-400 pt-4">All payment methods (Payment Pack)</h2>
                <p class="text-gray-400">Log in as the demo merchant → open <strong class="text-white">Payment Pack</strong> for separate ₹1 links per method (UPI, Debit, Credit, PayU UPI, Razorpay, Cashfree).</p>

                <h2 class="font-semibold text-sky-400 pt-4">Merchant account test (optional)</h2>
                <div class="bg-dark-900 rounded-lg p-4 font-mono text-xs space-y-1">
                    <p><span class="text-gray-500">Login:</span> <a href="login.php" class="text-sky-400"><?= e($demo['login_email']) ?></a></p>
                    <p><span class="text-gray-500">Password:</span> <?= e($demo['login_password']) ?></p>
                    <p><span class="text-gray-500">Dashboard →</span> Payment Links → Create link → Copy → Share</p>
                </div>

                <h2 class="font-semibold text-sky-400 pt-4">New merchant signup</h2>
                <p class="text-gray-400">Anyone can register — <a href="merchant_register.php" class="text-brand-400 font-medium">Free Register →</a> · Test mode payment links are available immediately (KYC can be completed later).</p>
            </div>
        </div>

        <div class="text-center mt-8 flex flex-wrap justify-center gap-4 text-sm">
            <a href="merchant_register.php" class="btn-primary px-6 py-3">Become a Merchant</a>
            <a href="index.php" class="text-gray-400 hover:text-white">← Home</a>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/footer.php'; ?>
