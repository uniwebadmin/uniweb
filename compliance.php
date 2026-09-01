<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';
$pageTitle = 'Compliance Framework';
require_once __DIR__ . '/header.php';

renderPublicLegalPage([
    'eyebrow' => 'Trust & Governance',
    'title' => 'Compliance Framework',
    'summary' => 'How UniWeb reviews merchants, watches transactions, and stays inside what we actually are: a technology platform on licensed partner rails.',
    'effective' => '15 August 2026',
    'version' => '2026.08',
    'notice' => '<strong>Who we are:</strong> UniWeb is a merchant technology platform. Live payments, UPI, cards and payouts are processed by licensed banking and payment partners. We do not independently hold an RBI Payment Aggregator or banking licence. We do not offer a consumer PPI wallet or an NBFC lending product.',
    'sections' => [
        ['Merchant verification (KYC)', '<p>Before Live Mode we verify business identity, owners, PAN, GST where applicable, address, bank account, website or use-case, and a signed merchant agreement. Video KYC, where required, is a live camera capture with IP and timestamp — not a file-upload-only shortcut.</p>'],
        ['AML & risk monitoring', '<p>We watch unusual amounts, repeated failures, velocity and other fraud indicators. Open KYC-pending AML flags stay until verification clears them. We may pause collections, hold settlement, or request extra evidence.</p>'],
        ['What we do not sell', '<p><strong>No consumer PPI wallet.</strong> Customers pay merchants; they do not keep a UniWeb prepaid balance. <strong>No NBFC lending product</strong> is offered from this platform. Those menus stay hidden because they are not our licence.</p>'],
        ['Payment aggregation via partners', '<p>Acquiring, UPI, cards, net banking and payouts run on contracted partners after commercial activation. Partner configuration on the Status page is not proof that a given merchant’s Live rail is healthy. <strong>Route / Split capture-time transfers (Razorpay Route, Cashfree Easy Split) are parked by default</strong> — live checkout uses standard collect + M/P settlement unless Owner explicitly enables Phase 11.</p>'],
        ['Website & business review', '<p>We check that a merchant’s website or declared use-case matches the category, with clear pricing, refund policy and contact details, before enabling live collections.</p>'],
        ['Settlement &amp; reconciliation', '<p>Successful collections are matched against partner records before settlement. Unresolved mismatches, refunds, reserves, chargebacks or legal holds can delay payout. Timing follows the written T+N schedule — not a public instant-settlement slogan.</p>'],
        ['Data &amp; information security', '<p>Encrypted connections, role-based access, audit logs. Merchants must protect API keys, use HTTPS, and never ask customers for OTP, UPI PIN or CVV.</p>'],
        ['Staff access', '<p>Staff see merchant data needed for their role. Sensitive KYC and Live activation use independent checker approval where configured.</p>'],
        ['Working with regulators', '<p>We cooperate with valid requests from courts, RBI, banks and payment partners as required by law.</p>'],
        ['Reporting a concern', '<p>Use the <a href="contact.php">Contact page</a> (ticket saved even if email is delayed), <a href="grievance.php">Grievance Redressal</a>, or check <a href="status.php">System Status</a>. Never share passwords, OTPs or card details.</p>'],
    ],
]);
require_once __DIR__ . '/footer.php';
