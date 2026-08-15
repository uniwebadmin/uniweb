<?php
declare(strict_types=1);

/**
 * Customer (payer) portal — passwordless WhatsApp/SMS OTP login, read-only
 * transaction history by mobile number (across merchants), and grievance tickets
 * visible to merchant / admin / staff with reply fan-out.
 *
 * Scope: pay + support only. Not a PPI / stored-value consumer wallet.
 */

require_once __DIR__ . '/notify.php';

function customerPortalScopeCopy(): string
{
    return 'This portal is for payments and complaints only. It is not a PPI or stored-value wallet.';
}

function ensureCustomerPortalSchema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS customer_otps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            phone VARCHAR(20) NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            consumed TINYINT(1) NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_phone_created (phone, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS customer_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id VARCHAR(30) NOT NULL UNIQUE,
            customer_phone VARCHAR(20) NOT NULL,
            customer_name VARCHAR(160) DEFAULT NULL,
            merchant_id INT DEFAULT NULL,
            category VARCHAR(40) NOT NULL DEFAULT 'grievance',
            subject VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            txn_reference VARCHAR(60) DEFAULT NULL,
            priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
            status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customer_phone (customer_phone),
            INDEX idx_status (status),
            INDEX idx_merchant (merchant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS customer_ticket_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            sender_type ENUM('customer','admin','merchant','staff') NOT NULL,
            sender_label VARCHAR(120) DEFAULT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ticket (ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }

    // Graceful upgrades for older installs.
    if (function_exists('schemaExecQuiet')) {
        schemaExecQuiet("ALTER TABLE customer_tickets ADD INDEX idx_merchant (merchant_id)");
        schemaExecQuiet("ALTER TABLE customer_ticket_messages MODIFY sender_type ENUM('customer','admin','merchant','staff') NOT NULL");
        schemaExecQuiet("ALTER TABLE customer_ticket_messages ADD COLUMN sender_label VARCHAR(120) DEFAULT NULL");
    } else {
        try { $db->exec("ALTER TABLE customer_ticket_messages MODIFY sender_type ENUM('customer','admin','merchant','staff') NOT NULL"); } catch (Throwable $e) { /* ok */ }
        try { $db->exec("ALTER TABLE customer_ticket_messages ADD COLUMN sender_label VARCHAR(120) DEFAULT NULL"); } catch (Throwable $e) { /* ok */ }
    }
}

/** Reduce any Indian phone input to its 10-digit subscriber number, or '' if invalid. */
function customerNormalizePhone(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw) ?? '';
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }
    return (strlen($digits) === 10 && $digits[0] >= '6') ? $digits : '';
}

function isCustomerLoggedIn(): bool
{
    return !empty($_SESSION['customer_phone']);
}

function currentCustomerPhone(): string
{
    return (string)($_SESSION['customer_phone'] ?? '');
}

function requireCustomer(): void
{
    if (!isCustomerLoggedIn()) {
        flash('error', 'Please log in with your mobile number to continue.');
        redirect('customer_login.php');
    }
}

function customerLogout(): void
{
    unset($_SESSION['customer_phone'], $_SESSION['customer_login_at']);
}

/**
 * Generate + deliver a login OTP. Falls back WhatsApp -> SMS. When no channel
 * is configured (e.g. demo without keys), returns the OTP in 'demo_otp'.
 * @return array{ok:bool,channel:string,demo_otp:?string,message:string}
 */
