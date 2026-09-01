<?php
declare(strict_types=1);

/**
 * KYC failure scenarios + payment reconcile tools — Owner / ops education (English UI).
 * Audit: merchant submit, admin review, partner forward staged, reconcile paths.
 */

/** @return list<array{scenario:string,outcome:string,look:string}> */
function kycFailureMerchantScenarios(): array
{
    return [
        ['scenario' => 'Document missing / wrong type', 'outcome' => 'Upload rejected or stays pending', 'look' => 'Clear error; status not verified'],
        ['scenario' => 'Blurry / unreadable ID', 'outcome' => 'Admin reject → re-upload', 'look' => 'Reason shown on KYC page'],
        ['scenario' => 'Name vs PAN/Aadhaar mismatch', 'outcome' => 'Hold or reject', 'look' => 'Status + rejection reason'],
        ['scenario' => 'Bank / IFSC validation fail', 'outcome' => 'Bank step fails separately', 'look' => 'Bank section error — rest of KYC may continue'],
        ['scenario' => 'Duplicate business / phone / PAN', 'outcome' => 'Block or merge hold', 'look' => '“Already registered” style message'],
        ['scenario' => 'Session expired mid-KYC', 'outcome' => 'Re-login; uploads kept when possible', 'look' => 'Re-open KYC — prior uploads listed'],
        ['scenario' => 'Video KYC fail / skip', 'outcome' => 'Live camera required when gate ON', 'look' => 'No “verified” without admin video OK'],
        ['scenario' => 'Team member without role', 'outcome' => '403 / no access', 'look' => 'Role check on sensitive actions'],
    ];
}

/** @return list<array{scenario:string,risk:string,check:string}> */
function kycFailureAdminScenarios(): array
{
    return [
        ['scenario' => 'Approve without docs', 'risk' => 'Compliance', 'check' => 'Verify only when readiness green'],
        ['scenario' => 'Reject without reason', 'risk' => 'Merchant stuck', 'check' => 'Min 10 chars — merchant sees reason'],
        ['scenario' => 'Admin UI ≠ DB status', 'risk' => 'Confusion', 'check' => 'Same kyc_status on list + portal'],
        ['scenario' => 'Live collect + KYC pending', 'risk' => 'High', 'check' => 'Live gate blocks unverified'],
        ['scenario' => 'Forward “success” when staged', 'risk' => 'False trust', 'check' => 'Staged ≠ sent to bank'],
    ];
}

/** @return list<array{scenario:string,meaning:string}> */
function kycForwardFailureScenarios(): array
{
    return [
        ['scenario' => 'local_record / staged', 'meaning' => 'Saved on UniWeb — not at partner yet'],
        ['scenario' => 'Partner API 4xx', 'meaning' => 'Failed + sanitized error (bad data)'],
        ['scenario' => 'Partner 5xx / timeout', 'meaning' => 'Retry / backoff — no auto spam'],
        ['scenario' => 'Duplicate enqueue', 'meaning' => 'Idempotent skip — no Error Log flood'],
        ['scenario' => 'Keys missing', 'meaning' => '“Not configured” — not fake sent'],
    ];
}

/** @return list<array{tool:string,path:string,role:string}> */
function reconcileToolsMap(): array
{
    return [
        ['tool' => 'Canonical reconcile (code)', 'path' => 'includes/payment_reconcile.php', 'role' => 'One ruleset: webhook + poll + checkout + manual'],
        ['tool' => 'Admin Reconciliation', 'path' => 'admin_reconciliation.php', 'role' => 'Manual backfill / retry · source=manual'],
        ['tool' => 'Transaction detail', 'path' => 'transaction_detail.php', 'role' => 'Status · confirmed via · ledger timeline'],
        ['tool' => 'PG Webhooks log', 'path' => 'admin_pg_webhooks.php', 'role' => 'Inbound events · sig fail · duplicate'],
        ['tool' => 'Webhook reliability', 'path' => 'admin_webhook_reliability.php', 'role' => 'Poll / retry pending'],
        ['tool' => 'Platform Status', 'path' => 'admin_platform_status.php', 'role' => 'Health · cron · gaps'],
        ['tool' => 'Error Log', 'path' => 'admin_error_log.php', 'role' => 'Mismatch · sig fail · ledger pending'],
        ['tool' => 'Soft Launch', 'path' => 'admin_soft_launch.php', 'role' => 'Migrate · keys · cron checklist'],
        ['tool' => 'Partner panel', 'path' => 'Razorpay / Cashfree / PayU dashboard', 'role' => 'Source of truth for capture'],
    ];
}

function renderKycFailureMerchantNote(): string
{
    $html = '<div class="glass rounded-xl p-4 mb-6 border border-sky-500/20 text-xs text-gray-400 kyc-failure-merchant-note">';
    $html .= '<p class="font-semibold text-sky-300 mb-2">If KYC fails or pauses</p><ul class="space-y-1 list-disc list-inside">';
    foreach (array_slice(kycFailureMerchantScenarios(), 0, 5) as $row) {
        $html .= '<li><strong class="text-gray-300">' . e($row['scenario']) . ':</strong> ' . e($row['look']) . '</li>';
    }
    $html .= '</ul><p class="text-[11px] text-gray-600 mt-2">Rejected documents show the exact reason below. Real money needs Admin Verify + Live activation — not upload alone.</p></div>';
    return $html;
}

