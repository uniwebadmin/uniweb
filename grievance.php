<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';

$officerName = COMPANY_CEO;
$officerEmail = COMPANY_SUPPORT_EMAIL;
$officerPhone = COMPANY_PHONE;

$pageTitle = 'Grievance Redressal';
require_once __DIR__ . '/header.php';
renderPublicLegalPage([
    'eyebrow' => 'Customer & Merchant Protection',
    'title' => 'Grievance Redressal',
    'summary' => 'A named officer, a ticket reference, and a time-bound path — not a generic inbox with a fake name.',
    'effective' => '15 August 2026',
    'version' => '2026.08',
    'notice' => '<strong>Grievance Officer:</strong> ' . e($officerName) . ' (Managing Director / Grievance Officer, ' . e(COMPANY_LEGAL_NAME) . ') — <a href="mailto:' . e($officerEmail) . '">' . e($officerEmail) . '</a> · ' . e($officerPhone) . '. Never share your OTP, PIN or CVV with anyone — even someone claiming to be from UniWeb.',
    'sections' => [
        ['Who can complain', '<p>Any merchant or paying customer using UniWeb can raise a complaint about a payment, settlement, KYC decision, or a technical issue. Website messages are saved as a ticket even if email is delayed.</p>'],
        ['Step 1: Support ticket', '<p>Use the <a href="contact.php">Contact page</a> or a signed-in Portal ticket. Include merchant code, transaction or settlement ID, date, amount and a short description.<br><strong>Acknowledgement target: 1 business day.</strong></p>'],
        ['Step 2: Grievance Officer', '<p>Not resolved in 5 working days? Email the officer above with the same ticket reference and “Grievance” in the subject.<br><strong>Acknowledged in 2 working days. Aimed resolution: 7 working days.</strong></p>'],
        ['Step 3: Bank / partner', '<p>If the issue depends on a bank or payment partner (delayed refund, UPI dispute, card chargeback), we forward it and share their timeline. UniWeb cannot override issuer or NPCI decisions.</p>'],
        ['Step 4: RBI Ombudsman', '<p>Still unresolved after 30 days? You may approach the RBI Ombudsman for Digital Transactions at <a href="https://cms.rbi.org.in" target="_blank" rel="noopener">cms.rbi.org.in</a>.</p>'],
        ['What to send us', '<p>Ticket or inquiry ID (CTI…), transaction ID, date, amount, and a short description. Never send card number, CVV, PIN, password or OTP.</p>'],
        ['Suspicious activity?', '<p>Contact your bank immediately to secure the account, then tell us so we can investigate on the platform side.</p>'],
    ],
]);
require_once __DIR__ . '/footer.php';
