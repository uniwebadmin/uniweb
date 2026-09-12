<?php
require_once __DIR__ . '/config.php';
if (function_exists('ensureMissingColumns')) {
    ensureMissingColumns();
}
requireStaffAccess(['super', 'ceo', 'finance', 'ops']);

if (!function_exists('getPartnerFeeReport') && is_file(__DIR__ . '/includes/ops_partner.php')) {
    require_once __DIR__ . '/includes/ops_partner.php';
}
if (!function_exists('getAllPartnerCommercial') && is_file(__DIR__ . '/includes/split_settlement.php')) {
    require_once __DIR__ . '/includes/split_settlement.php';
}

$days = (int)($_GET['days'] ?? 30);
if ($days < 1 || $days > 365) {
    $days = 30;
}

$report = function_exists('getPartnerFeeReport')
    ? getPartnerFeeReport($days)
    : ['rows' => [], 'totals' => ['txns' => 0, 'success' => 0, 'volume' => 0.0, 'fees' => 0.0, 'partner_fee' => 0.0, 'gst_on_fee' => 0.0, 'chargebacks' => 0]];
$partnerStats = $report['rows'];
$totals = $report['totals'];

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $clean = static function ($value): string {
        $value = (string)$value;
        return $value !== '' && preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uniweb-partner-fee-report-' . $days . 'd-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['UniWeb fee report — customer payment money stays with the partner. This CSV is for invoicing UniWeb Pvt Ltd (NEFT).']);
    fputcsv($out, ['Period (days)', $days, 'Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['Partner', 'Partner key', 'Keys saved', 'Txns', 'Success', 'Success rate %', 'Volume INR', 'UniWeb fee INR', 'Partner fee (info) INR', 'GST on fee INR', 'Avg amount INR', 'Avg settlement hours', 'Pending settlements', 'Chargebacks']);
    foreach ($partnerStats as $p) {
        fputcsv($out, array_map($clean, [
            $p['name'],
            $p['key'],
            !empty($p['configured']) ? 'yes' : 'no',
            $p['total_txns'],
            $p['success_txns'],
            $p['success_rate'],
            number_format((float)$p['total_volume'], 2, '.', ''),
            number_format((float)$p['uniweb_fee'], 2, '.', ''),
            number_format((float)$p['partner_fee'], 2, '.', ''),
            number_format((float)$p['gst_on_fee'], 2, '.', ''),
            number_format((float)$p['avg_amount'], 2, '.', ''),
            $p['avg_settlement_hours'] > 0 ? $p['avg_settlement_hours'] : '',
            $p['pending_settlements'],
            $p['chargebacks'],
        ]));
    }
    fputcsv($out, array_map($clean, [
        'TOTAL',
        '',
        '',
        $totals['txns'],
        $totals['success'],
        '',
        number_format((float)$totals['volume'], 2, '.', ''),
        number_format((float)$totals['fees'], 2, '.', ''),
        number_format((float)$totals['partner_fee'], 2, '.', ''),
        number_format((float)$totals['gst_on_fee'], 2, '.', ''),
        '',
        '',
        '',
        $totals['chargebacks'],
    ]));
    fclose($out);
    exit;
}

$commercialRows = function_exists('getAllPartnerCommercial') ? getAllPartnerCommercial() : [];
$registry = function_exists('getPartnerRegistry') ? getPartnerRegistry() : [];

$pageTitle = 'Partner Commercial Dashboard';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex flex-wrap gap-3 items-center justify-between">
        <div>
            <p class="text-sm text-gray-400">Fee report by collect partner — for UniWeb invoice / NEFT. Customer payment money stays with the partner.</p>
            <p class="text-xs text-gray-600 mt-1">This page does not move customer rupees. After NEFT, mark received on <a href="admin_platform_wallet.php" class="text-sky-400 hover:underline">Platform Fee Ledger</a>. Set Partner MDR on <a href="admin_gateway_registry.php" class="text-sky-400 hover:underline">Partner Registry → Commercial</a>.</p>
        </div>
        <div class="flex flex-wrap gap-2 items-center">
            <form method="GET" class="flex gap-2 items-center">
                <select name="days" class="input-field text-xs w-32" onchange="this.form.submit()">
                    <option value="7" <?= $days === 7 ? 'selected' : '' ?>>Last 7 days</option>
                    <option value="30" <?= $days === 30 ? 'selected' : '' ?>>Last 30 days</option>
                    <option value="90" <?= $days === 90 ? 'selected' : '' ?>>Last 90 days</option>
                    <option value="365" <?= $days === 365 ? 'selected' : '' ?>>Last 1 year</option>
                </select>
            </form>
            <a href="admin_partner_commercial.php?days=<?= (int)$days ?>&amp;export=csv" class="btn-secondary text-xs px-3 py-2 rounded-lg">Download CSV (invoice)</a>
        </div>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Total Transactions</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= number_format((int)$totals['txns']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Collect volume (partner holds)</p><p class="text-2xl font-bold text-emerald-400 mt-1"><?= formatMoney((float)$totals['volume']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">UniWeb fee (invoice this)</p><p class="text-2xl font-bold text-violet-400 mt-1"><?= formatMoney((float)$totals['fees']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Chargebacks</p><p class="text-2xl font-bold <?= (int)$totals['chargebacks'] > 0 ? 'text-red-400' : 'text-emerald-400' ?> mt-1"><?= number_format((int)$totals['chargebacks']) ?></p></div>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800">
            <h2 class="font-semibold">Partner fee snapshot (<?= (int)$days ?> days)</h2>
            <p class="text-xs text-gray-500 mt-1">Grouped by <code>partner_key</code> on each payment — not the old gateway column.</p>
        </div>
        <div class="overflow-x-auto"><table class="min-w-[1100px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Partner</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-right">Txns</th>
                <th class="px-4 py-3 text-right">Success Rate</th>
                <th class="px-4 py-3 text-right">Volume</th>
                <th class="px-4 py-3 text-right">Avg Amount</th>
                <th class="px-4 py-3 text-right">UniWeb Fee</th>
                <th class="px-4 py-3 text-right">Partner fee (info)</th>
                <th class="px-4 py-3 text-right">Avg Settlement</th>
                <th class="px-4 py-3 text-right">Pending Stlm</th>
                <th class="px-4 py-3 text-right">Chargebacks</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($partnerStats as $p): ?>
                <tr>
                    <td class="px-4 py-3"><span class="text-lg"><?= e((string)$p['icon']) ?></span> <?= e((string)$p['name']) ?> <span class="text-xs text-gray-500"><?= e((string)$p['key']) ?><?= $p['type'] !== '' ? ' · ' . e((string)$p['type']) : '' ?></span></td>
                    <td class="px-4 py-3"><?= !empty($p['configured']) ? '<span class="text-xs text-emerald-400">● Keys saved</span>' : '<span class="text-xs text-gray-500">○ Keys not saved</span>' ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= number_format((int)$p['total_txns']) ?></td>
                    <td class="px-4 py-3 text-right text-xs<?php $sr = (float)$p['success_rate']; echo $sr >= 95 ? ' text-emerald-400' : ($sr >= 80 ? ' text-amber-400' : ($sr > 0 ? ' text-red-400' : '')); ?>"><?= (int)$p['total_txns'] > 0 ? $sr . '%' : '—' ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= (float)$p['total_volume'] > 0 ? formatMoney((float)$p['total_volume']) : '—' ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= (float)$p['avg_amount'] > 0 ? formatMoney((float)$p['avg_amount']) : '—' ?></td>
                    <td class="px-4 py-3 text-right text-xs"><?= (float)$p['uniweb_fee'] > 0 ? formatMoney((float)$p['uniweb_fee']) : '—' ?></td>
                    <td class="px-4 py-3 text-right text-xs text-gray-400"><?= (float)$p['partner_fee'] > 0 ? formatMoney((float)$p['partner_fee']) : '—' ?></td>
                    <td class="px-4 py-3 text-right text-xs<?php $sh = (float)$p['avg_settlement_hours']; echo $sh > 0 && $sh <= 24 ? ' text-emerald-400' : ($sh > 48 ? ' text-amber-400' : ''); ?>"><?= $sh > 0 ? $sh . 'h' : '—' ?></td>
                    <td class="px-4 py-3 text-right text-xs<?= (int)$p['pending_settlements'] > 0 ? ' text-amber-400' : '' ?>"><?= (int)$p['pending_settlements'] > 0 ? number_format((int)$p['pending_settlements']) : '—' ?></td>
                    <td class="px-4 py-3 text-right text-xs<?= (int)$p['chargebacks'] > 0 ? ' text-red-400' : '' ?>"><?= (int)$p['chargebacks'] > 0 ? number_format((int)$p['chargebacks']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800">
            <h2 class="font-semibold">Saved partner MDR (live)</h2>
            <p class="text-xs text-gray-500 mt-1">Rates from Partner Detail → Commercial after you save. Empty means not saved yet — not a guessed price list.</p>
        </div>
        <div class="overflow-x-auto"><table class="min-w-[640px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Partner</th><th class="px-4 py-3 text-left">Base MDR</th><th class="px-4 py-3 text-left">Settlement mode</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if ($commercialRows === []): ?>
                <tr><td colspan="3" class="px-4 py-6 text-sm text-gray-500">No partner commercial rows saved yet. Open Partner Registry → a partner → Commercial.</td></tr>
                <?php else: ?>
                <?php foreach ($commercialRows as $c): $ck = (string)($c['partner_key'] ?? ''); ?>
                <tr>
                    <td class="px-4 py-3"><?= e((string)($registry[$ck]['name'] ?? $ck)) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e(rtrim(rtrim(number_format((float)($c['base_mdr_percent'] ?? 0), 4, '.', ''), '0'), '.') . '%') ?></td>
                    <td class="px-4 py-3 text-xs"><?= e((string)($c['settlement_mode'] ?? '—')) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
