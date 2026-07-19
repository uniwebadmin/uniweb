<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
$db = getDB();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $name = trim($_POST['name']??''); $email = trim($_POST['email']??''); $phone = trim($_POST['phone']??'');
    $business = trim($_POST['business_name']??''); $password = $_POST['password']??'';
    if (!$name||!$email||!$phone||!$business||!$password) $errors[] = 'All fields required.';
    if (strlen($password)<8) $errors[] = 'Password must be 8+ chars.';
    if (empty($errors)) {
        $check = $db->prepare('SELECT id FROM merchants WHERE email=? OR phone=?');
        $check->execute([$email,$phone]);
        if ($check->fetch()) $errors[] = 'Email/phone exists.';
        else {
            $code = 'UW' . strtoupper(substr(bin2hex(random_bytes(4)),0,8));
            $db->prepare('INSERT INTO merchants (merchant_code,name,email,phone,password,business_name,business_type,api_key,api_secret,upi_id,kyc_status) VALUES (?,?,?,?,?,?,?,?,?,?,"verified")')
                ->execute([$code,$name,$email,$phone,password_hash($password,PASSWORD_BCRYPT),$business,$_POST['business_type']??'retail','uk_'.bin2hex(random_bytes(16)),'us_'.bin2hex(random_bytes(24)),strtolower(preg_replace('/\s+/','',$business)).'@uniweb']);
            flash('success', 'Merchant created: ' . $code);
            redirect('manage_merchant.php');
        }
    }
}
$pageTitle = 'Add Merchant';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-lg glass rounded-xl p-6">
    <h2 class="font-semibold mb-4">Add New Merchant</h2>
    <?php if ($errors): ?><div class="bg-red-500/10 text-red-400 text-sm p-3 rounded-lg mb-4"><?php foreach($errors as $e) echo '<p>'.e($e).'</p>'; ?></div><?php endif; ?>
    <form method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div><label class="text-sm text-gray-400">Full Name *</label><input type="text" name="name" required class="input-field mt-1"></div>
        <div><label class="text-sm text-gray-400">Email *</label><input type="email" name="email" required class="input-field mt-1"></div>
        <div><label class="text-sm text-gray-400">Phone *</label><input type="tel" name="phone" required maxlength="10" class="input-field mt-1"></div>
        <div><label class="text-sm text-gray-400">Business Name *</label><input type="text" name="business_name" required class="input-field mt-1"></div>
        <div><label class="text-sm text-gray-400">Business Type</label><select name="business_type" class="input-field mt-1"><?php foreach(['retail','restaurant','services','ecommerce','other'] as $t): ?><option value="<?= $t ?>"><?= ucfirst($t) ?></option><?php endforeach; ?></select></div>
        <div><label class="text-sm text-gray-400">Password *</label><input type="password" name="password" required minlength="8" class="input-field mt-1"></div>
        <button type="submit" class="btn-primary px-6 py-2.5">Create Merchant</button>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
