<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';
$pageTitle = 'Refund Policy';
require_once __DIR__ . '/header.php';
renderPublicLegalPage([
    'eyebrow' => 'Customer Protection',
    'title' => 'Refund, Cancellation and Dispute Policy',
    'summary' => 'How refund requests, failed payments, reversals, duplicate charges, cancellations and disputes are handled across merchants and payment partners.',
    'effective' => '19 July 2026',
    'version' => '2026.07',
    'notice' => '<strong>For customers:</strong> UniWeb provides payment technology; the merchant from whom you purchased remains responsible for the product, service, delivery, cancellation and commercial refund decision. Never share an OTP, UPI PIN or card PIN while seeking a refund.',
    'sections' => [
        ['Who handles a refund', '<p>The merchant that sold the goods or services is the first point of contact and decides refund eligibility under its published policy and applicable consumer law. UniWeb can provide payment status and technical support but cannot independently cancel an order or promise a merchant-funded refund.</p>'],
        ['How merchants initiate refunds', '<p>An authorized merchant user may request a full or partial refund for an eligible successful transaction through enabled dashboard tools or support. The transaction identifier, amount, reason and customer/order reference must be accurate. A submitted request may not be cancellable once sent to the payment partner.</p>'],
        ['Processing timeline', '<p>Once accepted by the relevant payment partner, a refund is normally credited to the original payment method within the timeline shown for that method. Actual timing is controlled by the bank, issuer, UPI system, gateway and holidays. Status such as “processed” means the refund instruction was sent; final customer credit can occur later.</p>'],
        ['Failed, pending and reversed payments', '<p>If a customer is debited but UniWeb shows Failed or Pending, the merchant should not treat it as confirmed payment. Banks commonly reverse failed debits automatically after reconciliation. The customer should check the original account statement and use the bank reference number when contacting the bank.</p>'],
        ['Duplicate payments', '<p>For an apparent duplicate, first verify that both entries are final successful debits and relate to the same order. The merchant may refund the duplicate after confirming both transaction IDs. A pending authorization that later reverses is not necessarily a second completed payment.</p>'],
        ['Order cancellation', '<p>Cancelling an order does not automatically reverse a completed payment. The merchant must separately initiate the eligible refund. Cancellation windows, restocking, shipping, service usage and non-refundable items are governed by the merchant’s disclosed policy and applicable law.</p>'],
        ['Fees and deductions', '<p>Original transaction fees, taxes or partner charges may not be returned to the merchant unless the commercial schedule says otherwise. Any refund processing charge will be disclosed in the applicable schedule. The customer should receive the refund amount approved by the merchant without an undisclosed platform deduction.</p>'],
        ['Settlement and balance impact', '<p>Refunds may be deducted from unsettled funds, merchant wallet balance, reserve or future settlements. If funds are insufficient, the request may remain pending or the merchant may be required to fund the shortfall. A negative balance can result in settlement hold or feature restriction.</p>'],
        ['Disputes and chargebacks', '<p>A customer may raise a dispute through the merchant, issuing bank or permitted network process. Merchants must provide genuine order, invoice, consent, delivery and communication evidence by the stated deadline. Final outcomes may be determined by the issuer, bank, network or payment partner and may include additional charges.</p>'],
        ['Fraud and unauthorized transactions', '<p>Customers should immediately contact their bank for an unauthorized debit and secure the affected account. UniWeb may preserve records, restrict related accounts and cooperate with lawful investigations. Reporting fraud does not itself determine reimbursement; the bank and applicable rules govern that outcome.</p>'],
        ['How to raise a request', '<p>Provide the transaction ID, merchant/order name, amount, date, registered contact details and a concise description. Do not send full card numbers, CVV, PINs, passwords or OTPs. Merchants can use the Portal support ticket; customers may use the <a href="contact.php">Contact page</a>.</p>'],
        ['Escalation', '<p>If the merchant has confirmed a refund but the expected bank timeline has passed, obtain the refund reference and contact the issuing bank. You may also contact UniWeb support for available technical status. Legal and consumer remedies remain available under applicable law.</p>'],
    ],
]);
require_once __DIR__ . '/footer.php';
