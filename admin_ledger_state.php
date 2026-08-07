<?php
require_once __DIR__ . '/config.php';
requireAdmin();

$pageTitle = 'Ledger State Machine';
$adminSection = 'financial';

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'rebuild_one') {
            $merchantId = (int)($_POST['merchant_id'] ?? 0);
            if ($merchantId <= 0) throw new InvalidArgumentException('Invalid merchant ID.');
            $result = rebuildMerchantBalanceFromLedger($merchantId);
        } elseif ($action === 'rebuild_all') {
            $result = rebuildAllMerchantBalancesFromLedger();
        } elseif ($action === 'view_breakdown') {
            $merchantId = (int)($_POST['merchant_id'] ?? 0);
            if ($merchantId <= 0) throw new InvalidArgumentException('Invalid merchant ID.');
            $merchant = getDB()->prepare('SELECT id, business_name, email, account_mode, kyc_status, wallet_balance FROM merchants WHERE id=?');
            $merchant->execute([$merchantId]);
            $m = $merchant->fetch();
            if (!$m) throw new RuntimeException('Merchant not found.');
            $mode = isMerchantTest($m) ? 'test' : 'live';
            $result = ['breakdown' => getMerchantBalanceBreakdown($merchantId, $mode), 'merchant' => $m];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Get all merchants for dropdown
$merchants = getDB()->query('SELECT id, business_name, email, wallet_balance FROM merchants ORDER BY id LIMIT 200')->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-6xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-white mb-2">Ledger State Machine</h1>
    <p class="text-gray-400 text-sm mb-6">Rebuild merchant balances from ledger entries. Validate state transitions. Enforce non-negative available balance.</p>

    <?php if ($error): ?>
        <div class="bg-red-500/10 border border-red-500/30 rounded-xl p-4 mb-6 text-red-400 text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($result): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 rounded-xl p-4 mb-6">
            <pre class="text-xs text-emerald-300 overflow-x-auto"><?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
    <?php endif; ?>

    <div class="grid md:grid-cols-2 gap-6 mb-8">
        <!-- Rebuild single merchant -->
        <div class="glass rounded-xl p-6 border border-sky-500/20">
            <h2 class="text-lg font-semibold text-white mb-4">Rebuild Single Merchant</h2>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="rebuild_one">
                <select name="merchant_id" class="w-full bg-gray-800 text-white rounded-lg px-3 py-2 text-sm border border-gray-700" required>
                    <option value="">Select merchant…</option>
                    <?php foreach ($merchants as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['business_name'] ?: $m['email']) ?> (#<?= (int)$m['id'] ?>, bal: <?= formatMoney((float)$m['wallet_balance']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="w-full bg-sky-600 hover:bg-sky-700 text-white rounded-lg py-2 text-sm font-medium">Rebuild Balance from Ledger</button>
            </form>
        </div>

        <!-- View balance breakdown -->
        <div class="glass rounded-xl p-6 border border-violet-500/20">
            <h2 class="text-lg font-semibold text-white mb-4">View Balance Breakdown</h2>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="view_breakdown">
                <select name="merchant_id" class="w-full bg-gray-800 text-white rounded-lg px-3 py-2 text-sm border border-gray-700" required>
                    <option value="">Select merchant…</option>
                    <?php foreach ($merchants as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['business_name'] ?: $m['email']) ?> (#<?= (int)$m['id'] ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="w-full bg-violet-600 hover:bg-violet-700 text-white rounded-lg py-2 text-sm font-medium">View Breakdown</button>
            </form>
        </div>
    </div>

    <!-- Rebuild all -->
    <div class="glass rounded-xl p-6 border border-amber-500/20 mb-8">
        <h2 class="text-lg font-semibold text-white mb-2">Rebuild ALL Merchant Balances</h2>
        <p class="text-xs text-gray-500 mb-4">Recalculates every merchant's wallet_balance from ledger entries. Use with caution — logs all diffs to platform_errors.</p>
        <form method="POST" onsubmit="return confirm('Rebuild ALL merchant balances? This will update wallet_balance for every merchant.')">
            <input type="hidden" name="action" value="rebuild_all">
            <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white rounded-lg px-6 py-2 text-sm font-medium">Rebuild All Balances</button>
        </form>
    </div>

    <!-- Allowed transitions reference -->
    <div class="glass rounded-xl p-6 border border-gray-700">
        <h2 class="text-lg font-semibold text-white mb-4">Allowed Ledger State Transitions</h2>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-gray-400 text-xs border-b border-gray-700">
                    <th class="text-left py-2 px-3">Action</th>
                    <th class="text-left py-2 px-3">Allowed From States</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (getAllowedLedgerTransitions() as $action => $states): ?>
                    <tr class="border-b border-gray-800">
                        <td class="py-2 px-3 text-sky-400 font-mono text-xs"><?= htmlspecialchars($action) ?></td>
                        <td class="py-2 px-3 text-gray-300 text-xs"><?= htmlspecialchars(implode(' → ', $states)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
