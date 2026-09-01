<?php
declare(strict_types=1);

/**
 * KYC submit idempotency — prevents double-tab / double-click duplicate uploads & verify calls.
 * Replay within TTL returns success without re-running side effects.
 */

function ensureKycSubmitGuardTable(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    getDB()->exec("CREATE TABLE IF NOT EXISTS kyc_submit_locks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT UNSIGNED NOT NULL,
        action_key VARCHAR(32) NOT NULL,
        fingerprint CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_kyc_submit (merchant_id, action_key, fingerprint),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

function kycSubmitFingerprint(string $action, array $parts): string
{
    $payload = $action . '|' . implode('|', array_map(static fn($v): string => (string)$v, $parts));
    return hash('sha256', $payload);
}

function kycSubmitFileFingerprint(int $merchantId, string $docType, array $file): string
{
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    $hash = '';
    if ($tmp !== '' && is_uploaded_file($tmp)) {
        $hash = (string)@hash_file('sha256', $tmp);
    }
    if ($hash === '') {
        $hash = hash('sha256', (string)($file['name'] ?? '') . ':' . $size);
    }
    return kycSubmitFingerprint('upload', [$merchantId, $docType, $hash, $size]);
}

/**
 * @return array{ok:bool,replay?:bool,message?:string,fingerprint?:string}
 */
function claimKycSubmitLock(int $merchantId, string $action, string $fingerprint, int $ttlSeconds = 120): array
{
    if ($merchantId < 1 || $action === '' || strlen($fingerprint) !== 64) {
        return ['ok' => false, 'message' => 'Invalid submit lock request.'];
    }
    $ttlSeconds = max(30, min(600, $ttlSeconds));
    try {
        ensureKycSubmitGuardTable();
        $db = getDB();
        $db->exec('DELETE FROM kyc_submit_locks WHERE expires_at < NOW()');

        $sel = $db->prepare(
            'SELECT id, expires_at FROM kyc_submit_locks
             WHERE merchant_id=? AND action_key=? AND fingerprint=? LIMIT 1'
        );
        $sel->execute([$merchantId, $action, $fingerprint]);
        $existing = $sel->fetch();
        if ($existing && strtotime((string)$existing['expires_at']) > time()) {
            return [
                'ok' => false,
                'replay' => true,
                'message' => 'Already processing — please wait.',
                'fingerprint' => $fingerprint,
            ];
        }

        if ($existing) {
            $upd = $db->prepare(
                'UPDATE kyc_submit_locks SET expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND), created_at=NOW()
                 WHERE id=?'
            );
            $upd->execute([$ttlSeconds, (int)$existing['id']]);
            return ['ok' => true, 'fingerprint' => $fingerprint];
        }

        $ins = $db->prepare(
            'INSERT INTO kyc_submit_locks (merchant_id, action_key, fingerprint, expires_at)
             VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL ? SECOND))'
        );
        $ins->execute([$merchantId, $action, $fingerprint, $ttlSeconds]);
        return ['ok' => true, 'fingerprint' => $fingerprint];
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), '1062')) {
            return [
                'ok' => false,
                'replay' => true,
                'message' => 'Already processing — please wait.',
                'fingerprint' => $fingerprint,
            ];
        }
        return ['ok' => false, 'message' => 'Submit lock unavailable. Retry in a moment.'];
    }
}

function releaseKycSubmitLock(int $merchantId, string $action, string $fingerprint): void
{
    if ($merchantId < 1 || $action === '' || strlen($fingerprint) !== 64) {
        return;
    }
    try {
        ensureKycSubmitGuardTable();
        getDB()->prepare(
            'DELETE FROM kyc_submit_locks WHERE merchant_id=? AND action_key=? AND fingerprint=?'
        )->execute([$merchantId, $action, $fingerprint]);
    } catch (Throwable $e) {
        /* ok */
    }
}
