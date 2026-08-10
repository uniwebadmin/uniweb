<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops']);
ensureGatewaySubmissionsTable();
$db = getDB();

$allowedGateways = gatewaySubmissionAllowedGateways();
$adminId = (int)($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? 'forward';
    $merchantId = (int)($_POST['merchant_id'] ?? 0);
    $redirectTo = 'admin_gateway_submit.php' . ($merchantId ? ('?merchant_id=' . $merchantId) : '');

    if ($action === 'purge_secrets' && isSuperAdmin()) {
        if (!function_exists('purgeSecretsFromSubmissions')) {
            require_once __DIR__ . '/includes/partner_payload.php';
        }
        $result = purgeSecretsFromSubmissions();
        $total = $result['gateway_submissions'] + $result['partner_forward_queue'];
        flash('success', "Secrets purge complete. {$total} rows cleaned (gateway_submissions: {$result['gateway_submissions']}, partner_forward_queue: {$result['partner_forward_queue']}).");
        recordImmutableAudit('purge_secrets', null, 'admin', (string)$adminId, "Purged plaintext secrets from {$total} submission rows");
        redirect($redirectTo);
    }

    if ($action === 'update_status') {
        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        $response = trim((string)($_POST['gateway_response'] ?? ''));
        if ($submissionId && updateGatewaySubmissionStatus($submissionId, $status, $adminId, $response)) {
            flash('success', 'Submission status updated.');
        } else {
            flash('error', 'Could not update status.');
        }
        redirect($redirectTo);
    }

    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($action === 'forward_all') {
        $gateways = $allowedGateways;
    } else {
        $gateways = array_values(array_intersect((array)($_POST['gateways'] ?? []), $allowedGateways));
    }

    if ($merchantId && $gateways) {
        $count = submitMerchantToGateways($merchantId, $gateways, $adminId, $notes);
        flash($count > 0 ? 'success' : 'error', $count > 0
            ? ('Forwarded to ' . $count . ' gateway' . ($count > 1 ? 's' : '') . '.')
            : 'Nothing was forwarded.');
    } else {
        flash('error', 'Select a merchant and at least one gateway.');
    }
    redirect($redirectTo);
}

$merchants = $db->query("SELECT id, merchant_code, business_name, name, email, phone, kyc_status, status, business_entity_type FROM merchants WHERE status != 'deleted' ORDER BY business_name")->fetchAll();

$selectedId = (int)($_GET['merchant_id'] ?? 0);
$selMerchant = null;
$matrix = [];
$docVersions = [];
$audit = [];
if ($selectedId) {
    foreach ($merchants as $m) {
        if ((int)$m['id'] === $selectedId) {
            $selMerchant = $m;
            break;
        }
    }
    if ($selMerchant) {
        $matrix = getGatewaySubmissionMatrix($selectedId);
        $docVersions = getMerchantKycDocumentVersions($selectedId);
        $audit = getComplianceAudit($selectedId, 40);
    }
}

$registry = getPartnerRegistry();
$submissions = $db->query('SELECT gs.*, m.business_name, m.merchant_code, m.id AS merchant_row_id FROM gateway_submissions gs JOIN merchants m ON gs.merchant_id = m.id ORDER BY gs.created_at DESC LIMIT 50')->fetchAll();

$statusOptions = ['submitted' => 'Submitted', 'pending_review' => 'Pending Review', 'approved' => 'Approved', 'rejected' => 'Rejected'];

