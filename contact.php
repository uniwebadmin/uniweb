<?php
require_once __DIR__ . '/config.php';
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
        $to = COMPANY_SUPPORT_EMAIL;
        $mailSubject = APP_NAME . ' Contact — ' . $subject;
        $body = "Name: {$name}\nEmail: {$email}\nSubject: {$subject}\n\nMessage:\n{$message}\n\n— Sent from " . APP_URL . '/contact.php';
        $ok = sendPlatformEmail($to, $mailSubject, $body);
        if ($ok) {
            $sent = true;
            flash('success', 'Message sent! We will respond within 24 hours.');
            redirect('contact.php');
        }
        $error = 'Could not send right now. Email us at ' . COMPANY_SUPPORT_EMAIL;
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
            <div class="company-card mt-5"><h3>Response and escalation</h3><p>We aim to acknowledge normal support requests within one business day. Payment and banking resolution time can depend on external partners. If a case is unresolved, reply on the same ticket with the existing reference rather than opening duplicates.</p></div>
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
            <p class="company-lead text-sm mb-5">Provide enough information to identify the case, without including payment credentials.</p>
            <?php if ($error !== ''): ?><p class="text-sm text-amber-400 mb-4"><?= e($error) ?></p><?php endif; ?>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <div><label class="text-sm text-gray-400">Full name</label><input type="text" name="name" required maxlength="120" class="input-field mt-1" value="<?= e($_POST['name'] ?? '') ?>"></div>
                <div><label class="text-sm text-gray-400">Email address</label><input type="email" name="email" required maxlength="190" class="input-field mt-1" value="<?= e($_POST['email'] ?? '') ?>"></div>
                <div><label class="text-sm text-gray-400">Subject or reference ID</label><input type="text" name="subject" required maxlength="190" class="input-field mt-1" value="<?= e($_POST['subject'] ?? '') ?>"></div>
                <div><label class="text-sm text-gray-400">Message</label><textarea name="message" required maxlength="4000" rows="7" class="input-field mt-1"><?= e($_POST['message'] ?? '') ?></textarea></div>
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
