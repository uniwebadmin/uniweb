<?php
require_once __DIR__ . '/config.php';
$pageTitle = __('faq');
require_once __DIR__ . '/header.php';

$faqGroups = [
    'Getting started' => [
        ['What is UniWeb?', 'UniWeb is a merchant payment-operations technology platform. It combines onboarding, Test Mode, payment links, QR journeys, transaction reporting, refunds, settlements and support workflows. Live financial services depend on merchant approval and activated banking or payment partners.'],
        ['How do you compare with Razorpay or Cashfree?', 'We match payment links and QR as the reliability bar. Live cards and UPI use those partners when keys are on. We do not copy Route, Easy Split, POS or a consumer wallet. See the Compare page for the honest table.'],
        ['Can I explore the platform before Live Mode?', 'Yes. Test Mode and the platform tour are designed for demos and integration checks. Test transactions do not move real money and must not be treated as real payment confirmation.'],
        ['Which business entities can apply?', 'Sole proprietorships, partnerships, LLPs, companies and other supported entities may apply. The required documents and final eligibility depend on the entity type, use case, business category and payment-partner policy.'],
        ['Does registration guarantee activation?', 'No. Registration creates an account. Live Mode requires complete KYC, business and website review, bank verification, risk acceptance, commercial setup and partner activation.'],
    ],
    'KYC and compliance' => [
        ['Why is KYC required?', 'KYC helps identify the merchant, authorized signatory, beneficial owners, business activity and settlement bank. It supports fraud prevention and applicable legal and payment-partner obligations.'],
        ['Why do document requirements change by entity type?', 'A sole proprietor and a private company have different ownership and registration evidence. UniWeb shows entity-relevant documents so merchants are not asked to upload every possible document.'],
        ['What happens if a document is rejected?', 'The KYC page shows the status and available reason. Upload a clear, current replacement that matches the registered name and details. A rejected document does not automatically close the account.'],
        ['Is the Merchant Agreement public?', 'A reference copy may be available for review. The binding acceptance is shown to the signed-in merchant and records the agreement version, merchant identity, timestamp and audit details.'],
    ],
    'Payments and QR' => [
        ['What payment tools are available?', 'Depending on activation, merchants can use payment links, all-method QR, dynamic UPI QR, fixed-amount QR, checkout and APIs. A method shown in Test Mode may still require separate Live Mode activation.'],
        ['Can one merchant create multiple QR codes?', 'Yes. Merchants can create separate QR codes for a counter, branch, campaign, product or fixed amount and open transaction history filtered to a specific QR.'],
        ['What does Pending payment mean?', 'Pending means a final success or failure confirmation has not yet been received. Do not deliver solely on a screenshot or pending state; wait for confirmed status and reconcile the transaction.'],
        ['How are API keys protected?', 'Test and Live keys are separate. Keep secrets on the server, rotate exposed keys, validate webhook signatures and never place a secret key in browser code, screenshots or public repositories.'],
    ],
    'Settlements, refunds and disputes' => [
        ['When will a settlement arrive?', 'The applicable schedule appears in the merchant commercial setup or dashboard. Timing can be affected by bank holidays, partner processing, reconciliation, disputes, risk review and settlement-bank availability.'],
        ['Why can a settlement be held?', 'Common reasons include incomplete KYC, changed bank details, unmatched transactions, refunds, chargebacks, negative balance, suspicious activity, partner instructions or a legal review. The portal should display the available reason.'],
        ['How does a customer receive a refund?', 'The merchant first approves and initiates an eligible refund. It is sent to the original payment method through the relevant partner. Final credit timing is controlled by the customer’s bank or payment network.'],
        ['Who decides a chargeback?', 'The merchant submits evidence through the available workflow, but the issuer, bank, card network or payment partner may make the final decision under its rules and deadlines.'],
    ],
    'Account and security' => [
        ['Who controls merchants vs partners?', 'UniWeb Admin (and scoped staff) own every merchant account. Bank and PG partners are tech rails — keys and methods in Partner Registry. Partners do not get a UniWeb merchant-management portal and do not “own” your merchants.'],
        ['Which login do I use?', 'Merchants: login.php. Customers: customer_login.php. Super Admin: admin_login.php. Staff/ops: staff_login.php. There is no separate partner login for banks or gateways.'],
        ['How long does a login session remain active?', 'The Portal displays an IST clock and session countdown. Idle and maximum session limits are enforced by portal type. Save work before expiry and sign out on shared devices.'],
        ['Can I enable two-factor authentication?', 'Yes. Merchants can enable optional authenticator-app verification from Settings. Admin and staff accounts use their configured security controls and role permissions.'],
        ['What should I do if credentials are exposed?', 'Change the password, revoke other sessions if available, rotate API and webhook secrets, review staff access and contact support with the incident time. Never send the exposed secret itself.'],
        ['Can staff see every merchant?', 'Staff access is role- and scope-based. Sensitive actions and merchant-management events may be recorded for audit and review.'],
    ],
    'Pricing and fees' => [
        ['Is Test Mode free?', 'Yes. Instant Test Pay, sandbox API keys, payment links and QR in Test Mode do not move real money and do not attract Live MDR.'],
        ['What do I pay in Live Mode?', 'Partner MDR + UniWeb platform fee + GST on applicable fees. Settlement follows the written T+N schedule in your Portal. We do not publish a fake 0% live UPI or instant-settlement public rate card.'],
        ['Where is the official rate?', 'Your Merchant Portal commercial schedule is the source of truth. Website numbers appear only when a public MDR table is approved for publication.'],
    ],
    'Support and customer protection' => [
        ['What details should I send to support?', 'Include merchant code, transaction or settlement ID, date, amount, payment status and a concise description. Do not send OTPs, UPI PINs, card PINs, CVV, passwords or complete card numbers.'],
        ['How fast will you reply to the website form?', 'We save a ticket even if email is delayed. Acknowledgement target is 1 business day. Payment or bank issues may take longer because partners must confirm. Keep the CTI reference if you follow up.'],
        ['What if money was debited but payment failed?', 'Check whether the debit is final and keep the bank reference. Failed debits are often reversed by the bank after reconciliation. Contact the merchant and issuing bank if the normal reversal period has passed.'],
        ['Where can I read the legal policies?', 'Terms, Privacy, Refund, Compliance, Trust and Grievance pages are in the website footer. Signed-in merchants accept the Merchant Agreement from Merchant Settings.'],
        ['Do you offer a customer wallet or NBFC loan?', 'No. UniWeb does not issue a consumer PPI wallet and does not sell an NBFC lending product.'],
    ],
];
?>
<main class="company-page">
    <section class="company-hero"><div class="company-shell">
        <div class="company-eyebrow">Help Centre</div>
        <h1>Frequently asked questions, clearly answered.</h1>
        <p>Understand onboarding, KYC, Test and Live Mode, QR codes, payments, settlements, refunds, security and support before you begin.</p>
    </div></section>
    <section class="company-section"><div class="company-shell" style="max-width:900px">
        <input id="faq-search" class="faq-search" type="search" placeholder="Search questions, for example: KYC, QR, refund or settlement" aria-label="Search frequently asked questions">
        <p id="faq-empty" class="hidden text-center text-gray-500 text-sm mt-8">No matching question. Try a shorter keyword or contact support.</p>
        <?php foreach ($faqGroups as $group => $faqs): ?>
        <section class="faq-group" data-faq-group>
            <h2><?= e($group) ?></h2>
            <?php foreach ($faqs as [$q, $a]): ?>
            <details class="faq-item" data-faq-item>
                <summary><?= e($q) ?><span class="float-right text-brand-400">+</span></summary>
                <div><?= e($a) ?></div>
            </details>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>
    </div></section>
    <section class="company-section" style="padding-top:0"><div class="company-shell" style="max-width:900px"><div class="company-cta">
        <h2>Still need help?</h2><p>Send the relevant reference ID and issue details. Never send your password, OTP, UPI PIN, card PIN or CVV.</p><a href="contact.php" class="btn-primary inline-block px-6 py-3">Contact UniWeb</a>
    </div></div></section>
</main>
<script>
document.getElementById('faq-search')?.addEventListener('input', function () {
    const term = this.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('[data-faq-group]').forEach(group => {
        let groupVisible = 0;
        group.querySelectorAll('[data-faq-item]').forEach(item => {
            const show = !term || item.textContent.toLowerCase().includes(term);
            item.hidden = !show;
            if (show) { groupVisible++; visible++; }
        });
        group.hidden = groupVisible === 0;
    });
    document.getElementById('faq-empty')?.classList.toggle('hidden', visible !== 0);
});
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
