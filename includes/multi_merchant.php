<?php
declare(strict_types=1);

/**
 * Point 4 — Multi-GST: Same PAN, different GSTINs.
 *
 * Rules:
 * 1. Same PAN + same GSTIN → BLOCK (duplicate business)
 * 2. Same PAN + no GSTIN (null/empty) on existing → BLOCK (can't have second without GST)
 * 3. Same PAN + different GSTIN → ALLOW (create second merchant, link to same user)
 * 4. New PAN → ALLOW (completely new business)
 *
 * user_merchant_roles: one user (email/phone) can own multiple merchants.
 * Session uses active_merchant_id to switch between businesses.
 */

function ensureMultiMerchantTables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS user_merchant_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_email VARCHAR(150) NOT NULL,
            user_phone VARCHAR(20) NOT NULL DEFAULT '',
            merchant_id INT NOT NULL,
            role ENUM('owner','staff') NOT NULL DEFAULT 'owner',
            invited_by INT DEFAULT NULL,
            invited_at DATETIME DEFAULT NULL,
            accepted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status ENUM('active','invited','revoked') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_user_merchant (user_email, merchant_id),
            INDEX idx_merchant (merchant_id),
            INDEX idx_user_phone (user_phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Add gstin column to merchants if missing
        $db->exec("ALTER TABLE merchants ADD COLUMN gstin VARCHAR(15) DEFAULT NULL");
    } catch (Throwable $e) { /* already exists */ }
}

/**
 * Normalize PAN: uppercase, strip spaces.
 */
function normalizePan(string $pan): string
{
    return strtoupper(preg_replace('/\s+/', '', $pan));
}

/**
 * Normalize GSTIN: uppercase, strip spaces.
 */
function normalizeGstin(string $gstin): string
{
    return strtoupper(preg_replace('/\s+/', '', $gstin));
}

/**
 * Check PAN/GSTIN duplicate rules.
 * Returns ['allowed' => bool, 'reason' => string, 'existing_merchant_id' => ?int]
 */