$pageTitle = 'Gateway Submission';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-6xl mx-auto space-y-4 sm:space-y-6 min-w-0">
    <div>
        <h1 class="text-xl sm:text-2xl font-bold">Multi-Gateway Forward</h1>
        <p class="text-sm text-gray-400 mt-1">Forward a merchant's verified KYC to several gateways in one click, then track each gateway's status. Sensitive documents are shared via each gateway's secure portal/API — never emailed. CSRF required on all POST actions.</p>
    </div>

    <!-- Step 1: pick merchant -->
    <div class="glass rounded-xl p-4 sm:p-6 min-w-0">
        <form method="GET" class="flex flex-col sm:flex-row gap-3 sm:items-end">
            <div class="flex-1 min-w-0">
                <label class="text-sm text-gray-400">Merchant</label>
                <select name="merchant_id" class="input-field mt-1 w-full" onchange="this.form.submit()">
                    <option value="">Select a merchant…</option>
                    <?php foreach ($merchants as $m): ?>
                    <option value="<?= (int)$m['id'] ?>" <?= $selectedId === (int)$m['id'] ? 'selected' : '' ?>>
                        <?= e($m['business_name'] ?: $m['name']) ?> (<?= e($m['merchant_code']) ?>) — KYC: <?= e($m['kyc_status']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button type="submit" class="border border-gray-700 rounded-lg hover:bg-white/5 px-4 py-2.5">Load</button></noscript>
        </form>
    </div>

    <?php if ($selMerchant): ?>
    <!-- Step 2: forward + status matrix -->
    <div class="glass rounded-xl p-4 sm:p-6 min-w-0">
        <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
            <div class="min-w-0">
                <h2 class="font-semibold break-words"><?= e($selMerchant['business_name'] ?: $selMerchant['name']) ?></h2>
                <p class="text-xs text-gray-500 font-mono break-all"><?= e($selMerchant['merchant_code']) ?> · <?= e(str_replace('_', ' ', (string)$selMerchant['business_entity_type'])) ?> · KYC <?= e($selMerchant['kyc_status']) ?></p>
            </div>
            <a href="<?= e(adminMerchantUrl($selectedId)) ?>" class="text-sm text-sky-400 hover:underline shrink-0">Open full profile →</a>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="merchant_id" value="<?= $selectedId ?>">
            <div>
                <label class="text-sm text-gray-400 mb-2 block">Select gateways to forward</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                    <?php foreach ($allowedGateways as $gw): $r = $registry[$gw] ?? ['name' => ucfirst($gw), 'icon' => '💳']; $cur = $matrix[$gw] ?? null; ?>
                    <label class="flex items-center gap-2 glass rounded-lg px-3 py-2.5 cursor-pointer hover:bg-white/5 min-w-0">
                        <input type="checkbox" name="gateways[]" value="<?= e($gw) ?>" class="accent-sky-500 shrink-0">
                        <span class="text-lg shrink-0"><?= $r['icon'] ?></span>
                        <span class="text-sm flex-1 min-w-0 truncate"><?= e($r['name']) ?></span>
                        <?php if ($cur): ?><?= statusBadge($cur['status']) ?><?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <label class="text-sm text-gray-400">Admin note (optional)</label>
                <input type="text" name="notes" class="input-field mt-1 w-full" placeholder="Internal note for this forward…">
            </div>
            <div class="flex flex-col sm:flex-row flex-wrap gap-3">
                <button type="submit" name="action" value="forward" class="btn-primary px-6 py-2.5 w-full sm:w-auto">Forward to selected</button>
                <button type="submit" name="action" value="forward_all" class="border border-gray-700 rounded-lg hover:bg-white/5 px-6 py-2.5 w-full sm:w-auto" onclick="return confirm('Forward this merchant to ALL gateways?')">Forward to ALL gateways</button>
            </div>
        </form>
    </div>

    <!-- Status matrix -->
    <div class="glass rounded-xl overflow-hidden min-w-0">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Gateway status</h2></div>
        <div class="overflow-x-auto">
            <table class="min-w-[640px] w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left">Gateway</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">Last update</th>
                        <th class="px-4 py-3 text-left">Update / Onboard</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($allowedGateways as $gw): $r = $registry[$gw] ?? ['name' => ucfirst($gw), 'icon' => '💳']; $cur = $matrix[$gw] ?? null; ?>
                    <tr>
                        <td class="px-4 py-3"><span class="mr-1"><?= $r['icon'] ?></span><?= e($r['name']) ?></td>
                        <td class="px-4 py-3"><?= $cur ? statusBadge($cur['status']) : '<span class="text-xs text-gray-500">Not forwarded</span>' ?></td>
                        <td class="px-4 py-3 text-xs text-gray-500"><?= $cur ? e(formatDate($cur['updated_at'] ?? $cur['created_at'])) : '—' ?></td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <?php if ($cur): ?>
                                <form method="POST" class="flex flex-wrap items-center gap-2 min-w-0">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="merchant_id" value="<?= $selectedId ?>">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="submission_id" value="<?= (int)$cur['id'] ?>">
                                    <select name="status" class="input-field !py-1 !text-xs w-full sm:w-auto">
                                        <?php foreach ($statusOptions as $sv => $sl): ?>
                                        <option value="<?= $sv ?>" <?= ($cur['status'] === $sv) ? 'selected' : '' ?>><?= $sl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="border border-gray-700 rounded-lg hover:bg-white/5 !px-3 !py-1 text-xs">Save</button>
                                </form>
                                <?php endif; ?>
                                <a href="<?= e(gatewayOnboardingMailto($gw, $selMerchant)) ?>" class="text-xs text-sky-400 hover:underline">Onboarding email</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4 sm:gap-6">
        <!-- Document versions -->
        <div class="glass rounded-xl overflow-hidden min-w-0">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Documents &amp; version history</h2></div>
            <div class="p-4">
                <?php if (empty($docVersions)): ?>
                <p class="text-gray-500 text-sm text-center py-6">No KYC documents uploaded yet.</p>
                <?php else: ?>
                <?php foreach ($docVersions as $type => $versions): ?>
                <div class="mb-4">
                    <p class="text-sm font-medium mb-1"><?= e(ucwords(str_replace('_', ' ', $type))) ?> <span class="text-xs text-gray-500">(<?= count($versions) ?> version<?= count($versions) > 1 ? 's' : '' ?>)</span></p>
                    <ul class="space-y-1">
                        <?php foreach ($versions as $i => $v): ?>
                        <li class="flex items-center gap-2 text-xs <?= $i === 0 ? 'text-gray-200' : 'text-gray-500' ?>">
                            <?php if ($i === 0): ?><span class="px-1.5 py-0.5 rounded bg-emerald-500/15 text-emerald-400">Current</span><?php else: ?><span class="px-1.5 py-0.5 rounded bg-gray-700/40">v<?= count($versions) - $i ?></span><?php endif; ?>
                            <span class="font-mono truncate max-w-[10rem]"><?= e($v['file_name']) ?></span>
                            <span>· <?= e($v['scan_status']) ?></span>
                            <span class="ml-auto"><?= e(formatDate($v['created_at'])) ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Audit trail -->
        <div class="glass rounded-xl overflow-hidden min-w-0">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Compliance audit trail</h2></div>
            <div class="p-4">
                <?php if (empty($audit)): ?>
                <p class="text-gray-500 text-sm text-center py-6">No audit events yet.</p>
                <?php else: ?>
                <ul class="space-y-2">
                    <?php foreach ($audit as $a): ?>
                    <li class="text-xs border-l-2 border-gray-700 pl-3">
                        <span class="text-gray-300"><?= e($a['detail'] ?: $a['action']) ?></span>
                        <span class="block text-gray-500"><?= e($a['action']) ?> · <?= e(formatDate($a['created_at'])) ?><?= !empty($a['admin_id']) ? (' · admin #' . (int)$a['admin_id']) : '' ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent submissions -->
    <div class="glass rounded-xl overflow-hidden min-w-0">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Recent submissions (all merchants)</h2></div>
        <?php if (empty($submissions)): ?>
        <p class="text-gray-500 text-sm text-center py-8">No gateway submissions yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-[560px] w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                    <tr><th class="px-4 py-3 text-left">Merchant</th><th class="px-4 py-3 text-left">Gateway</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Date</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($submissions as $s): ?>
                    <tr<?= uiRowClick('admin_gateway_submit.php?merchant_id=' . (int)$s['merchant_row_id']) ?>>
                        <td class="px-4 py-3">
                            <p class="font-medium"><?= adminMerchantLink((int)$s['merchant_row_id'], $s['business_name'], 'font-medium hover:text-sky-300') ?></p>
                            <p class="text-xs text-gray-500"><?= adminMerchantLink((int)$s['merchant_row_id'], $s['merchant_code'], 'font-mono text-sky-400') ?></p>
                        </td>
                        <td class="px-4 py-3 uppercase text-xs"><?= e($s['gateway']) ?></td>
                        <td class="px-4 py-3"><?= statusBadge($s['status']) ?></td>
                        <td class="px-4 py-3 text-xs text-gray-500"><?= formatDate($s['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if (isSuperAdmin()): ?>
    <div class="glass rounded-xl p-4 sm:p-6 border border-red-500/20">
        <h2 class="font-semibold text-red-400 mb-2">Security Cleanup — Purge Plaintext Secrets</h2>
        <p class="text-xs text-gray-500 mb-3">Scans all <code class="text-xs bg-gray-800 px-1 rounded">gateway_submissions</code> and <code class="text-xs bg-gray-800 px-1 rounded">partner_forward_queue</code> rows for plaintext PAN/GST/password/api_secret and overwrites them with redacted versions. <strong class="text-amber-400">Take a DB backup before running.</strong> Safe to run multiple times (idempotent).</p>
        <form method="POST" class="inline" onsubmit="return confirm('Have you taken a DB backup? This will overwrite old payload data. Continue?')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="purge_secrets">
            <button type="submit" class="px-4 py-2 rounded-lg bg-red-600/20 text-red-400 hover:bg-red-600/30 text-sm font-medium">Purge Old Secrets Now</button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
