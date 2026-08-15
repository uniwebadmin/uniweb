<?php
require_once __DIR__ . '/config.php';
if (!function_exists('recordPublicContactInquiry') && is_file(__DIR__ . '/includes/schema_ensure.php')) {
    require_once __DIR__ . '/includes/schema_ensure.php';
}
require_once __DIR__ . '/includes/page_ux.php';
$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    if ($name === '' || $email === '' || $subject === '' || $message === '') {
        $error = 'Please fill all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $rateFile = sys_get_temp_dir() . '/uniweb_contact_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $rateCount = 0;
        if (is_file($rateFile)) {
            $rateData = json_decode((string)file_get_contents($rateFile), true);
            if (is_array($rateData) && (time() - (int)($rateData['ts'] ?? 0)) < 600) {
                $rateCount = (int)($rateData['count'] ?? 0);
            }
        }
        if ($rateCount >= 8) {
            $error = 'Too many messages from this network. Email us at ' . COMPANY_SUPPORT_EMAIL . ' or try again in a few minutes.';
        } else {
            @file_put_contents($rateFile, json_encode(['ts' => time(), 'count' => $rateCount + 1]));
            $to = COMPANY_SUPPORT_EMAIL;
            $mailSubject = APP_NAME . ' Contact — ' . $subject;
            $body = "Name: {$name}\nEmail: {$email}\nSubject: {$subject}\n\nMessage:\n{$message}\n\n— Sent from " . APP_URL . '/contact.php';
            $emailSent = false;
            try {
                $emailSent = (bool)sendPlatformEmail($to, $mailSubject, $body);
            } catch (Throwable $e) {
                $emailSent = false;
            }
            $saved = function_exists('recordPublicContactInquiry')
                ? recordPublicContactInquiry($name, $email, $subject, $message, $emailSent)
                : ['ok' => false, 'inquiry_id' => ''];
            if (!empty($saved['ok'])) {
                $ref = (string)$saved['inquiry_id'];
                flash('success', 'Request saved as ' . $ref . '. We acknowledge within 1 business day. Keep this reference if you follow up.');
                redirect('contact.php');
            }
            if ($emailSent) {
                flash('success', 'Message sent. We acknowledge within 1 business day.');
                redirect('contact.php');
            }
            $error = 'Could not save right now. Email us at ' . COMPANY_SUPPORT_EMAIL . ' with your details.';
        }
    }
}

