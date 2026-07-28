<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';

// Placeholder/demo officer details — owner should replace with the real named Grievance/Nodal
// Officer before going fully live (RBI Payment Aggregator/Payment Gateway guidelines
// require a NAMED officer, not just a generic support inbox).
if (!defined('GRIEVANCE_OFFICER_NAME')) {
    define('GRIEVANCE_OFFICER_NAME', 'Rohan Sharma');
}
if (!defined('GRIEVANCE_OFFICER_DESIGNATION')) {
    define('GRIEVANCE_OFFICER_DESIGNATION', 'Grievance / Nodal Officer');
}
if (!defined('GRIEVANCE_OFFICER_EMAIL')) {
    define('GRIEVANCE_OFFICER_EMAIL', 'grievance@uniweb.co.in');
}
if (!defined('GRIEVANCE_OFFICER_PHONE')) {
    define('GRIEVANCE_OFFICER_PHONE', '+919900000002');
}

$pageTitle = 'Grievance Redressal';
require_once __DIR__ . '/header.php';
renderPublicLegalPage([
    'eyebrow' => 'Customer & Merchant Protection',
    'title' => 'Grievance Redressal',
    'summary' => 'A simple, time-bound way to raise and resolve complaints.',
    'effective' => '19 July 2026',
    'version' => '2026.07',
    'notice' => '<strong>Grievance Officer:</strong> ' . e(GRIEVANCE_OFFICER_NAME) . ' (' . e(GRIEVANCE_OFFICER_DESIGNATION) . ') — <a href="mailto:' . e(GRIEVANCE_OFFICER_EMAIL) . '">' . e(GRIEVANCE_OFFICER_EMAIL) . '</a> · ' . e(GRIEVANCE_OFFICER_PHONE) . '. Never share your OTP, PIN or CVV with anyone — even someone claiming to be from UniWeb.',
    'sections' => [
        ['Who can complain', '<p>Any merchant or customer using UniWeb can raise a complaint — about a payment, a technical issue, or anything else.</p>'],
        ['Step 1: Support ticket', '<p>Raise a ticket on the <a href="contact.php">Contact page</a> with your transaction ID, date, amount and a short description.<br><strong>Response time: 24 hours.</strong></p>'],
        ['Step 2: Grievance Officer', '<p>Not resolved in 5 working days? Email the Grievance Officer above directly.<br><strong>Acknowledged in 2 working days. Resolved in 7 working days.</strong></p>'],
        ['Step 3: Bank / partner', '<p>If the issue depends on your bank or payment partner (e.g. a delayed refund), we forward it to them and share their timeline with you.</p>'],
        ['Step 4: RBI Ombudsman', '<p>Still unresolved after 30 days? You can approach the RBI Ombudsman for Digital Transactions at <a href="https://cms.rbi.org.in" target="_blank" rel="noopener">cms.rbi.org.in</a>.</p>'],
        ['What to send us', '<p>Transaction ID, date, amount, and a short description. Never send your card number, CVV, PIN, password or OTP.</p>'],
        ['Suspicious activity?', '<p>Contact your bank immediately to secure your account, then let us know so we can help investigate.</p>'],
    ],
]);
require_once __DIR__ . '/footer.php';
