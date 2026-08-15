<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();

$db = getDB();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    if (!defined('ENCRYPTION_KEY') || ENCRYPTION_KEY === '') {
        flash('error', 'ENCRYPTION_KEY is not configured.');
        redirect('admin_encrypt_pii.php');
    }
    try {
        sensitiveEncrypt('test-key-check');
    } catch (Throwable $e) {
        flash('error', 'ENCRYPTION_KEY is invalid: ' . $e->getMessage());
        redirect('admin_encrypt_pii.php');
    }

    set_time_limit(0);
    ignore_user_abort(true);

    $tables = [
        ['table' => 'merchants', 'column' => 'aadhaar_number', 'id' => 'id'],
        ['table' => 'merchants', 'column' => 'pan_number', 'id' => 'id'],
        ['table' => 'merchants', 'column' => 'gstin', 'id' => 'id'],
        ['table' => 'merchants', 'column' => 'cin_llpin', 'id' => 'id'],
        ['table' => 'merchants', 'column' => 'udyam_number', 'id' => 'id'],
        ['table' => 'merchants', 'column' => 'iec_number', 'id' => 'id'],
        ['table' => 'merchants', 'column' => 'address', 'id' => 'id'],
        ['table' => 'bank_accounts', 'column' => 'account_number', 'id' => 'id'],
        ['table' => 'payout_beneficiaries', 'column' => 'account_number', 'id' => 'id'],
        ['table' => 'kyc_verifications', 'column' => 'doc_number', 'id' => 'id'],
    ];

    $total = 0;
    $failed = 0;
    foreach ($tables as $t) {
        $table = $t['table'];
        $column = $t['column'];
        $id = $t['id'];

        $rows = $db->query(
            "SELECT {$id}, {$column} FROM {$table} WHERE {$column} IS NOT NULL AND {$column} != '' AND {$column} NOT LIKE 'enc:v1:%'"
        )->fetchAll();

        $update = $db->prepare("UPDATE {$table} SET {$column} = ? WHERE {$id} = ?");
        foreach ($rows as $row) {
            try {
                $enc = sensitiveEncrypt($row[$column]);
                $update->execute([$enc, $row[$id]]);
                $total++;

                // C3: Populate search hash columns for merchants table
                if ($table === 'merchants' && function_exists('pii_hash')) {
                    $hashCol = match($column) {
                        'pan_number' => 'pan_hash',
                        'gstin' => 'gstin_hash',
                        'cin_llpin' => 'cin_hash',
                        'aadhaar_number' => 'aadhaar_hash',
                        default => null,
                    };
                    if ($hashCol !== null) {
                        try {
                            $normalized = strtoupper(preg_replace('/\s+/', '', (string)$row[$column]));
                            $db->prepare("UPDATE merchants SET {$hashCol} = ? WHERE id = ?")
                                ->execute([pii_hash($normalized), $row[$id]]);
                        } catch (Throwable $e) { /* hash column may not exist yet */ }
                    }
                }
            } catch (Throwable $e) {
                $failed++;
                error_log("PII backfill failed for {$table} #{$row[$id]}: " . $e->getMessage());
            }
        }
    }

    flash('success', "Encrypted {$total} plaintext value(s). {$failed} failed.");
    redirect('admin_encrypt_pii.php');
}

// Show how many rows still need backfill
$pending = 0;
foreach (['merchants aadhaar_number', 'merchants pan_number', 'merchants gstin', 'merchants cin_llpin', 'merchants udyam_number', 'merchants iec_number', 'merchants address', 'bank_accounts account_number', 'payout_beneficiaries account_number', 'kyc_verifications doc_number'] as $t) {
    [$table, $column] = explode(' ', $t);
    try {
        $st = $db->query("SELECT COUNT(*) FROM {$table} WHERE {$column} IS NOT NULL AND {$column} != '' AND {$column} NOT LIKE 'enc:v1:%'");
        $pending += (int)$st->fetchColumn();
    } catch (Throwable $e) { /* column/table may not exist yet — skip */ }
}

$pageTitle = 'Encrypt PII Backfill';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6 max-w-3xl mx-auto">
    <h2 class="text-xl font-bold">Encrypt PII Backfill</h2>
    <p class="text-sm text-gray-400">This one-time backfill encrypts any remaining plaintext PAN, GST, CIN, Aadhaar, address, bank account numbers and verification numbers. Login email/phone stay plaintext so merchants can still sign in. Run it after you are sure <code>ENCRYPTION_KEY</code> is set and correct.</p>

    <div class="glass rounded-xl p-6">
        <p class="text-sm text-gray-400">Plaintext rows still pending: <strong class="text-amber-400"><?= (int)$pending ?></strong></p>

        <?php if (!defined('ENCRYPTION_KEY') || ENCRYPTION_KEY === ''): ?>
        <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mt-4 text-sm text-red-300">ENCRYPTION_KEY is not configured in <code>config.php</code>.</div>
        <?php else: ?>
        <form method="POST" class="mt-6">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <button type="submit" class="btn-primary px-6 py-3" onclick="return confirm('Encrypt all pending plaintext PII?')">Start Backfill</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