$pageTitle = 'Contact Us';
require_once __DIR__ . '/header.php';
?>
<main class="company-page">
    <section class="company-hero"><div class="company-shell">
        <div class="company-eyebrow">Contact & Grievance Support</div>
        <h1>Reach the right team with the right details.</h1>
        <p>For faster investigation, include your merchant code, transaction or settlement ID and the exact issue. Never send passwords, OTPs, UPI PINs, card PINs or CVV.</p>
    </div></section>
    <section class="company-section"><div class="company-shell">
        <div class="company-grid">
            <div class="company-card"><div class="company-kicker">Merchant support</div><h3><?= e(COMPANY_SUPPORT_EMAIL) ?></h3><p>Account, KYC, payment, refund, settlement and dashboard assistance. Signed-in merchants should use a Portal ticket for an auditable thread.</p></div>
            <div class="company-card"><div class="company-kicker">Business & administration</div><h3><?= e(COMPANY_ADMIN_EMAIL) ?></h3><p>Commercial, partner, compliance and formal administrative communication.</p></div>
            <div class="company-card"><div class="company-kicker">Phone</div><h3><?= e(COMPANY_PHONE) ?></h3><p>Use email or a support ticket for sensitive or evidence-based cases so supporting records remain attached.</p></div>
        </div>
    </div></section>
    <section class="company-section" style="padding-top:0"><div class="company-shell contact-layout">
        <div>
            <div class="company-kicker">Registered office</div><h2 class="company-title">Company contact details</h2>
            <div class="company-facts" style="grid-template-columns:1fr">
                <div class="company-fact"><span>Legal entity</span><strong><?= e(COMPANY_LEGAL_NAME) ?></strong></div>
                <div class="company-fact"><span>CIN / GST</span><strong><?= e(COMPANY_CIN) ?> · <?= e(COMPANY_GST) ?></strong></div>
                <div class="company-fact"><span>Address</span><strong><?= e(COMPANY_ADDRESS) ?></strong></div>
                <div class="company-fact"><span>Office location</span><strong><a href="<?= e(COMPANY_MAP_URL) ?>" target="_blank" rel="noopener" class="text-brand-400">Open verified map location →</a></strong></div>
            </div>
            <div class="company-card mt-5"><h3>Response and escalation</h3><p><strong>Website form:</strong> we save a ticket and try email. Acknowledgement target: <strong>1 business day</strong>. Payment or bank issues may take longer because partners must confirm. If unresolved, reply with the same reference rather than opening a duplicate.</p></div>
            <div class="company-card mt-5 border border-sky-500/20">
                <div class="company-kicker">Grievance Officer</div>
                <h3><?= e(COMPANY_CEO) ?></h3>
                <p class="text-sm text-gray-400 mt-2">Designation: Managing Director / Grievance Officer · <?= e(COMPANY_LEGAL_NAME) ?></p>
                <p class="text-sm text-gray-400 mt-1">Email: <a href="mailto:<?= e(COMPANY_SUPPORT_EMAIL) ?>" class="text-sky-400"><?= e(COMPANY_SUPPORT_EMAIL) ?></a> · Phone: <?= e(COMPANY_PHONE) ?></p>
                <p class="text-xs text-gray-500 mt-3">Write “Grievance” in the subject. Include merchant code and payment/settlement ID. Acknowledgement target: 48 business hours. Escalation if unresolved after 7 days: reply on the same thread.</p>
                <p class="text-xs mt-2"><a href="trust.php" class="text-brand-400">Trust &amp; Security centre →</a></p>
            </div>
        </div>
        <div class="contact-form-card">
            <div class="company-kicker">Send a message</div>
            <h2 class="company-title" style="font-size:1.65rem">Tell us how we can help.</h2>
            <p class="company-lead text-sm mb-5">We save a ticket even if email is delayed. Include merchant code or TXN / LNK ID. Never send OTPs, PINs or passwords. SLA: acknowledge in 1 business day.</p>
            <?php if ($error !== ''): ?><p class="text-sm text-amber-400 mb-4"><?= e($error) ?></p><?php endif; ?>
            <form method="POST" class="space-y-4" aria-label="Contact form">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <div><?= uxLabel('contact-name', 'Full name', true) ?><input id="contact-name" type="text" name="name" required maxlength="120" class="input-field mt-1" value="<?= e($_POST['name'] ?? '') ?>"></div>
                <div><?= uxLabel('contact-email', 'Email address', true) ?><input id="contact-email" type="email" name="email" required maxlength="190" class="input-field mt-1" value="<?= e($_POST['email'] ?? '') ?>"></div>
                <div><?= uxLabel('contact-subject', 'Subject or reference ID', true) ?><input id="contact-subject" type="text" name="subject" required maxlength="190" class="input-field mt-1" value="<?= e($_POST['subject'] ?? '') ?>"></div>
                <div><?= uxLabel('contact-message', 'Message', true) ?><textarea id="contact-message" name="message" required maxlength="4000" rows="7" class="input-field mt-1"><?= e($_POST['message'] ?? '') ?></textarea></div>
                <button type="submit" class="w-full btn-primary py-3">Send securely</button>
            </form>
        </div>
    </div></section>
    <section class="company-section" style="padding-top:0"><div class="company-shell"><div class="company-cta">
        <h2>Security or privacy concern?</h2><p>Mark the subject clearly and avoid sharing secrets. For a suspected account takeover, change your password and rotate exposed API credentials immediately.</p>
        <div class="flex flex-wrap gap-3"><a href="compliance.php" class="btn-primary px-6 py-3">Compliance framework</a><a href="privacy.php" class="px-6 py-3 rounded-lg border border-gray-700">Privacy policy</a></div>
    </div></div></section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
