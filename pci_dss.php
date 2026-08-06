<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';

$pageTitle = 'PCI-DSS Readiness';
require_once __DIR__ . '/header.php';
renderPublicLegalPage([
    'eyebrow' => 'Security & Compliance',
    'title' => 'PCI-DSS Readiness Path',
    'summary' => 'How UniWeb approaches Payment Card Industry Data Security Standard compliance.',
    'effective' => '19 July 2026',
    'version' => '2026.07',
    'notice' => '<strong>Important:</strong> UniWeb does not store, process, or transmit raw cardholder data (PAN, CVV, PIN). All card payments are handled by PCI-DSS certified payment gateways. UniWeb operates as a payment aggregator that routes transactions to certified partners.',
    'sections' => [
        ['Our approach', '<p>UniWeb is designed to be <strong>PCI-DSS SAQ-A eligible</strong> — the lowest burden tier — because we never touch raw card data. Card details are entered directly on the payment gateway\'s hosted page (Razorpay, Cashfree, PayU), and we only receive a tokenized reference back.</p>'],
        ['What we do NOT store', '<ul class="list-disc pl-6 space-y-1"><li>Primary Account Number (PAN) — full card numbers</li><li>Card verification values — CVV, CVC, CID</li><li>PINs or PIN blocks</li><li>Track data from magnetic stripes</li><li>Sensitive authentication data of any kind</li></ul>'],
        ['What we DO store', '<ul class="list-disc pl-6 space-y-1"><li>Last 4 digits of card number (for display only)</li><li>Card brand (Visa, Mastercard, RuPay, etc.)</li><li>Card expiry month/year (for display only)</li><li>Gateway transaction reference / order ID</li><li>Tokenized card references from gateways (if customer opts for saved cards)</li></ul>'],
        ['12 PCI-DSS Requirements — Our Status', '
        <table class="min-w-full text-sm mt-3">
        <thead class="text-xs text-gray-500 uppercase"><tr><th class="text-left py-2 pr-4">Requirement</th><th class="text-left py-2 pr-4">Status</th><th class="text-left py-2">How</th></tr></thead>
        <tbody class="divide-y divide-gray-800">
        <tr><td class="py-2 pr-4">1. Firewall config</td><td class="py-2 pr-4 text-emerald-400">N/A — delegated</td><td class="py-2 text-xs">Hosting provider (Hostinger) manages network firewalls</td></tr>
        <tr><td class="py-2 pr-4">2. Default passwords</td><td class="py-2 pr-4 text-emerald-400">Compliant</td><td class="py-2 text-xs">All DB/app passwords are unique, rotated, stored in secrets manager</td></tr>
        <tr><td class="py-2 pr-4">3. Stored cardholder data</td><td class="py-2 pr-4 text-emerald-400">N/A</td><td class="py-2 text-xs">We do not store PAN/CVV. Only last 4 digits and brand</td></tr>
        <tr><td class="py-2 pr-4">4. Encrypt transmission</td><td class="py-2 pr-4 text-emerald-400">Compliant</td><td class="py-2 text-xs">TLS 1.2+ enforced site-wide via Hostinger. All API calls use HTTPS</td></tr>
        <tr><td class="py-2 pr-4">5. Anti-virus</td><td class="py-2 pr-4 text-emerald-400">N/A — delegated</td><td class="py-2 text-xs">Hosting provider manages server-side AV</td></tr>
        <tr><td class="py-2 pr-4">6. Secure development</td><td class="py-2 pr-4 text-emerald-400">Compliant</td><td class="py-2 text-xs">Prepared statements (PDO), CSRF tokens, input validation, no SQL injection</td></tr>
        <tr><td class="py-2 pr-4">7. Restrict access</td><td class="py-2 pr-4 text-emerald-400">Compliant</td><td class="py-2 text-xs">Role-based access (merchant, staff, admin). Least-privilege principle</td></tr>
        <tr><td class="py-2 pr-4">8. Unique IDs</td><td class="py-2 pr-4 text-emerald-400">Compliant</td><td class="py-2 text-xs">Every user/staff has unique account. TOTP 2FA for staff. Session tracking</td></tr>
        <tr><td class="py-2 pr-4">9. Physical access</td><td class="py-2 pr-4 text-emerald-400">N/A — delegated</td><td class="py-2 text-xs">Cloud-hosted, no on-premise servers. Hosting provider manages physical security</td></tr>
        <tr><td class="py-2 pr-4">10. Track & monitor</td><td class="py-2 pr-4 text-emerald-400">Compliant</td><td class="py-2 text-xs">All transactions, staff actions, logins logged. Audit trail in platform_audit_runs</td></tr>
        <tr><td class="py-2 pr-4">11. Security testing</td><td class="py-2 pr-4 text-amber-400">In progress</td><td class="py-2 text-xs">Integrity tests run on every deploy. External penetration testing planned pre-launch</td></tr>
        <tr><td class="py-2 pr-4">12. Security policy</td><td class="py-2 pr-4 text-emerald-400">Compliant</td><td class="py-2 text-xs">This document + internal security policy + staff training plan</td></tr>
        </tbody></table>'],
        ['SAQ-A eligibility', '<p>Because UniWeb never touches cardholder data — all card entry happens on the payment gateway\'s PCI-certified hosted pages — we qualify for <strong>SAQ-A</strong> (the simplest Self-Assessment Questionnaire). This means:</p><ul class="list-disc pl-6 space-y-1 mt-2"><li>No cardholder data environment (CDE) on our servers</li><li>No card data flows through our network</li><li>We only receive transaction results and tokens</li><li>Annual SAQ-A self-assessment + quarterly vulnerability scans</li></ul>'],
        ['Partner gateways', '<p>All payment partners (Razorpay, Cashfree, PayU, Axis Bank, Decentro) are PCI-DSS Level 1 certified — the highest level. They handle all card data processing on our behalf. We maintain attestations of compliance (AOCs) from each partner.</p>'],
        ['Encryption', '<ul class="list-disc pl-6 space-y-1"><li><strong>At rest:</strong> Sensitive fields (API keys, gateway credentials) encrypted with AES-256-CBC</li><li><strong>In transit:</strong> TLS 1.2+ enforced for all connections</li><li><strong>Hashing:</strong> Passwords hashed with bcrypt/Argon2</li><li><strong>Key management:</strong> Encryption keys stored in config, never in database or code repo</li></ul>'],
        ['Incident response', '<p>In case of a suspected security incident:</p><ol class="list-decimal pl-6 space-y-1 mt-2"><li>Immediate isolation of affected systems</li><li>Notification to affected merchants/customers within 72 hours</li><li>Report to RBI (if applicable) within 7 days</li><li>Post-incident review and remediation</li><li>Documentation in incident register</li></ol>'],
        ['Roadmap to full compliance', '<ul class="list-disc pl-6 space-y-1"><li>Complete external penetration testing (pre-launch)</li><li>Engage Qualified Security Assessor (QSA) for formal SAQ-A review</li><li>Implement automated vulnerability scanning (quarterly)</li><li>Staff security awareness training (annual)</li><li>Vendor security assessment for all partners (annual)</li></ul>'],
    ],
]);
require_once __DIR__ . '/footer.php';
