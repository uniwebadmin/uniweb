<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops', 'finance']);

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$registry = getPartnerRegistry();

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'multi_forward' && isset($_POST['merchant_id'])) {
        $merchantId = (int)$_POST['merchant_id'];
        $selectedGateways = $_POST['gateways'] ?? [];
        $forwarded = 0;
        $errors = [];

        foreach ($selectedGateways as $gw) {
            if (!isset($registry[$gw])) continue;
            try {
                // Log the forward request
                getDB()->prepare(
                    "INSERT INTO gateway_onboarding_requests (merchant_id, gateway, status, requested_by, created_at)
                     VALUES (?, ?, 'pending', ?, NOW())
                     ON DUPLICATE KEY UPDATE status='pending', requested_by=VALUES(requested_by)"
                )->execute([$merchantId, $gw, $adminId]);
                $forwarded++;
            } catch (Throwable $e) {
                $errors[] = $gw . ': ' . $e->getMessage();
            }
        }

        if ($forwarded > 0) {
            flash('success', "Forwarded to {$forwarded} gateway(s)." . (!empty($errors) ? ' Errors: ' . implode(', ', $errors) : ''));
        } else {
            flash('error', 'No gateways forwarded. ' . implode(', ', $errors));
        }
        redirect('admin_gateway_matrix.php?merchant_id=' . $merchantId);
    }

    if ($action === 'update_status' && isset($_POST['merchant_id']) && isset($_POST['gateway']) && isset($_POST['status'])) {
        $merchantId = (int)$_POST['merchant_id'];
        $gateway = trim($_POST['gateway']);
        $status = trim($_POST['status']);
        $note = trim($_POST['note'] ?? '');

        try {
            getDB()->prepare(
                "INSERT INTO gateway_onboarding_requests (merchant_id, gateway, status, note, requested_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE status=VALUES(status), note=VALUES(note), updated_at=NOW()"
            )->execute([$merchantId, $gateway, $status, $note, $adminId]);
            flash('success', "Status updated for {$gateway}.");
        } catch (Throwable $e) {
            flash('error', 'Failed to update: ' . $e->getMessage());
        }
        redirect('admin_gateway_matrix.php?merchant_id=' . $merchantId);
    }
}

// Get selected merchant
$selectedMerchantId = (int)($_GET['merchant_id'] ?? 0);
$selectedMerchant = null;
$gatewayStatuses = [];
$healthSummary = gatewayHealthSummary();

if ($selectedMerchantId > 0) {
    $st = getDB()->prepare("SELECT id, merchant_code, business_name, kyc_status, status FROM merchants WHERE id=?");
    $st->execute([$selectedMerchantId]);
    $selectedMerchant = $st->fetch();

    if ($selectedMerchant) {
        // Get gateway onboarding statuses
        try {
            $st = getDB()->prepare("SELECT gateway, status, note, created_at, updated_at FROM gateway_onboarding_requests WHERE merchant_id=?");
            $st->execute([$selectedMerchantId]);
            foreach ($st->fetchAll() as $row) {
                $gatewayStatuses[$row['gateway']] = $row;
            }
        } catch (Throwable $e) {}
    }
}

