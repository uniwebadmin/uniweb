<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';

$pageTitle = 'Responsibility Matrix';
require_once __DIR__ . '/header.php';
renderPublicLegalPage([
    'eyebrow' => 'Roles & Accountability',
    'title' => 'Responsibility Matrix',
    'summary' => 'Who is responsible for what in the UniWeb payment ecosystem.',
    'effective' => '19 July 2026',
    'version' => '2026.07',
    'notice' => '<strong>Important:</strong> This matrix defines the division of responsibilities between UniWeb, the Merchant, the Payment Partner (gateway/bank), and the Customer. It forms part of the Merchant Agreement.',
    'sections' => [
        ['Overview', '<p>UniWeb operates a merchant technology platform. Live payments are routed through licensed banking and payment partners. We manage merchant onboarding and KYC, reconciliation tools, and grievance intake. We do not independently hold an RBI Payment Aggregator licence unless that is disclosed on the Trust centre. We do not offer a consumer PPI wallet or an NBFC lending product.</p>'],
        ['Responsibility Matrix', '
        <div class="overflow-x-auto mt-3">
        <table class="min-w-full text-sm border border-gray-800 rounded-lg">
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
        <tr><th class="text-left py-3 px-4">Activity</th><th class="text-center py-3 px-2">UniWeb</th><th class="text-center py-3 px-2">Merchant</th><th class="text-center py-3 px-2">Partner</th><th class="text-center py-3 px-2">Customer</th></tr>
        </thead>
        <tbody class="divide-y divide-gray-800">
        <tr><td class="py-2 px-4">Merchant onboarding & KYC</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Business verification</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Payment page hosting</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Card/UPI data security (PCI-DSS)</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Transaction routing</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Payment success/failure notification</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Settlement to merchant bank account</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Refund initiation</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Refund processing to customer</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Chargeback handling</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Dispute evidence submission</td><td class="text-center text-gray-600">—</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Reconciliation & settlement matching</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Risk scoring & fraud detection</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Velocity checks & block</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Rolling reserve hold</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Grievance redressal</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td></tr>
        <tr><td class="py-2 px-4">AML / sanctions screening</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Data privacy (customer data)</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td></tr>
        <tr><td class="py-2 px-4">Compliance reporting (RBI)</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">QR code generation & management</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Payment link creation</td><td class="text-center text-gray-600">—</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Customer support (first response)</td><td class="text-center text-gray-600">—</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Technical support (platform issues)</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td></tr>
        <tr><td class="py-2 px-4">Bank-side issues (failed transfers)</td><td class="text-center text-gray-600">—</td><td class="text-center text-gray-600">—</td><td class="text-center text-emerald-400">●</td><td class="text-center text-emerald-400">●</td></tr>
        <tr><td class="py-2 px-4">Providing correct bank details</td><td class="text-center text-gray-600">—</td><td class="text-center text-emerald-400">●</td><td class="text-center text-gray-600">—</td><td class="text-center text-emerald-400">●</td></tr>
        </tbody>
        </table>
        </div>
        <p class="text-xs text-gray-500 mt-2">● = Responsible &nbsp; — = Not responsible</p>'],
        ['UniWeb responsibilities', '<ul class="list-disc pl-6 space-y-1"><li>Merchant onboarding, KYC verification, and ongoing monitoring</li><li>Transaction routing to appropriate payment partners</li><li>Settlement processing and reconciliation</li><li>Risk scoring, fraud detection, velocity checks</li><li>Rolling reserve management</li><li>AML/sanctions screening and compliance reporting</li><li>Grievance redressal as per RBI guidelines</li><li>Platform uptime, security, and data protection</li><li>Providing merchant dashboard, analytics, and reporting tools</li></ul>'],
        ['Merchant responsibilities', '<ul class="list-disc pl-6 space-y-1"><li>Providing accurate business and KYC documents</li><li>Maintaining valid bank account details</li><li>Delivering goods/services as promised to customers</li><li>Initiating refunds when applicable</li><li>Submitting chargeback evidence within timelines</li><li>Providing first-level customer support</li><li>Complying with applicable laws and regulations</li><li>Not using the platform for prohibited businesses</li><li>Maintaining accurate product/service descriptions</li></ul>'],
        ['Payment Partner responsibilities', '<ul class="list-disc pl-6 space-y-1"><li>PCI-DSS compliant card data processing</li><li>Payment authorization and processing</li><li>Settlement to UniWeb\'s nodal account</li><li>Refund processing to customer accounts</li><li>Chargeback arbitration and resolution</li><li>Bank-side technical infrastructure</li><li>Providing settlement files and reconciliation data</li></ul>'],
        ['Customer responsibilities', '<ul class="list-disc pl-6 space-y-1"><li>Providing accurate payment information</li><li>Keeping card/UPI credentials secure (never sharing OTP, PIN, CVV)</li><li>Raising disputes promptly for unauthorized transactions</li><li>Providing correct bank account details for refunds</li><li>Contacting their bank for bank-side issues</li></ul>'],
        ['Escalation path', '<ol class="list-decimal pl-6 space-y-1"><li><strong>Level 1:</strong> Merchant provides first-level support to customer</li><li><strong>Level 2:</strong> UniWeb support team (via ticket or email)</li><li><strong>Level 3:</strong> UniWeb Grievance Officer (' . e(COMPANY_SUPPORT_EMAIL) . ')</li><li><strong>Level 4:</strong> Payment Partner (for gateway/bank-side issues)</li><li><strong>Level 5:</strong> RBI Ombudsman (if unresolved after 30 days)</li></ol>'],
        ['Liability', '<p>Each party is liable for their respective responsibilities as defined above. UniWeb\'s liability is limited to the transaction amount in dispute and does not extend to indirect or consequential damages. For details, see our <a href="terms.php" class="text-brand-400 hover:underline">Terms of Service</a> and <a href="business_agreement.php" class="text-brand-400 hover:underline">Merchant Agreement</a>.</p>'],
    ],
]);
require_once __DIR__ . '/footer.php';
