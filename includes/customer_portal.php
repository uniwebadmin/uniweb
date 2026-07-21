<?php
declare(strict_types=1);

/**
 * Customer (payer) portal — passwordless WhatsApp/SMS OTP login, read-only
 * transaction history by mobile number, and grievance/support tickets.
 * Deliberately isolated from merchant/admin sessions and tables.
 */

require_once __DIR__ . '/notify.php';

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
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS customer_ticket_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            sender_type ENUM('customer','admin') NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ticket (ticket_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

/** Reduce any Indian phone input to its 10-digit subscriber number, or '' if invalid. */
function customerNormalizePhone(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw);
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
 * is configured (e.g. demo without keys), returns the OTP in 'demo_otp' so the
 * login screen can show it in a clearly-labelled demo notice.
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
    $hash = password_hash($otp, PASSWORD_DEFAULT);
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
    // No delivery channel configured — demo mode: surface the OTP so the flow is testable.
    return ['ok' => true, 'channel' => 'demo', 'demo_otp' => $otp, 'message' => 'Demo mode: SMS/WhatsApp not configured. Use the OTP shown below.'];
}

/** @return array{ok:bool,message:string} */
function verifyCustomerOtp(string $phone, string $otp): array
{
    ensureCustomerPortalSchema();
    $db = getDB();
    $otp = preg_replace('/\D/', '', $otp);
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

/** Read-only transaction history for a payer's mobile (last 10 digits match). */
function getCustomerTransactions(string $phone, int $limit = 100): array
{
    $db = getDB();
    $limit = max(1, min(200, $limit));
    $sql = "SELECT t.*, m.business_name
            FROM transactions t
            LEFT JOIN merchants m ON m.id = t.merchant_id
            WHERE RIGHT(REGEXP_REPLACE(COALESCE(t.customer_phone,''), '[^0-9]', ''), 10) = ?
            ORDER BY t.created_at DESC LIMIT {$limit}";
    try {
        $st = $db->prepare($sql);
        $st->execute([$phone]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        // Fallback for engines without REGEXP_REPLACE.
        try {
            $st = $db->prepare("SELECT t.*, m.business_name FROM transactions t LEFT JOIN merchants m ON m.id = t.merchant_id
                WHERE t.customer_phone LIKE CONCAT('%', ?) ORDER BY t.created_at DESC LIMIT {$limit}");
            $st->execute([$phone]);
            return $st->fetchAll();
        } catch (Throwable $e2) {
            return [];
        }
    }
}

function customerTransactionReason(array $t): string
{
    foreach (['failure_reason', 'failure_message', 'status_reason', 'remarks'] as $col) {
        if (!empty($t[$col])) {
            return (string)$t[$col];
        }
    }
    $status = strtolower((string)($t['status'] ?? ''));
    return match ($status) {
        'success', 'paid', 'captured' => 'Payment successful.',
        'pending' => 'Payment is being confirmed by the bank/gateway.',
        'failed' => 'Payment did not complete. Any debited amount is auto-reversed by your bank in 3-5 working days.',
        default => '',
    };
}

function createCustomerTicket(string $phone, string $subject, string $message, ?string $txnRef, string $category = 'grievance'): array
{
    ensureCustomerPortalSchema();
    $subject = trim($subject);
    $message = trim($message);
    if ($subject === '' || $message === '') {
        return ['ok' => false, 'message' => 'Please enter a subject and describe your issue.'];
    }
    if (mb_strlen($subject) > 200 || mb_strlen($message) > 5000) {
        return ['ok' => false, 'message' => 'Subject or message is too long.'];
    }
    $db = getDB();
    $merchantId = null;
    $customerName = null;
    $txnRef = $txnRef ? trim($txnRef) : null;
    if ($txnRef) {
        try {
            $st = $db->prepare("SELECT merchant_id, customer_name FROM transactions
                WHERE txn_id=? AND RIGHT(REGEXP_REPLACE(COALESCE(customer_phone,''), '[^0-9]', ''), 10)=? LIMIT 1");
            $st->execute([$txnRef, $phone]);
            $tx = $st->fetch();
            if (!$tx) {
                return ['ok' => false, 'message' => 'That transaction is not linked to your mobile number.'];
            }
            $merchantId = $tx['merchant_id'] !== null ? (int)$tx['merchant_id'] : null;
            $customerName = $tx['customer_name'] ?: null;
        } catch (Throwable $e) {
            $merchantId = null;
        }
    }
    $ticketId = generateId('CT');
    try {
        $db->prepare("INSERT INTO customer_tickets (ticket_id, customer_phone, customer_name, merchant_id, category, subject, message, txn_reference) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$ticketId, $phone, $customerName, $merchantId, $category, $subject, $message, $txnRef]);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Could not create ticket. Please try again.'];
    }
    return ['ok' => true, 'message' => 'Ticket ' . $ticketId . ' created.', 'ticket_id' => $ticketId];
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

function addCustomerTicketMessage(int $ticketDbId, string $senderType, string $message): bool
{
    $message = trim($message);
    if ($message === '' || mb_strlen($message) > 5000) {
        return false;
    }
    $senderType = $senderType === 'admin' ? 'admin' : 'customer';
    try {
        getDB()->prepare("INSERT INTO customer_ticket_messages (ticket_id, sender_type, message) VALUES (?,?,?)")
            ->execute([$ticketDbId, $senderType, $message]);
        $newStatus = $senderType === 'admin' ? 'in_progress' : 'open';
        getDB()->prepare("UPDATE customer_tickets SET status=? WHERE id=?")->execute([$newStatus, $ticketDbId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ---------------- Admin-side helpers ---------------- */

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
