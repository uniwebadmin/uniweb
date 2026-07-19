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
        if (!$username || !$name || strlen($password) < 6) {
            flash('error', 'Username, name, and password (6+ chars) required.');
        } elseif (!in_array($role, $roles, true)) {
            flash('error', 'Invalid role.');
        } else {
            try {
                $db->prepare('INSERT INTO admins (username, password, name, role, email, phone, reports_to) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$username, password_hash($password, PASSWORD_BCRYPT), $name, $role, trim($_POST['email'] ?? '') ?: null, preg_replace('/\D/', '', $_POST['phone'] ?? '') ?: null, $reportsTo]);
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
$pageTitle = 'Staff Control';
require_once __DIR__ . '/header.php';
?>
<div class="grid lg:grid-cols-3 gap-6">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Add Team Member</h2>
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
            <div><label class="text-gray-400 text-xs">Password</label><input type="password" name="password" required minlength="6" class="input-field mt-1"></div>
            <button type="submit" class="w-full btn-primary py-3">Create Account</button>
        </form>
        <p class="text-xs text-gray-500 mt-4">Staff login: <a href="staff_login.php" class="text-sky-400">staff_login.php</a></p>
        <p class="text-xs text-gray-600 mt-2">Field staff only see merchants assigned to them. Managers see team activity in Activity Log.</p>
    </div>
    <div class="lg:col-span-2 space-y-4">
        <div class="glass rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-800 flex justify-between items-center">
                <h2 class="font-semibold">Team Hierarchy</h2>
                <a href="admin_staff_activity.php" class="text-xs text-sky-400">View full activity log →</a>
            </div>
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">ID</th><th class="px-5 py-3 text-left">Name</th><th class="px-5 py-3 text-left">Role</th><th class="px-5 py-3 text-left">Manager</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Action</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($staff as $s): if ($s['role'] === 'ceo') continue; ?>
                    <tr>
                        <td class="px-5 py-3 font-mono text-xs"><?= e($s['username']) ?></td>
                        <td class="px-5 py-3"><?= e($s['name']) ?></td>
                        <td class="px-5 py-3 text-xs"><?= e(staffRoleLabel($s['role'])) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-500"><?= e($s['manager_name'] ?? '—') ?></td>
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
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
