<?php
declare(strict_types=1);

/**
 * Cases spine — support_ticket | customer_complaint | dispute (one Owner path).
 * Upgrade existing tables; partner inbound via case_partner_events.
 */

/** @return list<string> */
function casesValidTypes(): array
{
    return ['support_ticket', 'customer_complaint', 'dispute'];
}

/** @return list<string> */
function casesOpsValidTabs(): array
{
    return ['all', 'support', 'complaints', 'disputes'];
}

function casesOpsCurrentTab(): string
{
    $tab = strtolower(trim((string)($_GET['tab'] ?? 'all')));
    if ($tab === 'support' || $tab === 'complaints' || $tab === 'disputes') {
        return $tab;
    }
    return 'all';
}

/**
 * @param array<string, scalar|null> $query
 */
function casesOpsUrl(string $tab = 'all', array $query = []): string
{
    $tab = in_array($tab, casesOpsValidTabs(), true) ? $tab : 'all';
    if ($tab === 'complaints') {
        return casesOpsComplaintsPageUrl($query);
    }
    if ($tab === 'disputes') {
        return casesOpsDisputesPageUrl($query);
    }
    if ($tab === 'support') {
        $query['tab'] = 'support';
    } elseif ($tab === 'all') {
        unset($query['tab']);
    }
    $qs = http_build_query(array_filter($query, static fn($v) => $v !== null && $v !== ''));
    return 'admin_support.php' . ($qs !== '' ? ('?' . $qs) : '');
}

function casesOpsSupportPageUrl(array $query = []): string
{
    unset($query['tab']);
    $qs = http_build_query(array_filter($query, static fn($v) => $v !== null && $v !== ''));
    return 'admin_support.php' . ($qs !== '' ? ('?' . $qs) : '');
}

function casesOpsComplaintsPageUrl(array $query = []): string
{
    unset($query['tab']);
    $qs = http_build_query(array_filter($query, static fn($v) => $v !== null && $v !== ''));
    return 'admin_customer_tickets.php' . ($qs !== '' ? ('?' . $qs) : '');
}

function casesOpsDisputesPageUrl(array $query = []): string
{
    unset($query['tab']);
    $qs = http_build_query(array_filter($query, static fn($v) => $v !== null && $v !== ''));
    return 'admin_disputes.php' . ($qs !== '' ? ('?' . $qs) : '');
}

function casesOpsTabForPage(string $scriptName): string
{
    $base = strtolower(basename($scriptName));
    if ($base === 'admin_customer_tickets.php') {
        return 'complaints';
    }
    if ($base === 'admin_disputes.php') {
        return 'disputes';
    }
    if ($base === 'admin_support.php') {
        return casesOpsCurrentTab() === 'all' ? 'all' : 'support';
    }
    return 'all';
}

