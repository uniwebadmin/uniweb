<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';
$pageTitle = 'Compliance Framework';
require_once __DIR__ . '/header.php';

renderPublicLegalPage([
    'eyebrow' => 'Trust & Governance',
    'title' => 'Compliance Framework',
    'summary' => 'How we keep merchants, customers and payments safe.',
    'effective' => '19 July 2026',
    'version' => '2026.07',
    'notice' => '<strong>Who we are:</strong> UniWeb is a technology platform. Live payments are processed by our licensed banking and payment partners — we do not claim to independently hold an RBI Payment Aggregator or banking licence.',
    'sections' => [
        ['Merchant verification (KYC)', '<p>Before going live, we verify the business identity, owners, PAN, GST, address and bank account. Live payments start only after this is complete.</p>'],
        ['Fraud & risk monitoring', '<p>We watch for suspicious patterns — unusual amounts, repeated failures, fraud indicators — and may pause or review a merchant\'s account if something looks wrong.</p>'],
        ['Website & business review', '<p>We check that a merchant\'s website matches their declared business, with clear pricing, refund policy and contact details, before enabling live payments.</p>'],
        ['Settlement & reconciliation', '<p>Every transaction is matched against bank and gateway records before money is settled. Unresolved mismatches or disputes can delay settlement.</p>'],
        ['Data & information security', '<p>We use encrypted connections, access controls, and audit logs. Merchants must protect their own API keys and use HTTPS.</p>'],
        ['Staff access', '<p>Our staff can only access merchant data relevant to their job, and sensitive actions are logged.</p>'],
        ['Working with regulators', '<p>We cooperate with valid requests from courts, RBI, banks and payment partners as required by law.</p>'],
        ['Reporting a concern', '<p>Report anything suspicious via our <a href="contact.php">Contact page</a> or <a href="grievance.php">Grievance Redressal</a> page. Never share passwords, OTPs or card details.</p>'],
    ],
]);
require_once __DIR__ . '/footer.php';
