<?php
declare(strict_types=1);

/**
 * Merchant → Admin → Partner → Admin (final) payment-method enable workflow.
 *
 * Sequence:
 *  1) Merchant requests locked method
 *  2) Admin "Send to Partner" (gateway forward + status=sent_to_partner)
 *  3) Partner decision recorded (API/webhook later, or admin marks received)
 *  4) Admin "Final Enable" unlocks method for merchant (status=approved)
 *
 * Internal unlock without partner remains available for ops (explicit action).
 */

function ensureMethodRequestSchema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = getDB();
    try {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS merchant_method_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                merchant_id INT NOT NULL,
                method_key VARCHAR(40) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "pending",
                merchant_note VARCHAR(500) DEFAULT NULL,
                admin_note VARCHAR(500) DEFAULT NULL,
                decided_by VARCHAR(120) DEFAULT NULL,
                partner_gateway VARCHAR(40) DEFAULT NULL,
                partner_ref VARCHAR(120) DEFAULT NULL,
                partner_note VARCHAR(500) DEFAULT NULL,
                hold_until DATETIME DEFAULT NULL,
                sent_to_partner_at DATETIME DEFAULT NULL,
                partner_responded_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                decided_at DATETIME DEFAULT NULL,
                INDEX idx_mmr_merchant (merchant_id),
                INDEX idx_mmr_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) {
        error_log('ensureMethodRequestSchema create: ' . $e->getMessage());
    }

    $alters = [
        "ALTER TABLE merchant_method_requests MODIFY status VARCHAR(32) NOT NULL DEFAULT 'pending'",
        'ALTER TABLE merchant_method_requests ADD COLUMN partner_gateway VARCHAR(40) DEFAULT NULL',
        'ALTER TABLE merchant_method_requests ADD COLUMN partner_ref VARCHAR(120) DEFAULT NULL',
        'ALTER TABLE merchant_method_requests ADD COLUMN partner_note VARCHAR(500) DEFAULT NULL',
        'ALTER TABLE merchant_method_requests ADD COLUMN hold_until DATETIME DEFAULT NULL',
        'ALTER TABLE merchant_method_requests ADD COLUMN sent_to_partner_at DATETIME DEFAULT NULL',
        'ALTER TABLE merchant_method_requests ADD COLUMN partner_responded_at DATETIME DEFAULT NULL',
    ];
    foreach ($alters as $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable $e) {
            // column/type already applied
        }
    }
}

function methodRequestNeedsPartner(string $methodKey): bool
{
    $catalog = getPaymentMethodCatalog();
    $gw = (string)($catalog[$methodKey]['gateway'] ?? 'direct');
    return $gw !== 'direct';
}

function methodRequestPartnerGateway(string $methodKey): string
{
    $catalog = getPaymentMethodCatalog();
    $gw = (string)($catalog[$methodKey]['gateway'] ?? 'payu');
    if ($gw === 'direct') {
        return 'payu';
    }
    if ($gw === 'nbfc') {
        $pref = trim((string)getSetting('nbfc_partner_gateway', 'payu'));
        $gw = $pref !== '' ? $pref : 'payu';
    }
    if ($gw === 'instant') {
        $pref = trim((string)getSetting('instant_settlement_gateway', 'razorpay'));
        $gw = $pref !== '' ? $pref : 'razorpay';
    }
    if ($methodKey === 'payout') {
        $gw = 'razorpay';
    }
    $allowed = function_exists('gatewaySubmissionAllowedGateways')
        ? gatewaySubmissionAllowedGateways()
        : ['razorpay', 'cashfree', 'payu', 'decentro', 'phonepe', 'axis'];
    return in_array($gw, $allowed, true) ? $gw : 'payu';
}

function merchantEntitledMethods(array $merchant): array
{
    // Only what is actually unlocked — not the old entity "wish list" profile.
    $methods = getMerchantEnabledMethods($merchant);
    foreach (approvedMethodKeys((int)$merchant['id']) as $k) {
        $methods[] = $k;
    }
    $catalog = array_keys(getPaymentMethodCatalog());
    return array_values(array_unique(array_intersect($catalog, $methods)));
}