function ensureCasesSpineSchema(): void
{
    if (!function_exists('schemaExecQuiet')) {
        return;
    }
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS case_partner_events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        case_type ENUM('support_ticket','customer_complaint','dispute') NOT NULL,
        case_db_id INT UNSIGNED NOT NULL,
        case_ref VARCHAR(40) NOT NULL,
        merchant_id INT UNSIGNED DEFAULT NULL,
        partner_key VARCHAR(40) DEFAULT NULL,
        event_type ENUM('forwarded','not_wired','need_info','partner_query','partner_reply') NOT NULL,
        message TEXT NOT NULL,
        created_by_type ENUM('admin','merchant','partner_manual','system') NOT NULL DEFAULT 'admin',
        created_by_id INT UNSIGNED DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_case (case_type, case_db_id),
        KEY idx_merchant (merchant_id),
        KEY idx_ref (case_ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    schemaExecQuiet('ALTER TABLE support_tickets ADD COLUMN partner_forward_key VARCHAR(40) DEFAULT NULL AFTER status');
    schemaExecQuiet('ALTER TABLE support_tickets ADD COLUMN partner_forward_status VARCHAR(24) DEFAULT NULL AFTER partner_forward_key');
    schemaExecQuiet('ALTER TABLE support_tickets ADD COLUMN partner_forward_at DATETIME DEFAULT NULL AFTER partner_forward_status');
    schemaExecQuiet('ALTER TABLE customer_tickets ADD COLUMN partner_forward_key VARCHAR(40) DEFAULT NULL AFTER status');
    schemaExecQuiet('ALTER TABLE customer_tickets ADD COLUMN partner_forward_status VARCHAR(24) DEFAULT NULL AFTER partner_forward_key');
    schemaExecQuiet('ALTER TABLE customer_tickets ADD COLUMN partner_forward_at DATETIME DEFAULT NULL AFTER partner_forward_status');
}

function casesTypeLabel(string $type): string
{
    return match ($type) {
        'support_ticket' => 'Support ticket',
        'customer_complaint' => 'Customer complaint',
        'dispute' => 'Dispute',
        default => ucfirst(str_replace('_', ' ', $type)),
    };
}

function casesEventTypeLabel(string $eventType): string
{
    return match ($eventType) {
        'forwarded' => 'Forwarded to partner',
        'not_wired' => 'Partner API not wired',
        'need_info' => 'Partner needs more info',
        'partner_query' => 'Partner query',
        'partner_reply' => 'Partner reply',
        default => ucfirst(str_replace('_', ' ', $eventType)),
    };
}

/**
 * @return array{ok:bool,message:string,partner_key?:string,wired?:bool}
 */
function casesPartnerForwardWired(string $partnerKey, string $caseType): array
{
    $partnerKey = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($partnerKey))) ?? '';
    if ($partnerKey === '') {
        return ['ok' => false, 'message' => 'Partner key required.', 'wired' => false];
    }
    if ($caseType === 'dispute') {
        return ['ok' => true, 'message' => 'Dispute forward recorded (manual V1 — live PG API may still be pending).', 'partner_key' => $partnerKey, 'wired' => false];
    }
    if (!function_exists('partnerIsConfigured')) {
        if (is_file(__DIR__ . '/partner_control.php')) {
            require_once __DIR__ . '/partner_control.php';
        }
    }
    $hasKeys = function_exists('partnerIsConfigured') && partnerIsConfigured($partnerKey);
    if (!$hasKeys) {
        return ['ok' => true, 'message' => 'Recorded — partner keys missing; not sent to bank API yet.', 'partner_key' => $partnerKey, 'wired' => false];
    }
    return ['ok' => true, 'message' => 'Recorded — partner keys present; live case API may still be not wired.', 'partner_key' => $partnerKey, 'wired' => false];
}

/**
 * @return array<int,array<string,mixed>>
 */
