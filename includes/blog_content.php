<?php
declare(strict_types=1);

function ensureProfessionalBlogContent(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS blog_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(200) NOT NULL UNIQUE,
        title_en VARCHAR(300) NOT NULL,
        excerpt_en TEXT,
        content_en LONGTEXT,
        status ENUM('draft','published') DEFAULT 'published',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $posts = [
        [
            'upi-payments-guide-2026',
            'UPI Payment Operations: A Practical Guide for Indian Merchants',
            'Understand QR types, payment confirmation, reconciliation, refunds and the controls a merchant needs beyond simply displaying a UPI code.',
            '<h2>UPI is a payment flow, not only a QR image</h2><p>A professional merchant setup connects the customer’s payment action to an order, a transaction identifier, a final status and a reconciliation record. A screenshot or customer confirmation is not enough. The merchant should fulfil an order only after the dashboard, API or verified webhook reports final success.</p><h2>Choose the correct collection experience</h2><p>An all-method QR can open a checkout with the payment methods enabled for the merchant. A dynamic UPI QR can encode order-specific amount and reference information. A fixed-amount QR is useful for a known price, while a reusable counter QR may let the customer enter an amount. Separate QR records also make branch, campaign and counter-level reporting easier.</p><h2>Pending is not paid</h2><p>Network timeouts can leave a payment pending even when the customer sees a debit. Do not create a second untracked order or manually mark success based on a message. Keep the order pending, poll the authorized status endpoint where available and reconcile the bank or gateway reference. A failed debit may reverse automatically through the customer’s bank.</p><h2>Reconcile every business day</h2><p>Compare successful orders, gateway records, webhooks, refunds and settlement entries. Investigate missing webhooks, duplicate references and amount mismatches. The total collected amount is not the same as the amount eligible for settlement after fees, refunds, disputes, reserves or adjustments.</p><h2>Protect the customer</h2><p>Show the merchant name, order description, amount and support channel clearly. Never request a UPI PIN to receive a refund. A UPI PIN authorizes a debit and should be entered only in the customer’s UPI application. Refunds should follow the original payment route and include a traceable reference.</p><h2>Move from Test to Live carefully</h2><p>Test Mode validates screens and integration behavior but does not prove that a live bank or payment partner has approved the merchant. Before launch, complete KYC, confirm the approved use case, activate live credentials, verify webhooks, test small controlled payments and document the refund and settlement process for support staff.</p>',
        ],
        [
            'kyc-documents-checklist',
            'Merchant KYC Checklist by Business Entity',
            'A practical document guide for proprietorships, partnerships, LLPs and companies, including why bank and website checks matter.',
            '<h2>Why entity-specific KYC matters</h2><p>KYC should establish who the merchant is, who controls the business, what it sells and where eligible funds should be settled. Asking every applicant for every document creates confusion. The correct list depends on the legal entity, business category, ownership and payment partner.</p><h2>Sole proprietorship</h2><p>A proprietor will commonly need personal PAN, identity and address evidence, a business proof such as GST or Udyam where applicable, bank proof showing the proprietor or trade name, and a recent photograph or video verification. The spelling across PAN, bank and business records should be consistent or supported by an explanation.</p><h2>Partnership and LLP</h2><p>A partnership may need the partnership deed, firm PAN, registration or GST evidence, partner identity records, authorization and bank proof. An LLP generally needs its incorporation and LLP identification records, agreement, designated-partner information, registered-office proof and entity bank account.</p><h2>Private or public company</h2><p>Company review commonly includes certificate of incorporation, CIN, company PAN, constitutional documents, registered-office evidence, board authorization, director and beneficial-owner information, GST where applicable and a bank account in the company name. Material ownership changes should be reported.</p><h2>Website and business evidence</h2><p>Payment approval is not based only on identity documents. The website or application should accurately show products or services, prices, delivery, cancellation, refund, privacy, terms and contact information. The declared MCC and use case should match actual collection behavior.</p><h2>Upload quality and security</h2><p>Use clear, complete, current files. Do not crop important edges or upload an unrelated person’s document. Submit documents only through the authenticated KYC flow, not ordinary email or chat unless specifically instructed through a secure process. Never send OTPs or account passwords with a KYC document.</p><h2>What happens after submission</h2><p>Documents may be verified through registries, banking or approved verification providers. A reviewer may request clarification, a replacement, ownership evidence or video verification. KYC completion is one input to Live Mode approval; risk, commercial and partner activation may still be required.</p>',
        ],
        [
            'settlement-t1-explained',
            'The Merchant Settlement Lifecycle, Explained',
            'Learn the difference between payment capture and settlement, why funds may be pending, and how reconciliation protects merchants.',
            '<h2>Payment success is not the final settlement</h2><p>A successful customer payment creates a collection record. Settlement is the later movement of eligible net funds to the merchant’s verified bank account. Between these events, the platform and payment partners may reconcile status, calculate charges and account for refunds, disputes, reserves and risk controls.</p><h2>What a schedule means</h2><p>Terms such as same-day or T+1 describe the configured processing schedule, not an unconditional guarantee of bank credit at an exact hour. Cut-off times, weekends, bank holidays, partner processing and account reviews can move the effective date. The merchant dashboard or commercial schedule is the correct source for the activated arrangement.</p><h2>How net settlement is calculated</h2><p>The eligible amount may start with captured payments and then account for applicable gateway or platform fees, taxes on fees, refunds, reversals, chargebacks, reserve requirements, previous negative balance and manual corrections. Reports should show the batch and transaction references needed to reproduce the calculation.</p><h2>Common pending reasons</h2><p>A settlement can remain pending because the scheduled time has not arrived, the bank account is under verification, KYC is incomplete, transactions have not reconciled, a refund or dispute is open, a partner has not confirmed processing, or a risk review is active. A useful system displays the actual available reason instead of a generic status alone.</p><h2>Failed settlement handling</h2><p>Failure can result from invalid bank details, beneficiary restrictions, bank downtime or partner rejection. Do not repeatedly send uncontrolled payout instructions. Confirm whether the first instruction has a final status, correct the cause where allowed and retry with an idempotent reference so duplicate credits are avoided.</p><h2>Reconciliation and UTR</h2><p>Use the settlement ID, batch amount, processing date, bank reference or UTR and included transaction list to match the bank statement. Report unmatched items promptly. Automated matching helps, but exceptions still require review and evidence.</p><h2>Protecting settlement access</h2><p>Changes to the primary bank account should require strong authentication, review and an audit trail. Restrict staff permission to trigger or edit settlements. If an account is suspected to be compromised, suspend sensitive actions, rotate credentials and contact support immediately.</p>',
        ],
    ];

    $db = getDB();
    $insert = $db->prepare("INSERT IGNORE INTO blog_posts (slug,title_en,excerpt_en,content_en,status) VALUES (?,?,?,?, 'published')");
    $update = $db->prepare("UPDATE blog_posts SET title_en=?, excerpt_en=?, content_en=? WHERE slug=? AND (content_en IS NULL OR CHAR_LENGTH(content_en) < 1000)");
    foreach ($posts as [$slug, $title, $excerpt, $content]) {
        $insert->execute([$slug, $title, $excerpt, $content]);
        $update->execute([$title, $excerpt, $content, $slug]);
    }
}