function requestCustomerOtp(string $phone): array
{
    ensureCustomerPortalSchema();
    $db = getDB();

    $recent = 0;
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM customer_otps WHERE phone=? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $st->execute([$phone]);
        $recent = (int)$st->fetchColumn();
    } catch (Throwable $e) { /* ok */ }
    if ($recent >= 5) {
        return ['ok' => false, 'channel' => 'rate_limited', 'demo_otp' => null, 'message' => 'Too many OTP requests. Please try again in an hour.'];
    }

    $otp = (string)random_int(100000, 999999);
    $hash = password_hash($otp, PASSWORD_ARGON2ID);
    try {
        $db->prepare("INSERT INTO customer_otps (phone, otp_hash, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
            ->execute([$phone, $hash]);
    } catch (Throwable $e) {
        return ['ok' => false, 'channel' => 'error', 'demo_otp' => null, 'message' => 'Could not generate OTP. Please try again.'];
    }

    $channel = 'none';
    if (function_exists('sendWhatsAppOtp')) {
        $wa = sendWhatsAppOtp($phone, $otp);
        if (!empty($wa['ok'])) {
            $channel = 'whatsapp';
        }
    }
    if ($channel === 'none' && function_exists('sendSMS')) {
        if (sendSMS($phone, "Your UniWeb OTP is {$otp}. Valid 10 minutes. Do not share.")) {
            $channel = 'sms';
        }
    }

    if ($channel !== 'none') {
        return ['ok' => true, 'channel' => $channel, 'demo_otp' => null, 'message' => 'OTP sent to your mobile via ' . strtoupper($channel) . '.'];
    }
    return ['ok' => true, 'channel' => 'demo', 'demo_otp' => $otp, 'message' => 'Demo mode: SMS/WhatsApp not configured. Use the OTP shown below.'];
}

