<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureMerchantTeamSchema();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
$isOwner = currentMerchantTeamRole() === 'owner';
$selfMemberId = (int)($_SESSION['merchant_team_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('manage_team');
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'invite') {
        $result = inviteMerchantTeamMember(
            $merchantId,
            (string)($_POST['email'] ?? ''),
            (string)($_POST['name'] ?? ''),
            (string)($_POST['role'] ?? 'viewer'),
            $merchantId
        );
        if ($result['ok'] && !empty($result['invite_url'])) {
            $_SESSION['last_team_invite_url'] = (string)$result['invite_url'];
        }
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Invite sent. Share the link below if email is delayed.' : ($result['error'] ?? 'Invite failed.'));
    } elseif ($action === 'resend') {
        $result = resendMerchantTeamInvite($merchantId, $id);
        if ($result['ok'] && !empty($result['invite_url'])) {
            $_SESSION['last_team_invite_url'] = (string)$result['invite_url'];
        }
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Invite resent. Share the link below if email is delayed.' : ($result['error'] ?? 'Resend failed.'));
    } elseif ($action === 'revoke') {
        if ($selfMemberId > 0 && $id === $selfMemberId) {
            flash('error', 'You cannot revoke your own access.');
        } else {
            $result = revokeMerchantTeamMember($merchantId, $id);
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Team member access revoked.' : ($result['error'] ?? 'Revoke failed.'));
        }
    } elseif ($action === 'role') {
        if ($selfMemberId > 0 && $id === $selfMemberId) {
            flash('error', 'You cannot change your own role.');
        } else {
            $result = updateMerchantTeamRole($merchantId, $id, (string)($_POST['role'] ?? 'viewer'));
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Role updated.' : ($result['error'] ?? 'Role update failed.'));
        }
    }
    redirect('merchant_team.php');
}

