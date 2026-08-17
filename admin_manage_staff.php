<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager']);
$db = getDB();
ensureStaffRoles();

$roles = array_keys(array_filter(staffRoleDefinitions(), fn($d) => ($d['level'] ?? 0) < 100));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $role = $_POST['role'] ?? 'field_staff';
        $password = $_POST['password'] ?? '';
        $reportsTo = (int)($_POST['reports_to'] ?? 0) ?: null;
        if (!$username || !$name) {
            flash('error', 'Username and name are required.');
        } elseif ($policyError = validateStrongPassword($password, 12)) {
            flash('error', $policyError);
        } elseif (!in_array($role, $roles, true)) {
            flash('error', 'Invalid role.');
        } else {
            try {
                $db->prepare('INSERT INTO admins (username, password, name, role, email, phone, reports_to) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$username, password_hash($password, PASSWORD_ARGON2ID), $name, $role, trim($_POST['email'] ?? '') ?: null, preg_replace('/\D/', '', $_POST['phone'] ?? '') ?: null, $reportsTo]);
                logStaffActivity('staff_created', "Created staff {$username} as {$role}");
                flash('success', 'Staff account created: ' . $username);
            } catch (Throwable $e) {
                flash('error', 'Username already exists.');
            }
        }
    } elseif ($action === 'toggle' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        if ($id !== (int)($_SESSION['admin_id'] ?? 0)) {
            $db->prepare("UPDATE admins SET is_active = IF(is_active=1,0,1) WHERE id=? AND role NOT IN ('super','ceo')")->execute([$id]);
            logStaffActivity('staff_toggled', 'Toggled staff #' . $id);
            flash('success', 'Staff status updated.');
        }
    }
    redirect('admin_manage_staff.php');
}

$staff = $db->query("SELECT a.*, p.name AS manager_name FROM admins a LEFT JOIN admins p ON p.id=a.reports_to WHERE a.role NOT IN ('super') ORDER BY a.role, a.name")->fetchAll();
$managers = $db->query("SELECT id, name, role FROM admins WHERE role IN ('ceo','regional_manager','area_sales_manager','team_leader','staff_manager') AND is_active=1 ORDER BY name")->fetchAll();

$detailId = (int)($_GET['id'] ?? 0);
$detailStaff = null;
$detailActivity = [];
if ($detailId > 0) {
    $st = $db->prepare("SELECT a.*, p.name AS manager_name FROM admins a LEFT JOIN admins p ON p.id=a.reports_to WHERE a.id=? AND a.role NOT IN ('super')");
    $st->execute([$detailId]);
    $detailStaff = $st->fetch();
    if ($detailStaff) {
        $detailActivity = getStaffActivityLogs($detailId, 50);
    }
}

