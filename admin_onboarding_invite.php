<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops', 'regional_manager', 'area_sales_manager', 'team_leader']);

$baseUrl = rtrim(getSetting('app_url', 'http://localhost:8000'), '/');
$db = getDB();

// Ensure table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS onboarding_invites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(64) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(20) DEFAULT '',
        business_name VARCHAR(200) DEFAULT '',
        business_type VARCHAR(50) DEFAULT 'retail',
        business_entity_type VARCHAR(50) DEFAULT 'sole_proprietorship',
        note TEXT,
        created_by INT DEFAULT 0,
        used_by INT NULL,
        expires_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { /* ok */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_invite') {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $businessName = trim($_POST['business_name'] ?? '');
        $businessType = trim($_POST['business_type'] ?? 'retail');
        $entityType = trim($_POST['business_entity_type'] ?? 'sole_proprietorship');
        $note = trim($_POST['note'] ?? '');

        if ($email === '' || $name === '') {
            flash('error', 'Name and email are required.');
            redirect('admin_onboarding_invite.php');
        }

        $token = bin2hex(random_bytes(24));
        try {
            $db->prepare(
                "INSERT INTO onboarding_invites (token, name, email, phone, business_name, business_type, business_entity_type, note, created_by, expires_at)
                 VALUES (?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 7 DAY))"
            )->execute([$token, $name, $email, $phone, $businessName, $businessType, $entityType, $note, (int)($_SESSION['admin_id'] ?? 0)]);

            $inviteUrl = $baseUrl . '/merchant_register.php?invite=' . $token;

            $emailSent = false;
            if (function_exists('sendMailgunEmail')) {
                $emailBody = "Hello $name,\n\n"
                    . "You have been invited to join UniWeb as a merchant.\n\n"
                    . ($businessName ? "Business: $businessName\n\n" : '')
                    . "Click the link below to complete your signup (pre-filled with your details):\n"
                    . "$inviteUrl\n\n"
                    . "This link expires in 7 days.\n\n"
                    . "Regards,\nUniWeb Team";
                try { $emailSent = sendMailgunEmail($email, 'Complete your UniWeb merchant signup', $emailBody); } catch (Throwable $e) {}
            }

            logStaffActivity('onboarding_invite_created', "Invite sent to $email", 0);
            flash('success', 'Onboarding link created' . ($emailSent ? ' and email sent.' : '. Copy the link below to share.'));
            redirect('admin_onboarding_invite.php?created=' . $token);
        } catch (Throwable $e) {
            flash('error', 'Could not create invite: ' . $e->getMessage());
            redirect('admin_onboarding_invite.php');
        }
    }

    if ($action === 'revoke') {
        $inviteId = (int)($_POST['invite_id'] ?? 0);
        $db->prepare("DELETE FROM onboarding_invites WHERE id=? AND used_by IS NULL")->execute([$inviteId]);
        flash('success', 'Invite revoked.');
        redirect('admin_onboarding_invite.php');
    }
}

// Fetch existing invites
try {
    $invites = $db->query("SELECT * FROM onboarding_invites ORDER BY created_at DESC LIMIT 50")->fetchAll() ?: [];
} catch (Throwable $e) {
    $invites = [];
}

$createdToken = $_GET['created'] ?? '';
$createdInvite = null;
if ($createdToken !== '') {
    $st = $db->prepare("SELECT * FROM onboarding_invites WHERE token=?");
    $st->execute([$createdToken]);
    $createdInvite = $st->fetch();
}

$categories = getBusinessCategories();
$entities = getBusinessEntityTypes();

