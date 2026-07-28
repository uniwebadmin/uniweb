<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';

// Placeholder officer details — owner should replace with the real named Grievance/Nodal
// Officer before going fully live (RBI Payment Aggregator/Payment Gateway guidelines
// require a NAMED officer, not just a generic support inbox).
if (!defined('GRIEVANCE_OFFICER_NAME')) {
    define('GRIEVANCE_OFFICER_NAME', COMPANY_CEO);
}
if (!defined('GRIEVANCE_OFFICER_DESIGNATION')) {
    define('GRIEVANCE_OFFICER_DESIGNATION', 'Grievance / Nodal Officer');
}
if (!defined('GRIEVANCE_OFFICER_EMAIL')) {
    define('GRIEVANCE_OFFICER_EMAIL', 'grievance@uniweb.co.in');
}
if (!defined('GRIEVANCE_OFFICER_PHONE')) {
    define('GRIEVANCE_OFFICER_PHONE', COMPANY_PHONE);
}

$pageTitle = 'Grievance Redressal';
require_once __DIR__ . '/header.php';
renderPublicLegalPage([
    'eyebrow' => 'Customer & Merchant Protection',
    'title' => 'Grievance Redressal Mechanism',
    'summary' => 'A structured, time-bound escalation path for merchants and customers to raise and resolve complaints, in line with RBI Payment Aggregator / Payment Gateway grievance-redressal expectations.',
    'effective' => '19 July 2026',
    'version' => '2026.07',
    'notice' => '<strong>Named officer:</strong> ' . e(GRIEVANCE_OFFICER_NAME) . ', ' . e(GRIEVANCE_OFFICER_DESIGNATION) . ' — <a href="mailto:' . e(GRIEVANCE_OFFICER_EMAIL) . '">' . e(GRIEVANCE_OFFICER_EMAIL) . '</a> · ' . e(GRIEVANCE_OFFICER_PHONE) . '. Never share OTP, UPI PIN, card PIN or CVV in any grievance communication — no genuine officer will ask for these.',
    'sections' => [
        ['Who can raise a grievance', '<p>Any merchant, customer, partner or user of the Platform may raise a complaint about a transaction, service failure, unauthorized activity, data concern, staff conduct or any other issue connected to UniWeb\'s services.</p>'],
        ['Level 1 — Support Ticket (first contact)', '<p>Raise the issue through the Merchant Portal support ticket, or via the <a href="contact.php">Contact page</a> for customers, with the transaction ID, date, amount and a clear description. <strong>Target first response: within 24 business hours.</strong> Most transaction-status and technical queries are resolved at this level.</p>'],
        ['Level 2 — Grievance / Nodal Officer', '<p>If Level 1 does not resolve the issue within <strong>5 working days</strong>, or the matter concerns fraud, unauthorized transactions, data protection or a policy dispute, escalate directly to the named Grievance Officer above. <strong>Target acknowledgement: within 2 working days. Target resolution: within 7 working days</strong> of acknowledgement, depending on complexity and any dependency on a banking or payment partner\'s response.</p>'],
        ['Level 3 — Payment partner / bank escalation', '<p>Where the underlying issue depends on the acquiring bank, UPI switch, card network or payment partner (for example, delayed bank-side credit reversal), UniWeb will forward the complaint with reference details to the relevant partner and communicate the partner\'s timeline to the complainant.</p>'],
        ['Level 4 — RBI Ombudsman for Digital Transactions', '<p>If a complaint relating to a digital payment transaction is not resolved within <strong>30 days</strong>, or the resolution is unsatisfactory, the complainant may approach the <strong>Reserve Bank of India — Ombudsman for Digital Transactions</strong> under the RBI Integrated Ombudsman Scheme via <a href="https://cms.rbi.org.in" target="_blank" rel="noopener">cms.rbi.org.in</a>, subject to the scheme\'s eligibility conditions.</p>'],
        ['What to include', '<p>Transaction ID / UTR, date and amount, merchant or order name, registered mobile/email, and a concise description of the issue. Do not include full card numbers, CVV, PINs, passwords or OTPs in any written communication.</p>'],
        ['Fraud and unauthorized transactions', '<p>For a suspected unauthorized transaction, contact your bank immediately to secure your account, then notify UniWeb using the channels above so records can be preserved and the matter reviewed alongside the bank\'s process.</p>'],
        ['Record-keeping', '<p>Grievance records, correspondence and resolution notes are retained in line with the <a href="privacy.php">Privacy Policy</a> and applicable regulatory retention requirements.</p>'],
    ],
]);
require_once __DIR__ . '/footer.php';