$pageTitle = 'Employees / Staff';
require_once __DIR__ . '/header.php';
?>
<?php if ($detailStaff): ?>
<div class="max-w-4xl space-y-6">
    <div class="mb-2">
        <a href="admin_manage_staff.php" class="text-sm text-gray-400 hover:text-white">← Back to Employees list</a>
    </div>
    <div class="glass rounded-xl p-6 border border-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
            <div>
                <h2 class="font-semibold text-lg"><?= e($detailStaff['name']) ?></h2>
                <p class="text-xs text-gray-500 font-mono mt-1"><?= e($detailStaff['username']) ?> · #<?= (int)$detailStaff['id'] ?></p>
                <div class="flex gap-2 mt-2">
                    <span class="text-[10px] px-2 py-0.5 rounded bg-brand-500/10 text-brand-400"><?= e(staffRoleLabel($detailStaff['role'])) ?></span>
                    <span class="text-[10px] px-2 py-0.5 rounded <?= (int)$detailStaff['is_active'] === 1 ? 'bg-emerald-500/20 text-emerald-400' : 'bg-gray-700/50 text-gray-400' ?>"><?= (int)$detailStaff['is_active'] === 1 ? '● Active' : '○ Inactive' ?></span>
                </div>
            </div>
            <div class="flex gap-2">
                <?php if ((int)$detailStaff['id'] !== (int)($_SESSION['admin_id'] ?? 0)): ?>
                <form method="POST" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$detailStaff['id'] ?>">
                    <button type="submit" class="text-xs px-4 py-2 rounded-lg <?= (int)$detailStaff['is_active'] === 1 ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30' ?>"><?= (int)$detailStaff['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4 text-sm">
            <div><span class="text-gray-500 text-xs">Email</span><p class="text-gray-300"><?= e($detailStaff['email'] ?? '—') ?></p></div>
            <div><span class="text-gray-500 text-xs">Phone</span><p class="text-gray-300"><?= e($detailStaff['phone'] ?? '—') ?></p></div>
            <div><span class="text-gray-500 text-xs">Manager</span><p class="text-gray-300"><?php
                if (!empty($detailStaff['reports_to']) && !empty($detailStaff['manager_name'])) {
                    echo adminStaffLink((int)$detailStaff['reports_to'], (string)$detailStaff['manager_name']);
                } else {
                    echo e((string)($detailStaff['manager_name'] ?? '—'));
                }
            ?></p></div>
            <div><span class="text-gray-500 text-xs">Last Login</span><p class="text-gray-300"><?= !empty($detailStaff['last_login_at']) ? e(formatDate($detailStaff['last_login_at'])) : 'Never' ?></p></div>
            <div><span class="text-gray-500 text-xs">Last Login IP</span><p class="text-gray-300 font-mono"><?= e($detailStaff['last_login_ip'] ?? '—') ?></p></div>
            <div><span class="text-gray-500 text-xs">Created</span><p class="text-gray-300"><?= e(formatDate($detailStaff['created_at'] ?? '')) ?></p></div>
        </div>
    </div>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800 flex justify-between items-center">
            <h3 class="font-semibold">Recent Activity</h3>
            <a href="admin_staff_activity.php?staff_id=<?= (int)$detailStaff['id'] ?>" class="text-xs text-sky-400">View full activity log →</a>
        </div>
        <?php if (empty($detailActivity)): ?>
        <p class="px-6 py-10 text-center text-sm text-gray-500">No activity logged yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="min-w-[480px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">Time</th><th class="px-5 py-3 text-left">Action</th><th class="px-5 py-3 text-left">Details</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($detailActivity as $log): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap"><?= formatDate($log['created_at']) ?></td>
                    <td class="px-5 py-3 font-mono text-xs text-sky-400"><?= e($log['action']) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-400 max-w-md truncate" title="<?= e($log['details'] ?? '') ?>"><?= e($log['details'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; return; endif; ?>

<div class="grid lg:grid-cols-3 gap-6 pb-24 lg:pb-6">
    <div class="glass rounded-xl p-6 lg:sticky lg:top-24 self-start max-h-[calc(100vh-7rem)] overflow-y-auto">
        <h2 class="font-semibold mb-4">Add Employee</h2>
        <form method="POST" class="space-y-4 text-sm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create">
            <div><label class="text-gray-400 text-xs">Login ID</label><input name="username" required class="input-field mt-1"></div>
            <div><label class="text-gray-400 text-xs">Full Name</label><input name="name" required class="input-field mt-1"></div>
            <div><label class="text-gray-400 text-xs">Email</label><input type="email" name="email" class="input-field mt-1"></div>
            <div><label class="text-gray-400 text-xs">Mobile (WhatsApp)</label><input type="tel" name="phone" maxlength="10" class="input-field mt-1"></div>
            <div><label class="text-gray-400 text-xs">Role / Hierarchy</label>
                <select name="role" class="input-field mt-1">
                    <?php foreach ($roles as $r): ?>
                    <option value="<?= e($r) ?>"><?= e(staffRoleLabel($r)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="text-gray-400 text-xs">Reports To (Manager)</label>
                <select name="reports_to" class="input-field mt-1">
                    <option value="">— None —</option>
                    <?php foreach ($managers as $mg): ?>
                    <option value="<?= (int)$mg['id'] ?>"><?= e($mg['name']) ?> (<?= e(staffRoleLabel($mg['role'])) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="text-gray-400 text-xs">Password (12+ with upper, lower, number, symbol)</label><input type="password" name="password" required minlength="12" class="input-field mt-1" autocomplete="new-password"></div>
            <button type="submit" class="w-full btn-primary py-3">Create Account</button>
        </form>
        <p class="text-xs text-gray-500 mt-4">Staff login: <a href="staff_login.php" class="text-sky-400">staff_login.php</a></p>
        <p class="text-xs text-gray-600 mt-2">Field staff only see merchants assigned to them. Managers see team activity in Activity Log. Partner Registry and Platform Settings (live keys) stay with Super Admin — do not give Support or KYC those pages.</p>
    </div>
    <div class="lg:col-span-2 space-y-4">
        <div class="glass rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-800 flex justify-between items-center">
                <h2 class="font-semibold">Employees / Staff hierarchy</h2>
                <a href="admin_staff_activity.php" class="text-xs text-sky-400">View full activity log →</a>
            </div>
            <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">ID</th><th class="px-5 py-3 text-left">Name</th><th class="px-5 py-3 text-left">Role</th><th class="px-5 py-3 text-left">Manager</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Action</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($staff as $s): if ($s['role'] === 'ceo') continue; ?>
                    <tr class="hover:bg-white/5">
                        <td class="px-5 py-3 font-mono text-xs"><a href="<?= e(adminStaffDetailUrl((int)$s['id'])) ?>" class="text-sky-400 hover:underline"><?= e($s['username']) ?></a></td>
                        <td class="px-5 py-3">
                            <?= adminStaffLink((int)$s['id'], (string)$s['name'], 'text-gray-200 hover:text-brand-400 hover:underline') ?>
                            <a href="<?= e(adminStaffDetailUrl((int)$s['id'])) ?>" class="block text-[10px] text-gray-500 hover:text-sky-400 hover:underline mt-0.5">Profile →</a>
                        </td>
                        <td class="px-5 py-3 text-xs"><?= e(staffRoleLabel($s['role'])) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-500">
                            <?php if (!empty($s['reports_to']) && !empty($s['manager_name'])): ?>
                            <?= adminStaffLink((int)$s['reports_to'], (string)$s['manager_name'], 'text-gray-400 hover:text-sky-300 hover:underline') ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="px-5 py-3"><?= ($s['is_active'] ?? 1) ? statusBadge('active') : statusBadge('suspended') ?></td>
                        <td class="px-5 py-3">
                            <?php if ((int)$s['id'] !== (int)($_SESSION['admin_id'] ?? 0)): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="text-xs text-amber-400"><?= ($s['is_active'] ?? 1) ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