function merchantLockedMethods(array $merchant): array
{
    $catalog = array_keys(getPaymentMethodCatalog());
    return array_values(array_diff($catalog, merchantEntitledMethods($merchant)));
}

function approvedMethodKeys(int $merchantId): array
{
    ensureMethodRequestSchema();
    try {
        $stmt = getDB()->prepare('SELECT DISTINCT method_key FROM merchant_method_requests WHERE merchant_id=? AND status="approved"');
        $stmt->execute([$merchantId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function merchantMethodRequestMap(int $merchantId): array
{
    ensureMethodRequestSchema();
    $map = [];
    try {
        $stmt = getDB()->prepare('SELECT method_key, status FROM merchant_method_requests WHERE merchant_id=? ORDER BY id ASC');
        $stmt->execute([$merchantId]);
        foreach ($stmt->fetchAll() as $row) {
            $map[(string)$row['method_key']] = (string)$row['status'];
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $map;
}

function methodRequestStatusLabel(string $status): string
{
    return match ($status) {
        'pending' => 'Pending admin review',
        'sent_to_partner' => 'Sent to partner — awaiting partner',
        'partner_approved' => 'Partner approved — enabling',
        'partner_rejected' => 'Partner rejected',
        'approved' => 'Enabled',
        'rejected' => 'Rejected by admin',
        'not_requested' => 'Queued on signup/KYC',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function requestMethodEnable(int $merchantId, string $methodKey, string $note = ''): array
{
    ensureMethodRequestSchema();
    $catalog = getPaymentMethodCatalog();
    if (!isset($catalog[$methodKey])) {
        return ['ok' => false, 'error' => 'Unknown payment method.'];
    }
    try {
        $db = getDB();
        $chk = $db->prepare('SELECT id FROM merchant_method_requests WHERE merchant_id=? AND method_key=? AND status IN ("pending","sent_to_partner","partner_approved") LIMIT 1');
        $chk->execute([$merchantId, $methodKey]);
        if ($chk->fetch()) {
            return ['ok' => false, 'error' => 'A request for this method is already in progress.'];
        }
        $holdUntil = date('Y-m-d H:i:s', time() + 3600); // 1-hour admin review window
        $db->prepare('INSERT INTO merchant_method_requests (merchant_id, method_key, merchant_note, hold_until) VALUES (?,?,?,?)')
            ->execute([$merchantId, $methodKey, mb_substr(trim($note), 0, 500) ?: null, $holdUntil]);
        $msg = $catalog[$methodKey]['label'] . ' requested. Admin will review (then partner, if required).';
        return ['ok' => true, 'message' => $msg];
    } catch (Throwable $e) {
        error_log('requestMethodEnable: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not submit request. Please try again.'];
    }
}

function getPendingMethodRequestCount(): int
{
    ensureMethodRequestSchema();
    try {
        return (int)getDB()->query(
            'SELECT COUNT(*) FROM merchant_method_requests WHERE status IN ("pending","sent_to_partner","partner_approved")'
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function getMethodRequests(string $status = 'pending', int $limit = 200): array
{
    ensureMethodRequestSchema();
    $sql = 'SELECT r.*, m.business_name, m.merchant_code, m.account_mode
            FROM merchant_method_requests r
            JOIN merchants m ON r.merchant_id = m.id';
    $params = [];
    $allowed = ['pending', 'sent_to_partner', 'partner_approved', 'partner_rejected', 'approved', 'rejected'];
    if (in_array($status, $allowed, true)) {
        $sql .= ' WHERE r.status = ?';
        $params[] = $status;
    } elseif ($status === 'actionable') {
        $sql .= ' WHERE r.status IN ("pending","sent_to_partner","partner_approved")';
    }
    $sql .= ' ORDER BY r.created_at DESC LIMIT ' . max(1, min(500, $limit));
    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getMethodRequestById(int $requestId): ?array
{
    ensureMethodRequestSchema();
    try {
        $stmt = getDB()->prepare('SELECT * FROM merchant_method_requests WHERE id=? LIMIT 1');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Admin: forward request to partner gateway (scaffold + gateway_submissions row). */
function sendMethodRequestToPartner(int $requestId, string $actor, string $adminNote = '', ?string $gatewayOverride = null): array
{
    ensureMethodRequestSchema();
    $req = getMethodRequestById($requestId);
    if (!$req) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }
    if (!in_array((string)$req['status'], ['pending', 'partner_rejected'], true)) {
        return ['ok' => false, 'error' => 'Request cannot be sent to partner from status: ' . $req['status']];
    }

    $methodKey = (string)$req['method_key'];
    $gateway = $gatewayOverride ?: methodRequestPartnerGateway($methodKey);
    $merchantId = (int)$req['merchant_id'];
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $note = trim($adminNote);
    if ($note === '') {
        $note = 'Method enable request: ' . $methodKey;
    }

    $forwarded = false;
    $partnerRef = null;
    if (function_exists('submitMerchantToGateway') && $gateway !== 'direct') {
        try {
            $forwarded = submitMerchantToGateway($merchantId, $gateway, $adminId > 0 ? $adminId : 1, $note);
        } catch (Throwable $e) {
            error_log('sendMethodRequestToPartner forward: ' . $e->getMessage());
        }
    }
    try {
        $st = getDB()->prepare('SELECT id FROM gateway_submissions WHERE merchant_id=? AND gateway=? ORDER BY id DESC LIMIT 1');
        $st->execute([$merchantId, $gateway]);
        $sid = $st->fetchColumn();
        if ($sid) {
            $partnerRef = 'GS' . (int)$sid;
        }
    } catch (Throwable $e) {
        // ok
    }
    if ($partnerRef === null) {
        $partnerRef = 'MANUAL-' . strtoupper($gateway) . '-' . $requestId;
    }

    try {
        getDB()->prepare(
            'UPDATE merchant_method_requests SET status=?, admin_note=?, decided_by=?, partner_gateway=?, partner_ref=?, sent_to_partner_at=NOW() WHERE id=?'
        )->execute([
            'sent_to_partner',
            mb_substr($note, 0, 500),
            mb_substr($actor, 0, 120),
            $gateway,
            $partnerRef,
            $requestId,
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update request.'];
    }

    if (function_exists('createNotification')) {
        createNotification(
            $merchantId,
            'Method request sent to partner',
            methodRequestStatusLabel('sent_to_partner') . ' (' . strtoupper($gateway) . '). We will enable after partner approval.'
        );
    }

    $keysReady = function_exists('isGatewayConfigured') ? isGatewayConfigured($gateway) : false;
    $extra = $forwarded
        ? ' Forwarded via gateway submissions.'
        : ' Recorded for manual partner follow-up.';
    if (!$keysReady) {
        $extra .= ' Partner API keys not configured yet — mark partner decision manually when received.';
    }

    return ['ok' => true, 'message' => 'Sent to partner (' . strtoupper($gateway) . ').' . $extra, 'gateway' => $gateway, 'partner_ref' => $partnerRef];
}

/** Admin (or future webhook): record partner approve/reject. Does NOT unlock merchant yet. */
function recordMethodRequestPartnerDecision(int $requestId, bool $partnerApproved, string $actor, string $partnerNote = ''): array
{
    ensureMethodRequestSchema();
    $req = getMethodRequestById($requestId);
    if (!$req) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }
    if ((string)$req['status'] !== 'sent_to_partner') {
        return ['ok' => false, 'error' => 'Partner decision only allowed when status is sent_to_partner.'];
    }
    $newStatus = $partnerApproved ? 'partner_approved' : 'partner_rejected';
    try {
        getDB()->prepare(
            'UPDATE merchant_method_requests SET status=?, partner_note=?, decided_by=?, partner_responded_at=NOW() WHERE id=?'
        )->execute([
            $newStatus,
            mb_substr(trim($partnerNote), 0, 500) ?: null,
            mb_substr($actor, 0, 120),
            $requestId,
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save partner decision.'];
    }

    if (function_exists('createNotification')) {
        createNotification(
            (int)$req['merchant_id'],
            'Partner update on method request',
            methodRequestStatusLabel($newStatus)
        );
    }

    // Partner OK = merchant method ON automatically (less admin clicking).
    if ($partnerApproved) {
        $enabled = finalEnableMethodRequest($requestId, $actor, 'Auto-enabled after partner approval');
        triggerAgreementResignCheck((int)$req['merchant_id'], (string)($req['partner_gateway'] ?? ''));
        if (!empty($enabled['ok'])) {
            return [
                'ok' => true,
                'message' => 'Partner approved and method enabled for merchant.',
                'auto_enabled' => true,
            ];
        }
        return [
            'ok' => true,
            'message' => 'Partner approved. Final enable pending: ' . ($enabled['error'] ?? 'unknown'),
            'auto_enabled' => false,
        ];
    }

    return ['ok' => true, 'message' => 'Partner decision saved: ' . $newStatus . '.'];
}

/** Admin final enable after partner_approved (or direct methods). Unlocks merchant method. */
function finalEnableMethodRequest(int $requestId, string $actor, string $adminNote = ''): array
{
    ensureMethodRequestSchema();
    $req = getMethodRequestById($requestId);
    if (!$req) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }
    $status = (string)$req['status'];
    $methodKey = (string)$req['method_key'];
    $needsPartner = methodRequestNeedsPartner($methodKey);

    if ($needsPartner && $status !== 'partner_approved') {
        return ['ok' => false, 'error' => 'Final enable requires partner approval first.'];
    }
    if (!$needsPartner && !in_array($status, ['pending', 'partner_approved'], true)) {
        return ['ok' => false, 'error' => 'Cannot final-enable from status: ' . $status];
    }

    try {
        getDB()->prepare(
            'UPDATE merchant_method_requests SET status=?, admin_note=COALESCE(?, admin_note), decided_by=?, decided_at=NOW() WHERE id=?'
        )->execute([
            'approved',
            mb_substr(trim($adminNote), 0, 500) ?: null,
            mb_substr($actor, 0, 120),
            $requestId,
        ]);
        unlockMerchantMethod((int)$req['merchant_id'], $methodKey);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not enable method.'];
    }

    $label = getPaymentMethodCatalog()[$methodKey]['label'] ?? $methodKey;
    if (function_exists('createNotification')) {
        createNotification((int)$req['merchant_id'], 'Payment method enabled', $label . ' is now enabled on your account.');
    }

    return ['ok' => true, 'message' => $label . ' enabled for merchant.'];
}

/** Reject at admin stage (pending / sent_to_partner / partner_rejected). */
function rejectMethodRequest(int $requestId, string $actor, string $adminNote = ''): array
{
    ensureMethodRequestSchema();
    $req = getMethodRequestById($requestId);
    if (!$req) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }
    if (in_array((string)$req['status'], ['approved', 'rejected'], true)) {
        return ['ok' => false, 'error' => 'Request already closed.'];
    }
    try {
        getDB()->prepare(
            'UPDATE merchant_method_requests SET status=?, admin_note=?, decided_by=?, decided_at=NOW() WHERE id=?'
        )->execute([
            'rejected',
            mb_substr(trim($adminNote), 0, 500) ?: null,
            mb_substr($actor, 0, 120),
            $requestId,
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not reject request.'];
    }
    if (function_exists('createNotification')) {
        createNotification((int)$req['merchant_id'], 'Method request declined', methodRequestStatusLabel('rejected'));
    }
    return ['ok' => true, 'message' => 'Request rejected.'];
}

/**
 * Ops override: unlock without partner (demo / internal). Logged clearly.
 * Prefer send → partner → final enable for live card rails.
 */
function decideMethodRequest(int $requestId, bool $approve, string $decidedBy, string $adminNote = ''): array
{
    if (!$approve) {
        return rejectMethodRequest($requestId, $decidedBy, $adminNote);
    }
    ensureMethodRequestSchema();
    $req = getMethodRequestById($requestId);
    if (!$req) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }
    if ((string)$req['status'] === 'partner_approved') {
        return finalEnableMethodRequest($requestId, $decidedBy, $adminNote);
    }
    if ((string)$req['status'] !== 'pending') {
        return ['ok' => false, 'error' => 'Use Send to Partner / Partner decision / Final Enable for this status.'];
    }
    // Direct (no partner) methods: final enable immediately.
    if (!methodRequestNeedsPartner((string)$req['method_key'])) {
        return finalEnableMethodRequest($requestId, $decidedBy, $adminNote !== '' ? $adminNote : 'Direct method — no partner required');
    }
    // Partner methods: internal unlock only with explicit note prefix
    $note = trim($adminNote);
    if ($note === '' || !str_starts_with(strtolower($note), 'internal')) {
        return ['ok' => false, 'error' => 'This method needs partner approval. Click Send to Partner, or type note starting with "internal" to unlock without partner (ops only).'];
    }
    try {
        getDB()->prepare(
            'UPDATE merchant_method_requests SET status=?, admin_note=?, decided_by=?, decided_at=NOW(), partner_note=? WHERE id=?'
        )->execute([
            'approved',
            mb_substr($note, 0, 500),
            mb_substr($decidedBy, 0, 120),
            'Internal unlock without partner',
            $requestId,
        ]);
        unlockMerchantMethod((int)$req['merchant_id'], (string)$req['method_key']);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not unlock internally.'];
    }
    return ['ok' => true, 'message' => 'Internal unlock done (no partner).'];
}

function unlockMerchantMethod(int $merchantId, string $methodKey): void
{
    try {
        $db = getDB();
        $m = $db->prepare('SELECT enabled_methods FROM merchants WHERE id=?');
        $m->execute([$merchantId]);
        $raw = (string)($m->fetchColumn() ?: '');
        $current = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $current = $decoded;
            }
        }
        if (!in_array($methodKey, $current, true)) {
            $current[] = $methodKey;
        }
        $db->prepare('UPDATE merchants SET enabled_methods=? WHERE id=?')
            ->execute([json_encode(array_values($current)), $merchantId]);

        // Keep payout module flag in sync when payout method is unlocked.
        if ($methodKey === 'payout' && function_exists('requestPayoutEnable') === false) {
            // no-op if payout helpers missing
        }
        if ($methodKey === 'payout') {
            try {
                $db->exec("ALTER TABLE merchants ADD COLUMN payout_enabled TINYINT(1) NOT NULL DEFAULT 0");
            } catch (Throwable $e) { /* ok */ }
            try {
                $db->prepare('UPDATE merchants SET payout_enabled=1 WHERE id=?')->execute([$merchantId]);
            } catch (Throwable $e) { /* ok */ }
            // Keep payout enable-request table in sync with method unlock.
            try {
                $db->prepare(
                    "UPDATE merchant_payout_enable_requests SET status='approved', admin_note=COALESCE(admin_note,'Synced from method unlock'), decided_by='system', decided_at=NOW()
                     WHERE merchant_id=? AND status='pending'"
                )->execute([$merchantId]);
            } catch (Throwable $e) { /* table may miss */ }
        }
        if ($methodKey === 'instant_settlement') {
            try {
                $db->prepare("UPDATE merchants SET settlement_mode='scheduled', batch_interval_minutes=15, settlement_use_platform_default=0 WHERE id=?")
                    ->execute([$merchantId]);
            } catch (Throwable $e) {
                try {
                    $db->prepare("UPDATE merchants SET settlement_mode='scheduled' WHERE id=?")->execute([$merchantId]);
                } catch (Throwable $e2) { /* ok */ }
            }
        }
    } catch (Throwable $e) {
        error_log('unlockMerchantMethod: ' . $e->getMessage());
    }
}

/** Methods that must be auto-queued for admin → partner (everything except P2M). */
function getAutoQueueMethodKeys(): array
{
    $keys = array_keys(getPaymentMethodCatalog());
    return array_values(array_filter($keys, static fn(string $k): bool => $k !== 'upi_p2m'));
}

/** Turn UPI P2M ON immediately (no partner needed). */
function enableP2mForMerchant(int $merchantId): void
{
    unlockMerchantMethod($merchantId, 'upi_p2m');
    try {
        getDB()->prepare("UPDATE merchants SET collection_mode=COALESCE(NULLIF(collection_mode,''),'direct_upi'), provision_profile=COALESCE(provision_profile,'auto_p2m'), auto_provisioned=1 WHERE id=?")
            ->execute([$merchantId]);
    } catch (Throwable $e) {
        try {
            getDB()->prepare("UPDATE merchants SET collection_mode='direct_upi' WHERE id=?")->execute([$merchantId]);
        } catch (Throwable $e2) { /* ok */ }
    }
}

/**
 * Signup / KYC: P2M ON + every other method auto-requested into admin queue.
 * Safe to call many times (skips methods already in progress / approved).
 */
function bootstrapMerchantMethodAutomation(int $merchantId, string $note = 'Auto-queued for partner review'): array
{
    ensureMethodRequestSchema();
    enableP2mForMerchant($merchantId);

    $queued = [];
    $skipped = [];
    foreach (getAutoQueueMethodKeys() as $methodKey) {
        $res = requestMethodEnable($merchantId, $methodKey, $note);
        if (!empty($res['ok'])) {
            $queued[] = $methodKey;
        } else {
            $skipped[] = ['method' => $methodKey, 'reason' => $res['error'] ?? 'skip'];
        }
    }

    // Payout module enable request (separate table) stays in sync.
    if (function_exists('requestPayoutEnable')) {
        try {
            requestPayoutEnable($merchantId, $note);
        } catch (Throwable $e) { /* ok */ }
    }

    static $notified = [];
    if (!isset($notified[$merchantId]) && function_exists('createNotification') && $queued) {
        createNotification(
            $merchantId,
            'Methods sent for partner review',
            'UPI P2M is already ON. Other methods (cards, wallets, EMI, VA, NBFC, payout, instant settlement) are with admin → partner. You do not need to click Request.'
        );
        $notified[$merchantId] = true;
    }

    return [
        'ok' => true,
        'p2m' => true,
        'queued' => count($queued),
        'queued_methods' => $queued,
        'skipped' => $skipped,
    ];
}

/**
 * One-click for OLD merchants (signed up before auto-queue existed).
 * Runs bootstrapMerchantMethodAutomation for each active merchant (idempotent).
 */
function queueAllExistingMerchantsMethodAutomation(string $note = 'Auto-queued for existing merchant (admin one-click)', int $limit = 500): array
{
    ensureMethodRequestSchema();
    $limit = max(1, min(2000, $limit));
    try {
        $rows = getDB()->query(
            "SELECT id FROM merchants
             WHERE status='active'
               AND COALESCE(email,'') <> 'demo@uniweb.co.in'
             ORDER BY id ASC
             LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not load merchants.', 'merchants' => 0, 'queued_total' => 0];
    }

    $merchantCount = 0;
    $queuedTotal = 0;
    foreach ($rows as $mid) {
        $mid = (int)$mid;
        if ($mid < 1) {
            continue;
        }
        $boot = bootstrapMerchantMethodAutomation($mid, $note);
        $merchantCount++;
        $queuedTotal += (int)($boot['queued'] ?? 0);
    }

    return [
        'ok' => true,
        'merchants' => $merchantCount,
        'queued_total' => $queuedTotal,
        'message' => 'Processed ' . $merchantCount . ' merchant(s). New queue rows: ' . $queuedTotal
            . '. (Already-queued methods were skipped.)',
    ];
}

/**
 * After KYC is verified: ensure method queue exists, then auto-send pending
 * partner methods so admin does not click Send for that merchant.
 */
function afterKycVerifiedAutoSendMethods(int $merchantId, string $actor = 'kyc_verified_auto'): array
{
    ensureMethodRequestSchema();
    $boot = bootstrapMerchantMethodAutomation($merchantId, 'Auto-queued after KYC verified');
    $sent = sendAllPendingMethodRequestsToPartner($merchantId, $actor, 'Auto-sent after KYC verified');
    if (function_exists('createNotification')) {
        createNotification(
            $merchantId,
            'Methods sent to partner',
            'KYC verified. Your payment methods were forwarded to the partner for approval.'
        );
    }
    return [
        'ok' => !empty($sent['ok']),
        'queued' => (int)($boot['queued'] ?? 0),
        'sent' => (int)($sent['sent'] ?? 0),
        'message' => 'KYC auto-send: queued ' . (int)($boot['queued'] ?? 0)
            . ', sent/enabled ' . (int)($sent['sent'] ?? 0) . '.',
    ];
}

/** Admin: one click — send every pending partner method for this merchant (or all merchants). */
function sendAllPendingMethodRequestsToPartner(?int $merchantId, string $actor, string $adminNote = ''): array
{
    ensureMethodRequestSchema();
    $sql = 'SELECT id, method_key FROM merchant_method_requests WHERE status="pending"';
    $params = [];
    if ($merchantId !== null && $merchantId > 0) {
        $sql .= ' AND merchant_id=?';
        $params[] = $merchantId;
    }
    $sql .= ' ORDER BY id ASC LIMIT 500';
    $st = getDB()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    $sent = 0;
    $errors = [];
    foreach ($rows as $row) {
        if (!methodRequestNeedsPartner((string)$row['method_key'])) {
            $r = finalEnableMethodRequest((int)$row['id'], $actor, 'Direct method auto-enable');
            if (!empty($r['ok'])) {
                $sent++;
            }
            continue;
        }
        $r = sendMethodRequestToPartner((int)$row['id'], $actor, $adminNote !== '' ? $adminNote : 'Bulk send to partner');
        if (!empty($r['ok'])) {
            $sent++;
        } else {
            $errors[] = '#' . (int)$row['id'] . ': ' . ($r['error'] ?? 'fail');
        }
    }
    return [
        'ok' => $sent > 0 || empty($rows),
        'message' => 'Sent/enabled ' . $sent . ' of ' . count($rows) . ' request(s).'
            . ($errors ? ' Issues: ' . implode('; ', array_slice($errors, 0, 5)) : ''),
        'sent' => $sent,
        'total' => count($rows),
    ];
}

/**
 * Partner bank/PG reply (webhook or signed URL) updates request by partner_ref.
 * On approve → merchant method turns ON automatically.
 */
function applyPartnerMethodDecisionByRef(
    string $partnerRef,
    bool $approved,
    string $actor = 'partner_webhook',
    string $note = '',
    ?string $gateway = null
): array {
    ensureMethodRequestSchema();
    $partnerRef = trim($partnerRef);
    if ($partnerRef === '') {
        return ['ok' => false, 'error' => 'Missing partner reference.'];
    }
    $sql = 'SELECT id FROM merchant_method_requests WHERE partner_ref=?';
    $params = [$partnerRef];
    if ($gateway) {
        $sql .= ' AND partner_gateway=?';
        $params[] = $gateway;
    }
    $sql .= ' AND status="sent_to_partner" ORDER BY id DESC LIMIT 1';
    $st = getDB()->prepare($sql);
    $st->execute($params);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id < 1) {
        // Also accept UniWeb request id style: MMR-123 or bare numeric in notes.
        if (preg_match('/(?:MMR-)?(\d+)/i', $partnerRef, $m)) {
            $maybe = (int)$m[1];
            $chk = getMethodRequestById($maybe);
            if ($chk && (string)$chk['status'] === 'sent_to_partner') {
                $id = $maybe;
            }
        }
    }
    if ($id < 1) {
        return ['ok' => false, 'error' => 'No open partner request for this reference.'];
    }
    return recordMethodRequestPartnerDecision($id, $approved, $actor, $note !== '' ? $note : ($approved ? 'Partner webhook approved' : 'Partner webhook rejected'));
}

function verifyMethodPartnerWebhookSecret(string $provided): bool
{
    $secrets = array_values(array_unique(array_filter([
        trim((string)getSetting('method_partner_webhook_secret', '')),
        trim((string)(function_exists('getPartnerSetting') ? getPartnerSetting('razorpay', 'razorpay_webhook_secret', '') : '')),
        trim((string)(function_exists('getPartnerSetting') ? getPartnerSetting('payu', 'payu_merchant_salt', '') : '')),
        trim((string)(function_exists('cashfreeSecretKey') ? cashfreeSecretKey() : '')),
    ], static fn(string $s): bool => $s !== '')));
    if ($secrets === [] || $provided === '') {
        return false;
    }
    foreach ($secrets as $secret) {
        if (hash_equals($secret, $provided)) {
            return true;
        }
    }
    return false;
}

/**
 * When a partner approves a merchant, check if the merchant's agreement
 * includes this partner's name. If not, flag for re-sign and notify.
 */
function triggerAgreementResignCheck(int $merchantId, string $partnerGateway): void
{
    if ($partnerGateway === '') {
        return;
    }
    $partnerLabel = partnerDisplayName($partnerGateway);
    if ($partnerLabel === '') {
        return;
    }

    try {
        $db = getDB();
        $st = $db->prepare('SELECT * FROM merchant_agreement_acceptances WHERE merchant_id=? ORDER BY id DESC LIMIT 1');
        $st->execute([$merchantId]);
        $acceptance = $st->fetch();

        if (!$acceptance) {
            if (function_exists('createNotification')) {
                createNotification(
                    $merchantId,
                    'Agreement signature required',
                    'A partner (' . $partnerLabel . ') has approved your account. Please sign your Merchant Services Agreement to activate services.'
                );
            }
            return;
        }

        $currentPartners = trim((string)($acceptance['partner_names'] ?? ''));
        $partnerList = array_filter(array_map('trim', explode(',', $currentPartners)));
        if (in_array($partnerLabel, $partnerList, true)) {
            return;
        }

        $partnerList[] = $partnerLabel;
        $newPartnerNames = implode(', ', $partnerList);

        $db->prepare('UPDATE merchant_agreement_acceptances SET requires_resign=1, partner_names=? WHERE id=?')
            ->execute([$newPartnerNames, $acceptance['id']]);

        if (function_exists('createNotification')) {
            createNotification(
                $merchantId,
                'Agreement re-sign required',
                'A new partner (' . $partnerLabel . ') has been added to your account. Please re-sign your Merchant Services Agreement to include all active partners. Current partners: ' . $newPartnerNames
            );
        }

        if (function_exists('recordImmutableAudit')) {
            recordImmutableAudit(
                'agreement_resign_triggered',
                $merchantId,
                'agreement',
                (string)$acceptance['id'],
                'Re-sign triggered by partner approval: ' . $partnerLabel . '. Partners: ' . $newPartnerNames
            );
        }
    } catch (Throwable $e) {
        error_log('triggerAgreementResignCheck: ' . $e->getMessage());
    }
}

function partnerDisplayName(string $gateway): string
{
    return match (strtolower($gateway)) {
        'razorpay' => 'Razorpay',
        'cashfree' => 'Cashfree',
        'payu' => 'PayU',
        'decentro' => 'Decentro',
        'phonepe' => 'PhonePe',
        'axis' => 'Axis Bank',
        'icici' => 'ICICI Bank',
        'sbi' => 'State Bank of India',
        default => ucfirst($gateway),
    };
}

/**
 * Get all approved partner gateways for a merchant.
 */
function getMerchantApprovedPartners(int $merchantId): array
{
    ensureMethodRequestSchema();
    try {
        $st = getDB()->prepare('SELECT DISTINCT partner_gateway FROM merchant_method_requests WHERE merchant_id=? AND status="approved" AND partner_gateway IS NOT NULL AND partner_gateway != ""');
        $st->execute([$merchantId]);
        $gateways = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_map('partnerDisplayName', $gateways);
    } catch (Throwable $e) {
        return [];
    }
}
