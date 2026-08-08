<?php
require_once __DIR__ . '/config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/includes/payment_methods.php';
}
requireStaffAccess(['super', 'ceo', 'ops']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'register_gateway') {
        $key = trim((string)($_POST['gateway_key'] ?? ''));
        $name = trim((string)($_POST['gateway_name'] ?? ''));
        if ($key === '' || $name === '') {
            flash('error', 'Gateway key and name are required.');
            redirect('admin_gateway_registry.php');
        }
        $capabilities = [
            'collection' => isset($_POST['supports_collection']) ? 1 : 0,
            'payout' => isset($_POST['supports_payout']) ? 1 : 0,
            'refund' => isset($_POST['supports_refund']) ? 1 : 0,
            'recurring' => isset($_POST['supports_recurring']) ? 1 : 0,
            'adapter' => trim((string)($_POST['adapter_class'] ?? '')) ?: null,
            'webhook_url' => trim((string)($_POST['webhook_url'] ?? '')) ?: null,
        ];
        $result = registerGateway($key, $name, $capabilities);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? "Gateway '{$name}' registered. All merchants can now use it." : ($result['error'] ?? 'Error'));
        redirect('admin_gateway_registry.php');
    }

    if ($action === 'toggle_gateway') {
        $gatewayId = (int)($_POST['gateway_id'] ?? 0);
        $isActive = ($_POST['is_active'] ?? '') === '1';
        if ($gatewayId > 0) {
            try {
                getDB()->prepare("UPDATE gateway_registry SET is_active=? WHERE id=?")->execute([$isActive ? 1 : 0, $gatewayId]);
                flash('success', 'Gateway ' . ($isActive ? 'activated' : 'deactivated'));
            } catch (Throwable $e) {
                flash('error', 'Could not update gateway.');
            }
        }
        redirect('admin_gateway_registry.php');
    }
}

$gateways = getRegisteredGateways();
$pageTitle = 'Gateway Orchestrator';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-4xl space-y-6">
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h2 class="font-semibold mb-2">Gateway Orchestrator</h2>
        <p class="text-xs text-gray-500 mb-6">Register a new payment partner. Once added, all merchants automatically get the new gateway's payment methods in their ON/OFF list. No manual code changes needed.</p>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="register_gateway">
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm text-gray-400">Gateway Key *</label>
                    <input type="text" name="gateway_key" required placeholder="e.g. decentro, razorpay_x" class="input-field mt-1 font-mono text-sm" pattern="[a-z0-9_]+">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Display Name *</label>
                    <input type="text" name="gateway_name" required placeholder="e.g. Decentro Payments" class="input-field mt-1">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Adapter Class (optional)</label>
                    <input type="text" name="adapter_class" placeholder="includes/gateways/decentro_adapter.php" class="input-field mt-1 font-mono text-xs">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Webhook URL (optional)</label>
                    <input type="text" name="webhook_url" placeholder="<?= e(APP_URL) ?>/decentro_webhook.php" class="input-field mt-1 font-mono text-xs">
                </div>
            </div>
            <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="supports_collection" checked class="rounded border-gray-600"> Collection</label>
                <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="supports_payout" class="rounded border-gray-600"> Payout</label>
                <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="supports_refund" class="rounded border-gray-600"> Refund</label>
                <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="supports_recurring" class="rounded border-gray-600"> Recurring</label>
            </div>
            <button type="submit" class="btn-primary px-6 py-2.5">Register Gateway</button>
        </form>
    </div>

    <div class="glass rounded-xl overflow-hidden border border-gray-800">
        <div class="px-6 py-4 border-b border-gray-800">
            <h3 class="font-semibold">Registered Gateways</h3>
        </div>
        <?php if (empty($gateways)): ?>
        <div class="p-6 text-sm text-gray-500">No gateways registered yet.</div>
        <?php else: ?>
        <div class="divide-y divide-gray-800">
            <?php foreach ($gateways as $g): ?>
            <div class="px-6 py-4 flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-200"><?= e($g['gateway_name']) ?></p>
                    <p class="text-xs text-gray-500 font-mono"><?= e($g['gateway_key']) ?></p>
                    <div class="flex gap-2 mt-1">
                        <?php if ((int)$g['supports_collection']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400">Collection</span><?php endif; ?>
                        <?php if ((int)$g['supports_payout']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-sky-500/10 text-sky-400">Payout</span><?php endif; ?>
                        <?php if ((int)$g['supports_refund']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-amber-500/10 text-amber-400">Refund</span><?php endif; ?>
                        <?php if ((int)$g['supports_recurring']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-violet-500/10 text-violet-400">Recurring</span><?php endif; ?>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="toggle_gateway">
                        <input type="hidden" name="gateway_id" value="<?= (int)$g['id'] ?>">
                        <input type="hidden" name="is_active" value="<?= (int)$g['is_active'] ? '0' : '1' ?>">
                        <button type="submit" class="text-xs px-3 py-1.5 rounded-lg <?= (int)$g['is_active'] ? 'bg-emerald-600/20 text-emerald-400' : 'bg-gray-700/50 text-gray-400' ?>">
                            <?= (int)$g['is_active'] ? 'Active' : 'Inactive' ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php';
