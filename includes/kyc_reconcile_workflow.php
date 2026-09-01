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

/**
 * Code-only live prove probes — no partner keys or ₹1 payment required.
 *
 * @return array{ok:bool,failed:int,checks:list<array{id:string,label:string,ok:bool,detail:string}>}
 */
function runReconcileLiveProveProbes(): array
{
    $checks = [];
    $pass = static function (string $id, string $label, string $detail = 'OK') use (&$checks): void {
        $checks[] = ['id' => $id, 'label' => $label, 'ok' => true, 'detail' => $detail];
    };
    $fail = static function (string $id, string $label, string $detail) use (&$checks): void {
        $checks[] = ['id' => $id, 'label' => $label, 'ok' => false, 'detail' => $detail];
    };

    $reconcilePhp = (string)@file_get_contents(__DIR__ . '/payment_reconcile.php');
    foreach (['paymentAllowedStatusTransition', 'manualReconcileTransaction', 'logPaymentPartnerStatusMismatch'] as $fn) {
        if (str_contains($reconcilePhp, 'function ' . $fn)) {
            $pass('reconcile_' . $fn, 'payment_reconcile.php · ' . $fn);
        } else {
            $fail('reconcile_' . $fn, 'payment_reconcile.php · ' . $fn, 'Missing function');
        }
    }

    $txnDetail = (string)@file_get_contents(__DIR__ . '/transaction_detail.php');
    if (str_contains($txnDetail, 'function transactionConfirmationSourceSummary')) {
        $pass('txn_detail_source', 'Transaction detail shows confirmed-via source');
    } else {
        $fail('txn_detail_source', 'Transaction detail source summary', 'Missing transactionConfirmationSourceSummary');
    }

    $errorCatcher = (string)@file_get_contents(__DIR__ . '/error_catcher.php');
    if (str_contains($errorCatcher, 'maskPiiRegex') && str_contains($errorCatcher, '$requestUri')) {
        $pass('error_log_pii_new', 'New Error Log rows mask message/url/trace');
    } else {
        $fail('error_log_pii_new', 'Error Log PII mask (new rows)', 'maskPiiRegex on message/url/trace missing');
    }

    $guardPhp = (string)@file_get_contents(__DIR__ . '/kyc_submit_guard.php');
    if (str_contains($guardPhp, 'function claimKycSubmitLock')) {
        $pass('kyc_submit_guard', 'KYC double-submit idempotent lock');
    } else {
        $fail('kyc_submit_guard', 'KYC submit guard', 'includes/kyc_submit_guard.php missing');
    }

    $kycVerify = (string)@file_get_contents(__DIR__ . '/kyc_verify.php');
    if (str_contains($kycVerify, 'function evaluateMerchantNameAgainstRegistry')) {
        $pass('kyc_name_auto', 'Auto name mismatch evaluation wired');
    } else {
        $fail('kyc_name_auto', 'KYC name mismatch', 'evaluateMerchantNameAgainstRegistry missing');
    }

    foreach (['razorpay_webhook.php', 'cashfree_webhook.php', 'payu_webhook.php'] as $whFile) {
        $path = dirname(__DIR__) . '/' . $whFile;
        if (is_file($path)) {
            $pass('webhook_file_' . $whFile, 'Webhook endpoint file · ' . $whFile);
        } else {
            $fail('webhook_file_' . $whFile, 'Webhook endpoint · ' . $whFile, 'File missing');
        }
    }

    $failed = count(array_filter($checks, static fn(array $c): bool => empty($c['ok'])));
    return ['ok' => $failed === 0, 'failed' => $failed, 'checks' => $checks];
}

function renderReconcileLiveProvePanel(): string
{
    $probe = runReconcileLiveProveProbes();
    $html = '<div class="glass rounded-xl p-4 mb-6 border border-emerald-500/30 text-xs text-gray-400 reconcile-live-prove">';
    $html .= '<p class="font-semibold text-emerald-300 mb-2">Live prove probes (code — Owner still runs ₹1 test with keys)</p>';
    $html .= '<p class="text-[11px] text-gray-600 mb-3">Green here = wiring OK locally. Real money proof still needs Partner Registry keys + one ₹1 payment on live.</p>';
    $html .= '<ul class="space-y-1">';
    foreach ($probe['checks'] as $row) {
        $icon = !empty($row['ok']) ? '<span class="text-emerald-400">✓</span>' : '<span class="text-red-400">✗</span>';
        $html .= '<li>' . $icon . ' <strong class="text-gray-300">' . e((string)$row['label']) . '</strong>';
        if (($row['detail'] ?? '') !== '' && ($row['detail'] ?? '') !== 'OK') {
            $html .= ' — ' . e((string)$row['detail']);
        }
        $html .= '</li>';
    }
    $html .= '</ul></div>';
    return $html;
}

function renderReconcilePayVsRefundPanel(): string
{
    $html = '<div class="glass rounded-xl p-4 mb-6 border border-violet-500/20 text-xs text-gray-400 reconcile-pay-refund">';
    $html .= '<p class="font-semibold text-violet-300 mb-2">Pay vs Refund (MIS clarity)</p>';
    $html .= '<ul class="space-y-1 list-disc list-inside">';
    $html .= '<li><strong class="text-gray-300">Successful Txns</strong> = customer paid (capture) — wallet credit when ledger posted</li>';
    $html .= '<li><strong class="text-gray-300">Refunds</strong> = money returned — separate from chargeback/dispute (see Disputes page)</li>';
    $html .= '<li><strong class="text-gray-300">Pending Txns</strong> = not confirmed paid — do not treat as settled revenue</li>';
    $html .= '<li><strong class="text-gray-300">Settlement CSV</strong> = partner bank file — match unmatched rows before closing the day</li>';
    $html .= '</ul></div>';
    return $html;
}
