<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';
$pageTitle = 'Compliance Framework';
require_once __DIR__ . '/header.php';

renderPublicLegalPage([
    'eyebrow' => 'Trust & Governance',
    'title' => 'Compliance Framework',
    'summary' => 'How UniWeb approaches merchant due diligence, payment-partner controls, transaction monitoring, customer protection, information security and regulatory cooperation.',
    'effective' => '19 July 2026',
    'version' => '2026.07',
    'notice' => '<strong>Accurate positioning:</strong> UniWeb is a technology platform preparing and enabling services through contracted banking and payment partners. This page does not claim that UniWeb independently holds an RBI payment-aggregator, banking or card-network licence.',
    'sections' => [
        ['Governance approach', '<p>Compliance is built into merchant onboarding, permissions, payment workflows, settlement controls and support. Controls are reviewed as products, partner requirements and applicable Indian laws change. Final obligations may differ by merchant category, transaction type and enabled partner.</p>'],
        ['Merchant identification and KYC', '<p>Before Live Mode, we may verify the legal entity, authorized signatory, beneficial owners, PAN, GSTIN, registration records, address and bank account. Entity-specific documents reduce unnecessary collection. Inconsistencies, expired records or unverifiable ownership require clarification.</p>'],
        ['Business and website review', '<p>We review the declared business model, products, pricing, fulfilment, refund policy, contact details, legal pages, domain ownership and intended collection flow. The live collection use case must match the approved business and merchant category.</p>'],
        ['AML and prohibited activity', '<p>Risk controls are intended to identify suspicious patterns, undisclosed third-party collection, rapid fund movement, account takeover, fraud and prohibited business. Alerts may trigger document requests, transaction review, limits, settlement hold, reporting or suspension as applicable.</p>'],
        ['Sanctions and adverse information', '<p>Where required by law or partner policy, merchants, owners and counterparties may be screened against applicable sanctions, watchlists and adverse information. A potential match is reviewed before action; screening alone is not a finding of wrongdoing.</p>'],
        ['Transaction and velocity monitoring', '<p>Signals may include repeated failures, unusual amount or volume, device and IP activity, refund or chargeback patterns, customer concentration and deviation from the declared use case. Controls may block, step up verification or send activity for manual review.</p>'],
        ['Payment-partner controls', '<p>Live payment methods are enabled only after relevant commercial and technical activation. Partner rules may impose additional limits, restricted categories, data requirements, settlement timelines, reserves and dispute processes. Partner confirmation is authoritative for network transaction states.</p>'],
        ['Settlement and reconciliation', '<p>Transactions are reconciled against available gateway, webhook and bank records. Unmatched or inconsistent items are investigated before final settlement where needed. Settlement can be delayed for unresolved reconciliation, disputes, negative balance, risk review or lawful direction.</p>'],
        ['Customer protection', '<p>Merchants must display accurate identity, price, delivery, cancellation, refund and support information. Customers should receive an order reference and use only authorized checkout interfaces. Complaints are routed to the responsible merchant and technical payment status is investigated where available.</p>'],
        ['Data protection', '<p>Personal information is collected for defined onboarding, payment, security, support and legal purposes. Access is role-based and activity may be logged. Information is shared only as needed with authorized service providers, payment partners or authorities under applicable requirements.</p>'],
        ['Information and API security', '<p>Controls include secure transport, session protection, rate limits, credential separation, webhook verification, audit logging and restricted administrative access. Merchants remain responsible for protecting API keys, using HTTPS, validating webhook signatures and maintaining secure applications.</p>'],
        ['Operational resilience', '<p>We maintain monitoring, backups, incident handling and recovery procedures appropriate to the Platform. Dependencies on telecom, cloud, bank and gateway systems mean uninterrupted service cannot be guaranteed. Material incidents are assessed, contained and communicated as required.</p>'],
        ['Staff access and accountability', '<p>Administrative and staff access is limited by role and merchant scope. Sensitive actions may be recorded with actor, timestamp and context. Personnel should access merchant information only for assigned operational, support, compliance or security work.</p>'],
        ['Audit records and retention', '<p>KYC decisions, agreement acceptance, account changes, payment events, settlement actions and support communication may be retained for contractual, audit, fraud, tax and legal needs. Retention follows applicable obligations and the Privacy Policy.</p>'],
        ['Regulatory and law-enforcement requests', '<p>We respond to valid requests from courts, regulators, government bodies, banks and payment partners after appropriate review. We may preserve records or restrict activity where legally required and cannot notify an affected user when notice is prohibited.</p>'],
        ['Reporting a concern', '<p>Security concerns, suspected misuse, customer complaints and privacy grievances can be reported through the <a href="contact.php">Contact page</a>. Include relevant IDs and dates but never include passwords, OTPs, CVV, PINs or complete card credentials.</p>'],
    ],
]);
require_once __DIR__ . '/footer.php';