// Ensure table exists
try {
    getDB()->exec("CREATE TABLE IF NOT EXISTS gateway_onboarding_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        gateway VARCHAR(32) NOT NULL,
        status ENUM('pending','submitted','approved','rejected','live','paused') NOT NULL DEFAULT 'pending',
        note VARCHAR(500) DEFAULT NULL,
        requested_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY idx_merchant_gateway (merchant_id, gateway),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Get merchants with gateway requests
$allRequests = [];
try {
    $st = getDB()->query("SELECT gor.merchant_id, m.business_name, m.merchant_code,
        GROUP_CONCAT(CONCAT(gor.gateway, ':', gor.status) SEPARATOR ',') as statuses
        FROM gateway_onboarding_requests gor
        JOIN merchants m ON m.id = gor.merchant_id
        GROUP BY gor.merchant_id
        ORDER BY m.business_name");
    $allRequests = $st->fetchAll();
} catch (Throwable $e) {}

$pageTitle = 'Gateway Status Matrix';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <p class="text-sm text-gray-400">Multi-gateway onboarding status, one-click forward, per-merchant matrix</p>

    <div class="glass rounded-xl p-4 sm:p-6">
        <h3 class="font-semibold mb-4">Select Merchant</h3>
        <form method="GET" class="flex gap-3 items-end">
            <div><label class="text-sm text-gray-400">Merchant ID</label>
                <input type="number" name="merchant_id" value="<?= $selectedMerchantId ?: '' ?>" class="input-field mt-1 w-full" placeholder="Enter merchant ID">
            </div>
            <button type="submit" class="btn-primary px-4 py-2">Load</button>
        </form>
    </div>

    <?php if ($selectedMerchant): ?>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800">
            <h2 class="font-semibold"><?= e($selectedMerchant['business_name']) ?> <span class="font-mono text-gray-500 text-sm"><?= e($selectedMerchant['merchant_code']) ?></span></h2>
            <p class="text-xs text-gray-500 mt-1">KYC: <?= e($selectedMerchant['kyc_status']) ?> · Status: <?= e($selectedMerchant['status']) ?></p>
        </div>

        <!-- Gateway Health -->
        <div class="px-6 py-4 border-b border-gray-800">
            <h3 class="text-sm font-semibold mb-3">Gateway Health (Live)</h3>
            <div class="flex flex-wrap gap-3">
                <?php foreach ($healthSummary as $gw => $h): ?>
                <div class="text-xs px-3 py-2 rounded-lg border <?= $h['configured'] ? ($h['healthy'] ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-red-500/30 bg-red-500/5') : 'border-gray-800 bg-dark-900/30' ?>">
                    <span class="capitalize"><?= e($gw) ?></span>
                    <?php if (!$h['configured']): ?><span class="text-gray-500"> — Not configured</span>
                    <?php elseif ($h['healthy']): ?><span class="text-emerald-400"> ● Healthy</span>
                    <?php else: ?><span class="text-red-400"> ● Unhealthy</span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Status Matrix -->
        <div class="overflow-x-auto"><table class="min-w-[700px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Gateway</th>
                <th class="px-4 py-3 text-left">Configured</th>
                <th class="px-4 py-3 text-left">Onboarding Status</th>
                <th class="px-4 py-3 text-left">Note</th>
                <th class="px-4 py-3 text-left">Updated</th>
                <th class="px-4 py-3 text-left">Action</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($registry as $key => $reg):
                    $configured = partnerIsConfigured($key);
                    $gwStatus = $gatewayStatuses[$key] ?? null;
                    $status = $gwStatus['status'] ?? 'none';
                    $note = $gwStatus['note'] ?? '';
                    $updated = $gwStatus['updated_at'] ?? null;
                ?>
                <tr>
                    <td class="px-4 py-3 text-xs"><span class="text-lg"><?= e($reg['icon'] ?? '') ?></span> <?= e($reg['name'] ?? ucfirst($key)) ?></td>
                    <td class="px-4 py-3 text-xs"><?= $configured ? '<span class="text-emerald-400">Keys set</span>' : '<span class="text-gray-500">No keys</span>' ?></td>
                    <td class="px-4 py-3">
                        <form method="POST" class="inline-flex gap-2">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="merchant_id" value="<?= $selectedMerchantId ?>">
                            <input type="hidden" name="gateway" value="<?= e($key) ?>">
                            <select name="status" class="input-field text-xs w-32" onchange="this.form.submit()">
                                <option value="none" <?= $status === 'none' ? 'selected' : '' ?>>— None —</option>
                                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="submitted" <?= $status === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                                <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
                                <option value="live" <?= $status === 'live' ? 'selected' : '' ?>>Live</option>
                                <option value="paused" <?= $status === 'paused' ? 'selected' : '' ?>>Paused</option>
                                <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            </select>
                            <input type="text" name="note" value="<?= e($note) ?>" placeholder="Note" class="input-field text-xs w-32">
                        </form>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-400 max-w-xs truncate"><?= e($note) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= $updated ? formatDate($updated) : '—' ?></td>
                    <td class="px-4 py-3 text-xs">
                        <?php if (!empty($reg['admin_page'])): ?>
                        <a href="<?= e($reg['admin_page']) ?>" class="text-sky-400 hover:underline">Configure →</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>

        <!-- One-click multi-forward -->
        <div class="px-6 py-4 border-t border-gray-800">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="multi_forward">
                <input type="hidden" name="merchant_id" value="<?= $selectedMerchantId ?>">
                <p class="text-sm font-semibold mb-3">One-Click Multi-Gateway Forward</p>
                <div class="flex flex-wrap gap-3 mb-3">
                    <?php foreach ($registry as $key => $reg): ?>
                    <label class="flex items-center gap-2 text-xs">
                        <input type="checkbox" name="gateways[]" value="<?= e($key) ?>" <?= partnerIsConfigured($key) ? '' : 'disabled' ?> class="rounded">
                        <?= e($reg['name'] ?? ucfirst($key)) ?>
                        <?= !partnerIsConfigured($key) ? '<span class="text-gray-500">(no keys)</span>' : '' ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn-primary px-6 py-2.5 text-xs" onclick="return confirm('Forward KYC to selected gateways?')">Forward to Selected Gateways</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($allRequests)): ?>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">All Gateway Onboarding Requests</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[600px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Merchant</th><th class="px-4 py-3 text-left">Gateway Statuses</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($allRequests as $req): ?>
                <tr class="hover:bg-dark-900/30 cursor-pointer" onclick="window.location='?merchant_id=<?= (int)$req['merchant_id'] ?>'">
                    <td class="px-4 py-3 text-xs"><?= e($req['business_name']) ?> <span class="font-mono text-gray-500"><?= e($req['merchant_code']) ?></span></td>
                    <td class="px-4 py-3 text-xs">
                        <?php
                        $statuses = explode(',', $req['statuses']);
                        foreach ($statuses as $s) {
                            [$gw, $st] = explode(':', $s);
                            $cls = match($st) {
                                'live' => 'text-emerald-400',
                                'approved' => 'text-sky-400',
                                'submitted' => 'text-amber-400',
                                'pending' => 'text-gray-400',
                                'rejected' => 'text-red-400',
                                default => 'text-gray-500',
                            };
                            echo "<span class=\"{$cls} mr-2\">{$gw}:{$st}</span>";
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