function checkPanGstinDuplicate(string $pan, ?string $gstin): array
{
    ensureMultiMerchantTables();
    $pan = normalizePan($pan);
    $gstin = $gstin !== null ? normalizeGstin($gstin) : '';

    if ($pan === '') {
        return ['allowed' => true, 'reason' => '', 'existing_merchant_id' => null];
    }

    $db = getDB();

    if ($gstin !== '') {
        // Rule 1: Same PAN + same GSTIN → BLOCK
        $st = $db->prepare('SELECT id FROM merchants WHERE pan_number=? AND gstin=? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$pan, $gstin]);
        $existing = $st->fetch();
        if ($existing) {
            return [
                'allowed' => false,
                'reason' => 'This PAN and GSTIN combination is already registered. Use the existing merchant account.',
                'existing_merchant_id' => (int)$existing['id'],
            ];
        }
        // Rule 3: Same PAN + different GSTIN → ALLOW
        return ['allowed' => true, 'reason' => '', 'existing_merchant_id' => null];
    }

    // No GSTIN provided
    // Rule 2: Same PAN + no GSTIN on existing → BLOCK
    $st = $db->prepare('SELECT id, gstin FROM merchants WHERE pan_number=? AND deleted_at IS NULL');
    $st->execute([$pan]);
    $rows = $st->fetchAll();
    if (count($rows) > 0) {
        // Check if any existing merchant with this PAN has no GSTIN
        foreach ($rows as $row) {
            if (empty($row['gstin'])) {
                return [
                    'allowed' => false,
                    'reason' => 'This PAN is already registered without a GSTIN. Add a GSTIN to create a second business with the same PAN.',
                    'existing_merchant_id' => (int)$row['id'],
                ];
            }
        }
        // All existing merchants with this PAN have GSTINs — allow new one
        return ['allowed' => true, 'reason' => '', 'existing_merchant_id' => null];
    }

    // Rule 4: New PAN → ALLOW
    return ['allowed' => true, 'reason' => '', 'existing_merchant_id' => null];
}

/**
 * Link a user (by email/phone) to a merchant in user_merchant_roles.
 */
function linkUserToMerchant(string $email, string $phone, int $merchantId, string $role = 'owner'): void
{
    ensureMultiMerchantTables();
    try {
        getDB()->prepare(
            'INSERT IGNORE INTO user_merchant_roles (user_email, user_phone, merchant_id, role, status) VALUES (?,?,?,?,?)'
        )->execute([$email, $phone, $merchantId, $role, 'active']);
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Get all merchants linked to a user by email or phone.
 */
function getUserMerchants(string $email, string $phone = ''): array
{
    ensureMultiMerchantTables();
    $db = getDB();
    $st = $db->prepare(
        'SELECT m.id, m.merchant_code, m.business_name, m.name, m.kyc_status, m.account_mode,
                umr.role, umr.status as role_status
         FROM user_merchant_roles umr
         JOIN merchants m ON m.id = umr.merchant_id
         WHERE umr.user_email = ? AND umr.status = "active" AND m.deleted_at IS NULL
         ORDER BY umr.created_at ASC'
    );
    $st->execute([$email]);
    $merchants = $st->fetchAll() ?: [];

    if ($phone !== '') {
        $st2 = $db->prepare(
            'SELECT m.id, m.merchant_code, m.business_name, m.name, m.kyc_status, m.account_mode,
                    umr.role, umr.status as role_status
             FROM user_merchant_roles umr
             JOIN merchants m ON m.id = umr.merchant_id
             WHERE umr.user_phone = ? AND umr.user_email != ? AND umr.status = "active" AND m.deleted_at IS NULL
             ORDER BY umr.created_at ASC'
        );
        $st2->execute([$phone, $email]);
        $byPhone = $st2->fetchAll() ?: [];
        // Merge unique by merchant id
        $seen = array_column($merchants, 'id');
        foreach ($byPhone as $row) {
            if (!in_array($row['id'], $seen, true)) {
                $merchants[] = $row;
            }
        }
    }

    return $merchants;
}

/**
 * Switch the active merchant in session.
 */
function switchActiveMerchant(int $merchantId): bool
{
    ensureMultiMerchantTables();
    $merchant = getMerchant();
    if (!$merchant) return false;

    // Verify user has access to this merchant
    $email = (string)($merchant['email'] ?? '');
    $phone = (string)($merchant['phone'] ?? '');
    $userMerchants = getUserMerchants($email, $phone);
    $hasAccess = false;
    foreach ($userMerchants as $um) {
        if ((int)$um['id'] === $merchantId) {
            $hasAccess = true;
            break;
        }
    }

    if (!$hasAccess) return false;

    $_SESSION['merchant_id'] = $merchantId;
    return true;
}

/**
 * Create a new merchant business for an existing user (same PAN, different GSTIN).
 * Returns ['ok' => bool, 'merchant_id' => ?int, 'error' => string]
 */
function createAdditionalBusiness(array $currentUser, string $pan, ?string $gstin, string $businessName, string $entityType): array
{
    ensureMultiMerchantTables();
    $pan = normalizePan($pan);
    $gstin = $gstin ? normalizeGstin($gstin) : null;

    // Check duplicate rules
    $check = checkPanGstinDuplicate($pan, $gstin);
    if (!$check['allowed']) {
        return ['ok' => false, 'merchant_id' => null, 'error' => $check['reason']];
    }

    $db = getDB();
    $code = 'UW' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

    try {
        $db->prepare(
            'INSERT INTO merchants (merchant_code, name, email, phone, password, business_name, business_type, business_entity_type, pan_number, gstin, address, country, state, district, city, pincode, api_key, api_secret, upi_id, account_mode, provision_profile, enabled_methods, collection_mode, auto_provisioned)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $code,
            $currentUser['name'] ?? 'Merchant',
            $currentUser['email'],
            $currentUser['phone'],
            $currentUser['password'] ?? '',
            $businessName,
            'retail',
            $entityType,
            $pan ?: null,
            $gstin,
            '',
            'India', '', '', '', '',
            'uk_' . bin2hex(random_bytes(16)),
            'us_' . bin2hex(random_bytes(24)),
            'merchant' . strtolower(substr($code, 2)) . '@uniweb',
            'test',
            'auto_p2m',
            json_encode(['upi_p2m']),
            'direct_upi',
            1,
        ]);
        $newId = (int)$db->lastInsertId();

        // Link user to new merchant
        linkUserToMerchant($currentUser['email'], $currentUser['phone'] ?? '', $newId, 'owner');

        // Also link user to existing merchant if not already linked
        $existingId = (int)($currentUser['id'] ?? 0);
        if ($existingId > 0) {
            linkUserToMerchant($currentUser['email'], $currentUser['phone'] ?? '', $existingId, 'owner');
        }

        return ['ok' => true, 'merchant_id' => $newId, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'merchant_id' => null, 'error' => 'Could not create business: ' . $e->getMessage()];
    }
}

/**
 * Get merchants grouped by PAN for admin view.
 */
function getMerchantsByPan(string $pan): array
{
    ensureMultiMerchantTables();
    $pan = normalizePan($pan);
    if ($pan === '') return [];
    $st = getDB()->prepare(
        'SELECT id, merchant_code, business_name, name, gstin, kyc_status, account_mode, created_at
         FROM merchants
         WHERE pan_number = ? AND deleted_at IS NULL
         ORDER BY created_at ASC'
    );
    $st->execute([$pan]);
    return $st->fetchAll() ?: [];
}
