<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/payout.php';
requireLogin();
requireMerchantTeamCapability('api');
ensurePayoutSchema();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'generate') {
        $res = generatePayoutApiCredential($merchantId, true);
        if (!empty($res['ok'])) {
            $_SESSION['new_payout_api_credential'] = ['key' => $res['key'], 'secret' => $res['secret']];
        }
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Failed'));
    } elseif ($action === 'revoke') {
        $res = revokePayoutApiCredential($merchantId, (int)($_POST['credential_id'] ?? 0));
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Failed'));
    }
    redirect('merchant_payout_keys.php');
}

$creds = listPayoutApiCredentials($merchantId);
$newCred = $_SESSION['new_payout_api_credential'] ?? null;
unset($_SESSION['new_payout_api_credential']);

$pageTitle = 'Payout API Keys';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-3xl space-y-6">
    <div>
        <p class="text-sm text-gray-400">Generate / rotate / revoke <strong class="text-gray-300">UniWeb</strong> merchant payout API credentials (same pattern as collection API keys). These are not bank/PG partner secrets. Live payout API calls stay gated until Admin pastes partner keys + <code class="text-xs">payout_live_enabled</code>.</p>
        <p class="text-xs text-amber-400 mt-2"><?= e(payoutActivationMessage()) ?></p>
        <p class="text-xs mt-2"><a href="merchant_payout.php" class="text-sky-400 hover:underline">← Back to Payouts</a> · <a href="api_settings.php" class="text-sky-400 hover:underline">Collection API keys</a></p>
    </div>

    <?php if ($newCred): ?>
    <div class="glass rounded-xl p-6 border border-emerald-500/50">
        <h2 class="font-semibold text-emerald-400 mb-2">Copy your payout credential now</h2>
        <p class="text-xs text-gray-500 mb-4">The secret is shown once and stored only as a hash.</p>
        <label class="text-xs text-gray-500">API Key</label>
        <input readonly class="input-field font-mono text-xs mt-1 mb-3" value="<?= e($newCred['key']) ?>">
        <label class="text-xs text-gray-500">API Secret</label>
        <input readonly class="input-field font-mono text-xs mt-1" value="<?= e($newCred['secret']) ?>">
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-3">Create / rotate key</h2>
        <p class="text-xs text-gray-500 mb-4">Rotating revokes the previous active key immediately. Secret is never emailed.</p>
        <form method="POST" onsubmit="return confirm('Generate a new payout API key? The previous active key will be revoked.')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="generate">
            <button type="submit" class="btn-primary px-5 py-2.5">Generate payout API key</button>
        </form>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Credentials</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[520px]">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">Prefix</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Created</th>
                    <th class="px-5 py-3 text-left"></th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (empty($creds)): ?>
                    <tr><td colspan="4" class="px-5 py-10 text-center text-gray-500">No payout API keys yet.</td></tr>
                    <?php else: foreach ($creds as $c): ?>
                    <tr>
                        <td class="px-5 py-3 font-mono text-xs"><?= e($c['key_prefix']) ?></td>
                        <td class="px-5 py-3"><?= statusBadge((string)$c['status']) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($c['created_at']) ?></td>
                        <td class="px-5 py-3">
                            <?php if (($c['status'] ?? '') === 'active'): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Revoke this key?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="credential_id" value="<?= (int)$c['id'] ?>">
                                <button class="text-xs text-red-400 hover:underline">Revoke</button>
                            </form>
                            <?php else: ?><span class="text-xs text-gray-600">—</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
