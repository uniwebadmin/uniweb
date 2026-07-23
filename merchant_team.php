<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureMerchantTeamSchema();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
$isOwner = currentMerchantTeamRole() === 'owner';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('manage_team');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'invite') {
        $result = inviteMerchantTeamMember(
            $merchantId,
            (string)($_POST['email'] ?? ''),
            (string)($_POST['name'] ?? ''),
            (string)($_POST['role'] ?? 'viewer'),
            $merchantId
        );
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Invite sent. Teammate must accept by email link.' : ($result['error'] ?? 'Invite failed.'));
    } elseif ($action === 'revoke') {
        $id = (int)($_POST['id'] ?? 0);
        getDB()->prepare("UPDATE merchant_team_members SET status='revoked', invite_token=NULL WHERE id=? AND merchant_id=?")
            ->execute([$id, $merchantId]);
        flash('success', 'Team member access revoked.');
    } elseif ($action === 'role' && $isOwner) {
        $id = (int)($_POST['id'] ?? 0);
        $role = (string)($_POST['role'] ?? 'viewer');
        if (isset(merchantTeamRoles()[$role])) {
            getDB()->prepare("UPDATE merchant_team_members SET role=? WHERE id=? AND merchant_id=? AND status IN ('active','invited')")
                ->execute([$role, $id, $merchantId]);
            flash('success', 'Role updated.');
        }
    }
    redirect('merchant_team.php');
}

$members = listMerchantTeamMembers($merchantId);
$teamQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$listParams = listPageParams(20);
if ($teamQ !== '') {
    $members = array_values(array_filter($members, static function ($row) use ($teamQ) {
        $hay = strtolower(($row['name'] ?? '') . ' ' . ($row['email'] ?? '') . ' ' . ($row['role'] ?? ''));
        return str_contains($hay, strtolower($teamQ));
    }));
}
$teamTotal = count($members) + 1;
$pagedMembers = array_slice($members, max(0, $listParams['offset'] - 1), $listParams['page'] === 1 ? max(0, $listParams['perPage'] - 1) : $listParams['perPage']);
$pageTitle = 'Team Members';
require_once __DIR__ . '/header.php';
$roles = merchantTeamRoles();
?>
<div class="max-w-4xl">
    <div class="glass rounded-xl p-5 mb-6 border border-sky-500/20">
        <p class="text-xs text-sky-400 uppercase tracking-wider">Merchant team</p>
        <h1 class="font-semibold text-lg mt-1">Invite finance, developer or viewer access</h1>
        <p class="text-sm text-gray-500 mt-2">Like Razorpay team roles — colleagues sign in with their email and only see what their role allows. Owner: <?= e($merchant['email'] ?? '') ?></p>
        <?php if (!$isOwner): ?>
        <p class="text-xs text-amber-400 mt-2">You are signed in as <?= e(merchantTeamRoleLabel(currentMerchantTeamRole())) ?>. Only owners/admins can manage invites.</p>
        <?php endif; ?>
    </div>

    <?php if (merchantTeamCan('manage_team')): ?>
    <div class="glass rounded-xl p-6 mb-6">
        <h2 class="font-semibold mb-4">Invite teammate</h2>
        <form method="POST" class="grid sm:grid-cols-2 gap-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="invite">
            <div><label class="text-sm text-gray-400">Full name</label><input name="name" required maxlength="120" class="input-field mt-1" placeholder="Name"></div>
            <div><label class="text-sm text-gray-400">Email</label><input type="email" name="email" required class="input-field mt-1" placeholder="colleague@business.com"></div>
            <div class="sm:col-span-2"><label class="text-sm text-gray-400">Role</label>
                <select name="role" class="input-field mt-1">
                    <?php foreach ($roles as $key => $meta): ?>
                    <option value="<?= e($key) ?>"><?= e($meta['label']) ?> — <?= e($meta['hint']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-primary px-6 py-2.5 sm:col-span-2 sm:w-fit">Send invite</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold">Team</h2>
            <form method="GET" class="flex gap-2 items-center">
                <label class="sr-only" for="team-q">Search team</label>
                <input id="team-q" type="search" name="q" value="<?= e($teamQ) ?>" placeholder="Name / email / role" class="input-field text-sm">
                <button type="submit" class="btn-primary text-sm px-3 py-1.5">Search</button>
            </form>
            <?= renderExportCsvLink('export_team.php?q=' . rawurlencode($teamQ)) ?>
        </div>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">Member</th>
                <th class="px-5 py-3 text-left">Role</th>
                <th class="px-5 py-3 text-left">Status</th>
                <th class="px-5 py-3 text-left">Action</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <tr>
                    <td class="px-5 py-3"><p class="font-medium"><?= e($merchant['name'] ?? 'Owner') ?></p><p class="text-xs text-gray-500"><?= e($merchant['email'] ?? '') ?></p></td>
                    <td class="px-5 py-3"><span class="text-emerald-400 text-xs font-semibold">Owner</span></td>
                    <td class="px-5 py-3"><?= statusBadge('active') ?></td>
                    <td class="px-5 py-3 text-xs text-gray-600">—</td>
                </tr>
                <?php if (empty($members) && $teamQ !== ''): ?>
                <tr><td colspan="4" class="px-5 py-8 text-center text-gray-500 text-xs">No teammates match your search.</td></tr>
                <?php else: foreach ($pagedMembers as $row): ?>
                <tr>
                    <td class="px-5 py-3"><p class="font-medium"><?= e($row['name']) ?></p><p class="text-xs text-gray-500"><?= e($row['email']) ?></p></td>
                    <td class="px-5 py-3 text-xs"><?= e(merchantTeamRoleLabel((string)$row['role'])) ?></td>
                    <td class="px-5 py-3"><?= statusBadge($row['status']) ?></td>
                    <td class="px-5 py-3">
                        <?php if (merchantTeamCan('manage_team') && $row['status'] !== 'revoked'): ?>
                        <form method="POST" class="inline" onsubmit="return confirm('Revoke access?')">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button class="text-xs text-red-400">Revoke</button>
                        </form>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; if (empty($members) && $teamQ === ''): ?>
                <tr><td colspan="4" class="px-5 py-8 text-center text-gray-500 text-xs">No invited teammates yet.</td></tr>
                <?php endif; endif; ?>
            </tbody>
        </table></div>
        <?= renderListPagination($listParams['page'], $teamTotal, $listParams['perPage'], ['q' => $teamQ]) ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
