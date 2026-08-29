<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'open') {
        $title = trim((string)($_POST['title'] ?? ''));
        $details = trim((string)($_POST['details'] ?? ''));
        $severity = (string)($_POST['severity'] ?? 'medium');
        if ($title === '' || $details === '') {
            flash('error', 'Title and details are required.');
        } else {
            $ref = openIncident($title, $details, $severity);
            flash('success', 'Incident ' . $ref . ' opened. It will show on the public status page until resolved.');
        }
    } elseif ($action === 'status' && !empty($_POST['ref'])) {
        $ok = updateIncidentStatus((string)$_POST['ref'], (string)($_POST['status'] ?? ''));
        flash($ok ? 'success' : 'error', $ok ? 'Incident updated.' : 'Could not update incident.');
    } elseif ($action === 'delete' && !empty($_POST['ref'])) {
        $ok = deleteIncident((string)$_POST['ref']);
        flash($ok ? 'success' : 'error', $ok ? 'Incident deleted.' : 'Could not delete incident.');
    }
    redirect('admin_incidents.php');
}

$incidents = listIncidents(100);
$uptime = computeUptimeStats(90);
$pageTitle = 'Incidents & Uptime';
require_once __DIR__ . '/header.php';
?>

<div class="mb-6 flex flex-wrap gap-3 items-center justify-between">
    <div>
        <h1 class="text-xl font-bold">Incidents &amp; Uptime</h1>
        <p class="text-sm text-gray-400 mt-1">Log real incidents here — resolved and open incidents show automatically on the public <a href="status.php" class="text-brand-400 hover:underline" target="_blank">status page</a>.</p>
    </div>
    <div class="glass rounded-xl px-5 py-3 text-right">
        <p class="text-2xl font-bold text-emerald-400"><?= e((string)$uptime['uptime_pct']) ?>%</p>
        <p class="text-xs text-gray-500">uptime · last <?= (int)$uptime['days'] ?> days</p>
    </div>
</div>

<div class="glass rounded-xl p-6 mb-8 border border-gray-800">
    <h2 class="font-semibold mb-4">Open a new incident</h2>
    <form method="POST" class="grid sm:grid-cols-2 gap-4">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="open">
        <div class="sm:col-span-2">
            <label class="text-sm text-gray-400 mb-1 block">Title</label>
            <input type="text" name="title" required maxlength="190" class="input-field" placeholder="e.g. Checkout page slow for some merchants">
        </div>
        <div class="sm:col-span-2">
            <label class="text-sm text-gray-400 mb-1 block">Details (internal — a short public summary is auto-shown from the title)</label>
            <textarea name="details" required rows="3" class="input-field"></textarea>
        </div>
        <div>
            <label class="text-sm text-gray-400 mb-1 block">Severity</label>
            <select name="severity" class="input-field">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
            </select>
        </div>
        <div class="flex items-end">
            <button type="submit" class="btn-primary px-6 py-2.5">Open incident</button>
        </div>
    </form>
</div>

<div class="glass rounded-xl overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">All incidents</h2></div>
    <?php if (empty($incidents)): ?>
    <div class="px-6 py-16 text-center text-gray-500">
        <p class="text-emerald-400 text-lg mb-2">✓ No incidents logged</p>
        <p class="text-sm">Public status page will show 100% uptime until an incident is opened here.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-[720px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr>
                    <th class="px-5 py-3 text-left">Ref</th>
                    <th class="px-5 py-3 text-left">Title</th>
                    <th class="px-5 py-3 text-left">Severity</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Opened</th>
                    <th class="px-5 py-3 text-left">Resolved</th>
                    <th class="px-5 py-3 text-left">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($incidents as $inc): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3 text-xs font-mono text-gray-400"><?= e($inc['incident_ref']) ?></td>
                    <td class="px-5 py-3 max-w-xs break-words"><?= e($inc['title']) ?></td>
                    <td class="px-5 py-3 text-xs uppercase <?= $inc['severity'] === 'critical' ? 'text-red-400' : ($inc['severity'] === 'high' ? 'text-amber-400' : 'text-gray-400') ?>"><?= e($inc['severity']) ?></td>
                    <td class="px-5 py-3 text-xs">
                        <span class="<?= $inc['status'] === 'resolved' ? 'text-emerald-400' : 'text-amber-400' ?>"><?= e($inc['status']) ?></span>
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap"><?= e(formatDate($inc['opened_at'])) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap"><?= $inc['resolved_at'] ? e(formatDate($inc['resolved_at'])) : '—' ?></td>
                    <td class="px-5 py-3 whitespace-nowrap">
                        <?php if ($inc['status'] !== 'resolved'): ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="ref" value="<?= e($inc['incident_ref']) ?>">
                            <input type="hidden" name="status" value="resolved">
                            <button type="submit" class="text-xs text-emerald-400 hover:underline">Mark resolved</button>
                        </form>
                        <?php if ($inc['status'] === 'open'): ?>
                        <form method="POST" class="inline ml-3">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="ref" value="<?= e($inc['incident_ref']) ?>">
                            <input type="hidden" name="status" value="mitigating">
                            <button type="submit" class="text-xs text-amber-400 hover:underline">Mitigating</button>
                        </form>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-xs text-gray-600">—</span>
                        <?php endif; ?>
                        <form method="POST" class="inline ml-3" onsubmit="return confirm('Delete this incident permanently? It will be removed from the public status page too.')">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="ref" value="<?= e($inc['incident_ref']) ?>">
                            <button type="submit" class="text-xs text-red-400 hover:underline">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
