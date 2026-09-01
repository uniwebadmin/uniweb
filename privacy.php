<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';
if (!function_exists('compliancePrivacyDeleteRequestSection')) {
    require_once __DIR__ . '/includes/compliance_workflow.php';
}
$pageTitle = 'Privacy Policy';
require_once __DIR__ . '/header.php';
renderPublicLegalPage([
    'eyebrow' => 'Data Protection',
    'title' => 'Privacy Policy',
    'summary' => 'What we collect, why, where it is processed, and your rights under the Digital Personal Data Protection Act, 2023.',
    'effective' => '15 August 2026',
    'version' => '2026.08',
    'notice' => '<strong>Our promise:</strong> We do not sell personal information. Never enter your card PIN, CVV or UPI PIN anywhere except your bank or payment app — and never share it with UniWeb support.',
    'sections' => [
        ['What we collect', '<ul><li><strong>From you:</strong> name, email, phone, address, business and bank details, KYC documents, video KYC session metadata (IP and time).</li><li><strong>From payments:</strong> order amount, transaction ID, status, refunds, partner references.</li><li><strong>Automatically:</strong> IP address, device info, login activity, cookies needed to keep you signed in.</li><li><strong>From the public website form:</strong> name, email, subject, message, IP — stored as a support ticket.</li></ul>'],
        ['Why we use it', '<ul><li>To create and verify merchant accounts (KYC).</li><li>To process, track and reconcile payments on partner rails.</li><li>To detect fraud, abuse and AML patterns.</li><li>For support, legal, tax and accounting needs.</li></ul>'],
        ['Where data is processed', '<p>UniWeb application and database hosting is in India (currently Hostinger). Payment partners process transaction data in their own environments under their licences. Transactional email may be sent through the SMTP service configured in Gateway Settings. We do not run a consumer wallet balance for end customers.</p>'],
        ['Who we share it with', '<p>Banks and payment partners needed to move money; hosting and email providers needed to run the site; authorities when legally required. We do not sell data to advertisers.</p>'],
        ['How long we keep it', '<p>As long as needed for the service, disputes, tax or legal rules. After that it is deleted or anonymized. Payment and KYC records may be kept longer because banks and regulators require it.</p>'],
        ['How we protect it', '<p>TLS in transit, access controls, activity logs. No system is 100% secure — report anything suspicious immediately.</p>'],
        ['Cookies', '<p>Essential cookies keep you logged in and protect forms. Clearing cookies may sign you out.</p>'],
        ['Your rights (DPDP Act, 2023)', compliancePrivacyDeleteRequestSection()],
        ['Children', '<p>Services are for adults and registered businesses only.</p>'],
        ['Questions or complaints', '<p>Email <a href="mailto:' . e(COMPANY_SUPPORT_EMAIL) . '">' . e(COMPANY_SUPPORT_EMAIL) . '</a>, or use <a href="grievance.php">Grievance Redressal</a> if unresolved.</p>'],
    ],
]);
require_once __DIR__ . '/footer.php';
