<?php
require_once __DIR__ . '/config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/includes/payment_methods.php';
}
if (!function_exists('getPartnerRegistry')) {
    require_once __DIR__ . '/includes/partner_engine.php';
}
if (!function_exists('ensurePartnerControlTables')) {
    require_once __DIR__ . '/includes/partner_control.php';
}
requireStaffAccess(['super', 'ceo', 'ops']);

syncPartnerGateways();
ensurePartnerControlTables();

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
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? "Gateway '{$name}' registered as INACTIVE. Click Activate to enable for merchants." : ($result['error'] ?? 'Error'));
        redirect('admin_gateway_registry.php');
    }

    if ($action === 'activate') {
        $gatewayId = (int)($_POST['gateway_id'] ?? 0);
        if ($gatewayId > 0) {
            $result = activateGatewayForAllMerchants($gatewayId);
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['gateway_name'] . ' activated! Added to ' . $result['merchants'] . ' merchants.' : ($result['error'] ?? 'Error'));
        }
        redirect('admin_gateway_registry.php');
    }

    if ($action === 'deactivate') {
        $gatewayId = (int)($_POST['gateway_id'] ?? 0);
        if ($gatewayId > 0) {
            $result = deactivateGateway($gatewayId);
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Gateway deactivated.' : ($result['error'] ?? 'Error'));
        }
        redirect('admin_gateway_registry.php');
    }
}

$gateways = getRegisteredGateways();
$partnerRegistry = getPartnerRegistry();
$activeCount = 0;
$inactiveCount = 0;
foreach ($gateways as $g) {
    if ((int)$g['is_active']) $activeCount++;
    else $inactiveCount++;
}
$pageTitle = 'Gateway Orchestrator';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-4xl space-y-6">
    <div class="glass rounded-xl p-6 border border-gray-800">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="font-semibold text-lg">Gateway Orchestrator</h2>
                <p class="text-xs text-gray-500 mt-1">All payment partners in one place. New partners auto-appear here as Inactive. Click a gateway to add API keys and activate.</p>
            </div>
            <div class="flex gap-3">
                <div class="text-center">
                    <p class="text-2xl font-bold text-emerald-400"><?= $activeCount ?></p>
                    <p class="text-[10px] text-gray-500 uppercase">Active</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-gray-400"><?= $inactiveCount ?></p>
                    <p class="text-[10px] text-gray-500 uppercase">Inactive</p>
                </div>
            </div>
        </div>
    </div>

    <div class="glass rounded-xl overflow-hidden border border-gray-800">
        <div class="px-6 py-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="font-semibold">Registered Gateways</h3>
            <span class="text-xs text-gray-500"><?= count($gateways) ?> total</span>
        </div>
        <?php if (empty($gateways)): ?>
        <div class="p-8 text-center text-sm text-gray-500">No gateways registered yet.</div>
        <?php else: ?>
        <div class="divide-y divide-gray-800">
            <?php foreach ($gateways as $g):
                $partnerInfo = $partnerRegistry[$g['gateway_key']] ?? null;
                $isActive = (int)$g['is_active'] === 1;
                $hasKeys = $partnerInfo && partnerIsConfigured($g['gateway_key']);
                $credStat = getPartnerCredentialStatus($g['gateway_key']);
                $enabledMethods = getEnabledPartnerMethods($g['gateway_key']);
            ?>
            <div class="px-6 py-4 flex items-center justify-between gap-4 hover:bg-white/5 transition-colors">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-lg bg-dark-900/80 flex items-center justify-center text-xl flex-shrink-0">
                        <?= e($partnerInfo['icon'] ?? '⚙️') ?>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-medium text-gray-200"><?= e($g['gateway_name']) ?></p>
                            <span class="text-[10px] px-2 py-0.5 rounded-full <?= $isActive ? 'bg-emerald-500/20 text-emerald-400' : 'bg-gray-700/50 text-gray-400' ?>">
                                <?= $isActive ? '● Active' : '○ Inactive' ?>
                            </span>
                            <?php if ($hasKeys): ?>
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-sky-500/10 text-sky-400">Keys Saved</span>
                            <?php elseif ($partnerInfo): ?>
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-400">Awaiting Keys</span>
                            <?php endif; ?>
                            <?php if ($credStat['test']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-sky-500/10 text-sky-400">Test</span><?php endif; ?>
                            <?php if ($credStat['live']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400">Live</span><?php endif; ?>
                            <?php if (!empty($enabledMethods)): ?><span class="text-[10px] px-2 py-0.5 rounded bg-violet-500/10 text-violet-400"><?= count($enabledMethods) ?> methods</span><?php endif; ?>
                        </div>
                        <p class="text-xs text-gray-500 font-mono mt-0.5"><?= e($g['gateway_key']) ?></p>
                        <div class="flex gap-1.5 mt-1">
                            <?php if ((int)$g['supports_collection']): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-400">Collection</span><?php endif; ?>
                            <?php if ((int)$g['supports_payout']): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-sky-500/10 text-sky-400">Payout</span><?php endif; ?>
                            <?php if ((int)$g['supports_refund']): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400">Refund</span><?php endif; ?>
                            <?php if ((int)$g['supports_recurring']): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-violet-500/10 text-violet-400">Recurring</span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <a href="admin_gateway_detail.php?id=<?= (int)$g['id'] ?>" class="text-xs px-3 py-1.5 rounded-lg bg-dark-900/80 text-gray-300 border border-gray-700 hover:border-gray-500">Configure →</a>
                    <?php if (!$isActive): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="activate">
                        <input type="hidden" name="gateway_id" value="<?= (int)$g['id'] ?>">
                        <button type="submit" class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-600/30" onclick="return confirm('Activate <?= e($g['gateway_name']) ?>? Payment method will be added to all merchants (OFF by default).')">Activate</button>
                    </form>
                    <?php else: ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="deactivate">
                        <input type="hidden" name="gateway_id" value="<?= (int)$g['id'] ?>">
                        <button type="submit" class="text-xs px-3 py-1.5 rounded-lg bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/20">Deactivate</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-2">Register Custom Gateway</h3>
        <p class="text-xs text-gray-500 mb-4">Add a gateway not in the partner list. It will appear as Inactive — configure keys and activate from its detail page.</p>
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
</div>
<?php require_once __DIR__ . '/footer.php';