function casesGetPartnerEvents(string $caseType, int $caseDbId, int $limit = 50): array
{
    ensureCasesSpineSchema();
    if (!in_array($caseType, casesValidTypes(), true) || $caseDbId < 1) {
        return [];
    }
    try {
        $st = getDB()->prepare(
            'SELECT * FROM case_partner_events WHERE case_type=? AND case_db_id=? ORDER BY created_at DESC LIMIT ' . max(1, min(100, $limit))
        );
        $st->execute([$caseType, $caseDbId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function casesLogPartnerEvent(
    string $caseType,
    int $caseDbId,
    string $caseRef,
    string $eventType,
    string $message,
    ?string $partnerKey = null,
    string $createdByType = 'admin',
    ?int $merchantId = null
): bool {
    ensureCasesSpineSchema();
    if (!in_array($caseType, casesValidTypes(), true) || $caseDbId < 1) {
        return false;
    }
    $allowed = ['forwarded', 'not_wired', 'need_info', 'partner_query', 'partner_reply'];
    if (!in_array($eventType, $allowed, true)) {
        return false;
    }
    $message = trim($message);
    if ($message === '') {
        return false;
    }
    $partnerKey = $partnerKey !== null ? (preg_replace('/[^a-z0-9_]/', '', strtolower(trim($partnerKey))) ?? '') : null;
    if ($partnerKey === '') {
        $partnerKey = null;
    }
    try {
        $db = getDB();
        $db->prepare(
            'INSERT INTO case_partner_events (case_type, case_db_id, case_ref, merchant_id, partner_key, event_type, message, created_by_type, created_by_id)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $caseType,
            $caseDbId,
            $caseRef,
            $merchantId,
            $partnerKey,
            $eventType,
            mb_substr($message, 0, 5000),
            in_array($createdByType, ['admin', 'merchant', 'partner_manual', 'system'], true) ? $createdByType : 'admin',
            (int)($_SESSION['admin_id'] ?? $_SESSION['merchant_id'] ?? 0),
        ]);
        if ($eventType === 'need_info' || $eventType === 'partner_query') {
            casesSetPartnerInboundStatus($caseType, $caseDbId, $eventType);
        }
        if ($merchantId !== null && $merchantId > 0 && function_exists('createNotification')) {
            $title = $eventType === 'need_info' ? 'Partner needs more info' : 'Partner message on case';
            createNotification($merchantId, $title, $caseRef . ': ' . mb_substr($message, 0, 200), 'case_' . $caseType . '_' . $caseDbId);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function casesSetPartnerInboundStatus(string $caseType, int $caseDbId, string $status): void
{
    $status = in_array($status, ['need_info', 'partner_query', 'forwarded', 'not_wired'], true) ? $status : 'need_info';
    try {
        if ($caseType === 'support_ticket') {
            getDB()->prepare('UPDATE support_tickets SET partner_forward_status=? WHERE id=?')->execute([$status, $caseDbId]);
        } elseif ($caseType === 'customer_complaint') {
            getDB()->prepare('UPDATE customer_tickets SET partner_forward_status=? WHERE id=?')->execute([$status, $caseDbId]);
        }
    } catch (Throwable $e) {
        /* ok */
    }
}

/**
 * @return array{ok:bool,message:string}
 */
function casesResolvePartnerKeyForCase(string $caseType, int $caseDbId): array
{
    $db = getDB();
    try {
        if ($caseType === 'dispute') {
            $st = $db->prepare(
                'SELECT COALESCE(d.partner_key, t.partner_key) AS pk FROM disputes d JOIN transactions t ON t.id=d.transaction_id WHERE d.id=? LIMIT 1'
            );
            $st->execute([$caseDbId]);
            $pk = trim((string)($st->fetchColumn() ?: ''));
            return $pk !== '' ? ['ok' => true, 'message' => $pk, 'partner_key' => $pk] : ['ok' => false, 'message' => 'No partner on linked transaction.'];
        }
        if ($caseType === 'customer_complaint') {
            $st = $db->prepare('SELECT txn_reference, merchant_id FROM customer_tickets WHERE id=? LIMIT 1');
            $st->execute([$caseDbId]);
            $row = $st->fetch();
            if (!$row || empty($row['txn_reference'])) {
                return ['ok' => false, 'message' => 'Complaint has no transaction — partner forward not applicable.'];
            }
            $tst = $db->prepare('SELECT partner_key FROM transactions WHERE txn_id=? LIMIT 1');
            $tst->execute([(string)$row['txn_reference']]);
            $pk = trim((string)($tst->fetchColumn() ?: ''));
            return $pk !== '' ? ['ok' => true, 'message' => $pk, 'partner_key' => $pk] : ['ok' => false, 'message' => 'Transaction has no partner_key.'];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Could not resolve partner.'];
    }
    return ['ok' => false, 'message' => 'Support tickets stay UniWeb-only unless Admin picks a partner.'];
}

/**
 * @return array{ok:bool,message:string}
 */
function casesForwardCase(string $caseType, int $caseDbId, string $partnerKey, string $note): array
{
    if (!in_array($caseType, casesValidTypes(), true) || $caseDbId < 1) {
        return ['ok' => false, 'message' => 'Invalid case.'];
    }
    $note = trim($note);
    if ($note === '') {
        $note = 'Forwarded by Admin for partner review';
    }
    if ($caseType === 'dispute') {
        if (!function_exists('forwardDisputeToPartner')) {
            require_once __DIR__ . '/schema_ensure.php';
        }
        $res = forwardDisputeToPartner($caseDbId, $partnerKey, $note, (int)($_SESSION['admin_id'] ?? 0));
        if (!empty($res['ok'])) {
            $ref = casesCaseRef($caseType, $caseDbId);
            $mid = casesCaseMerchantId($caseType, $caseDbId);
            casesLogPartnerEvent($caseType, $caseDbId, $ref, 'forwarded', $note, $partnerKey, 'admin', $mid);
        }
        return $res;
    }

    $partnerKey = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($partnerKey))) ?? '';
    if ($partnerKey === '' && $caseType === 'customer_complaint') {
        $resolved = casesResolvePartnerKeyForCase($caseType, $caseDbId);
        if (!empty($resolved['ok']) && !empty($resolved['partner_key'])) {
            $partnerKey = (string)$resolved['partner_key'];
        }
    }
    if ($partnerKey === '') {
        return ['ok' => false, 'message' => 'Select a partner to forward this case.'];
    }

    $wired = casesPartnerForwardWired($partnerKey, $caseType);
    $eventType = !empty($wired['wired']) ? 'forwarded' : 'not_wired';
    $ref = casesCaseRef($caseType, $caseDbId);
    $mid = casesCaseMerchantId($caseType, $caseDbId);
    if ($ref === '') {
        return ['ok' => false, 'message' => 'Case not found.'];
    }

    try {
        $db = getDB();
        if ($caseType === 'support_ticket') {
            $db->prepare("UPDATE support_tickets SET partner_forward_key=?, partner_forward_status=?, partner_forward_at=NOW(), status='in_progress' WHERE id=? AND status NOT IN ('closed')")
                ->execute([$partnerKey, $eventType, $caseDbId]);
        } else {
            $db->prepare("UPDATE customer_tickets SET partner_forward_key=?, partner_forward_status=?, partner_forward_at=NOW(), status='in_progress', updated_at=NOW() WHERE id=? AND status NOT IN ('closed')")
                ->execute([$partnerKey, $eventType, $caseDbId]);
        }
        casesLogPartnerEvent($caseType, $caseDbId, $ref, $eventType, $note . ' — ' . (string)$wired['message'], $partnerKey, 'admin', $mid);
        if (function_exists('logStaffActivity')) {
            logStaffActivity('case_forward_partner', $ref . ' → ' . $partnerKey, $mid, $caseType, $ref);
        }
        return ['ok' => true, 'message' => (string)$wired['message']];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Could not forward case.'];
    }
}

/**
 * @return array{ok:bool,message:string}
 */
function casesCloseCase(string $caseType, int $caseDbId, string $note = ''): array
{
    if (!in_array($caseType, casesValidTypes(), true) || $caseDbId < 1) {
        return ['ok' => false, 'message' => 'Invalid case.'];
    }
    $note = trim($note) !== '' ? trim($note) : 'Closed by Admin';
    try {
        $db = getDB();
        if ($caseType === 'support_ticket') {
            $db->prepare("UPDATE support_tickets SET status='closed' WHERE id=?")->execute([$caseDbId]);
        } elseif ($caseType === 'customer_complaint') {
            $db->prepare("UPDATE customer_tickets SET status='closed', updated_at=NOW() WHERE id=?")->execute([$caseDbId]);
        } else {
            $db->prepare("UPDATE disputes SET status='closed', resolution=? WHERE id=?")->execute([$note, $caseDbId]);
        }
        $ref = casesCaseRef($caseType, $caseDbId);
        $mid = casesCaseMerchantId($caseType, $caseDbId);
        if (function_exists('logStaffActivity') && $ref !== '') {
            logStaffActivity('case_closed', $ref . ': ' . mb_substr($note, 0, 120), $mid, $caseType, $ref);
        }
        if ($mid > 0 && function_exists('createNotification')) {
            createNotification($mid, 'Case closed', $ref . ': ' . mb_substr($note, 0, 200), 'case_' . $caseType . '_' . $caseDbId);
        }
        return ['ok' => true, 'message' => 'Case closed.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Could not close case.'];
    }
}

function casesCaseRef(string $caseType, int $caseDbId): string
{
    try {
        $db = getDB();
        if ($caseType === 'support_ticket') {
            $st = $db->prepare('SELECT ticket_id FROM support_tickets WHERE id=? LIMIT 1');
        } elseif ($caseType === 'customer_complaint') {
            $st = $db->prepare('SELECT ticket_id FROM customer_tickets WHERE id=? LIMIT 1');
        } else {
            $st = $db->prepare('SELECT dispute_id FROM disputes WHERE id=? LIMIT 1');
        }
        $st->execute([$caseDbId]);
        return trim((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

function casesCaseMerchantId(string $caseType, int $caseDbId): int
{
    try {
        $db = getDB();
        if ($caseType === 'support_ticket') {
            $st = $db->prepare('SELECT merchant_id FROM support_tickets WHERE id=? LIMIT 1');
        } elseif ($caseType === 'customer_complaint') {
            $st = $db->prepare('SELECT merchant_id FROM customer_tickets WHERE id=? LIMIT 1');
        } else {
            $st = $db->prepare('SELECT merchant_id FROM disputes WHERE id=? LIMIT 1');
        }
        $st->execute([$caseDbId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @param list<array{case_type:string,case_id:int}> $items
 * @return array{ok:bool,message:string,closed:int}
 */
function casesBulkClose(array $items, string $note = ''): array
{
    $closed = 0;
    foreach ($items as $item) {
        $type = (string)($item['case_type'] ?? '');
        $id = (int)($item['case_id'] ?? 0);
        if ($id < 1 || !in_array($type, casesValidTypes(), true)) {
            continue;
        }
        if (function_exists('requireMerchantAccess')) {
            $mid = casesCaseMerchantId($type, $id);
            if ($mid > 0) {
                requireMerchantAccess($mid);
            }
        }
        $res = casesCloseCase($type, $id, $note);
        if (!empty($res['ok'])) {
            $closed++;
        }
    }
    return ['ok' => $closed > 0, 'message' => $closed . ' case(s) closed.', 'closed' => $closed];
}

/**
 * @param list<array{case_type:string,case_id:int}> $items
 * @return array{ok:bool,message:string,forwarded:int}
 */
function casesBulkForward(array $items, string $partnerKey, string $note): array
{
    $forwarded = 0;
    $errors = [];
    foreach ($items as $item) {
        $type = (string)($item['case_type'] ?? '');
        $id = (int)($item['case_id'] ?? 0);
        if ($id < 1 || !in_array($type, casesValidTypes(), true)) {
            continue;
        }
        if ($type === 'support_ticket') {
            $errors[] = 'Support tickets skipped in bulk — forward individually if needed.';
            continue;
        }
        if (function_exists('requireMerchantAccess')) {
            $mid = casesCaseMerchantId($type, $id);
            if ($mid > 0) {
                requireMerchantAccess($mid);
            }
        }
        $pk = $partnerKey;
        if ($pk === '' && $type !== 'support_ticket') {
            $resolved = casesResolvePartnerKeyForCase($type, $id);
            $pk = (string)($resolved['partner_key'] ?? '');
        }
        $res = casesForwardCase($type, $id, $pk, $note);
        if (!empty($res['ok'])) {
            $forwarded++;
        } else {
            $errors[] = $res['message'];
        }
    }
    $msg = $forwarded . ' case(s) forwarded.';
    if ($errors !== []) {
        $msg .= ' ' . implode(' ', array_slice(array_unique($errors), 0, 3));
    }
    return ['ok' => $forwarded > 0, 'message' => $msg, 'forwarded' => $forwarded];
}

/**
 * @return array{support:int,complaints:int,disputes:int,all_open:int}
 */
function casesOpsTabCounts(): array
{
    $counts = ['support' => 0, 'complaints' => 0, 'disputes' => 0, 'all_open' => 0];
    try {
        $db = getDB();
        $counts['support'] = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')")->fetchColumn();
        $counts['complaints'] = (int)$db->query("SELECT COUNT(*) FROM customer_tickets WHERE status IN ('open','in_progress')")->fetchColumn();
        $counts['disputes'] = (int)$db->query("SELECT COUNT(*) FROM disputes WHERE status IN ('open','under_review','forwarded_partner')")->fetchColumn();
        $counts['all_open'] = $counts['support'] + $counts['complaints'] + $counts['disputes'];
    } catch (Throwable $e) {
        /* ok */
    }
    return $counts;
}

/**
 * Unified inbox rows for Admin tab=all.
 *
 * @return list<array<string,mixed>>
 */
function casesUnifiedInbox(?string $typeFilter = null, ?string $statusFilter = null, string $q = '', int $merchantId = 0, int $limit = 80): array
{
    ensureCasesSpineSchema();
    if (!function_exists('ensureSupportTicketTable')) {
        require_once __DIR__ . '/demo_tour.php';
    }
    if (!function_exists('ensureCustomerPortalSchema')) {
        require_once __DIR__ . '/customer_portal.php';
    }
    if (!function_exists('ensureDisputesEngine')) {
        require_once __DIR__ . '/schema_ensure.php';
    }
    ensureSupportTicketTable();
    ensureCustomerPortalSchema();
    ensureDisputesEngine();

    $rows = [];
    $db = getDB();
    $qLower = strtolower(trim($q));

    if ($typeFilter === null || $typeFilter === '' || $typeFilter === 'support_ticket') {
        $sql = 'SELECT t.id, t.ticket_id AS case_ref, t.merchant_id, t.subject, t.status, t.priority, t.created_at, t.updated_at, t.partner_forward_status, m.business_name
                FROM support_tickets t JOIN merchants m ON m.id=t.merchant_id';
        $params = [];
        $where = [];
        if ($merchantId > 0) {
            $where[] = 't.merchant_id=?';
            $params[] = $merchantId;
        }
        if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
            $where[] = 't.status=?';
            $params[] = $statusFilter;
        } else {
            $where[] = "t.status IN ('open','in_progress')";
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.created_at DESC LIMIT 40';
        $st = $db->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() ?: [] as $r) {
            $rows[] = [
                'case_type' => 'support_ticket',
                'case_db_id' => (int)$r['id'],
                'case_ref' => (string)$r['case_ref'],
                'merchant_id' => (int)$r['merchant_id'],
                'business_name' => (string)$r['business_name'],
                'subject' => (string)$r['subject'],
                'status' => (string)$r['status'],
                'partner_status' => (string)($r['partner_forward_status'] ?? ''),
                'sort_at' => (string)($r['updated_at'] ?? $r['created_at']),
                'detail_url' => 'admin_support.php?q=' . rawurlencode((string)$r['case_ref']),
            ];
        }
    }

    if ($typeFilter === null || $typeFilter === '' || $typeFilter === 'customer_complaint') {
        $sql = 'SELECT t.id, t.ticket_id AS case_ref, t.merchant_id, t.subject, t.status, t.created_at, t.updated_at, t.partner_forward_status, t.txn_reference, m.business_name
                FROM customer_tickets t LEFT JOIN merchants m ON m.id=t.merchant_id';
        $params = [];
        $where = [];
        if ($merchantId > 0) {
            $where[] = 't.merchant_id=?';
            $params[] = $merchantId;
        }
        if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
            $where[] = 't.status=?';
            $params[] = $statusFilter;
        } else {
            $where[] = "t.status IN ('open','in_progress')";
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.updated_at DESC LIMIT 40';
        $st = $db->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() ?: [] as $r) {
            $rows[] = [
                'case_type' => 'customer_complaint',
                'case_db_id' => (int)$r['id'],
                'case_ref' => (string)$r['case_ref'],
                'merchant_id' => (int)($r['merchant_id'] ?? 0),
                'business_name' => (string)($r['business_name'] ?? '—'),
                'subject' => (string)$r['subject'],
                'status' => (string)$r['status'],
                'partner_status' => (string)($r['partner_forward_status'] ?? ''),
                'sort_at' => (string)($r['updated_at'] ?? $r['created_at']),
                'detail_url' => 'admin_customer_tickets.php?id=' . (int)$r['id'],
            ];
        }
    }

    if ($typeFilter === null || $typeFilter === '' || $typeFilter === 'dispute') {
        $sql = 'SELECT d.id, d.dispute_id AS case_ref, d.merchant_id, d.reason AS subject, d.status, d.created_at, d.forwarded_partner_key, m.business_name, t.txn_id
                FROM disputes d JOIN merchants m ON m.id=d.merchant_id JOIN transactions t ON t.id=d.transaction_id';
        $params = [];
        $where = [];
        if ($merchantId > 0) {
            $where[] = 'd.merchant_id=?';
            $params[] = $merchantId;
        }
        if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
            if ($statusFilter === 'open') {
                $where[] = "d.status IN ('open','under_review','forwarded_partner')";
            } else {
                $where[] = 'd.status=?';
                $params[] = $statusFilter;
            }
        } else {
            $where[] = "d.status IN ('open','under_review','forwarded_partner')";
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY d.created_at DESC LIMIT 40';
        $st = $db->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() ?: [] as $r) {
            $rows[] = [
                'case_type' => 'dispute',
                'case_db_id' => (int)$r['id'],
                'case_ref' => (string)$r['case_ref'],
                'merchant_id' => (int)$r['merchant_id'],
                'business_name' => (string)$r['business_name'],
                'subject' => (string)$r['subject'],
                'status' => (string)$r['status'],
                'partner_status' => (string)($r['forwarded_partner_key'] ?? ''),
                'sort_at' => (string)$r['created_at'],
                'detail_url' => 'admin_disputes.php?q=' . rawurlencode((string)$r['case_ref']),
            ];
        }
    }

    if ($qLower !== '') {
        $rows = array_values(array_filter($rows, static function (array $row) use ($qLower): bool {
            $hay = strtolower(
                ($row['case_ref'] ?? '') . ' ' .
                ($row['subject'] ?? '') . ' ' .
                ($row['business_name'] ?? '') . ' ' .
                ($row['status'] ?? '')
            );
            return str_contains($hay, $qLower);
        }));
    }

    if (!function_exists('isSuperAdmin') || !isSuperAdmin()) {
        $rows = array_values(array_filter($rows, static fn(array $row): bool => empty($row['merchant_id']) || staffHasMerchantAccess((int)$row['merchant_id'])));
    }

    usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['sort_at'] ?? ''), (string)($a['sort_at'] ?? '')));
    return array_slice($rows, 0, max(1, min(100, $limit)));
}

/**
 * Handle bulk / forward / close / partner_log POST from Cases hub pages.
 */
function casesOpsHandlePost(string $returnPage): ?string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? '')) {
        return null;
    }
    $action = (string)($_POST['cases_action'] ?? $_POST['action'] ?? '');
    $allowed = ['bulk_close', 'bulk_forward', 'close_case', 'forward_case', 'partner_log'];
    if (!in_array($action, $allowed, true)) {
        return null;
    }

    $note = trim((string)($_POST['note'] ?? $_POST['resolution'] ?? ''));
    $partnerKey = (string)($_POST['partner_key'] ?? '');

    if ($action === 'bulk_close' || $action === 'bulk_forward') {
        $selected = $_POST['selected'] ?? [];
        if (!is_array($selected) || count($selected) < 1) {
            flash('error', 'Select at least one case.');
            return $returnPage;
        }
        $items = [];
        foreach ($selected as $raw) {
            if (!is_string($raw) || !str_contains($raw, ':')) {
                continue;
            }
            [$type, $id] = explode(':', $raw, 2);
            $items[] = ['case_type' => $type, 'case_id' => (int)$id];
        }
        if ($action === 'bulk_close') {
            $res = casesBulkClose($items, $note);
        } else {
            $res = casesBulkForward($items, $partnerKey, $note);
        }
        flash(!empty($res['ok']) ? 'success' : 'error', (string)$res['message']);
        return $returnPage;
    }

    $caseType = (string)($_POST['case_type'] ?? '');
    $caseId = (int)($_POST['case_id'] ?? $_POST['id'] ?? 0);
    if ($caseId < 1 || !in_array($caseType, casesValidTypes(), true)) {
        flash('error', 'Invalid case.');
        return $returnPage;
    }
    $mid = casesCaseMerchantId($caseType, $caseId);
    if ($mid > 0 && function_exists('requireMerchantAccess')) {
        requireMerchantAccess($mid);
    }

    if ($action === 'close_case') {
        $res = casesCloseCase($caseType, $caseId, $note);
        flash(!empty($res['ok']) ? 'success' : 'error', (string)$res['message']);
    } elseif ($action === 'forward_case') {
        $res = casesForwardCase($caseType, $caseId, $partnerKey, $note);
        flash(!empty($res['ok']) ? 'success' : 'error', (string)$res['message']);
    } elseif ($action === 'partner_log') {
        $eventType = (string)($_POST['event_type'] ?? 'partner_query');
        if (!in_array($eventType, ['need_info', 'partner_query', 'partner_reply'], true)) {
            $eventType = 'partner_query';
        }
        $ref = casesCaseRef($caseType, $caseId);
        $ok = casesLogPartnerEvent($caseType, $caseId, $ref, $eventType, $note, $partnerKey !== '' ? $partnerKey : null, 'admin', $mid);
        flash($ok ? 'success' : 'error', $ok ? 'Partner message logged — merchant will see it.' : 'Could not log partner message.');
    }
    return $returnPage;
}

function renderCasesOpsTabs(string $activeTab): string
{
    $activeTab = in_array($activeTab, casesOpsValidTabs(), true) ? $activeTab : 'all';
    $counts = casesOpsTabCounts();
    $tabs = [
        'all' => 'All cases',
        'support' => 'Support tickets',
        'complaints' => 'Customer complaints',
        'disputes' => 'Disputes',
    ];
    $html = '<nav class="mb-6 flex flex-wrap gap-2 border-b border-gray-800 pb-3" aria-label="Cases sections">';
    foreach ($tabs as $key => $label) {
        $active = $key === $activeTab;
        $cls = $active
            ? 'bg-brand-600 text-white border-brand-500'
            : 'bg-dark-800 text-gray-400 border-gray-700 hover:text-white hover:border-gray-600';
        $badge = '';
        $n = $key === 'all' ? (int)$counts['all_open'] : (int)($counts[$key] ?? 0);
        if ($n > 0 && !$active) {
            $badge = ' <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full bg-amber-500/30 text-amber-200">' . $n . '</span>';
        }
        $href = $key === 'all' ? casesOpsUrl('all') : casesOpsUrl($key);
        $html .= '<a href="' . e($href) . '" class="px-4 py-2 rounded-lg border text-sm font-medium whitespace-nowrap ' . $cls . '">' . e($label) . $badge . '</a>';
    }
    $html .= '</nav>';
    return $html;
}

/**
 * Partner inbound panel for admin case detail.
 */
function renderMerchantCasesTabs(string $active): string
{
    $tabs = [
        'complaints' => ['merchant_customer_tickets.php', 'Customer complaints'],
        'support' => ['support.php', 'My support tickets'],
        'disputes' => ['disputes.php', 'Disputes'],
    ];
    $html = '<nav class="mb-6 flex flex-wrap gap-2 border-b border-gray-800 pb-3" aria-label="Merchant cases">';
    foreach ($tabs as $key => $meta) {
        $activeCls = $key === $active ? 'bg-brand-600 text-white border-brand-500' : 'bg-dark-800 text-gray-400 border-gray-700 hover:text-white';
        $html .= '<a href="' . e($meta[0]) . '" class="px-4 py-2 rounded-lg border text-sm font-medium ' . $activeCls . '">' . e($meta[1]) . '</a>';
    }
    $html .= '</nav>';
    return $html;
}

{
    $events = casesGetPartnerEvents($caseType, $caseDbId);
    $html = '<div class="mt-4 rounded-lg border border-violet-500/25 bg-violet-500/5 p-4">';
    $html .= '<p class="text-sm font-semibold text-violet-300 mb-2">Partner messages (inbound)</p>';
    if ($events === []) {
        $html .= '<p class="text-xs text-gray-500 mb-2">No partner queries yet. Log manually if partner emailed — webhook not wired.</p>';
    } else {
        $html .= '<ul class="space-y-2 text-xs text-gray-300 mb-3">';
        foreach ($events as $ev) {
            $html .= '<li><span class="text-gray-500">' . e((string)($ev['created_at'] ?? '')) . '</span> · <strong>' . e(casesEventTypeLabel((string)$ev['event_type'])) . '</strong>';
            if (!empty($ev['partner_key'])) {
                $html .= ' · ' . e((string)$ev['partner_key']);
            }
            $html .= '<br>' . e((string)$ev['message']) . '</li>';
        }
        $html .= '</ul>';
    }
    if ($allowLog) {
        $html .= '<form method="POST" class="space-y-2 text-xs">';
        $html .= '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
        $html .= '<input type="hidden" name="cases_action" value="partner_log">';
        $html .= '<input type="hidden" name="case_type" value="' . e($caseType) . '">';
        $html .= '<input type="hidden" name="case_id" value="' . (int)$caseDbId . '">';
        $html .= '<label class="text-gray-500">Log partner request (manual)</label>';
        $html .= '<select name="event_type" class="input-field text-sm"><option value="need_info">Need more docs</option><option value="partner_query">Partner query</option><option value="partner_reply">Partner reply</option></select>';
        $html .= '<input type="text" name="partner_key" placeholder="Partner key (optional)" class="input-field text-sm">';
        $html .= '<textarea name="note" required rows="2" placeholder="What did the partner ask?" class="input-field text-sm"></textarea>';
        $html .= '<button type="submit" class="btn-primary px-3 py-1.5 text-sm">Log for merchant</button>';
        $html .= '</form>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Merchant-visible partner events block.
 */
function renderMerchantCasesPartnerEvents(string $caseType, int $caseDbId): string
{
    $events = array_values(array_filter(
        casesGetPartnerEvents($caseType, $caseDbId),
        static fn(array $ev): bool => in_array((string)($ev['event_type'] ?? ''), ['need_info', 'partner_query', 'partner_reply', 'forwarded', 'not_wired'], true)
    ));
    if ($events === []) {
        return '';
    }
    $html = '<div class="mt-4 rounded-lg border border-amber-500/30 bg-amber-500/5 p-4">';
    $html .= '<p class="text-sm font-semibold text-amber-300 mb-2">Partner / bank messages</p><ul class="space-y-2 text-xs text-gray-300">';
    foreach ($events as $ev) {
        $html .= '<li><span class="text-gray-500">' . e((string)($ev['created_at'] ?? '')) . '</span> · ' . e(casesEventTypeLabel((string)$ev['event_type']));
        if (!empty($ev['partner_key'])) {
            $html .= ' (' . e((string)$ev['partner_key']) . ')';
        }
        $html .= '<br>' . e((string)$ev['message']) . '</li>';
    }
    $html .= '</ul><p class="text-[10px] text-gray-500 mt-2">Reply on this case or upload docs if requested.</p></div>';
    return $html;
}

/** @return list<string> */
function casesOpsPartnerChoices(): array
{
    $choices = [];
    if (function_exists('getPartnerRegistry')) {
        foreach (getPartnerRegistry() as $pk => $meta) {
            $choices[(string)$pk] = (string)($meta['name'] ?? $pk);
        }
    }
    if ($choices === []) {
        return ['payu' => 'PayU', 'razorpay' => 'Razorpay', 'cashfree' => 'Cashfree'];
    }
    return $choices;
}
