<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/method_requests.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops', 'regional_manager']);
ensureMethodRequestSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['request_id'] ?? 0);
    $note = (string)($_POST['admin_note'] ?? '');
    $actor = getAdmin()['name'] ?? ($_SESSION['admin_username'] ?? 'admin');
    $gateway = trim((string)($_POST['partner_gateway'] ?? '')) ?: null;
    $merchantFilter = (int)($_POST['merchant_id'] ?? 0);

    $res = ['ok' => false, 'error' => 'Unknown action.'];
    if ($action === 'send_all_pending') {
        $res = sendAllPendingMethodRequestsToPartner($merchantFilter > 0 ? $merchantFilter : null, (string)$actor, $note !== '' ? $note : 'Bulk send to partner');
    } elseif ($action === 'send_partner') {
        $res = sendMethodRequestToPartner($id, (string)$actor, $note, $gateway);
    } elseif ($action === 'partner_approve') {
        $res = recordMethodRequestPartnerDecision($id, true, (string)$actor, $note);
    } elseif ($action === 'partner_reject') {
        $res = recordMethodRequestPartnerDecision($id, false, (string)$actor, $note);
    } elseif ($action === 'final_enable') {
        $res = finalEnableMethodRequest($id, (string)$actor, $note);
    } elseif ($action === 'approve' || $action === 'reject') {
        $res = decideMethodRequest($id, $action === 'approve', (string)$actor, $note);
    }

    if ($res['ok'] && function_exists('logStaffActivity')) {
        logStaffActivity('method_request_' . $action, 'Request #' . $id . ($note !== '' ? ' — ' . $note : ''), null, 'method_request', (string)$id);
    }
    flash($res['ok'] ? 'success' : 'error', $res['ok'] ? ($res['message'] ?? 'Done.') : ($res['error'] ?? 'Failed.'));
    redirect('admin_method_requests.php' . (($_GET['status'] ?? '') ? '?status=' . urlencode((string)$_GET['status']) : ''));
}

$statusFilter = $_GET['status'] ?? 'actionable';
$allowedFilters = ['actionable', 'pending', 'sent_to_partner', 'partner_approved', 'partner_rejected', 'approved', 'rejected', 'all'];
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'actionable';
}
$requests = getMethodRequests($statusFilter);
$catalog = getPaymentMethodCatalog();
$pendingCount = getPendingMethodRequestCount();

$pageTitle = 'Payment Method Requests';
require_once __DIR__ . '/header.php';
?>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">Payment Method Requests</h1>
        <p class="text-sm text-gray-500 mt-1">Merchants auto-queue on signup/KYC. You send once to partner. Partner reply turns methods ON/OFF automatically.</p>
    </div>
    <div class="flex flex-wrap gap-2 text-xs items-center">
        <form method="POST" onsubmit="return confirm('Send ALL pending requests to partner?')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="send_all_pending">
            <button class="px-3 py-1.5 rounded-lg bg-sky-600 text-white font-medium">Send all pending → Partner</button>
        </form>
        <?php foreach ([
            'actionable' => 'Needs action',
            'pending' => 'Pending',
            'sent_to_partner' => 'At partner',
            'partner_approved' => 'Partner OK',
            'approved' => 'Enabled',
            'rejected' => 'Rejected',
            'all' => 'All',
        ] as $sk => $sl): ?>
        <a href="?status=<?= e($sk) ?>" class="px-3 py-1.5 rounded-lg <?= $statusFilter === $sk ? 'bg-brand-600 text-white' : 'glass text-gray-400 hover:text-white' ?>">
            <?= e($sl) ?><?= $sk === 'actionable' && $pendingCount > 0 ? ' (' . $pendingCount . ')' : '' ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="glass rounded-xl p-4 mb-4 text-xs text-gray-400 border border-sky-500/20">
    <p><strong class="text-sky-300">Automation:</strong> Signup + KYC upload auto-creates requests. P2M is already ON. Partner webhook URL: <code class="text-gray-300">method_partner_webhook.php</code> (secret in Gateway Settings).</p>
    <p class="mt-1">Partner approve = method enabled for merchant (no second Final Enable needed). Manual Partner approved button still works if webhook is late.</p>
</div>

