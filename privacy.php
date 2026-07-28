<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';
$pageTitle = 'Privacy Policy';
require_once __DIR__ . '/header.php';
renderPublicLegalPage([
    'eyebrow' => 'Data Protection',
    'title' => 'Privacy Policy',
    'summary' => 'What information we collect, why, and the choices you have.',
    'effective' => '19 July 2026',
    'version' => '2026.07',
    'notice' => '<strong>Our promise:</strong> We never sell your personal information. Never enter your card PIN, CVV or UPI PIN anywhere except your bank or payment app — and never share it with UniWeb support.',
    'sections' => [
        ['What we collect', '<ul><li><strong>From you:</strong> name, email, phone, address, business and bank details, KYC documents.</li><li><strong>From payments:</strong> order amount, transaction ID, status, refunds.</li><li><strong>Automatically:</strong> IP address, device info, login activity, cookies.</li></ul>'],
        ['Why we use it', '<ul><li>To create your account and verify your identity (KYC).</li><li>To process, track and reconcile payments.</li><li>To detect fraud and keep accounts secure.</li><li>For support, legal and accounting needs.</li></ul>'],
        ['Who we share it with', '<p>Only banks, payment partners, and service providers who need it to do their job — never sold to advertisers. We may share information with authorities if legally required.</p>'],
        ['How long we keep it', '<p>Only as long as needed for the service, disputes, tax or legal rules. After that, it\'s deleted or anonymized.</p>'],
        ['How we protect it', '<p>Encrypted connections, access controls, activity logs, and regular security checks. No system is 100% secure — report anything suspicious right away, and never share your OTP, password or PINs with anyone.</p>'],
        ['Cookies', '<p>We use essential cookies to keep you logged in and secure. You can clear cookies in your browser, but some features may stop working.</p>'],
        ['Your rights (DPDP Act, 2023)', '<p>You can ask to see, correct, or delete your data, or withdraw consent — email <a href="mailto:privacy@uniweb.co.in">privacy@uniweb.co.in</a>. We respond within 30 days. Some records must be kept longer for legal reasons (like payment history).</p>'],
        ['Children', '<p>Our services are for adults and registered businesses only.</p>'],
        ['Questions or complaints', '<p>Email <a href="mailto:privacy@uniweb.co.in">privacy@uniweb.co.in</a>, or use our <a href="grievance.php">Grievance Redressal</a> page if unresolved.</p>'],
    ],
]);
require_once __DIR__ . '/footer.php';
