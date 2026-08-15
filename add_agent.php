<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureMerchantAgentColumns();
$merchant = getMerchant();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $business = trim($_POST['business_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $commission = (float)($_POST['agent_commission'] ?? 0.5);
    if (!$name || !$email || !$phone || !$business || !$password) {
        $errors[] = 'All fields required.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (empty($errors)) {
        $db = getDB();
        $check = $db->prepare('SELECT id FROM merchants WHERE email=? OR phone=?');
        $check->execute([$email, $phone]);
        if ($check->fetch()) {
            $errors[] = 'Email or phone already registered.';
        } else {
            $code = 'AG' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $db->prepare('INSERT INTO merchants (merchant_code,parent_merchant_id,name,email,phone,password,business_name,business_type,business_entity_type,agent_commission,api_key,api_secret,upi_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$code, $merchant['id'], $name, $email, $phone, password_hash($password, PASSWORD_ARGON2ID), $business, 'retail', 'sole_proprietorship', $commission, 'uk_' . bin2hex(random_bytes(16)), 'us_' . bin2hex(random_bytes(24)), strtolower(preg_replace('/\s+/', '', $business)) . '@uniweb']);
            $id = (int)$db->lastInsertId();
            createNotification($id, 'Agent Account Created', 'You have been added as an agent under ' . $merchant['business_name']);
            flash('success', 'Agent created: ' . $code);
            redirect('agents.php');
        }
    }
}
$pageTitle = 'Add Agent';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-lg mx-auto w-full glass rounded-xl p-4 sm:p-6">
    <h2 class="font-semibold mb-4">New Agent</h2>
    <?php if ($errors): ?><div class="bg-red-500/10 text-red-400 text-sm p-3 rounded-lg mb-4"><?php foreach ($errors as $e) echo '<p>' . e($e) . '</p>'; ?></div><?php endif; ?>
    <form method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div><label class="text-sm text-gray-400">Name *</label><input type="text" name="name" required autocomplete="name" class="input-field mt-1 w-full"></div>
        <div><label class="text-sm text-gray-400">Email *</label><input type="email" name="email" required autocomplete="email" class="input-field mt-1 w-full"></div>
        <div><label class="text-sm text-gray-400">Phone *</label><input type="tel" name="phone" required maxlength="10" inputmode="numeric" pattern="[0-9]{10}" autocomplete="tel" class="input-field mt-1 w-full ap-phone"></div>
        <div><label class="text-sm text-gray-400">Business Name *</label><input type="text" name="business_name" required class="input-field mt-1 w-full"></div>
        <div><label class="text-sm text-gray-400">Agent Commission (%)</label><input type="number" name="agent_commission" value="0.50" step="0.01" min="0" max="5" class="input-field mt-1 w-full"></div>
        <div><label class="text-sm text-gray-400">Password *</label><input type="password" name="password" required minlength="8" autocomplete="new-password" class="input-field mt-1 w-full"></div>
        <button type="submit" class="btn-primary w-full sm:w-auto px-6 py-2.5">Create Agent</button>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