<div class="glass rounded-xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[900px]">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">Merchant</th>
                <th class="px-5 py-3 text-left">Method</th>
                <th class="px-5 py-3 text-left">Requested</th>
                <th class="px-5 py-3 text-left">Status</th>
                <th class="px-5 py-3 text-left">Action</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($requests)): ?>
                <tr><td colspan="5" class="px-5 py-10 text-center text-gray-500">No requests in this filter.</td></tr>
                <?php else: foreach ($requests as $r):
                    $cat = $catalog[$r['method_key']] ?? null;
                    $label = $cat ? (($cat['icon'] ?? '') . ' ' . $cat['label']) : $r['method_key'];
                    $needsPartner = methodRequestNeedsPartner((string)$r['method_key']);
                    $defaultGw = methodRequestPartnerGateway((string)$r['method_key']);
                    $st = (string)$r['status'];
                ?>
                <tr class="hover:bg-white/5 align-top">
                    <td class="px-5 py-3">
                        <p class="font-medium"><?= adminMerchantLink((int)$r['merchant_id'], $r['business_name'], 'text-white hover:text-sky-300') ?></p>
                        <p class="text-xs text-gray-500 font-mono"><?= e($r['merchant_code']) ?></p>
                        <?php if (!empty($r['hold_until']) && $st === 'pending'): ?>
                        <p class="text-[10px] text-amber-400/80 mt-1">Review window until <?= e(formatDate($r['hold_until'])) ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3"><?= e($label) ?>
                        <p class="text-[10px] text-gray-600 mt-0.5"><?= $needsPartner ? ('Partner: ' . strtoupper($defaultGw)) : 'Direct (no partner)' ?></p>
                        <?php if (!empty($r['merchant_note'])): ?><p class="text-xs text-gray-500 mt-1 italic">"<?= e($r['merchant_note']) ?>"</p><?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($r['created_at']) ?></td>
                    <td class="px-5 py-3">
                        <p class="text-xs <?= $st === 'approved' ? 'text-emerald-400' : ($st === 'rejected' || $st === 'partner_rejected' ? 'text-red-400' : 'text-amber-300') ?>">
                            <?= e(methodRequestStatusLabel($st)) ?>
                        </p>
                        <?php if (!empty($r['partner_gateway'])): ?>
                        <p class="text-[11px] text-gray-500 mt-1">GW <?= e(strtoupper((string)$r['partner_gateway'])) ?><?= !empty($r['partner_ref']) ? ' · ' . e((string)$r['partner_ref']) : '' ?></p>
                        <?php endif; ?>
                        <?php if (!empty($r['decided_by'])): ?><p class="text-[11px] text-gray-600 mt-1">by <?= e($r['decided_by']) ?></p><?php endif; ?>
                        <?php if (!empty($r['admin_note'])): ?><p class="text-[11px] text-gray-500 mt-0.5"><?= e($r['admin_note']) ?></p><?php endif; ?>
                        <?php if (!empty($r['partner_note'])): ?><p class="text-[11px] text-sky-500/80 mt-0.5">Partner: <?= e($r['partner_note']) ?></p><?php endif; ?>
                    </td>
                    <td class="px-5 py-3">
                        <form method="POST" class="flex flex-col gap-2 max-w-xs" onsubmit="return confirm('Confirm this action?')">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                            <input type="text" name="admin_note" placeholder="Note (optional)" class="input-field text-xs py-1.5 w-full" aria-label="Admin note">

                            <?php if ($st === 'pending'): ?>
                                <?php if ($needsPartner): ?>
                                <input type="hidden" name="partner_gateway" value="<?= e($defaultGw) ?>">
                                <button type="submit" name="action" value="send_partner" class="text-xs px-3 py-1.5 rounded-lg bg-sky-600 hover:bg-sky-500 text-white font-medium">Send to Partner →</button>
                                <?php else: ?>
                                <button type="submit" name="action" value="final_enable" class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-medium">Enable (direct)</button>
                                <?php endif; ?>
                                <button type="submit" name="action" value="reject" class="text-xs px-3 py-1.5 rounded-lg bg-red-600/80 hover:bg-red-600 text-white">Reject</button>
                            <?php elseif ($st === 'sent_to_partner'): ?>
                                <button type="submit" name="action" value="partner_approve" class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600/90 hover:bg-emerald-500 text-white font-medium">Partner approved</button>
                                <button type="submit" name="action" value="partner_reject" class="text-xs px-3 py-1.5 rounded-lg bg-amber-700 hover:bg-amber-600 text-white">Partner rejected</button>
                                <button type="submit" name="action" value="reject" class="text-xs px-3 py-1.5 rounded-lg border border-red-500/40 text-red-300">Cancel request</button>
                            <?php elseif ($st === 'partner_approved'): ?>
                                <button type="submit" name="action" value="final_enable" class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-semibold">Final Enable →</button>
                                <button type="submit" name="action" value="reject" class="text-xs px-3 py-1.5 rounded-lg border border-red-500/40 text-red-300">Reject</button>
                            <?php elseif ($st === 'partner_rejected'): ?>
                                <button type="submit" name="action" value="send_partner" class="text-xs px-3 py-1.5 rounded-lg bg-sky-600 hover:bg-sky-500 text-white">Resend to Partner</button>
                                <button type="submit" name="action" value="reject" class="text-xs px-3 py-1.5 rounded-lg bg-red-600/80 text-white">Close rejected</button>
                            <?php else: ?>
                                <span class="text-xs text-gray-600">—</span>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