function renderKycFailureAdminPanel(): string
{
    $html = '<div class="glass rounded-xl p-4 mb-6 border border-violet-500/25 text-sm text-gray-300 kyc-failure-admin-panel">';
    $html .= '<p class="font-semibold text-violet-300 mb-1">KYC failure scenarios (Admin checklist)</p>';
    $html .= '<div class="grid lg:grid-cols-2 gap-4 mt-3">';
    $html .= '<div><p class="text-xs font-semibold text-gray-400 mb-2">Admin review</p><ul class="text-xs text-gray-500 space-y-1">';
    foreach (kycFailureAdminScenarios() as $row) {
        $html .= '<li><strong class="text-gray-300">' . e($row['scenario']) . '</strong> — ' . e($row['check']) . '</li>';
    }
    $html .= '</ul></div>';
    $html .= '<div><p class="text-xs font-semibold text-gray-400 mb-2">Partner forward</p><ul class="text-xs text-gray-500 space-y-1">';
    foreach (kycForwardFailureScenarios() as $row) {
        $html .= '<li><strong class="text-gray-300">' . e($row['scenario']) . ':</strong> ' . e($row['meaning']) . '</li>';
    }
    $html .= '</ul><p class="text-[11px] text-gray-600 mt-2"><a href="admin_forward_queue.php" class="text-sky-400 hover:underline">Forward queue</a> — filter Staged; not sent to bank until adapter + keys.</p></div>';
    $html .= '</div></div>';
    return $html;
}

function renderReconcileToolsMapPanel(): string
{
    $html = '<div class="glass rounded-xl p-4 mb-6 border border-emerald-500/25 text-sm text-gray-300 reconcile-tools-map">';
    $html .= '<p class="font-semibold text-emerald-300 mb-1">Reconciliation tools (where to click)</p>';
    $html .= '<div class="overflow-x-auto mt-2"><table class="w-full text-xs min-w-[560px]">';
    $html .= '<thead class="text-gray-500 uppercase"><tr><th class="py-2 pr-3 text-left">Tool</th><th class="py-2 pr-3 text-left">Path</th><th class="py-2 text-left">Role</th></tr></thead><tbody class="divide-y divide-gray-800">';
    foreach (reconcileToolsMap() as $row) {
        $path = $row['path'];
        $link = str_contains($path, '/') || str_contains($path, ' ')
            ? e($path)
            : '<a href="' . e($path) . '" class="text-sky-400 hover:underline">' . e($path) . '</a>';
        $html .= '<tr><td class="py-2 pr-3 text-gray-200">' . e($row['tool']) . '</td><td class="py-2 pr-3 font-mono">' . $link . '</td><td class="py-2 text-gray-500">' . e($row['role']) . '</td></tr>';
    }
    $html .= '</tbody></table></div></div>';
    return $html;
}

function renderReconcileRulesPanel(): string
{
    $sources = function_exists('paymentReconcileSources') ? paymentReconcileSources() : ['webhook', 'poll', 'checkout', 'manual'];
    $html = '<div class="glass rounded-xl p-4 mb-6 border border-amber-500/20 text-xs text-gray-400 reconcile-rules-panel">';
    $html .= '<p class="font-semibold text-amber-300 mb-2">Reconcile rules (same for auto + manual)</p>';
    $html .= '<ul class="space-y-1 list-disc list-inside">';
    $html .= '<li><strong class="text-gray-300">pending → success/failed</strong> — allowed</li>';
    $html .= '<li><strong class="text-gray-300">success → success</strong> — idempotent (no double ledger)</li>';
    $html .= '<li><strong class="text-gray-300">success + partner says failed</strong> — Error Log only; no silent unpay</li>';
    $html .= '<li><strong class="text-gray-300">Ledger</strong> — one credit per successful capture</li>';
    $html .= '</ul>';
    $html .= '<p class="text-[11px] text-gray-600 mt-2">Sources: ' . e(implode(' · ', $sources)) . '. Txn detail shows <em>Status confirmed via</em>.</p></div>';
    return $html;
}

function renderReconcileOwnerChecklistPanel(): string
{
    $html = '<div class="glass rounded-xl p-4 mb-6 border border-sky-500/20 text-xs text-gray-400 reconcile-owner-checklist">';
    $html .= '<p class="font-semibold text-sky-300 mb-2">Owner reconcile check (before big volume)</p>';
    $html .= '<ol class="space-y-1 list-decimal list-inside">';
    $html .= '<li><a href="admin_soft_launch.php" class="text-sky-400 hover:underline">Soft Launch</a> — migrations <strong class="text-gray-300">081+</strong> applied</li>';
    $html .= '<li>GET <code class="text-gray-300">/razorpay_webhook.php</code> → health JSON</li>';
    $html .= '<li>₹1 test pay → txn <strong class="text-emerald-400">Success</strong> + ledger posted</li>';
    $html .= '<li>Same webhook retry → <strong class="text-gray-300">no second credit</strong></li>';
    $html .= '<li><a href="admin_reconciliation.php?tab=manual" class="text-sky-400 hover:underline">Manual reconcile</a> → source <strong class="text-gray-300">manual</strong> on txn detail</li>';
    $html .= '<li><a href="admin_error_log.php" class="text-sky-400 hover:underline">Error Log</a> — no silent mismatches</li>';
    $html .= '</ol></div>';
    return $html;
}
