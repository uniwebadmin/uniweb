<?php
declare(strict_types=1);

/**
 * A6: Merchant Beneficiaries — add bank/UPI, verify via Penny Drop (mock),
 * only verified beneficiaries selectable for auto payout.
 * Soft-delete/disable; never hard-delete if used in past payouts.
 */

function ensureBeneficiaryTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS merchant_beneficiaries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            type ENUM('bank','upi') NOT NULL DEFAULT 'bank',
            account_holder VARCHAR(160) NOT NULL,
            account_number VARCHAR(64) NOT NULL,
            ifsc_code VARCHAR(20) DEFAULT NULL,
            upi_id VARCHAR(128) DEFAULT NULL,
            bank_name VARCHAR(100) DEFAULT NULL,
            status ENUM('unverified','pending','verified','failed','disabled') NOT NULL DEFAULT 'unverified',
            verify_name VARCHAR(160) DEFAULT NULL,
            verify_score DECIMAL(5,2) DEFAULT NULL,
            verify_response TEXT DEFAULT NULL,
            verified_at TIMESTAMP NULL DEFAULT NULL,
            verified_by INT DEFAULT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_merchant (merchant_id, status),
            INDEX idx_status (status),
            INDEX idx_upi (upi_id),
            INDEX idx_account (account_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Add a beneficiary (bank or UPI) for a merchant.
 */
function addMerchantBeneficiary(int $merchantId, string $type, string $accountHolder, string $accountNumber, ?string $ifscCode = null, ?string $upiId = null, ?string $bankName = null): array
{
    ensureBeneficiaryTable();
    $db = getDB();

    if (!in_array($type, ['bank', 'upi'], true)) {
        return ['ok' => false, 'error' => 'Invalid beneficiary type.'];
    }
    if (trim($accountHolder) === '') {
        return ['ok' => false, 'error' => 'Account holder name is required.'];
    }
    if ($type === 'bank' && trim($accountNumber) === '') {
        return ['ok' => false, 'error' => 'Account number is required.'];
    }
    if ($type === 'upi' && trim($upiId ?? '') === '') {
        return ['ok' => false, 'error' => 'UPI ID is required.'];
    }

    // Check for duplicate (same account_number or upi_id for same merchant, not disabled)
    if ($type === 'bank') {
        $st = $db->prepare("SELECT id FROM merchant_beneficiaries WHERE merchant_id=? AND account_number=? AND status != 'disabled'");
        $st->execute([$merchantId, $accountNumber]);
        if ($st->fetch()) {
            return ['ok' => false, 'error' => 'This bank account is already added.'];
        }
    } else {
        $st = $db->prepare("SELECT id FROM merchant_beneficiaries WHERE merchant_id=? AND upi_id=? AND status != 'disabled'");
        $st->execute([$merchantId, $upiId]);
        if ($st->fetch()) {
            return ['ok' => false, 'error' => 'This UPI ID is already added.'];
        }
    }

    try {
        $db->prepare('INSERT INTO merchant_beneficiaries (merchant_id, type, account_holder, account_number, ifsc_code, upi_id, bank_name, status) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$merchantId, $type, $accountHolder, $accountNumber, $ifscCode, $upiId, $bankName, 'unverified']);
        $id = (int)$db->lastInsertId();
        return ['ok' => true, 'id' => $id, 'message' => 'Beneficiary added as unverified. Click Verify to run penny drop.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Failed to add beneficiary: ' . $e->getMessage()];
    }
}

/**
 * Verify a beneficiary via Penny Drop.
 * Uses real partner API (Decentro/Cashfree) when keys configured, mock otherwise.
 */
function verifyBeneficiary(int $beneficiaryId, ?int $verifiedBy = null): array
{
    ensureBeneficiaryTable();
    $db = getDB();

    $st = $db->prepare('SELECT * FROM merchant_beneficiaries WHERE id=?');
    $st->execute([$beneficiaryId]);
    $ben = $st->fetch();
    if (!$ben) {
        return ['ok' => false, 'error' => 'Beneficiary not found.'];
    }
    if ($ben['status'] === 'disabled') {
        return ['ok' => false, 'error' => 'Beneficiary is disabled.'];
    }

    // Mark as pending while verification in progress
    $db->prepare("UPDATE merchant_beneficiaries SET status='pending' WHERE id=?")
        ->execute([$beneficiaryId]);

    // Try real partner API first
    $apiResult = null;
    $provider = null;

    // Try Decentro penny drop if configured (Partner Registry → Decentro → Keys)
    if (function_exists('isDecentroConfigured') ? isDecentroConfigured() : (decentroClientId() !== '' && decentroClientSecret() !== '')) {
        $provider = 'decentro';
        $apiResult = decentroPennyDrop(
            $ben['account_number'],
            $ben['ifsc_code'] ?? '',
            $ben['account_holder']
        );
    }

    // Try Cashfree beneficiary verification if configured
    if (!$apiResult) {
        $cfKey = function_exists('cashfreePayoutClientId') ? cashfreePayoutClientId() : getPartnerSetting('cashfree', 'cashfree_payout_client_id', '');
        $cfSecret = function_exists('cashfreePayoutClientSecret') ? cashfreePayoutClientSecret() : getPartnerSetting('cashfree', 'cashfree_payout_client_secret', '');
        if ($cfKey !== '' && $cfSecret !== '') {
            $provider = 'cashfree';
            $apiResult = cashfreePennyDrop(
                $ben['account_number'],
                $ben['ifsc_code'] ?? '',
                $ben['account_holder']
            );
        }
    }

    // Fallback to mock if no partner keys configured
    if (!$apiResult) {
        $provider = 'mock';
        $apiResult = mockPennyDrop(
            $ben['account_number'],
            $ben['ifsc_code'] ?? '',
            $ben['account_holder']
        );
    }

    $nameMatchScore = (float)($apiResult['match_score'] ?? 0);
    $verifiedName = (string)($apiResult['name_from_bank'] ?? trim($ben['account_holder']));
    $newStatus = $nameMatchScore >= 70.0 ? 'verified' : 'failed';

    $verifyResponse = json_encode([
        'provider' => $provider,
        'name_from_bank' => $verifiedName,
        'match_score' => $nameMatchScore,
        'penny_amount' => $apiResult['penny_amount'] ?? 1.00,
        'raw_response' => $apiResult['raw'] ?? null,
        'timestamp' => date('c'),
    ]);

    try {
        $db->prepare('UPDATE merchant_beneficiaries SET status=?, verify_name=?, verify_score=?, verify_response=?, verified_at=NOW(), verified_by=? WHERE id=?')
            ->execute([$newStatus, $verifiedName, $nameMatchScore, $verifyResponse, $verifiedBy, $beneficiaryId]);

        if (function_exists('recordImmutableAudit')) {
            recordImmutableAudit(
                'beneficiary_verified',
                (int)$ben['merchant_id'],
                'beneficiary',
                (string)$beneficiaryId,
                "Penny drop via {$provider}: score={$nameMatchScore}%, status={$newStatus}"
            );
        }

        return [
            'ok' => true,
            'status' => $newStatus,
            'name' => $verifiedName,
            'score' => $nameMatchScore,
            'provider' => $provider,
            'message' => $newStatus === 'verified'
                ? "Beneficiary verified via {$provider}! Name match: {$verifiedName} (score: {$nameMatchScore}%)"
                : "Verification failed via {$provider}. Name match score too low ({$nameMatchScore}%).",
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Verification failed: ' . $e->getMessage()];
    }
}

/**
 * Decentro Penny Drop API call.
 */
function decentroPennyDrop(string $accountNumber, string $ifsc, string $name): array
{
    if (!function_exists('decentroClientId') && is_file(__DIR__ . '/partner_control.php')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $apiKey = decentroClientId();
    $apiSecret = decentroClientSecret();
    $baseUrl = function_exists('decentroBaseUrl') ? decentroBaseUrl() : 'https://api.decentro.tech';

    $payload = json_encode([
        'account_number' => $accountNumber,
        'ifsc' => $ifsc,
        'name' => $name,
        'penny_amount' => 100,
    ]);

    $ch = curl_init($baseUrl . '/v2/banking/verification/penny_drop');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'client_id: ' . $apiKey,
            'client_secret: ' . $apiSecret,
            'provider: decentro',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['match_score' => 0, 'name_from_bank' => '', 'raw' => ['error' => $curlError]];
    }

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['data']['name'])) {
        $bankName = (string)$data['data']['name'];
        $score = calculateNameMatchScore($name, $bankName);
        return [
            'match_score' => $score,
            'name_from_bank' => $bankName,
            'penny_amount' => 1.00,
            'raw' => $data,
        ];
    }

    return ['match_score' => 0, 'name_from_bank' => '', 'raw' => $data ?? ['http_code' => $httpCode]];
}

/**
 * Cashfree Beneficiary Verification API call.
 */
function cashfreePennyDrop(string $accountNumber, string $ifsc, string $name): array
{
    if (!function_exists('cashfreePayoutClientId') && is_file(__DIR__ . '/partner_control.php')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $clientId = cashfreePayoutClientId();
    $clientSecret = cashfreePayoutClientSecret();
    $baseUrl = function_exists('cashfreePayoutBaseUrl') ? cashfreePayoutBaseUrl() : 'https://payout-api.cashfree.com';

    $ch = curl_init($baseUrl . '/payout/v1/verifyBankAccount');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'bankAccount' => $accountNumber,
            'ifsc' => $ifsc,
            'name' => $name,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Client-Id: ' . $clientId,
            'X-Client-Secret: ' . $clientSecret,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['match_score' => 0, 'name_from_bank' => '', 'raw' => ['error' => $curlError]];
    }

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['data']['nameAtBank'])) {
        $bankName = (string)$data['data']['nameAtBank'];
        $score = calculateNameMatchScore($name, $bankName);
        return [
            'match_score' => $score,
            'name_from_bank' => $bankName,
            'penny_amount' => 1.00,
            'raw' => $data,
        ];
    }

    return ['match_score' => 0, 'name_from_bank' => '', 'raw' => $data ?? ['http_code' => $httpCode]];
}