$pageTitle = 'Onboarding Invites';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-3xl space-y-6">
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h2 class="font-semibold text-lg mb-1">Send Pre-filled Onboarding Link</h2>
        <p class="text-xs text-gray-500 mb-6">Fill in the merchant's details — they'll get a signup link with everything pre-filled. They just set a password and verify OTP.</p>

        <?php if ($createdInvite): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 rounded-xl p-4 mb-6">
            <p class="text-sm font-semibold text-emerald-300 mb-2">Invite created for <?= e($createdInvite['name']) ?></p>
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" readonly value="<?= e($baseUrl . '/merchant_register.php?invite=' . $createdInvite['token']) ?>" class="input-field flex-1 font-mono text-xs" onclick="this.select()">
                <button type="button" onclick="navigator.clipboard.writeText('<?= e($baseUrl . '/merchant_register.php?invite=' . $createdInvite['token']) ?>'); this.textContent='Copied!'" class="btn-primary px-4 py-2 text-sm">Copy Link</button>
                <?php if (!empty($createdInvite['email'])): ?>
                <a href="mailto:<?= e($createdInvite['email']) ?>?subject=Complete your UniWeb signup&body=Click this link to signup: <?= e($baseUrl . '/merchant_register.php?invite=' . $createdInvite['token']) ?>" class="bg-sky-600 hover:bg-sky-500 text-white px-4 py-2 text-sm rounded-lg">Email Link</a>
                <?php endif; ?>
                <a href="https://api.whatsapp.com/send?text=<?= e(urlencode("Complete your UniWeb merchant signup:\n" . $baseUrl . '/merchant_register.php?invite=' . $createdInvite['token'])) ?>" target="_blank" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 text-sm rounded-lg">WhatsApp</a>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create_invite">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div><label class="text-sm text-gray-400">Full Name *</label><input type="text" name="name" required class="input-field mt-1 w-full" placeholder="Rahul Sharma"></div>
                <div><label class="text-sm text-gray-400">Email *</label><input type="email" name="email" required class="input-field mt-1 w-full" placeholder="rahul@business.com"></div>
                <div><label class="text-sm text-gray-400">Phone</label><input type="tel" name="phone" class="input-field mt-1 w-full" placeholder="9876543210"></div>
                <div><label class="text-sm text-gray-400">Business Name</label><input type="text" name="business_name" class="input-field mt-1 w-full" placeholder="Sharma Electronics"></div>
                <div><label class="text-sm text-gray-400">Business Type</label>
                    <select name="business_type" class="input-field mt-1">
                        <?php foreach ($categories as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label class="text-sm text-gray-400">Entity Type</label>
                    <select name="business_entity_type" class="input-field mt-1">
                        <?php foreach ($entities as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div><label class="text-sm text-gray-400">Note (internal)</label><input type="text" name="note" class="input-field mt-1 w-full" placeholder="Met at trade expo — wants UPI payments"></div>
            <button type="submit" class="btn-primary px-6 py-2.5">Create Onboarding Link</button>
        </form>
    </div>

    <?php if (!empty($invites)): ?>
    <div class="glass rounded-xl overflow-hidden border border-gray-800">
        <div class="px-6 py-4 border-b border-gray-800">
            <h3 class="font-semibold">Recent Invites</h3>
        </div>
        <div class="divide-y divide-gray-800">
            <?php foreach ($invites as $inv):
                $isUsed = !empty($inv['used_by']);
                $isExpired = strtotime($inv['expires_at']) < time();
                $status = $isUsed ? 'Used' : ($isExpired ? 'Expired' : 'Active');
                $statusColor = $isUsed ? 'emerald' : ($isExpired ? 'gray' : 'sky');
            ?>
            <div class="px-6 py-4 flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-200"><?= e($inv['name']) ?> <span class="text-gray-500 font-normal">· <?= e($inv['email']) ?></span></p>
                    <p class="text-xs text-gray-500 mt-0.5">
                        <?php if ($inv['business_name']): ?><?= e($inv['business_name']) ?> · <?php endif; ?>
                        Created <?= e(date('d M Y', strtotime($inv['created_at']))) ?>
                        · Expires <?= e(date('d M Y', strtotime($inv['expires_at']))) ?>
                    </p>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="text-xs px-2.5 py-1 rounded-full bg-<?= $statusColor ?>-500/15 text-<?= $statusColor ?>-400"><?= $status ?></span>
                    <?php if (!$isUsed && !$isExpired): ?>
                    <button type="button" onclick="navigator.clipboard.writeText('<?= e($baseUrl . '/merchant_register.php?invite=' . $inv['token']) ?>'); this.textContent='Copied!'" class="text-xs text-brand-400 hover:text-brand-300">Copy</button>
                    <form method="POST" class="m-0" onsubmit="return confirm('Revoke this invite?')">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="revoke">
                        <input type="hidden" name="invite_id" value="<?= (int)$inv['id'] ?>">
                        <button type="submit" class="text-xs text-red-400 hover:text-red-300">Revoke</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php';
