<?php
declare(strict_types=1);

function merchantAgreementVersion(): string
{
    return '2026.07.19';
}

function merchantAgreementSections(): array
{
    return [
        ['Parties and electronic acceptance', '<p>This Merchant Services Agreement is entered into between <strong>' . e(COMPANY_LEGAL_NAME) . '</strong> (“UniWeb”, “Platform”, “we”) and the person or entity registered as a merchant (“Merchant”, “you”). Electronic acceptance, including acceptance from the authenticated Merchant Portal, has the same effect as a signed acceptance to the extent permitted by applicable law.</p>'],
        ['Platform role and service availability', '<p>UniWeb provides technology for merchant onboarding, payment links, QR-based collections, transaction reporting, support workflows and settlement instructions. Live payment processing, acquiring, banking, verification and payout services may be performed by regulated banks, payment gateways or other approved partners. No service is represented as active until it is enabled for the Merchant.</p>'],
        ['Onboarding, KYC and due diligence', '<p>The Merchant must provide accurate identity, ownership, business, tax and bank information. UniWeb may request PAN, GSTIN, Udyam, incorporation records, beneficial-owner details, address proof, bank proof, video KYC or additional evidence. Access to Live Mode may be withheld, limited or withdrawn until due diligence is satisfactory.</p>'],
        ['Merchant business and declared use case', '<p>The Merchant may use the Platform only for the business, website, products, services, MCC and collection model declared during onboarding. Processing for an undisclosed third party, payment aggregation, sub-merchant activity or a material business-model change requires prior written approval.</p>'],
        ['Prohibited and restricted activity', '<p>The Merchant must not use the Platform for unlawful goods or services, money laundering, fraud, deceptive sales, unauthorized financial services, prohibited lending, gambling, adult content, narcotics, weapons, sanctions evasion or any activity restricted by a banking or payment partner. UniWeb may apply a stricter partner-specific restricted-business policy.</p>'],
        ['Payment authorization and customer consent', '<p>The Merchant is responsible for obtaining a valid customer order and authorization, providing accurate descriptions and prices, issuing invoices where required, and maintaining proof of delivery or service. The Merchant must not split, recycle or disguise transactions to avoid limits or monitoring.</p>'],
        ['Fees, taxes and pricing changes', '<p>Applicable setup fees, platform fees, gateway charges, MDR, refund charges, settlement charges and taxes are shown in the Merchant Portal, commercial proposal or pricing schedule. Rates may vary by payment method, risk category and partner. Changes will be notified through the Portal, email or an updated commercial schedule before they take effect where practicable.</p>'],
        ['Settlement, reserves and holds', '<p>Net eligible funds are settled to the verified primary bank account after applicable fees, refunds, reversals, disputes, reserves and adjustments. Timelines are estimates and may be affected by bank holidays, partner processing, risk review, reconciliation or legal directions. UniWeb may hold or delay funds reasonably required for suspected fraud, disputes, negative balance, compliance review or partner instructions.</p>'],
        ['Refunds, reversals and chargebacks', '<p>The Merchant is responsible for its cancellation and refund policy and must respond to disputes within the stated deadline. Refunds and reversals may be deducted from unsettled funds, wallet balance or future settlements. Evidence requested for a chargeback must be complete, authentic and submitted on time; a final decision may be made by the issuer, network, bank or gateway.</p>'],
        ['Security and account controls', '<p>The Merchant must protect passwords, OTPs, API keys, webhook secrets and devices; restrict staff permissions; and promptly report suspected compromise. API credentials must never be placed in public code or shared with unauthorized parties. Activity performed through valid credentials may be treated as authorized until compromise is reported.</p>'],
        ['Data protection and customer information', '<p>Each party will process personal data for legitimate payment, compliance, fraud-prevention and support purposes and comply with applicable Indian data-protection requirements. The Merchant must provide required notices to its customers and must not collect or transmit prohibited card authentication data through free-text fields or support messages.</p>'],
        ['Monitoring, audit and records', '<p>UniWeb may monitor transactions, velocity, device and account activity and may request order, invoice, delivery, customer-consent or source-of-funds records. The Merchant must retain legally required records and reasonably cooperate with audits, partner reviews, regulatory requests and reconciliation.</p>'],
        ['Intellectual property and acceptable integration', '<p>UniWeb retains rights in its software, dashboard, documentation, trademarks and APIs. The Merchant receives a limited, revocable, non-transferable right to use enabled services for its approved business. The Merchant may not reverse engineer, resell, misrepresent, copy branding or interfere with Platform security.</p>'],
        ['Suspension and termination', '<p>Either party may terminate subject to outstanding obligations. UniWeb may immediately suspend access or settlements for fraud indicators, prohibited activity, false KYC, security risk, excessive disputes, legal requirement, partner instruction or material breach. Termination does not remove obligations relating to refunds, disputes, fees, confidentiality, records or pending settlements.</p>'],
        ['Warranties, indemnity and liability', '<p>The Merchant warrants that its information, products, marketing and transactions are lawful and accurate. The Merchant will indemnify UniWeb for losses arising from its unlawful activity, customer claims, tax failures, data misuse or breach. Services depend on third-party networks and are provided subject to availability. Liability exclusions and caps apply to the maximum extent permitted by law and do not exclude liability that cannot lawfully be excluded.</p>'],
        ['Confidentiality', '<p>Non-public commercial, technical, security and customer information must be protected and used only for the Agreement. Confidentiality does not cover information lawfully public, independently developed or required to be disclosed by law, court, regulator or payment partner.</p>'],
        ['Notices, amendments and assignment', '<p>Notices may be delivered through the Portal, registered email or written communication. UniWeb may update this Agreement for legal, partner, security or product changes and will identify the effective version. Continued use after notice constitutes acceptance where permitted; material changes may require fresh electronic acceptance.</p>'],
        ['Governing law and dispute resolution', '<p>This Agreement is governed by the laws of India. The parties will first attempt good-faith resolution through the grievance channel. Subject to mandatory law, courts with jurisdiction over the registered office of ' . e(COMPANY_LEGAL_NAME) . ' will have jurisdiction.</p>'],
    ];
}