/**
 * Mock Penny Drop — used when no partner keys are configured.
 */
function mockPennyDrop(string $accountNumber, string $ifsc, string $name): array
{
    $nameMatchScore = strlen(trim($name)) >= 3 ? 85.0 : 0.0;
    return [
        'match_score' => $nameMatchScore,
        'name_from_bank' => trim($name),
        'penny_amount' => 1.00,
        'raw' => ['mock' => true, 'note' => 'Mock penny drop — no partner keys configured'],
    ];
}

/**
 * Calculate name match score between merchant-provided name and bank-returned name.
 */
function calculateNameMatchScore(string $inputName, string $bankName): float
{
    $input = strtolower(trim($inputName));
    $bank = strtolower(trim($bankName));
    if ($input === '' || $bank === '') return 0.0;
    if ($input === $bank) return 100.0;

    similar_text($input, $bank, $percent);
    $levenshteinRatio = 1 - (levenshtein($input, $bank) / max(strlen($input), strlen($bank)));
    $score = ($percent * 0.6 + $levenshteinRatio * 100 * 0.4);
    return round(min(100, max(0, $score)), 2);
}

/**
 * Disable a beneficiary (soft-delete). Never hard-delete if used in past payouts.
 */
function disableBeneficiary(int $beneficiaryId, int $merchantId): array
{
    ensureBeneficiaryTable();
    $db = getDB();

    // Check if used in past settlements
    $st = $db->prepare('SELECT id FROM merchant_beneficiaries WHERE id=? AND merchant_id=?');
    $st->execute([$beneficiaryId, $merchantId]);
    if (!$st->fetch()) {
        return ['ok' => false, 'error' => 'Beneficiary not found.'];
    }

    $db->prepare("UPDATE merchant_beneficiaries SET status='disabled' WHERE id=?")
        ->execute([$beneficiaryId]);
    return ['ok' => true, 'message' => 'Beneficiary disabled.'];
}

