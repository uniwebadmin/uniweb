<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();

$pageTitle = 'Reason Map Manager';
$adminSection = 'financial';

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'upsert') {
        $errorCode = trim($_POST['error_code'] ?? '');
        $messageEn = trim($_POST['message_en'] ?? '');
        $messageHi = trim($_POST['message_hi'] ?? '');
        $category = trim($_POST['category'] ?? 'other');
        if ($errorCode && $messageEn) {
            $ok = upsertDbReasonMap($errorCode, $messageEn, $messageHi ?: null, $category);
            $result = $ok ? ['ok' => true, 'message' => 'Reason map saved.'] : ['ok' => false, 'error' => 'Failed to save.'];
        } else {
            $error = 'Error code and English message are required.';
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $active = ($_POST['active'] ?? '0') === '1';
        $ok = toggleDbReasonMap($id, $active);
        $result = $ok ? ['ok' => true, 'message' => 'Status updated.'] : ['ok' => false, 'error' => 'Failed.'];
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $ok = deleteDbReasonMap($id);
        $result = $ok ? ['ok' => true, 'message' => 'Reason map deleted.'] : ['ok' => false, 'error' => 'Could not delete.'];
    }

    if ($result) {
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    }
    if ($error) {
        flash('error', $error);
    }
    redirect('admin_reason_map.php');
}

$reasonMaps = getAllDbReasonMaps();
$categories = ['funds', 'timeout', 'risk', 'decline', 'limit', 'cancel', 'settlement', 'upi', 'other'];

require_once __DIR__ . '/header.php';
?>
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-white mb-2">Reason Map Manager</h1>
    <p class="text-sm text-gray-500 mb-2">Map gateway error codes to merchant-facing messages. English + Hindi. DB-backed, admin-editable.</p>
    <p class="text-xs text-gray-600 mb-6">Common codes (e.g. insufficient funds, timeout) are <strong class="text-gray-400">auto-loaded</strong> from the system seed list. Add rows here only for <strong class="text-gray-400">new partner codes</strong> not covered yet — you do not need to type every error by hand.</p>

    <!-- Add / Edit form -->
    <div class="glass rounded-xl p-6 border border-sky-500/20 mb-8">
        <h2 class="font-semibold text-lg mb-4">Add / Update Reason Map</h2>
        <form method="POST" class="grid md:grid-cols-2 gap-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="upsert">
            <div>
                <label class="text-xs text-gray-500">Error Code</label>
                <input type="text" name="error_code" required class="input-field text-sm mt-1" placeholder="INSUFFICIENT_FUNDS" style="text-transform:uppercase">
            </div>
            <div>
                <label class="text-xs text-gray-500">Category</label>
                <select name="category" class="input-field text-sm mt-1">
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c) ?>"><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-xs text-gray-500">English Message</label>
                <input type="text" name="message_en" required class="input-field text-sm mt-1" placeholder="Insufficient balance in the customer's account.">
            </div>
            <div>
                <label class="text-xs text-gray-500">Hindi Message (हिंदी)</label>
                <input type="text" name="message_hi" class="input-field text-sm mt-1" placeholder="ग्राहक के खाते में पर्याप्त बैलेंस नहीं है।">
            </div>
            <div class="md:col-span-2">
                <button type="submit" class="bg-sky-600 hover:bg-sky-700 text-white rounded-lg px-6 py-2.5 text-sm font-medium">Save →</button>
            </div>
        </form>
    </div>

    <!-- Existing maps -->
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Existing Reason Maps (<?= count($reasonMaps) ?>)</h2></div>
        <?php if (empty($reasonMaps)): ?>
        <div class="p-8 text-center text-gray-500 text-sm">No reason maps in DB yet. Add one above or run migration 044 to seed defaults.</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Code</th><th class="px-4 py-3 text-left">Category</th>
                <th class="px-4 py-3 text-left">English</th><th class="px-4 py-3 text-left">Hindi</th>
                <th class="px-4 py-3 text-left">Active</th><th class="px-4 py-3 text-left">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($reasonMaps as $rm): ?>
                <tr>
                    <td class="px-4 py-3 font-mono text-xs text-sky-400"><?= e($rm['error_code']) ?></td>
                    <td class="px-4 py-3 text-xs"><span class="px-2 py-0.5 rounded bg-gray-700 text-gray-300"><?= e($rm['category']) ?></span></td>
                    <td class="px-4 py-3 text-xs text-gray-300 max-w-xs truncate" title="<?= e($rm['message_en']) ?>"><?= e(mb_substr($rm['message_en'], 0, 60)) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400 max-w-xs truncate" title="<?= e($rm['message_hi'] ?? '') ?>"><?= e(mb_substr($rm['message_hi'] ?? '—', 0, 60)) ?></td>
                    <td class="px-4 py-3 text-xs <?= (int)$rm['is_active'] ? 'text-emerald-400' : 'text-red-400' ?>"><?= (int)$rm['is_active'] ? 'Yes' : 'No' ?></td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <form method="POST" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$rm['id'] ?>">
                            <input type="hidden" name="active" value="<?= (int)$rm['is_active'] ? '0' : '1' ?>">
                            <button type="submit" class="text-xs <?= (int)$rm['is_active'] ? 'text-red-400' : 'text-emerald-400' ?> hover:underline">
                                <?= (int)$rm['is_active'] ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                        <form method="POST" class="inline ml-3" onsubmit="return confirm('Delete this reason map permanently?')">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$rm['id'] ?>">
                            <button type="submit" class="text-xs text-red-400 hover:underline">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
