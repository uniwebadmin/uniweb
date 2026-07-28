<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
require_once __DIR__ . '/includes/qr_events.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'finance', 'ops']);
ensureMerchantQrCodes();

$db = getDB();

const QR_TYPES = ['fixed' => 'Fixed Amount', 'upi_dynamic' => 'Dynamic UPI', 'all_methods' => 'All Methods'];
const QR_TEMPLATES = ['default' => 'Default', 'compact' => 'Compact', 'poster' => 'Poster'];
const NOTIFY_CHANNELS = ['whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'sms' => 'SMS', 'email' => 'Email'];

$currentUser = getAdmin();
$isSuper = isSuperAdmin();

function qrScanUrl(string $code): string
{
    return APP_URL . '/qr_pay.php?code=' . rawurlencode($code);
}

function qrImageUrlEncoded(string $scanUrl, int $size = 200): string
{
    return APP_URL . '/qr_image.php?d=' . rawurlencode(base64_encode(strtr($scanUrl, '+/', '-_')))
        . '&s=' . $size . '&logo=1';
}

function canStaffManageQr(?array $qrMerchant): bool
{
    if ($qrMerchant === null) return false;
    return isSuperAdmin() || staffHasMerchantAccess((int)$qrMerchant['merchant_id']);
}

// ---- Single-row actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bulkIds = array_map('intval', (array)($_POST['qr_ids'] ?? []));
    $bulkAction = trim((string)($_POST['bulk_action'] ?? ''));
    $singleId = (int)($_POST['id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? ''));

    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Security token expired. Refresh and try again.');
        redirect('admin_qr_codes.php');
    }

    // Single edit
    if ($action === 'edit' && $singleId > 0) {
        $qr = $db->prepare('SELECT q.*, m.business_name, m.merchant_code FROM merchant_qr_codes q JOIN merchants m ON m.id=q.merchant_id WHERE q.id=? LIMIT 1');
        $qr->execute([$singleId]);
        $row = $qr->fetch();
        if (!$row || !canStaffManageQr($row)) {
            flash('error', 'QR not found or access denied.');
            redirect('admin_qr_codes.php');
        }
        $label = trim((string)($_POST['label'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $template = in_array($_POST['print_template'] ?? '', array_keys(QR_TEMPLATES), true) ? $_POST['print_template'] : 'default';
        $validFrom = trim((string)($_POST['valid_from'] ?? ''));
        $expiresAt = trim((string)($_POST['expires_at'] ?? ''));
        $notifyOnPay = (int)(($_POST['notify_on_pay'] ?? '0') === '1');
        $channels = array_intersect((array)($_POST['notify_channels'] ?? []), array_keys(NOTIFY_CHANNELS));
        $channelsStr = $channels ? implode(',', $channels) : null;

        $amount = $row['qr_type'] === 'fixed'
            ? sanitizePaymentAmount((float)($_POST['amount'] ?? 0), (bool)$row['is_test'])
            : (float)$row['amount'];

        if ($label === '' || mb_strlen($label) > 120) {
            flash('error', 'Enter a QR name (max 120 characters).');
        } elseif ($row['qr_type'] === 'fixed' && $amount < 1) {
            flash('error', 'Fixed QR amount must be at least ₹1.');
        } else {
            $db->prepare('UPDATE merchant_qr_codes SET label=?, description=?, amount=?, category=?, print_template=?, valid_from=?, expires_at=?, notify_on_pay=?, notify_channels=? WHERE id=?')
                ->execute([
                    $label,
                    $description !== '' ? $description : null,
                    $amount,
                    $category !== '' ? $category : null,
                    $template,
                    $validFrom !== '' ? $validFrom : null,
                    $expiresAt !== '' ? $expiresAt : null,
                    $notifyOnPay,
                    $channelsStr,
                    $singleId,
                ]);
            logQrEvent($db, $singleId, (int)$row['merchant_id'], 'edit', ['staff_id' => $currentUser['id'] ?? null]);
            logStaffActivity('qr_edited', $row['qr_code'] . ' — ' . $label, (int)$row['merchant_id'], 'qr_code', $row['qr_code']);
            flash('success', 'QR updated.');
        }
        redirect('admin_qr_codes.php' . buildQrFilterQs());
    }

    // Single duplicate
    if ($action === 'duplicate' && $singleId > 0) {
        $qr = $db->prepare('SELECT q.*, m.business_name FROM merchant_qr_codes q JOIN merchants m ON m.id=q.merchant_id WHERE q.id=? LIMIT 1');
        $qr->execute([$singleId]);
        $row = $qr->fetch();
        if (!$row || !canStaffManageQr($row)) {
            flash('error', 'QR not found or access denied.');
            redirect('admin_qr_codes.php');
        }
        $newCode = 'QR' . strtoupper(bin2hex(random_bytes(8)));
        $newLabel = $row['label'] . ' (Copy)';
        try {
            $db->prepare('INSERT INTO merchant_qr_codes
                (qr_code, merchant_id, payment_link_id, qr_type, label, amount, description, is_test, status, expires_at, valid_from, notify_on_pay, notify_channels, print_template, category)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $newCode,
                    $row['merchant_id'],
                    null,
                    $row['qr_type'],
                    $newLabel,
                    $row['amount'],
                    $row['description'],
                    $row['is_test'],
                    $row['status'],
                    $row['expires_at'],
                    $row['valid_from'],
                    $row['notify_on_pay'],
                    $row['notify_channels'],
                    $row['print_template'],
                    $row['category'],
                ]);
            $newId = (int)$db->lastInsertId();
            logQrEvent($db, $newId, (int)$row['merchant_id'], 'duplicate', ['source_qr_id' => $singleId]);
            logStaffActivity('qr_duplicated', $newCode . ' from ' . $row['qr_code'], (int)$row['merchant_id'], 'qr_code', $newCode);
            flash('success', 'Duplicated QR: ' . $newLabel);
        } catch (Throwable $e) {
            logPlatformError('error', 'QR duplicate failed: ' . $e->getMessage());
            flash('error', 'Could not duplicate QR.');
        }
        redirect('admin_qr_codes.php' . buildQrFilterQs());
    }

    // Single delete
    if ($action === 'delete' && $singleId > 0) {
        $qr = $db->prepare('SELECT q.* FROM merchant_qr_codes q JOIN merchants m ON m.id=q.merchant_id WHERE q.id=? LIMIT 1');
        $qr->execute([$singleId]);
        $row = $qr->fetch();
        if (!$row || !canStaffManageQr($row)) {
            flash('error', 'QR not found or access denied.');
            redirect('admin_qr_codes.php');
        }
        try {
            $db->beginTransaction();
            $db->prepare('DELETE FROM qr_code_events WHERE qr_code_id=?')->execute([$singleId]);
            $db->prepare('DELETE FROM merchant_qr_codes WHERE id=?')->execute([$singleId]);
            $db->commit();
            logStaffActivity('qr_deleted', $row['qr_code'], (int)$row['merchant_id'], 'qr_code', $row['qr_code']);
            flash('success', 'QR deleted.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            logPlatformError('error', 'QR delete failed: ' . $e->getMessage());
            flash('error', 'Could not delete QR.');
        }
        redirect('admin_qr_codes.php' . buildQrFilterQs());
    }

    // Bulk actions
    if (!empty($bulkIds) && in_array($bulkAction, ['enable', 'disable', 'delete'], true)) {
        $placeholders = implode(',', array_fill(0, count($bulkIds), '?'));
        $rows = $db->prepare("SELECT q.* FROM merchant_qr_codes q JOIN merchants m ON m.id=q.merchant_id WHERE q.id IN ($placeholders)")
            ->execute($bulkIds)
            ->fetchAll();
        $allowed = array_values(array_filter($rows, static fn($r) => canStaffManageQr($r)));
        $allowedIds = array_map(static fn($r) => (int)$r['id'], $allowed);
        if (empty($allowedIds)) {
            flash('error', 'No selected QR codes found or access denied.');
            redirect('admin_qr_codes.php' . buildQrFilterQs());
        }
        $placeholders = implode(',', array_fill(0, count($allowedIds), '?'));
        try {
            if ($bulkAction === 'enable') {
                $db->prepare("UPDATE merchant_qr_codes SET status='active' WHERE id IN ($placeholders)")->execute($allowedIds);
                foreach ($allowed as $r) logQrEvent($db, (int)$r['id'], (int)$r['merchant_id'], 'enable', ['bulk' => true]);
                flash('success', count($allowedIds) . ' QR(s) enabled.');
            } elseif ($bulkAction === 'disable') {
                $db->prepare("UPDATE merchant_qr_codes SET status='inactive' WHERE id IN ($placeholders)")->execute($allowedIds);
                foreach ($allowed as $r) logQrEvent($db, (int)$r['id'], (int)$r['merchant_id'], 'disable', ['bulk' => true]);
                flash('success', count($allowedIds) . ' QR(s) disabled.');
            } elseif ($bulkAction === 'delete') {
                $db->beginTransaction();
                $db->prepare("DELETE FROM qr_code_events WHERE qr_code_id IN ($placeholders)")->execute($allowedIds);
                $db->prepare("DELETE FROM merchant_qr_codes WHERE id IN ($placeholders)")->execute($allowedIds);
                $db->commit();
                flash('success', count($allowedIds) . ' QR(s) deleted.');
            }
            logStaffActivity('qr_bulk_' . $bulkAction, 'count=' . count($allowedIds), 0, 'qr_code', '');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            logPlatformError('error', 'QR bulk ' . $bulkAction . ' failed: ' . $e->getMessage());
            flash('error', 'Bulk action failed.');
        }
        redirect('admin_qr_codes.php' . buildQrFilterQs());
    }
}

// ---- Helpers for filters & export ----
function buildQrFilterQs(): string
{
    $keep = array_filter([
        'q' => trim((string)($_GET['q'] ?? '')),
        'status' => trim((string)($_GET['status'] ?? 'all')),
        'type' => trim((string)($_GET['type'] ?? 'all')),
        'merchant_id' => (int)($_GET['merchant_id'] ?? 0),
        'mode' => trim((string)($_GET['mode'] ?? 'all')),
        'from' => trim((string)($_GET['from'] ?? '')),
        'to' => trim((string)($_GET['to'] ?? '')),
        'page' => max(1, (int)($_GET['page'] ?? 1)),
    ], static fn($v) => $v !== '' && $v !== 0 && $v !== 'all');
    return $keep ? '?' . http_build_query($keep) : '';
}

$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$statusFilter = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : 'all';
$typeFilter = in_array($_GET['type'] ?? '', array_keys(QR_TYPES), true) ? $_GET['type'] : 'all';
$modeFilter = in_array($_GET['mode'] ?? '', ['live', 'test'], true) ? $_GET['mode'] : 'all';
$merchantFilter = (int)($_GET['merchant_id'] ?? 0);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$where = ["q.qr_type != 'instant_upi'"];
$params = [];
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $where[] = "(LOWER(q.qr_code) LIKE ? OR LOWER(q.label) LIKE ? OR LOWER(q.description) LIKE ? OR LOWER(m.business_name) LIKE ? OR LOWER(m.merchant_code) LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like);
}
if ($statusFilter !== 'all') {
    $where[] = 'q.status = ?';
    $params[] = $statusFilter;
}
if ($typeFilter !== 'all') {
    $where[] = 'q.qr_type = ?';
    $params[] = $typeFilter;
}
if ($modeFilter !== 'all') {
    $where[] = 'q.is_test = ?';
    $params[] = $modeFilter === 'test' ? 1 : 0;
}
if ($merchantFilter > 0) {
    $where[] = 'q.merchant_id = ?';
    $params[] = $merchantFilter;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'DATE(q.created_at) >= ?';
    $params[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'DATE(q.created_at) <= ?';
    $params[] = $to;
}
$whereSql = implode(' AND ', $where);

// Fetch paginated rows
$countStmt = $db->prepare("SELECT COUNT(*) FROM merchant_qr_codes q JOIN merchants m ON m.id=q.merchant_id WHERE $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT q.*, m.business_name, m.merchant_code, m.phone AS merchant_phone, m.email AS merchant_email
    FROM merchant_qr_codes q
    JOIN merchants m ON m.id=q.merchant_id
    WHERE $whereSql
    ORDER BY q.created_at DESC
    LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$qrCodes = $stmt->fetchAll();

if (!$isSuper) {
    $qrCodes = array_values(array_filter($qrCodes, static fn($r) => staffHasMerchantAccess((int)$r['merchant_id'])));
}

// Bulk summary stats
$qrIds = array_map(static fn($r) => (int)$r['id'], $qrCodes);
$paidSummary = [];
$eventSummary = [];
if (!empty($qrIds)) {
    $ph = implode(',', array_fill(0, count($qrIds), '?'));
    $sumStmt = $db->prepare("SELECT pl.qr_code_id AS qid, COUNT(*) AS paid_count, COALESCE(SUM(t.amount),0) AS paid_total
        FROM transactions t
        JOIN payment_links pl ON pl.id=t.payment_link_id
        WHERE t.status='success' AND pl.qr_code_id IN ($ph)
        GROUP BY pl.qr_code_id");
    $sumStmt->execute($qrIds);
    foreach ($sumStmt->fetchAll() as $row) {
        $paidSummary[(int)$row['qid']] = $row;
    }
    $evtStmt = $db->prepare("SELECT qr_code_id AS qid, event_type, COUNT(*) AS c
        FROM qr_code_events
        WHERE qr_code_id IN ($ph)
        GROUP BY qr_code_id, event_type");
    $evtStmt->execute($qrIds);
    foreach ($evtStmt->fetchAll() as $row) {
        $eventSummary[(int)$row['qid']][$row['event_type']] = (int)$row['c'];
    }
}

// Aggregates for header analytics
$aggStmt = $db->query("SELECT
    COUNT(*) AS total_qrs,
    SUM(CASE WHEN q.status='active' THEN 1 ELSE 0 END) AS active_qrs,
    SUM(q.scan_count) AS total_scans,
    COALESCE(SUM(paid.total), 0) AS total_collected,
    COALESCE(SUM(paid.cnt), 0) AS total_payments
FROM merchant_qr_codes q
LEFT JOIN (SELECT pl.qr_code_id, COUNT(*) AS cnt, SUM(t.amount) AS total
    FROM transactions t JOIN payment_links pl ON pl.id=t.payment_link_id
    WHERE t.status='success' GROUP BY pl.qr_code_id) paid ON paid.qr_code_id=q.id");
$agg = $aggStmt ? $aggStmt->fetch() : [];

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $db->prepare("SELECT q.*, m.business_name, m.merchant_code
        FROM merchant_qr_codes q JOIN merchants m ON m.id=q.merchant_id
        WHERE $whereSql ORDER BY q.created_at DESC");
    $exportStmt->execute($params);
    $rows = [];
    foreach ($exportStmt->fetchAll() as $r) {
        $rows[] = [
            $r['qr_code'],
            $r['merchant_code'],
            $r['business_name'],
            QR_TYPES[$r['qr_type']] ?? $r['qr_type'],
            $r['is_test'] ? 'Test' : 'Live',
            $r['status'],
            $r['amount'],
            $r['scan_count'],
            $r['label'],
            $r['created_at'],
        ];
    }
    sendCsvDownload(['QR Code', 'Merchant Code', 'Business', 'Type', 'Mode', 'Status', 'Amount', 'Scans', 'Label', 'Created'], $rows, 'admin-qr-codes-' . date('Y-m-d') . '.csv');
}

$filterMerchant = null;
if ($merchantFilter > 0) {
    $fm = $db->prepare('SELECT id, merchant_code, business_name FROM merchants WHERE id=?');
    $fm->execute([$merchantFilter]);
    $filterMerchant = $fm->fetch() ?: null;
}

$editQr = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $eq = $db->prepare('SELECT q.*, m.business_name, m.merchant_code FROM merchant_qr_codes q JOIN merchants m ON m.id=q.merchant_id WHERE q.id=? LIMIT 1');
    $eq->execute([(int)$_GET['edit']]);
    $editQr = $eq->fetch();
    if ($editQr && !canStaffManageQr($editQr)) $editQr = null;
}

$pageTitle = 'QR Codes';
require_once __DIR__ . '/header.php';
?>

<div class="flex flex-wrap items-start justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold">QR Codes</h1>
        <p class="text-sm text-gray-500 mt-1">Manage all merchant QR codes in one place — bulk actions, analytics, edit and share.</p>
    </div>
    <a href="qr_code.php" target="_blank" class="btn-primary text-sm px-4 py-2.5">Merchant QR Portal</a>
</div>

<?php if ($filterMerchant): ?>
<div class="mb-4 glass rounded-xl px-4 py-3 flex flex-wrap items-center justify-between gap-2 border border-sky-500/30">
    <p class="text-sm text-gray-300">QR codes for <span class="font-mono text-sky-400"><?= e($filterMerchant['merchant_code']) ?></span> — <?= e($filterMerchant['business_name']) ?></p>
    <a href="admin_qr_codes.php" class="text-xs text-gray-400 hover:text-white">Clear filter</a>
</div>
<?php endif; ?>

<!-- Analytics cards -->
<div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="glass rounded-xl p-4 border border-gray-800">
        <p class="text-[10px] uppercase text-gray-500">Total QRs</p>
        <p class="text-xl font-bold"><?= (int)($agg['total_qrs'] ?? 0) ?></p>
    </div>
    <div class="glass rounded-xl p-4 border border-emerald-500/20">
        <p class="text-[10px] uppercase text-emerald-400/70">Active</p>
        <p class="text-xl font-bold text-emerald-300"><?= (int)($agg['active_qrs'] ?? 0) ?></p>
    </div>
    <div class="glass rounded-xl p-4 border border-gray-800">
        <p class="text-[10px] uppercase text-gray-500">Scans</p>
        <p class="text-xl font-bold"><?= (int)($agg['total_scans'] ?? 0) ?></p>
    </div>
    <div class="glass rounded-xl p-4 border border-sky-500/20">
        <p class="text-[10px] uppercase text-sky-400/70">Payments</p>
        <p class="text-xl font-bold text-sky-300"><?= (int)($agg['total_payments'] ?? 0) ?></p>
    </div>
    <div class="glass rounded-xl p-4 border border-violet-500/20">
        <p class="text-[10px] uppercase text-violet-400/70">Collected</p>
        <p class="text-xl font-bold text-violet-300"><?= formatMoney((float)($agg['total_collected'] ?? 0)) ?></p>
    </div>
</div>

<?= uxListToolbar(uxExportCsvLink(array_filter([
    'q' => $q ?: null,
    'status' => $statusFilter !== 'all' ? $statusFilter : null,
    'type' => $typeFilter !== 'all' ? $typeFilter : null,
    'mode' => $modeFilter !== 'all' ? $modeFilter : null,
    'merchant_id' => $merchantFilter ?: null,
    'from' => $from ?: null,
    'to' => $to ?: null,
]))) ?>

<!-- Filter bar -->
<form method="GET" class="glass rounded-xl p-4 mb-6 border border-gray-800 flex flex-wrap gap-3 items-end">
    <div class="flex-1 min-w-[220px]">
        <label class="text-[10px] text-gray-600 uppercase">Search</label>
        <input name="q" value="<?= e($q) ?>" class="input-field mt-1 text-sm" placeholder="QR code / label / merchant / code" autocomplete="off">
    </div>
    <div>
        <label class="text-[10px] text-gray-600 uppercase">Status</label>
        <select name="status" class="input-field mt-1 text-sm">
            <option value="all">All</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div>
        <label class="text-[10px] text-gray-600 uppercase">Type</label>
        <select name="type" class="input-field mt-1 text-sm">
            <option value="all">All</option>
            <?php foreach (QR_TYPES as $k => $l): ?>
            <option value="<?= e($k) ?>" <?= $typeFilter === $k ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="text-[10px] text-gray-600 uppercase">Mode</label>
        <select name="mode" class="input-field mt-1 text-sm">
            <option value="all">All</option>
            <option value="live" <?= $modeFilter === 'live' ? 'selected' : '' ?>>Live</option>
            <option value="test" <?= $modeFilter === 'test' ? 'selected' : '' ?>>Test</option>
        </select>
    </div>
    <div>
        <label class="text-[10px] text-gray-600 uppercase">From</label>
        <input type="date" name="from" value="<?= e($from) ?>" class="input-field mt-1 text-sm">
    </div>
    <div>
        <label class="text-[10px] text-gray-600 uppercase">To</label>
        <input type="date" name="to" value="<?= e($to) ?>" class="input-field mt-1 text-sm">
    </div>
    <button class="btn-primary px-4 py-2.5 text-sm">Filter</button>
    <a href="admin_qr_codes.php" class="text-sm text-gray-400 hover:text-white px-2 py-2.5">Reset</a>
</form>

<!-- Bulk actions toolbar -->
<form method="POST" id="qr-bulk-form" class="mb-4">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <div class="flex flex-wrap items-center gap-3 no-print">
        <select name="bulk_action" class="input-field text-sm" required>
            <option value="">Bulk action</option>
            <option value="enable">Enable</option>
            <option value="disable">Disable</option>
            <option value="delete">Delete</option>
        </select>
        <button type="submit" class="btn-primary text-sm px-4 py-2.5" onclick="return confirm('Apply bulk action to selected QR codes?')">Apply</button>
        <a href="qr_download_zip.php<?= ($merchantFilter ? '?merchant_id=' . $merchantFilter : '') ?>" class="glass px-3 py-2 rounded-lg text-xs text-emerald-400 hover:text-emerald-300">Download ZIP</a>
        <a href="admin_qr_codes.php?export=csv<?= ($q ? '&q=' . rawurlencode($q) : '') . ($statusFilter !== 'all' ? '&status=' . $statusFilter : '') . ($typeFilter !== 'all' ? '&type=' . $typeFilter : '') . ($modeFilter !== 'all' ? '&mode=' . $modeFilter : '') . ($merchantFilter ? '&merchant_id=' . $merchantFilter : '') . ($from ? '&from=' . $from : '') . ($to ? '&to=' . $to : '') ?>" class="glass px-3 py-2 rounded-lg text-xs text-brand-400 hover:text-brand-300 ml-auto">Export CSV</a>
    </div>

    <div class="glass rounded-xl overflow-hidden mt-4">
        <?php if (empty($qrCodes)): ?>
            <?= uxEmptyState('No QR codes found', 'Try clearing filters or creating a QR from the merchant portal.') ?>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[900px]">
                <?= uxTableCaption('Admin QR code list') ?>
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-4 py-3 text-left w-10"><input type="checkbox" id="select-all" class="rounded bg-dark-900 border-gray-700"></th>
                    <th class="px-4 py-3 text-left">Merchant</th>
                    <th class="px-4 py-3 text-left">QR</th>
                    <th class="px-4 py-3 text-left">Type</th>
                    <th class="px-4 py-3 text-left">Amount</th>
                    <th class="px-4 py-3 text-left">Analytics</th>
                    <th class="px-4 py-3 text-left">Status</th>
                    <th class="px-4 py-3 text-left">Created</th>
                    <th class="px-4 py-3 text-left no-print">Actions</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($qrCodes as $qr):
                        $scanUrl = qrScanUrl($qr['qr_code']);
                        $qrImage = qrImageUrlEncoded($scanUrl, 200);
                        $paid = $paidSummary[(int)$qr['id']] ?? null;
                        $paidCount = $paid ? (int)$paid['paid_count'] : 0;
                        $paidTotal = $paid ? (float)$paid['paid_total'] : 0.0;
                        $scans = (int)$qr['scan_count'];
                        $conversion = $scans > 0 ? round(($paidCount / $scans) * 100, 1) : 0;
                        $ev = $eventSummary[(int)$qr['id']] ?? [];
                        $isFixed = $qr['qr_type'] === 'fixed';
                        $expired = !empty($qr['expires_at']) && strtotime($qr['expires_at']) < time();
                        $notYetValid = !empty($qr['valid_from']) && strtotime($qr['valid_from']) > time();
                    ?>
                    <tr class="hover:bg-white/5">
                        <td class="px-4 py-3"><input type="checkbox" name="qr_ids[]" value="<?= (int)$qr['id'] ?>" class="qr-row-check rounded bg-dark-900 border-gray-700"></td>
                        <td class="px-4 py-3">
                            <a href="admin_view_merchant.php?id=<?= (int)$qr['merchant_id'] ?>" class="text-sky-400 hover:underline block font-medium"><?= e($qr['business_name']) ?></a>
                            <span class="text-xs text-gray-500 font-mono"><?= e($qr['merchant_code']) ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <img src="<?= e($qrImage) ?>" alt="" width="48" height="48" class="rounded bg-white p-0.5">
                                <div>
                                    <p class="font-semibold"><?= e($qr['label']) ?></p>
                                    <p class="font-mono text-xs text-gray-500"><?= e($qr['qr_code']) ?></p>
                                    <?php if ($qr['category']): ?><span class="text-[10px] bg-gray-800 px-1.5 py-0.5 rounded text-gray-400"><?= e($qr['category']) ?></span><?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <?= QR_TYPES[$qr['qr_type']] ?? e($qr['qr_type']) ?><br>
                            <span class="text-gray-500"><?= $qr['is_test'] ? 'Test' : 'Live' ?></span>
                        </td>
                        <td class="px-4 py-3 font-semibold"><?= $isFixed ? formatMoney((float)$qr['amount']) : '<span class="text-gray-500">Open</span>' ?></td>
                        <td class="px-4 py-3 text-xs">
                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div><p class="font-bold"><?= $scans ?></p><p class="text-gray-500">Scans</p></div>
                                <div><p class="font-bold text-emerald-400"><?= $paidCount ?></p><p class="text-gray-500">Paid</p></div>
                                <div><p class="font-bold text-violet-400"><?= $conversion ?>%</p><p class="text-gray-500">Conv.</p></div>
                            </div>
                            <p class="text-[10px] text-gray-500 mt-1">Collected <?= formatMoney($paidTotal) ?></p>
                        </td>
                        <td class="px-4 py-3">
                            <?= statusBadge($qr['status']) ?>
                            <?php if ($expired): ?><span class="block text-[10px] text-red-400 mt-1">Expired</span><?php endif; ?>
                            <?php if ($notYetValid): ?><span class="block text-[10px] text-amber-400 mt-1">Scheduled</span><?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-400"><?= e(date('d M Y H:i', strtotime($qr['created_at']))) ?></td>
                        <td class="px-4 py-3 text-xs no-print">
                            <div class="flex flex-wrap gap-2">
                                <a href="admin_qr_codes.php?edit=<?= (int)$qr['id'] ?>" class="px-2 py-1 rounded bg-gray-800 text-sky-400 hover:bg-gray-700">Edit</a>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="duplicate">
                                    <input type="hidden" name="id" value="<?= (int)$qr['id'] ?>">
                                    <button type="submit" class="px-2 py-1 rounded bg-gray-800 text-emerald-400 hover:bg-gray-700">Duplicate</button>
                                </form>
                                <a href="transactions.php?qr_id=<?= (int)$qr['id'] ?>" target="_blank" class="px-2 py-1 rounded bg-gray-800 text-violet-400 hover:bg-gray-700">Payments</a>
                                <button type="button" class="px-2 py-1 rounded bg-gray-800 text-amber-400 hover:bg-gray-700" onclick="shareQr('<?= e($qr['qr_code']) ?>','<?= e(rawurlencode($scanUrl)) ?>','<?= e(rawurlencode($qr['merchant_phone'] ?? '')) ?>','<?= e(rawurlencode($qr['merchant_email'] ?? '')) ?>')">Share</button>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete this QR permanently?')">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$qr['id'] ?>">
                                    <button type="submit" class="px-2 py-1 rounded bg-gray-800 text-red-400 hover:bg-gray-700">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= uxPageNav($page, $totalPages, array_filter(['q' => $q ?: null, 'status' => $statusFilter !== 'all' ? $statusFilter : null, 'type' => $typeFilter !== 'all' ? $typeFilter : null, 'mode' => $modeFilter !== 'all' ? $modeFilter : null, 'merchant_id' => $merchantFilter ?: null, 'from' => $from ?: null, 'to' => $to ?: null])) ?>
        <?php endif; ?>
    </div>
</form>

<?php if ($editQr): ?>
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" id="edit-modal">
    <div class="glass rounded-2xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto border border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold">Edit QR — <?= e($editQr['qr_code']) ?></h2>
            <a href="admin_qr_codes.php<?= buildQrFilterQs() ?>" class="text-gray-400 hover:text-white">Close</a>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int)$editQr['id'] ?>">
            <div class="space-y-4">
                <div>
                    <label class="text-sm text-gray-400">QR Name *</label>
                    <input type="text" name="label" value="<?= e($editQr['label']) ?>" maxlength="120" required class="input-field mt-1">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Description</label>
                    <input type="text" name="description" value="<?= e($editQr['description'] ?? '') ?>" maxlength="255" class="input-field mt-1">
                </div>
                <?php if ($editQr['qr_type'] === 'fixed'): ?>
                <div>
                    <label class="text-sm text-gray-400">Fixed Amount (₹)</label>
                    <input type="number" name="amount" value="<?= (float)$editQr['amount'] ?>" min="1" step="0.01" required class="input-field mt-1">
                </div>
                <?php endif; ?>
                <div>
                    <label class="text-sm text-gray-400">Category</label>
                    <input type="text" name="category" value="<?= e($editQr['category'] ?? '') ?>" maxlength="64" class="input-field mt-1" placeholder="e.g. Counter, Branch">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm text-gray-400">Valid From</label>
                        <input type="datetime-local" name="valid_from" value="<?= e($editQr['valid_from'] ? date('Y-m-d\TH:i', strtotime($editQr['valid_from'])) : '') ?>" class="input-field mt-1">
                    </div>
                    <div>
                        <label class="text-sm text-gray-400">Expires At</label>
                        <input type="datetime-local" name="expires_at" value="<?= e($editQr['expires_at'] ? date('Y-m-d\TH:i', strtotime($editQr['expires_at'])) : '') ?>" class="input-field mt-1">
                        <p class="text-[10px] text-gray-600 mt-1">Leave blank for No Expiry (QR stays active forever).</p>
                    </div>
                </div>
                <div>
                    <label class="text-sm text-gray-400">Print Template</label>
                    <select name="print_template" class="input-field mt-1">
                        <?php foreach (QR_TEMPLATES as $k => $l): ?>
                        <option value="<?= e($k) ?>" <?= ($editQr['print_template'] ?? 'default') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="border border-gray-800 rounded-xl p-4">
                    <label class="flex items-center gap-2 mb-3">
                        <input type="checkbox" name="notify_on_pay" value="1" <?= !empty($editQr['notify_on_pay']) ? 'checked' : '' ?> class="rounded bg-dark-900 border-gray-700">
                        <span class="text-sm">Notify on payment</span>
                    </label>
                    <p class="text-xs text-gray-500 mb-2">Channels (configure gateway keys for WhatsApp/SMS first)</p>
                    <div class="flex flex-wrap gap-3">
                        <?php foreach (NOTIFY_CHANNELS as $k => $l): ?>
                        <label class="flex items-center gap-1.5 text-sm text-gray-400">
                            <input type="checkbox" name="notify_channels[]" value="<?= e($k) ?>" <?= in_array($k, explode(',', (string)($editQr['notify_channels'] ?? '')), true) ? 'checked' : '' ?> class="rounded bg-dark-900 border-gray-700">
                            <?= e($l) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="btn-primary flex-1 py-2.5">Save Changes</button>
                    <a href="admin_qr_codes.php<?= buildQrFilterQs() ?>" class="px-4 py-2.5 rounded-xl border border-gray-700 text-gray-400 hover:text-white">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="share-menu" class="hidden fixed z-50 bg-dark-900 border border-gray-700 rounded-xl p-3 shadow-xl min-w-[220px]">
    <p class="text-sm font-semibold mb-2 px-2" id="share-title">Share QR</p>
    <div class="space-y-1" id="share-links"></div>
    <button type="button" onclick="document.getElementById('share-menu').classList.add('hidden')" class="w-full text-left px-2 py-1.5 text-xs text-gray-500 hover:text-white mt-1">Close</button>
</div>

<script>
document.getElementById('select-all')?.addEventListener('change', function () {
    document.querySelectorAll('.qr-row-check').forEach(cb => cb.checked = this.checked);
});

function shareQr(code, encodedUrl, phone, email) {
    const url = decodeURIComponent(encodedUrl);
    const cleanPhone = decodeURIComponent(phone).replace(/\D/g, '');
    const cleanEmail = decodeURIComponent(email);
    const text = 'Pay via QR: ' + url;
    const menu = document.getElementById('share-menu');
    const links = document.getElementById('share-links');
    const title = document.getElementById('share-title');
    title.textContent = 'Share ' + code;
    let html = '';
    html += '<a href="' + (cleanPhone.length >= 10 ? ('https://wa.me/' + cleanPhone) : 'https://api.whatsapp.com/send') + '?text=' + encodeURIComponent(text) + '" target="_blank" class="block px-2 py-1.5 rounded hover:bg-white/5 text-sm text-emerald-400">WhatsApp</a>';
    html += '<a href="https://t.me/share/url?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent('Pay via QR') + '" target="_blank" class="block px-2 py-1.5 rounded hover:bg-white/5 text-sm text-sky-400">Telegram</a>';
    html += cleanEmail ? '<a href="mailto:' + cleanEmail + '?subject=' + encodeURIComponent('QR Payment Link') + '&body=' + encodeURIComponent(text) + '" class="block px-2 py-1.5 rounded hover:bg-white/5 text-sm text-amber-400">Email</a>' : '';
    html += cleanPhone.length >= 10 ? '<a href="sms:' + cleanPhone + '?body=' + encodeURIComponent(text) + '" class="block px-2 py-1.5 rounded hover:bg-white/5 text-sm text-violet-400">SMS</a>' : '';
    html += '<button type="button" onclick="navigator.clipboard.writeText(\'' + url.replace(/'/g, "\\'") + '\');this.textContent=\'Copied!\';setTimeout(()=>this.textContent=\'Copy Link\',1500)" class="w-full text-left px-2 py-1.5 rounded hover:bg-white/5 text-sm text-gray-300">Copy Link</button>';
    links.innerHTML = html;
    menu.classList.remove('hidden');
    const rect = event.target.getBoundingClientRect();
    menu.style.top = (rect.bottom + window.scrollY + 8) + 'px';
    menu.style.left = Math.min(rect.left + window.scrollX, window.innerWidth - 240) + 'px';
}

window.addEventListener('click', function (e) {
    const menu = document.getElementById('share-menu');
    if (!menu.contains(e.target) && !e.target.matches('[onclick^="shareQr"]')) menu.classList.add('hidden');
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