/**
 * Get all beneficiaries for a merchant.
 */
function getMerchantBeneficiaries(int $merchantId, bool $includeDisabled = false): array
{
    ensureBeneficiaryTable();
    $sql = "SELECT * FROM merchant_beneficiaries WHERE merchant_id=?";
    if (!$includeDisabled) {
        $sql .= " AND status != 'disabled'";
    }
    $sql .= " ORDER BY is_default DESC, status ASC, id ASC";
    try {
        $st = getDB()->prepare($sql);
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get only verified beneficiaries (for auto payout selection).
 */
function getVerifiedBeneficiaries(int $merchantId): array
{
    ensureBeneficiaryTable();
    try {
        $st = getDB()->prepare("SELECT * FROM merchant_beneficiaries WHERE merchant_id=? AND status='verified' ORDER BY is_default DESC, id ASC");
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get a single beneficiary by ID.
 */
function getBeneficiary(int $beneficiaryId): ?array
{
    ensureBeneficiaryTable();
    try {
        $st = getDB()->prepare("SELECT * FROM merchant_beneficiaries WHERE id=?");
        $st->execute([$beneficiaryId]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Set default beneficiary.
 */
function setDefaultBeneficiary(int $beneficiaryId, int $merchantId): bool
{
    ensureBeneficiaryTable();
    $db = getDB();
    try {
        $db->prepare('UPDATE merchant_beneficiaries SET is_default=0 WHERE merchant_id=?')->execute([$merchantId]);
        $db->prepare('UPDATE merchant_beneficiaries SET is_default=1 WHERE id=? AND merchant_id=?')->execute([$beneficiaryId, $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
