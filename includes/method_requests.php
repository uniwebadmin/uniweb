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
    $allowed = function_exists('gatewaySubmissionAllowedGateways')
        ? gatewaySubmissionAllowedGateways()
        : ['razorpay', 'cashfree', 'payu', 'decentro', 'phonepe', 'axis'];
    return in_array($gw, $allowed, true) ? $gw : 'payu';
}

function merchantEntitledMethods(array $merchant): array
{
    $profile = getMerchantProvisionProfile($merchant);
    $methods = $profile['methods'] ?? [];
    $methods = array_merge($methods, getMerchantEnabledMethods($merchant));
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
        'partner_approved' => 'Partner approved — awaiting final enable',
        'partner_rejected' => 'Partner rejected',
        'approved' => 'Enabled',
        'rejected' => 'Rejected by admin',
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
    } catch (Throwable $e) {
        error_log('unlockMerchantMethod: ' . $e->getMessage());
    }
}
