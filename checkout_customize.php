<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/checkout_customize.php';
requireLogin();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_customize') {
        $res = saveMerchantCheckoutCustomize($merchantId, $_POST);
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Failed'));
    } elseif ($action === 'reset_customize') {
        try {
            getDB()->prepare('UPDATE merchant_checkout_customize SET is_active=0 WHERE merchant_id=?')->execute([$merchantId]);
            flash('success', 'Customization reset to default.');
        } catch (Throwable $e) {
            flash('error', 'Reset failed: ' . $e->getMessage());
        }
    }
    redirect('checkout_customize.php');
}

$cc = getMerchantCheckoutCustomize($merchantId);

$pageTitle = 'Customize Checkout Page';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-3xl mx-auto px-4 py-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Customize Checkout</h1>
            <p class="text-sm text-gray-500 mt-1">Logo, colours and titles on your payment page — same UniWeb checkout. Own domain + this look only; not a full white-label portal.</p>
        </div>
        <a href="dashboard.php" class="text-sm text-gray-400 border border-gray-700 px-4 py-2 rounded-lg">← Dashboard</a>
    </div>

    <?php if (!empty($cc['is_active'])): ?>
    <div class="bg-emerald-500/5 border border-emerald-500/20 rounded-xl p-4 mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="w-2 h-2 bg-emerald-400 rounded-full"></span>
            <span class="text-sm text-emerald-400">Customization is <strong>ON</strong> — checkout shows your logo and colours.</span>
        </div>
        <form method="POST" class="inline">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="reset_customize">
            <button class="text-xs text-red-400 border border-red-500/30 px-3 py-1.5 rounded-lg" onclick="return confirm('Reset to default?')">Reset</button>
        </form>
    </div>
    <?php else: ?>
    <div class="bg-gray-800/30 border border-gray-700 rounded-xl p-4 mb-6">
        <p class="text-sm text-gray-400">Customization is <strong class="text-gray-500">OFF</strong>. Save settings and tick <strong class="text-gray-300">Enable</strong> to activate.</p>
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_customize">

        <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-6">
            <h2 class="text-lg font-bold text-white mb-4">Logo</h2>
            <div class="mb-4">
                <label class="block text-sm text-gray-400 mb-2">Logo Image URL</label>
                <input type="url" name="logo_url" value="<?= e($cc['logo_url'] ?? '') ?>" placeholder="https://example.com/logo.png" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white text-sm">
                <p class="text-xs text-gray-600 mt-1">Paste your logo image URL. PNG/SVG recommended. Max width ~180px.</p>
            </div>
            <?php if (!empty($cc['logo_url'])): ?>
            <div class="bg-gray-800/50 rounded-lg p-4 flex items-center gap-3">
                <img src="<?= e($cc['logo_url']) ?>" alt="Logo preview" class="h-10 w-auto max-w-[180px]" onerror="this.style.display='none'">
                <span class="text-xs text-gray-500">Preview</span>
            </div>
            <?php endif; ?>
        </div>

        <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-6">
            <h2 class="text-lg font-bold text-white mb-4">Colors</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-2">Primary Color</label>
                    <div class="flex items-center gap-2">
                        <input type="color" name="primary_color" value="<?= e($cc['primary_color'] ?? '#6366f1') ?>" class="w-12 h-10 rounded cursor-pointer bg-gray-800 border border-gray-700">
                        <input type="text" value="<?= e($cc['primary_color'] ?? '#6366f1') ?>" class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm font-mono" id="primary_color_text" readonly>
                    </div>
                    <p class="text-xs text-gray-600 mt-1">Headings, highlights</p>
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-2">Button Color</label>
                    <div class="flex items-center gap-2">
                        <input type="color" name="button_color" value="<?= e($cc['button_color'] ?? '#6366f1') ?>" class="w-12 h-10 rounded cursor-pointer bg-gray-800 border border-gray-700">
                        <input type="text" value="<?= e($cc['button_color'] ?? '#6366f1') ?>" class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm font-mono" id="button_color_text" readonly>
                    </div>
                    <p class="text-xs text-gray-600 mt-1">Pay button background</p>
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-2">Accent / Link Color</label>
                    <div class="flex items-center gap-2">
                        <input type="color" name="accent_color" value="<?= e($cc['accent_color'] ?? '#38bdf8') ?>" class="w-12 h-10 rounded cursor-pointer bg-gray-800 border border-gray-700">
                        <input type="text" value="<?= e($cc['accent_color'] ?? '#38bdf8') ?>" class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm font-mono" id="accent_color_text" readonly>
                    </div>
                    <p class="text-xs text-gray-600 mt-1">Links, accents</p>
                </div>
            </div>
        </div>

        <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-6">
            <h2 class="text-lg font-bold text-white mb-4">Checkout Text</h2>
            <div class="mb-4">
                <label class="block text-sm text-gray-400 mb-2">Checkout Page Title (optional)</label>
                <input type="text" name="checkout_title" value="<?= e($cc['checkout_title'] ?? '') ?>" placeholder="e.g. Pay to Acme Store" maxlength="200" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white text-sm">
                <p class="text-xs text-gray-600 mt-1">Browser tab title and header text. Leave blank for default.</p>
            </div>
            <div class="mb-4">
                <label class="block text-sm text-gray-400 mb-2">Checkout Subtitle (optional)</label>
                <input type="text" name="checkout_subtitle" value="<?= e($cc['checkout_subtitle'] ?? '') ?>" placeholder="e.g. Secure Payment" maxlength="300" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white text-sm">
                <p class="text-xs text-gray-600 mt-1">Header ke side mein chhota text. Leave blank for "Secure Checkout".</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-2">Success Message (optional)</label>
                    <input type="text" name="success_message" value="<?= e($cc['success_message'] ?? '') ?>" placeholder="e.g. Payment successful! Thank you." maxlength="300" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white text-sm">
                    <p class="text-xs text-gray-600 mt-1">Payment successful hone ke baad dikhne wala message.</p>
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-2">Failure Message (optional)</label>
                    <input type="text" name="failure_message" value="<?= e($cc['failure_message'] ?? '') ?>" placeholder="e.g. Payment failed. Please try again." maxlength="300" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white text-sm">
                    <p class="text-xs text-gray-600 mt-1">Payment fail hone par dikhne wala message.</p>
                </div>
            </div>
            <div class="mt-4">
                <label class="block text-sm text-gray-400 mb-2">Redirect URL after Payment (optional)</label>
                <input type="url" name="redirect_url" value="<?= e($cc['redirect_url'] ?? '') ?>" placeholder="https://yourstore.com/thank-you" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white text-sm">
                <p class="text-xs text-gray-600 mt-1">Payment complete hone ke baad customer is URL par redirect hoga. Leave blank for default.</p>
            </div>
        </div>

        <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-6">
            <h2 class="text-lg font-bold text-white mb-4">Custom CSS (Advanced)</h2>
            <textarea name="custom_css" rows="5" placeholder="/* Apna custom CSS yahan likhein */" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2 text-white text-sm font-mono"><?= e($cc['custom_css'] ?? '') ?></textarea>
            <p class="text-xs text-gray-600 mt-1">Sirf checkout page ke liye extra CSS. Careful — galat CSS checkout break kar sakta hai.</p>
        </div>

        <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-6">
            <h2 class="text-lg font-bold text-white mb-4">UniWeb mark on checkout</h2>
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="hide_powered_by" value="1" <?= !empty($cc['hide_powered_by']) ? 'checked' : '' ?> class="w-5 h-5 rounded mt-0.5">
                <div>
                    <span class="text-sm text-white font-semibold">Hide “Secured by UniWeb”</span>
                    <p class="text-xs text-gray-500 mt-1">Default is OFF. Turn this on only if a written contract requires it. GST and CIN stay on the checkout footer. Partner names (PayU, Razorpay) stay.</p>
                </div>
            </label>
        </div>

        <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-6">
            <label class="flex items-center gap-3 cursor-pointer">
                <input type="checkbox" name="is_active" value="1" <?= !empty($cc['is_active']) ? 'checked' : '' ?> class="w-5 h-5 rounded">
                <div>
                    <span class="text-sm text-white font-semibold">Enable Customization</span>
                    <p class="text-xs text-gray-500">Tick to apply your settings on the checkout page.</p>
                </div>
            </label>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="btn-primary px-6 py-3 text-sm">Save Settings</button>
            <a href="payment_links.php" class="text-sm text-gray-400 border border-gray-700 px-6 py-3 rounded-lg">Create Test Link →</a>
        </div>
    </form>

    <div class="mt-8 bg-gray-900/60 border border-gray-800 rounded-xl p-6">
        <h2 class="text-lg font-bold text-white mb-2">Your domain + this checkout look</h2>
        <ol class="text-sm text-gray-400 space-y-2 list-decimal pl-5">
            <li>Point your own domain at this UniWeb site (Hostinger + SSL) — same app, not a second product.</li>
            <li>Use this page for logo, colours, and checkout titles.</li>
            <li>Collect with Payment Links, QR, or API embed on your website.</li>
            <li>Stop here: UniWeb does not sell a rebranded portal. Revenue is commission on payments.</li>
        </ol>
        <p class="text-xs text-gray-600 mt-4"><a href="payment_links.php" class="text-sky-400 hover:underline">Payment Links</a> · <a href="qr_code.php" class="text-sky-400 hover:underline">QR</a> · <a href="merchant_website.php" class="text-sky-400 hover:underline">Website / Pay button</a> · <a href="api_settings.php" class="text-sky-400 hover:underline">API keys</a></p>
    </div>

    <?php if (!empty($cc['is_active'])): ?>
    <div class="mt-8 bg-gray-900/60 border border-gray-800 rounded-xl p-6">
        <h2 class="text-lg font-bold text-white mb-2">Live Preview</h2>
        <p class="text-sm text-gray-500 mb-4">Approximate preview of your checkout page:</p>
        <div class="bg-dark-950 border border-gray-800 rounded-xl overflow-hidden">
            <div class="border-b border-gray-800 bg-dark-900/95 px-4 py-4">
                <div class="max-w-lg mx-auto flex items-center justify-between">
                    <?php if (!empty($cc['logo_url'])): ?>
                    <img src="<?= e($cc['logo_url']) ?>" alt="Logo" class="h-8 w-auto max-w-[180px]" onerror="this.style.display='none'">
                    <?php else: ?>
                    <span class="text-white font-bold text-lg"><?= e($merchant['business_name'] ?? 'Your Store') ?></span>
                    <?php endif; ?>
                    <span class="text-xs text-sky-400"><?= e($cc['checkout_subtitle'] ?? 'Secure Checkout') ?></span>
                </div>
            </div>
            <div class="p-6 text-center">
                <div class="glass rounded-2xl p-6 max-w-sm mx-auto">
                    <h3 class="text-white font-bold text-lg mb-2" style="<?= !empty($cc['primary_color']) ? 'color:' . e($cc['primary_color']) : '' ?>"><?= e($cc['checkout_title'] ?? 'Pay ' . ($merchant['business_name'] ?? 'Now')) ?></h3>
                    <p class="text-gray-400 text-sm mb-4">Amount: ₹1,000.00</p>
                    <button class="w-full py-3 rounded-xl font-semibold text-white text-sm" style="<?= !empty($cc['button_color']) ? 'background-color:' . e($cc['button_color']) : '' ?>">Pay ₹1,000</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('input[type="color"]').forEach(function(input) {
    input.addEventListener('input', function() {
        var textId = this.name + '_text';
        var textEl = document.getElementById(textId);
        if (textEl) textEl.value = this.value;
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
