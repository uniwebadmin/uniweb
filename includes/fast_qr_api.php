<?php
declare(strict_types=1);

/**
 * Fast QR Create API — minimal DB locks for high-throughput QR creation.
 *
 * Key optimizations vs the web UI path:
 *   - Single INSERT per QR (no transaction wrapping for single rows)
 *   - Batch INSERT for bulk creates (one query for N codes)
 *   - No payment_link creation (QR-only, UPI string embedded)
 *   - Pre-generated QR codes (no UUID collision check — 16 hex chars = 64 bits entropy)
 *   - No read-after-write (returns success immediately)
 *
 * Usage: POST /api/qr/create with API key header
 */

function ensureFastQrApi(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS merchant_qr_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            qr_code VARCHAR(32) NOT NULL UNIQUE,
            merchant_id INT NOT NULL,
            payment_link_id INT DEFAULT NULL,
            qr_type VARCHAR(20) NOT NULL DEFAULT 'fixed',
            label VARCHAR(120) NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            description VARCHAR(500) DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            is_test TINYINT(1) NOT NULL DEFAULT 0,
            expires_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_merchant (merchant_id, status),
            INDEX idx_qr_code (qr_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok — table likely exists already */ }
}

/**
 * Create a single QR code — fast path, no transaction.
 */
function fastQrCreate(int $merchantId, string $qrType, string $label, float $amount, ?string $description, bool $isTest): array
{
    ensureFastQrApi();
    $qrCode = 'QR' . strtoupper(bin2hex(random_bytes(8)));

    try {
        getDB()->prepare(
            "INSERT INTO merchant_qr_codes (qr_code, merchant_id, payment_link_id, qr_type, label, amount, description, is_test)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $qrCode, $merchantId, null, $qrType, $label, $amount,
            $description !== '' ? mb_substr($description, 0, 500) : null,
            $isTest ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        logPlatformError('warning', 'Fast QR create failed.', ['merchant_id' => $merchantId]);
        return ['ok' => false, 'error' => 'Could not create QR code. Retry or contact support.'];
    }

    return [
        'ok' => true,
        'qr_code' => $qrCode,
        'label' => $label,
        'qr_type' => $qrType,
        'amount' => $amount,
    ];
}

/**
 * Batch create QR codes — single INSERT for N rows (optimal for high throughput).
 */
function fastQrBatchCreate(int $merchantId, string $qrType, array $items, bool $isTest): array
{
    ensureFastQrApi();
    $db = getDB();
    $created = [];
    $errors = [];

    // Build batch INSERT
    $sql = 'INSERT INTO merchant_qr_codes (qr_code, merchant_id, payment_link_id, qr_type, label, amount, description, is_test) VALUES ';
    $placeholders = [];
    $params = [];

    foreach ($items as $i => $item) {
        $label = trim((string)($item['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) {
            $errors[] = ['row' => $i, 'error' => 'Invalid label'];
            continue;
        }
        $amount = $qrType === 'fixed' ? (float)($item['amount'] ?? 0) : 0.0;
        if ($qrType === 'fixed' && $amount < 1) {
            $errors[] = ['row' => $i, 'error' => 'Amount must be >= 1'];
            continue;
        }
        if ($isTest && $amount > 100) {
            $errors[] = ['row' => $i, 'error' => 'Test mode amount must be <= 100'];
            continue;
        }

        $qrCode = 'QR' . strtoupper(bin2hex(random_bytes(8)));
        $placeholders[] = '(?,?,?,?,?,?,?,?)';
        $params[] = $qrCode;
        $params[] = $merchantId;
        $params[] = null;
        $params[] = $qrType;
        $params[] = $label;
        $params[] = $amount;
        $params[] = !empty($item['description']) ? mb_substr($item['description'], 0, 500) : null;
        $params[] = $isTest ? 1 : 0;
        $created[] = ['qr_code' => $qrCode, 'label' => $label, 'amount' => $amount];
    }

    if (empty($placeholders)) {
        return ['ok' => false, 'error' => 'No valid items', 'errors' => $errors];
    }

    try {
        $sql .= implode(',', $placeholders);
        $db->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        // Fallback: insert one by one
        foreach ($created as $c) {
            try {
                $db->prepare(
                    "INSERT INTO merchant_qr_codes (qr_code, merchant_id, payment_link_id, qr_type, label, amount, is_test)
                     VALUES (?,?,?,?,?,?,?)"
                )->execute([$c['qr_code'], $merchantId, null, $qrType, $c['label'], $c['amount'], $isTest ? 1 : 0]);
            } catch (Throwable $e2) {
                $errors[] = ['qr_code' => $c['qr_code'], 'error' => 'insert_failed'];
            }
        }
    }

    return [
        'ok' => true,
        'created' => count($created),
        'items' => $created,
        'errors' => $errors,
    ];
}

/**
 * Validate API key and return merchant — uses canonical api_credentials (same as API Settings).
 */
function fastQrAuthenticate(string $apiKey): ?array
{
    if (!function_exists('authenticateMerchantApiKeyOnly')) {
        require_once __DIR__ . '/platform_api.php';
    }
    $merchant = authenticateMerchantApiKeyOnly($apiKey, 'links:write');
    return $merchant ?: null;
}