/** @return array{ok:bool,message:string} */
function verifyCustomerOtp(string $phone, string $otp): array
{
    ensureCustomerPortalSchema();
    $db = getDB();
    $otp = preg_replace('/\D/', '', $otp) ?? '';
    try {
        $st = $db->prepare("SELECT * FROM customer_otps WHERE phone=? AND consumed=0 AND expires_at >= NOW() ORDER BY id DESC LIMIT 1");
        $st->execute([$phone]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Verification failed. Please request a new OTP.'];
    }
    if (!$row) {
        return ['ok' => false, 'message' => 'OTP expired or not found. Please request a new one.'];
    }
    if ((int)$row['attempts'] >= 5) {
        $db->prepare("UPDATE customer_otps SET consumed=1 WHERE id=?")->execute([(int)$row['id']]);
        return ['ok' => false, 'message' => 'Too many wrong attempts. Please request a new OTP.'];
    }
    if (!password_verify($otp, (string)$row['otp_hash'])) {
        $db->prepare("UPDATE customer_otps SET attempts=attempts+1 WHERE id=?")->execute([(int)$row['id']]);
        return ['ok' => false, 'message' => 'Incorrect OTP. Please try again.'];
    }
    $db->prepare("UPDATE customer_otps SET consumed=1 WHERE id=?")->execute([(int)$row['id']]);
    $db->prepare("UPDATE customer_otps SET consumed=1 WHERE phone=? AND consumed=0")->execute([$phone]);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['customer_phone'] = $phone;
    $_SESSION['customer_login_at'] = time();
    return ['ok' => true, 'message' => 'Logged in.'];
}

/**
 * Read-only transaction history for a payer's mobile across ALL merchants.
 * Matches transactions.customer_phone and falls back to payment_links.customer_phone.
 *
 * @param array{from?:string,to?:string,status?:string,type?:string,amount_min?:float|string,amount_max?:float|string} $filters
 */
function getCustomerTransactions(string $phone, int $limit = 100, array $filters = []): array
{
    $db = getDB();
    $limit = max(1, min(200, $limit));
    $phone = customerNormalizePhone($phone) ?: $phone;

    $queries = [
        // Prefer digit-normalized match on txn phone OR linked payment-link phone.
        "SELECT t.*, m.business_name,
                COALESCE(NULLIF(TRIM(t.customer_phone),''), NULLIF(TRIM(pl.customer_phone),'')) AS matched_phone
         FROM transactions t
         LEFT JOIN merchants m ON m.id = t.merchant_id
         LEFT JOIN payment_links pl ON pl.id = t.payment_link_id
         WHERE RIGHT(REGEXP_REPLACE(COALESCE(NULLIF(TRIM(t.customer_phone),''), NULLIF(TRIM(pl.customer_phone),''), ''), '[^0-9]', ''), 10) = ?
         ORDER BY t.created_at DESC LIMIT 200",
        // Without REGEXP_REPLACE
        "SELECT t.*, m.business_name
         FROM transactions t
         LEFT JOIN merchants m ON m.id = t.merchant_id
         LEFT JOIN payment_links pl ON pl.id = t.payment_link_id
         WHERE t.customer_phone LIKE CONCAT('%', ?)
            OR pl.customer_phone LIKE CONCAT('%', ?)
         ORDER BY t.created_at DESC LIMIT 200",
        // Minimal fallback (txn phone only)
        "SELECT t.*, m.business_name FROM transactions t
         LEFT JOIN merchants m ON m.id = t.merchant_id
         WHERE t.customer_phone LIKE CONCAT('%', ?)
         ORDER BY t.created_at DESC LIMIT 200",
    ];

    $rows = [];
    foreach ($queries as $i => $sql) {
        try {
            $st = $db->prepare($sql);
            if ($i === 1) {
                $st->execute([$phone, $phone]);
            } else {
                $st->execute([$phone]);
            }
            $rows = $st->fetchAll();
            // PHP-side harden: keep only exact last-10 match.
            $rows = array_values(array_filter($rows, static function (array $row) use ($phone): bool {
                $raw = (string)($row['matched_phone'] ?? $row['customer_phone'] ?? '');
                $norm = customerNormalizePhone($raw);
                if ($norm === $phone) {
                    return true;
                }
                $digits = preg_replace('/\D/', '', $raw) ?? '';
                return strlen($digits) >= 10 && substr($digits, -10) === $phone;
            }));
            break;
        } catch (Throwable $e) {
            continue;
        }
    }

    $from = trim((string)($filters['from'] ?? ''));
    $to = trim((string)($filters['to'] ?? ''));
    $status = strtolower(trim((string)($filters['status'] ?? '')));
    $type = strtolower(trim((string)($filters['type'] ?? '')));
    $amountMin = $filters['amount_min'] ?? '';
    $amountMax = $filters['amount_max'] ?? '';
    $amountMin = $amountMin === '' || $amountMin === null ? null : (float)$amountMin;
    $amountMax = $amountMax === '' || $amountMax === null ? null : (float)$amountMax;

    $rows = array_values(array_filter($rows, static function (array $row) use ($from, $to, $status, $type, $amountMin, $amountMax): bool {
        $created = (string)($row['created_at'] ?? '');
        if ($from !== '' && $created !== '' && strncmp($created, $from, 10) < 0) {
            return false;
        }
        if ($to !== '' && $created !== '' && strncmp($created, $to, 10) > 0) {
            return false;
        }
        if ($status !== '' && $status !== 'all' && strtolower((string)($row['status'] ?? '')) !== $status) {
            return false;
        }
        if ($type !== '' && $type !== 'all') {
            $method = strtolower((string)($row['payment_method'] ?? ''));
            $mode = strtolower((string)($row['collection_mode'] ?? ''));
            if ($method !== $type && $mode !== $type && !str_contains($method, $type)) {
                return false;
            }
        }
        $amt = (float)($row['amount'] ?? 0);
        if ($amountMin !== null && $amt < $amountMin) {
            return false;
        }
        if ($amountMax !== null && $amt > $amountMax) {
            return false;
        }
        return true;
    }));

    return array_slice($rows, 0, $limit);
}

/** Mask payer phone for merchant UI (privacy). */
if (!function_exists('maskCustomerContactPortal')) {
    function maskCustomerContactPortal(?string $phone, ?string $name = null): string
    {
        $phone = trim((string)$phone);
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) >= 4) {
            return '••••' . substr($digits, -4);
        }
        $name = trim((string)$name);
        if ($name !== '') {
            $first = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
            return $first . '***';
        }
        return '—';
    }
}

if (!function_exists('maskCustomerContact')) {
    function maskCustomerContact(?string $phone, ?string $name = null): string
    {
        return maskCustomerContactPortal($phone, $name);
    }
}