$members = listMerchantTeamMembers($merchantId);
$teamEvents = listMerchantTeamEvents($merchantId, 25);
$inviteLink = (string)($_SESSION['last_team_invite_url'] ?? '');
unset($_SESSION['last_team_invite_url']);
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
$matrix = merchantTeamCapabilityMatrix();
$capLabels = [
    'manage_team' => 'Invite',
    'settle' => 'Settle',
    'refund' => 'Refund',
    'support' => 'Complaints',
    'api' => 'API',
    'create_links' => 'Links',
    'view' => 'View',
];
?>
<div class="max-w-4xl">
    <div class="glass rounded-xl p-5 mb-6 border border-sky-500/20">
        <p class="text-xs text-sky-400 uppercase tracking-wider">Merchant team</p>
        <h1 class="font-semibold text-lg mt-1">Invite teammates and assign roles</h1>
        <p class="text-sm text-gray-500 mt-2">Colleagues sign in with their email and only see what their role allows. Owner: <?= e($merchant['email'] ?? '') ?></p>
        <?php if (!$isOwner): ?>
        <p class="text-xs text-amber-400 mt-2">You are signed in as <?= e(merchantTeamRoleLabel(currentMerchantTeamRole())) ?>. Owners and admins can manage invites, roles, and revoke.</p>
        <?php endif; ?>
    </div>

    <?php if ($inviteLink !== ''): ?>
    <div class="glass rounded-xl p-4 mb-6 border border-emerald-500/20">
        <p class="text-xs text-emerald-400 uppercase tracking-wider mb-2">Invite link</p>
        <div class="flex flex-wrap gap-2 items-center">
            <input type="text" readonly value="<?= e($inviteLink) ?>" class="input-field text-xs flex-1 min-w-[200px]">
            <button type="button" data-copy-url="<?= e($inviteLink) ?>" onclick="var u=this.getAttribute('data-copy-url')||''; if(u){navigator.clipboard.writeText(u); this.textContent='Copied!'; setTimeout(()=>this.textContent='Copy URL',2000);}" class="text-xs px-3 py-2 rounded-lg border border-gray-700 text-gray-300 hover:text-white">Copy URL</button>
        </div>
        <p class="text-xs text-gray-500 mt-2">Email may be delayed. Share this link with the teammate.</p>
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl p-5 mb-6 overflow-x-auto">
        <h2 class="font-semibold mb-3">Role matrix</h2>
        <p class="text-xs text-gray-500 mb-3">Who can invite, settle, refund, handle complaints, use API, create links, and view.</p>
        <table class="min-w-[640px] w-full text-xs">
            <thead class="text-gray-500 uppercase bg-dark-900/50">
                <tr>
                    <th class="px-3 py-2 text-left">Role</th>
                    <?php foreach ($capLabels as $lbl): ?>
                    <th class="px-3 py-2 text-center"><?= e($lbl) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($matrix as $row): ?>
                <tr>
                    <td class="px-3 py-2 font-medium"><?= e((string)$row['label']) ?></td>
                    <?php foreach (array_keys($capLabels) as $cap): ?>
                    <td class="px-3 py-2 text-center <?= !empty($row[$cap]) ? 'text-emerald-400' : 'text-gray-600' ?>"><?= !empty($row[$cap]) ? 'Yes' : '—' ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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

    <div class="glass rounded-xl overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold">Team</h2>
            <form method="GET" class="flex gap-2 items-center">
                <label class="sr-only" for="team-q">Search team</label>
                <input id="team-q" type="search" name="q" value="<?= e($teamQ) ?>" placeholder="Name / email / role" class="input-field text-sm">
                <button type="submit" class="btn-primary text-sm px-3 py-1.5">Search</button>
            </form>
            <?= renderExportCsvLink('export_team.php?q=' . rawurlencode($teamQ)) ?>
        </div>
        <div class="overflow-x-auto"><table class="min-w-[640px] w-full text-sm">
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
                    <td class="px-5 py-3">
                        <?php if (merchantTeamCan('manage_team') && $row['status'] !== 'revoked' && (int)$row['id'] !== $selfMemberId): ?>
                        <form method="POST" class="inline-flex items-center gap-1">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="role">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <select name="role" class="input-field text-xs py-1" onchange="this.form.submit()">
                                <?php foreach ($roles as $key => $meta): ?>
                                <option value="<?= e($key) ?>" <?= (string)$row['role'] === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php else: ?>
                        <span class="text-xs"><?= e(merchantTeamRoleLabel((string)$row['role'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3"><?= statusBadge($row['status']) ?></td>
                    <td class="px-5 py-3">
                        <?php if (merchantTeamCan('manage_team') && $row['status'] !== 'revoked' && (int)$row['id'] !== $selfMemberId): ?>
                        <div class="flex flex-wrap gap-3">
                            <?php if ((string)$row['status'] === 'invited'): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="resend">
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button class="text-xs text-sky-400">Resend</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Revoke access?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button class="text-xs text-red-400">Revoke</button>
                            </form>
                        </div>
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

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800">
            <h2 class="font-semibold">Team activity</h2>
            <p class="text-xs text-gray-500 mt-1">Invite, role change, accept, and revoke are logged here.</p>
        </div>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">When</th>
                <th class="px-5 py-3 text-left">Action</th>
                <th class="px-5 py-3 text-left">Member</th>
                <th class="px-5 py-3 text-left">By</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($teamEvents)): ?>
                <tr><td colspan="4" class="px-5 py-8 text-center text-gray-500 text-xs">No team activity yet.</td></tr>
                <?php else: foreach ($teamEvents as $ev): ?>
                <tr>
                    <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap"><?= e((string)$ev['created_at']) ?></td>
                    <td class="px-5 py-3 text-xs"><?= e(str_replace('_', ' ', (string)$ev['action'])) ?><?php if (!empty($ev['details'])): ?> <span class="text-gray-500">· <?= e((string)$ev['details']) ?></span><?php endif; ?></td>
                    <td class="px-5 py-3 text-xs text-gray-400"><?= e((string)($ev['member_email'] ?? '')) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= e((string)($ev['actor_email'] ?: $ev['actor_role'] ?: '—')) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
