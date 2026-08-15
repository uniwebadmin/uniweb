<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';
$pageTitle = 'Refund Policy';
require_once __DIR__ . '/header.php';
renderPublicLegalPage([
    'eyebrow' => 'Customer Protection',
    'title' => 'Refund & Cancellation Policy',
    'summary' => 'How refunds, failed payments and disputes are handled.',
    'effective' => '15 August 2026',
    'version' => '2026.08',
    'notice' => '<strong>For customers:</strong> UniWeb provides the payment technology. The merchant you bought from decides refunds and cancellations. Never share your OTP, UPI PIN or card PIN while asking for a refund.',
    'sections' => [
        ['Who decides the refund', '<p>The merchant you bought from — not UniWeb — decides if you get a refund, based on their own policy. UniWeb can only tell you the payment status.</p>'],
        ['How refunds are processed', '<p>Once the merchant approves a refund, it\'s sent to your bank/UPI/card. Actual credit time depends on your bank — usually a few days.</p>'],
        ['Payment failed but money was deducted?', '<p>Don\'t worry — banks automatically reverse failed payments. Check your bank statement; if not reversed in a few days, contact your bank with the reference number.</p>'],
        ['Duplicate payment?', '<p>The merchant can refund the extra payment once both transactions are confirmed.</p>'],
        ['Cancelling an order', '<p>Cancelling an order does not automatically refund your money — the merchant must separately approve the refund as per their policy.</p>'],
        ['Disputes / chargebacks', '<p>You can raise a dispute with your bank or card network. The merchant must respond with proof (order, delivery, etc.) by the deadline given.</p>'],
        ['Unauthorized transaction?', '<p>Contact your bank immediately to secure your account. Then let us know so we can help investigate.</p>'],
        ['How to ask for a refund', '<p>Share your transaction ID, amount, date and a short description via the <a href="contact.php">Contact page</a>. We save a ticket even if email is delayed. Acknowledgement target: <strong>1 business day</strong>. Never send card numbers, CVV, PIN or OTP.</p>'],
        ['Still not resolved?', '<p>Use our <a href="grievance.php">Grievance Redressal</a> page for a structured escalation.</p>'],
    ],
]);
require_once __DIR__ . '/footer.php';
