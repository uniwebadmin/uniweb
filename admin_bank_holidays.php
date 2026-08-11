<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bank_holidays.php';
requireStaffAccess(['super', 'ceo', 'ops']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_holiday') {
        $res = addBankHoliday(
            trim($_POST['holiday_date'] ?? ''),
            trim($_POST['holiday_name'] ?? ''),
            trim($_POST['holiday_type'] ?? 'custom'),
            trim($_POST['state'] ?? '') ?: null
        );
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : $res['error']);
    } elseif ($action === 'remove_holiday') {
        $res = removeBankHoliday((int)($_POST['holiday_id'] ?? 0));
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : $res['error']);
    } elseif ($action === 'seed_holidays') {
        $year = (int)($_POST['year'] ?? (int)date('Y'));
        $res = seedDefaultHolidays($year);
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Failed'));
    }
    redirect('admin_bank_holidays.php');
}

$year = (int)($_GET['year'] ?? (int)date('Y'));
$holidays = getBankHolidays($year);
$upcoming = getUpcomingHolidays(30);

$pageTitle = 'Bank Holiday Calendar';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-6xl mx-auto px-4 py-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Bank Holiday Calendar</h1>
            <p class="text-sm text-gray-500 mt-1">RBI holidays + weekends. Settlement engine skips these days automatically.</p>
        </div>
        <div class="flex gap-2">
            <?php for ($y = (int)date('Y'); $y <= (int)date('Y') + 2; $y++): ?>
            <a href="admin_bank_holidays.php?year=<?= $y ?>" class="text-sm px-3 py-1.5 rounded-lg border <?= $y === $year ? 'border-emerald-500/40 text-emerald-400' : 'border-gray-700 text-gray-400' ?>"><?= $y ?></a>
            <?php endfor; ?>
        </div>
    </div>

    <?php if (!empty($upcoming)): ?>
    <div class="bg-amber-500/5 border border-amber-500/20 rounded-xl p-4 mb-6">
        <h3 class="text-sm font-bold text-amber-400 mb-2">Upcoming Holidays (Next 30 Days)</h3>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($upcoming as $h): ?>
            <span class="text-xs bg-gray-800/50 px-3 py-1.5 rounded-lg text-gray-300"><?= e($h['holiday_date']) ?> — <?= e($h['holiday_name']) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-6">
            <h2 class="text-lg font-bold text-white mb-4">Add Holiday</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="add_holiday">
                <div class="mb-3">
                    <label class="block text-sm text-gray-400 mb-1">Date</label>
                    <input type="date" name="holiday_date" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                </div>
                <div class="mb-3">
                    <label class="block text-sm text-gray-400 mb-1">Holiday Name</label>
                    <input type="text" name="holiday_name" required class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                </div>
                <div class="mb-3">
                    <label class="block text-sm text-gray-400 mb-1">Type</label>
                    <select name="holiday_type" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                        <option value="national">National</option>
                        <option value="festival">Festival</option>
                        <option value="state">State</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="block text-sm text-gray-400 mb-1">State (optional)</label>
                    <input type="text" name="state" placeholder="e.g. Maharashtra" class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-white text-sm">
                </div>
                <button class="text-sm text-emerald-400 border border-emerald-500/40 px-4 py-2 rounded-lg w-full">Add Holiday</button>
            </form>

            <div class="mt-4 pt-4 border-t border-gray-800">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="seed_holidays">
                    <input type="hidden" name="year" value="<?= $year ?>">
                    <button class="text-sm text-blue-400 border border-blue-500/40 px-4 py-2 rounded-lg w-full" onclick="return confirm('Seed default RBI holidays for <?= $year ?>?')">Seed RBI Holidays for <?= $year ?></button>
                </form>
            </div>
        </div>

        <div class="lg:col-span-2 bg-gray-900/60 border border-gray-800 rounded-xl p-6">
            <h2 class="text-lg font-bold text-white mb-4">Holidays in <?= $year ?></h2>
            <?php if (empty($holidays)): ?>
            <p class="text-sm text-gray-500">No holidays configured for <?= $year ?>. Click "Seed RBI Holidays" to auto-populate.</p>
            <?php else: ?>
            <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[480px]">
                <thead class="text-gray-400">
                    <tr>
                        <th class="px-3 py-2 text-left">Date</th>
                        <th class="px-3 py-2 text-left">Name</th>
                        <th class="px-3 py-2 text-left">Type</th>
                        <th class="px-3 py-2 text-left">State</th>
                        <th class="px-3 py-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($holidays as $h): ?>
                    <tr class="border-t border-gray-800">
                        <td class="px-3 py-2 text-white font-mono text-xs"><?= e($h['holiday_date']) ?></td>
                        <td class="px-3 py-2 text-gray-300"><?= e($h['holiday_name']) ?></td>
                        <td class="px-3 py-2"><span class="text-xs px-2 py-0.5 rounded-full border border-gray-700 text-gray-400"><?= e($h['holiday_type']) ?></span></td>
                        <td class="px-3 py-2 text-gray-500 text-xs"><?= e($h['state'] ?? 'All') ?></td>
                        <td class="px-3 py-2">
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="remove_holiday">
                                <input type="hidden" name="holiday_id" value="<?= (int)$h['id'] ?>">
                                <button class="text-xs text-red-400" onclick="return confirm('Remove this holiday?')">Remove</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
