<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';

$pageTitle = 'PCI-DSS Readiness';
require_once __DIR__ . '/header.php';
renderPublicLegalPage([
    'eyebrow' => 'Security & Compliance',
    'title' => 'PCI-DSS Readiness Path',
    'summary' => 'How UniWeb approaches Payment Card Industry Data Security Standard diligence — honesty first, no invented badges.',
    'effective' => '15 August 2026',
    'version' => '2026.08',
    'notice' => '<strong>Important:</strong> UniWeb does not store, process, or transmit raw cardholder data (PAN, CVV, PIN). Card payments are handled by PCI-certified payment partners on their hosted pages. UniWeb is a merchant technology platform — we do not claim an independent PCI Level 1 certification or an RBI Payment Aggregator licence on this page. For diligence answers, start at <a href="trust.php">Trust centre</a>.',
    'sections' => [
        ['Our approach', '<p>UniWeb aims for <strong>SAQ-A style scope</strong> (lowest burden) because we do not touch raw card data when partners host card entry. This is a readiness path — not a completed independent assessment badge.</p>'],
        ['What we do NOT store', '<ul class="list-disc pl-6 space-y-1"><li>Primary Account Number (PAN) — full card numbers</li><li>Card verification values — CVV, CVC, CID</li><li>PINs or PIN blocks</li><li>Track data from magnetic stripes</li><li>Sensitive authentication data of any kind</li></ul>'],
        ['What we DO store', '<ul class="list-disc pl-6 space-y-1"><li>Last 4 digits of card number (for display only, when the partner returns them)</li><li>Card brand (Visa, Mastercard, RuPay, etc.)</li><li>Card expiry month/year (for display only, when returned)</li><li>Gateway transaction reference / order ID</li><li>Tokenized references from gateways (if the customer opts for saved cards with the partner)</li></ul>'],
        ['12 PCI-DSS Requirements — Readiness map', '
        <p class="text-sm text-amber-300/90 mb-3">Statuses below are internal readiness notes for questionnaires — not a QSA attestation. Never treat green rows as “PCI Level 1 certified”.</p>
        <table class="min-w-full text-sm mt-3">
        <thead class="text-xs text-gray-500 uppercase"><tr><th class="text-left py-2 pr-4">Requirement</th><th class="text-left py-2 pr-4">Status</th><th class="text-left py-2">How</th></tr></thead>
        <tbody class="divide-y divide-gray-800">
        <tr><td class="py-2 pr-4">1. Firewall config</td><td class="py-2 pr-4 text-sky-400">Delegated</td><td class="py-2 text-xs">Hosting provider (Hostinger) manages network firewalls</td></tr>
        <tr><td class="py-2 pr-4">2. Default passwords</td><td class="py-2 pr-4 text-sky-400">Control in place</td><td class="py-2 text-xs">Unique app/DB secrets; never commit live keys</td></tr>
        <tr><td class="py-2 pr-4">3. Stored cardholder data</td><td class="py-2 pr-4 text-sky-400">Out of scope (design)</td><td class="py-2 text-xs">No PAN/CVV on UniWeb. Only last 4 / brand when partner returns them</td></tr>
        <tr><td class="py-2 pr-4">4. Encrypt transmission</td><td class="py-2 pr-4 text-sky-400">Control in place</td><td class="py-2 text-xs">TLS via Hostinger. API and dashboard over HTTPS</td></tr>
        <tr><td class="py-2 pr-4">5. Anti-virus</td><td class="py-2 pr-4 text-sky-400">Delegated</td><td class="py-2 text-xs">Hosting provider manages server-side AV</td></tr>
        <tr><td class="py-2 pr-4">6. Secure development</td><td class="py-2 pr-4 text-sky-400">Control in place</td><td class="py-2 text-xs">PDO prepared statements, CSRF, input validation</td></tr>
        <tr><td class="py-2 pr-4">7. Restrict access</td><td class="py-2 pr-4 text-sky-400">Control in place</td><td class="py-2 text-xs">Merchant / staff roles; partner keys not on Support nav</td></tr>
        <tr><td class="py-2 pr-4">8. Unique IDs</td><td class="py-2 pr-4 text-sky-400">Control in place</td><td class="py-2 text-xs">Unique accounts; staff 2FA available; session tracking</td></tr>
        <tr><td class="py-2 pr-4">9. Physical access</td><td class="py-2 pr-4 text-sky-400">Delegated</td><td class="py-2 text-xs">Cloud-hosted; provider physical controls</td></tr>
        <tr><td class="py-2 pr-4">10. Track &amp; monitor</td><td class="py-2 pr-4 text-sky-400">Control in place</td><td class="py-2 text-xs">Transactions, staff actions, immutable audit export</td></tr>
        <tr><td class="py-2 pr-4">11. Security testing</td><td class="py-2 pr-4 text-amber-400">In progress</td><td class="py-2 text-xs">Integrity / smoke tests on deploy. External pen-test when Owner schedules</td></tr>
        <tr><td class="py-2 pr-4">12. Security policy</td><td class="py-2 pr-4 text-sky-400">Documented here</td><td class="py-2 text-xs">This page + Trust centre + staff practices</td></tr>
        </tbody></table>'],
        ['SAQ-A style scope', '<p>Because card entry stays on partner hosted pages when configured that way, UniWeb targets <strong>SAQ-A style</strong> scope. That is a questionnaire path — complete only after Owner engages assessment / scans as required. Until then: no badge on the homepage.</p>'],
        ['Partner gateways', '<p>Card rails (Razorpay, Cashfree, PayU, banks) maintain their own PCI and network certifications. Ask partners for current AOC / attestation when a deal diligence requires it — UniWeb does not invent or re-badge their Level 1 status as our own.</p>'],
        ['Encryption', '<ul class="list-disc pl-6 space-y-1"><li><strong>At rest:</strong> Sensitive fields (API keys, gateway credentials) encrypted</li><li><strong>In transit:</strong> TLS for site and API traffic</li><li><strong>Hashing:</strong> Passwords with modern password hashing</li><li><strong>Key management:</strong> Encryption keys in live config — never in the public git repo</li></ul>'],
        ['Incident response', '<p>In case of a suspected security incident:</p><ol class="list-decimal pl-6 space-y-1 mt-2"><li>Isolate affected systems</li><li>Notify affected merchants within applicable legal timelines</li><li>Escalate to partners / regulators only as required by contract and law</li><li>Post-incident review and remediation</li><li>Record in the incident / status process</li></ol>'],
        ['Roadmap (Owner-gated)', '<ul class="list-disc pl-6 space-y-1"><li>External penetration testing when Owner schedules</li><li>QSA / formal SAQ only when commercial diligence requires it</li><li>Quarterly vulnerability scans when contracted</li><li>Staff security awareness refresh</li><li>Partner AOC collection on a named deal checklist</li></ul>'],
    ],
]);
require_once __DIR__ . '/footer.php';