function renderPublicLegalPage(array $page): void
{
    $sections = $page['sections'] ?? [];
    ?>
    <main class="public-doc-page">
        <section class="public-doc-hero">
            <div class="public-doc-shell">
                <div class="public-doc-eyebrow"><?= e($page['eyebrow'] ?? 'Legal & Compliance') ?></div>
                <h1><?= e($page['title'] ?? '') ?></h1>
                <p><?= e($page['summary'] ?? '') ?></p>
                <div class="public-doc-meta">
                    <span>Effective: <?= e($page['effective'] ?? '19 July 2026') ?></span>
                    <?php if (!empty($page['version'])): ?><span>Version <?= e($page['version']) ?></span><?php endif; ?>
                    <span><?= e(COMPANY_LEGAL_NAME) ?></span>
                </div>
            </div>
        </section>
        <div class="public-doc-shell public-doc-layout">
            <aside class="public-doc-toc">
                <strong>On this page</strong>
                <nav>
                    <?php foreach ($sections as $i => $section): ?>
                    <a href="#section-<?= $i + 1 ?>"><?= $i + 1 ?>. <?= e($section[0]) ?></a>
                    <?php endforeach; ?>
                </nav>
                <div class="public-doc-help">
                    <span>Need clarification?</span>
                    <a href="contact.php">Contact support →</a>
                </div>
            </aside>
            <article class="public-doc-article">
                <?php if (!empty($page['notice'])): ?><div class="public-doc-notice"><?= $page['notice'] ?></div><?php endif; ?>
                <?php foreach ($sections as $i => $section): ?>
                <section id="section-<?= $i + 1 ?>" class="public-doc-section">
                    <div class="public-doc-number"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></div>
                    <div><h2><?= e($section[0]) ?></h2><div class="public-doc-copy"><?= $section[1] ?></div></div>
                </section>
                <?php endforeach; ?>
                <?php if (!empty($page['after'])): ?><?= $page['after'] ?><?php endif; ?>
                <section class="public-doc-company">
                    <h2>Company and grievance contact</h2>
                    <p><strong><?= e(COMPANY_LEGAL_NAME) ?></strong><br>CIN: <?= e(COMPANY_CIN) ?> · GST: <?= e(COMPANY_GST) ?><br><?= e(COMPANY_ADDRESS) ?></p>
                    <p><a href="mailto:<?= e(COMPANY_SUPPORT_EMAIL) ?>"><?= e(COMPANY_SUPPORT_EMAIL) ?></a> · <?= e(COMPANY_PHONE) ?> · <a href="<?= e(COMPANY_MAP_URL) ?>" target="_blank" rel="noopener">Registered office map</a></p>
                </section>
            </article>
        </div>
    </main>
    <?php
}