if (!function_exists('adminCustomerHistoryUrl')) {
    function adminCustomerHistoryUrl(string $phone): string
    {
        $norm = customerNormalizePhone($phone) ?: preg_replace('/\D/', '', $phone);
        return 'admin_customer_view.php?phone=' . rawurlencode((string)$norm);
    }
}

function customerTransactionReason(array $t): string
{
    if (function_exists('transactionStatusExplainer')) {
        try {
            $explained = transactionStatusExplainer($t);
            if (is_array($explained) && !empty($explained['text'])) {
                return (string)$explained['text'];
            }
            if (is_string($explained) && $explained !== '') {
                return $explained;
            }
        } catch (Throwable $e) { /* fall through */ }
    }
    foreach (['failure_reason', 'failure_message', 'status_reason', 'remarks'] as $col) {
        if (!empty($t[$col])) {
            return (string)$t[$col];
        }
    }
    $status = strtolower((string)($t['status'] ?? ''));
    return match ($status) {
        'success', 'paid', 'captured' => 'Payment successful.',
        'pending' => 'Payment is being confirmed by the bank/gateway.',
        'failed' => 'Payment did not complete. Any debited amount is usually auto-reversed by your bank in 3–5 working days.',
        default => '',
    };
}

function createCustomerTicket(string $phone, string $subject, string $message, ?string $txnRef, string $category = 'grievance'): array
{
    ensureCustomerPortalSchema();
    $subject = trim($subject);
    $message = trim($message);
    $lenSub = function_exists('mb_strlen') ? mb_strlen($subject) : strlen($subject);
    $lenMsg = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
    if ($subject === '' || $message === '') {
        return ['ok' => false, 'message' => 'Please enter a subject and describe your issue.'];
    }
    if ($lenSub > 200 || $lenMsg > 5000) {
        return ['ok' => false, 'message' => 'Subject or message is too long.'];
    }
    $db = getDB();
    $merchantId = null;
    $customerName = null;
    $txnRef = $txnRef ? trim($txnRef) : null;
    if ($txnRef) {
        $tx = findCustomerOwnedTransaction($phone, $txnRef);
        if (!$tx) {
            return ['ok' => false, 'message' => 'That transaction is not linked to your mobile number.'];
        }
        $merchantId = $tx['merchant_id'] !== null ? (int)$tx['merchant_id'] : null;
        $customerName = $tx['customer_name'] ?: null;
    }
    $ticketId = generateId('CT');
    try {
        $db->prepare("INSERT INTO customer_tickets (ticket_id, customer_phone, customer_name, merchant_id, category, subject, message, txn_reference) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$ticketId, $phone, $customerName, $merchantId, $category, $subject, $message, $txnRef]);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Could not create ticket. Please try again.'];
    }

    // Notify merchant (in-app) when ticket is tied to their txn.
    if ($merchantId && function_exists('createNotification')) {
        try {
            createNotification(
                $merchantId,
                'New customer complaint',
                'Ticket ' . $ticketId . ': ' . $subject . ($txnRef ? ' (Txn ' . $txnRef . ')' : '')
            );
        } catch (Throwable $e) { /* best effort */ }
    }

    return ['ok' => true, 'message' => 'Ticket ' . $ticketId . ' created.', 'ticket_id' => $ticketId];
}

/** Ensure the txn belongs to this payer mobile (txn phone or payment-link phone). */
function findCustomerOwnedTransaction(string $phone, string $txnId): ?array
{
    $db = getDB();
    $phone = customerNormalizePhone($phone) ?: $phone;
    $attempts = [
        ["SELECT t.* FROM transactions t LEFT JOIN payment_links pl ON pl.id = t.payment_link_id
          WHERE t.txn_id=? AND RIGHT(REGEXP_REPLACE(COALESCE(NULLIF(TRIM(t.customer_phone),''), NULLIF(TRIM(pl.customer_phone),''), ''), '[^0-9]', ''), 10)=? LIMIT 1", [$txnId, $phone]],
        ["SELECT t.* FROM transactions t LEFT JOIN payment_links pl ON pl.id = t.payment_link_id
          WHERE t.txn_id=? AND (t.customer_phone LIKE CONCAT('%', ?) OR pl.customer_phone LIKE CONCAT('%', ?)) LIMIT 1", [$txnId, $phone, $phone]],
        ["SELECT * FROM transactions WHERE txn_id=? AND customer_phone LIKE CONCAT('%', ?) LIMIT 1", [$txnId, $phone]],
    ];
    foreach ($attempts as [$sql, $params]) {
        try {
            $st = $db->prepare($sql);
            $st->execute($params);
            $row = $st->fetch();
            if ($row) {
                return $row;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    return null;
}

function getCustomerTickets(string $phone, int $limit = 50): array
{
    ensureCustomerPortalSchema();
    try {
        $st = getDB()->prepare("SELECT * FROM customer_tickets WHERE customer_phone=? ORDER BY created_at DESC LIMIT " . max(1, min(200, $limit)));
        $st->execute([$phone]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getCustomerTicket(string $ticketId, string $phone): ?array
{
    ensureCustomerPortalSchema();
    try {
        $st = getDB()->prepare("SELECT * FROM customer_tickets WHERE ticket_id=? AND customer_phone=? LIMIT 1");
        $st->execute([$ticketId, $phone]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function getCustomerTicketMessages(int $ticketDbId): array
{
    try {
        $st = getDB()->prepare("SELECT * FROM customer_ticket_messages WHERE ticket_id=? ORDER BY created_at ASC, id ASC");
        $st->execute([$ticketDbId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function customerTicketSenderLabel(string $senderType, ?string $storedLabel = null): string
{
    if ($storedLabel) {
        return $storedLabel;
    }
    return match ($senderType) {
        'customer' => 'You',
        'merchant' => 'Merchant',
        'staff' => 'Support Team',
        'admin' => 'Support Team',
        default => 'Support',
    };
}

/**
 * Add a message to a ticket. senderType: customer|admin|merchant|staff.
 * Support-side replies notify the customer via WhatsApp/SMS when configured.
 */
function addCustomerTicketMessage(int $ticketDbId, string $senderType, string $message, string $senderLabel = ''): bool
{
    $message = trim($message);
    $len = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
    if ($message === '' || $len > 5000) {
        return false;
    }
    $allowed = ['customer', 'admin', 'merchant', 'staff'];
    if (!in_array($senderType, $allowed, true)) {
        $senderType = 'admin';
    }
    $label = $senderLabel !== '' ? (function_exists('mb_substr') ? mb_substr($senderLabel, 0, 120) : substr($senderLabel, 0, 120)) : null;
    try {
        try {
            getDB()->prepare("INSERT INTO customer_ticket_messages (ticket_id, sender_type, sender_label, message) VALUES (?,?,?,?)")
                ->execute([$ticketDbId, $senderType, $label, $message]);
        } catch (Throwable $e) {
            // Older schema without sender_label / expanded enum — map merchant/staff → admin for storage.
            $legacyType = in_array($senderType, ['merchant', 'staff'], true) ? 'admin' : $senderType;
            getDB()->prepare("INSERT INTO customer_ticket_messages (ticket_id, sender_type, message) VALUES (?,?,?)")
                ->execute([$ticketDbId, $legacyType === 'customer' ? 'customer' : 'admin', $message]);
        }
        $newStatus = $senderType === 'customer' ? 'open' : 'in_progress';
        getDB()->prepare("UPDATE customer_tickets SET status=? WHERE id=?")->execute([$newStatus, $ticketDbId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Support/merchant reply helper: saves message, optional status, notifies customer.
 * NEVER auto-approves contact changes — OTP-login only.
 * @return array{ok:bool,message:string}
 */
function replyToCustomerTicket(int $ticketDbId, string $senderType, string $message, string $status = '', string $actorLabel = ''): array
{
    ensureCustomerPortalSchema();
    $ticket = getCustomerTicketById($ticketDbId);
    if (!$ticket) {
        return ['ok' => false, 'message' => 'Complaint not found.'];
    }
    $message = trim($message);
    if ($message !== '') {
        if (!addCustomerTicketMessage($ticketDbId, $senderType, $message, $actorLabel)) {
            return ['ok' => false, 'message' => 'Could not save reply.'];
        }
        notifyCustomerTicketReply($ticket, $message);
    }
    if ($status !== '' && in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
        setCustomerTicketStatus($ticketDbId, $status);
    }
    return ['ok' => true, 'message' => 'Reply saved for complaint ' . $ticket['ticket_id'] . '.'];
}

function notifyCustomerTicketReply(array $ticket, string $reply): void
{
    $phone = customerNormalizePhone((string)($ticket['customer_phone'] ?? ''));
    if ($phone === '') {
        return;
    }
    $ticketCode = (string)($ticket['ticket_id'] ?? '');
    $snip = function_exists('mb_substr') ? mb_substr($reply, 0, 280) : substr($reply, 0, 280);
    $text = "UniWeb support replied to your complaint {$ticketCode}: {$snip}";
    $portalUrl = (defined('APP_URL') ? APP_URL : '') . '/customer_ticket.php?id=' . rawurlencode($ticketCode);
    $text .= ' View: ' . $portalUrl;

    try {
        if (function_exists('sendWhatsAppTextMessage') && getSetting('whatsapp_enabled', '0') === '1') {
            sendWhatsAppTextMessage($phone, $text);
        } elseif (function_exists('sendSMS')) {
            sendSMS($phone, $text);
        }
    } catch (Throwable $e) { /* best effort */ }
}

/* ---------------- Admin / staff / merchant helpers ---------------- */

function getAllCustomerTickets(?string $status = null, int $limit = 100): array
{
    ensureCustomerPortalSchema();
    $sql = "SELECT ct.*, m.business_name FROM customer_tickets ct LEFT JOIN merchants m ON m.id = ct.merchant_id";
    $params = [];
    if ($status && in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
        $sql .= " WHERE ct.status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY ct.updated_at DESC LIMIT " . max(1, min(300, $limit));
    try {
        $st = getDB()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Tickets tied to a single merchant's transactions only. */
function getMerchantCustomerTickets(int $merchantId, ?string $status = null, int $limit = 100): array
{
    ensureCustomerPortalSchema();
    $sql = "SELECT ct.*, m.business_name FROM customer_tickets ct LEFT JOIN merchants m ON m.id = ct.merchant_id WHERE ct.merchant_id = ?";
    $params = [$merchantId];
    if ($status && in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
        $sql .= " AND ct.status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY ct.updated_at DESC LIMIT " . max(1, min(300, $limit));
    try {
        $st = getDB()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getMerchantCustomerTicket(int $merchantId, int $ticketDbId): ?array
{
    ensureCustomerPortalSchema();
    try {
        $st = getDB()->prepare("SELECT ct.*, m.business_name FROM customer_tickets ct LEFT JOIN merchants m ON m.id = ct.merchant_id WHERE ct.id=? AND ct.merchant_id=? LIMIT 1");
        $st->execute([$ticketDbId, $merchantId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function getPendingMerchantCustomerTicketCount(int $merchantId): int
{
    ensureCustomerPortalSchema();
    try {
        $st = getDB()->prepare("SELECT COUNT(*) FROM customer_tickets WHERE merchant_id=? AND status IN ('open','in_progress')");
        $st->execute([$merchantId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function getCustomerTicketById(int $id): ?array
{
    ensureCustomerPortalSchema();
    try {
        $st = getDB()->prepare("SELECT ct.*, m.business_name FROM customer_tickets ct LEFT JOIN merchants m ON m.id = ct.merchant_id WHERE ct.id=? LIMIT 1");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function setCustomerTicketStatus(int $ticketDbId, string $status): bool
{
    if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
        return false;
    }
    try {
        getDB()->prepare("UPDATE customer_tickets SET status=? WHERE id=?")->execute([$status, $ticketDbId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
