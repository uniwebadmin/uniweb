<?php
declare(strict_types=1);

/** Banking partner signup URLs + production request templates */

function getBankingPartners(): array
{
    return [
        'decentro' => [
            'name' => 'Decentro',
            'use' => 'Full BaaS: KYC + UPI Collect + VA + Payouts + Split + Ledger',
            'signup' => 'https://decentro.tech',
            'docs' => 'https://docs.decentro.tech/docs/payments-overview-and-guide',
            'dashboard' => 'https://dashboard.decentro.tech',
            'sandbox' => 'https://in.staging.decentro.tech',
            'production' => 'https://in.decentro.tech',
            'email_support' => 'support@decentro.tech',
            'email_business' => 'hello@decentro.tech',
            'phone' => 'Schedule via hello@decentro.tech',
        ],
        'payu' => [
            'name' => 'PayU',
            'use' => 'Collections (Cards/UPI/NB) + Split Settlement + PayU Payouts (IMPS/NEFT/UPI)',
            'signup' => 'https://onboarding.payu.in/',
            'docs' => 'https://docs.payu.in/',
            'payout_docs' => 'https://docs.payu.in/docs/introduction-to-payouts',
            'dashboard' => 'https://dashboard.payu.in/',
            'payout_api' => 'https://payout.payumoney.com/payout/payment',
            'email' => 'help@payu.in',
            'email_alt' => 'merchantcare@payu.in',
            'payout_note' => 'PayU Payouts = separate product from payment collection. T+0/T+1 settlement to merchant; payouts API for vendor/refund transfers. Contact KAM for payoutMerchantId.',
        ],
        'razorpay' => [
            'name' => 'Razorpay',
            'use' => 'Checkout (Pay-in) + Route Linked Accounts (split settlement)',
            'signup' => 'https://dashboard.razorpay.com/signup',
            'partner_signup' => 'https://razorpay.com/partners/',
            'docs' => 'https://razorpay.com/docs/',
            'dashboard' => 'https://dashboard.razorpay.com/',
            'email' => 'partnerships@razorpay.com',
            'email_alt' => 'support@razorpay.com',
            'email_contact' => 'contact@razorpay.com',
            'payout_note' => 'Pay-in only. For merchant bank payouts use RazorpayX (separate product + separate email draft).',
        ],
        'cashfree' => [
            'name' => 'Cashfree',
            'use' => 'Payment Gateway (Pay-in) + Easy Split + Payouts API',
            'signup' => 'https://merchant.cashfree.com/merchants/signup',
            'docs' => 'https://docs.cashfree.com/',
            'payout_docs' => 'https://docs.cashfree.com/docs/payouts',
            'dashboard' => 'https://merchant.cashfree.com/',
            'email' => 'care@cashfree.com',
            'email_alt' => 'partnerships@cashfree.com',
            'payout_note' => 'Cashfree Payouts — beneficiary validation, instant IMPS, bulk transfers. Strong Payouts API.',
        ],
        'phonepe' => [
            'name' => 'PhonePe PG',
            'use' => 'UPI + Wallets (PG integration)',
            'signup' => 'https://www.phonepe.com/business-solutions/payment-gateway/',
            'docs' => 'https://developer.phonepe.com/',
            'email' => 'merchanthelp@phonepe.com',
        ],
        'axis' => [
            'name' => 'Axis Bank',
            'use' => 'Virtual Account Collections (Pay-in) + Corporate API',
            'signup' => 'https://apiportal.axis.bank.in/portal/',
            'docs' => 'https://apiportal.axis.bank.in/portal/index.php/security-features',
            'uat' => 'https://sakshamuat.axisbank.co.in',
            'email' => 'corporate.api@axisbank.com',
            'email_alt' => 'corporatebanking@axisbank.com',
        ],
        'razorpayx' => [
            'name' => 'RazorpayX',
            'use' => 'Business Banking + Payouts API (IMPS/NEFT/UPI) + Vendor Payments',
            'signup' => 'https://razorpay.com/x/',
            'partner_signup' => 'https://razorpay.com/x/partners/',
            'docs' => 'https://razorpay.com/docs/x/',
            'dashboard' => 'https://x.razorpay.com/',
            'email' => 'partnerships@razorpay.com',
            'email_alt' => 'support@razorpay.com',
            'email_contact' => 'contact@razorpay.com',
            'email_payroll' => 'xpayroll@razorpay.com',
            'payout_note' => 'RazorpayX = separate from Razorpay PG. Current account + Payouts API. T+0 IMPS. Apply via x.razorpay.com or X Partner Network.',
        ],
        'open' => [
            'name' => 'Open (Open Financial)',
            'use' => 'Business Account + Payouts + Expense + Connected Banking',
            'signup' => 'https://open.money/',
            'docs' => 'https://docs.open.money/',
            'email' => 'support@open.money',
            'payout_note' => 'Open Money — SME banking stack. Bulk payouts, vendor payments, GST invoicing.',
        ],
        'easebuzz' => [
            'name' => 'Easebuzz',
            'use' => 'Payment Gateway + Payouts + Smart Routing',
            'signup' => 'https://easebuzz.in/',
            'docs' => 'https://docs.easebuzz.in/',
            'email' => 'support@easebuzz.in',
            'payout_note' => 'Collections + Payout API. Good for education, SaaS, marketplaces.',
        ],
        'rbl' => [
            'name' => 'RBL Bank',
            'use' => 'Open Banking: Virtual Account, UPI Collection, Account Balance, Blob VA Statement, Corporate & Bulk Payments',
            'signup' => 'https://sandbox.rbl.bank.in/',
            'docs' => 'https://developer.rbl.bank.in/apicatalog',
            'sandbox' => 'https://apisandbox.rbl.bank.in/sandbox/api/v1',
            'email' => 'api_upi@rblbank.com',
            'payout_note' => 'Sandbox-first: Key/Secret from sandbox.rbl.bank.in. API base apisandbox.rbl.bank.in. Live keys later. Corp ID + Master Account from RM (no demo defaults).',
        ],
        'yesbank' => [
            'name' => 'Yes Bank',
            'use' => 'API banking, current account, IMPS/NEFT payouts',
            'signup' => 'https://www.yesbank.in/',
            'docs' => 'https://developer.yesbank.in/',
            'email' => 'yestouch@yesbank.in',
            'payout_note' => 'Yes Bank API banking — bulk payouts + collections.',
        ],
        'billdesk' => [
            'name' => 'BillDesk',
            'use' => 'BBPS + bill payment aggregation + PG',
            'signup' => 'https://www.billdesk.com/',
            'docs' => 'https://www.billdesk.com/',
            'email' => 'support@billdesk.com',
            'payout_note' => 'BillDesk BBPS + PG for utility and recurring bill payments.',
        ],
        'ccavenue' => [
            'name' => 'CCAvenue',
            'use' => 'Payment gateway + multi-currency checkout',
            'signup' => 'https://www.ccavenue.com/',
            'docs' => 'https://developer.ccavenue.com/',
            'email' => 'support@ccavenue.com',
            'payout_note' => 'CCAvenue payment gateway — cards, UPI, wallets, multi-currency.',
        ],
        'setu' => [
            'name' => 'Setu',
            'use' => 'BBPS + UPI DeepLinks + data API',
            'signup' => 'https://setu.co/',
            'docs' => 'https://docs.setu.co/',
            'email' => 'support@setu.co',
            'payout_note' => 'Setu BBPS + UPI DeepLinks + data APIs.',
        ],
        'pinelabs' => [
            'name' => 'Pine Labs',
            'use' => 'Plural checkout + payouts + POS gateway',
            'signup' => 'https://www.pinelabs.com/',
            'docs' => 'https://developer.pinelabs.com/',
            'email' => 'support@pinelabs.com',
            'payout_note' => 'Pine Labs Plural — online checkout, linked accounts, payouts.',
        ],
        'zwitch' => [
            'name' => 'Zwitch',
            'use' => 'Neo-banking + payouts + collections API',
            'signup' => 'https://zwitch.io/',
            'docs' => 'https://docs.zwitch.io/',
            'email' => 'support@zwitch.io',
            'payout_note' => 'Zwitch neo-banking stack — current account, payouts, collections.',
        ],
        'icici' => [
            'name' => 'ICICI Bank',
            'use' => 'Corporate API banking, current account, payouts',
            'signup' => 'https://www.icicibank.com/',
            'docs' => 'https://developer.icicibank.com/',
            'email' => 'api.support@icicibank.com',
            'payout_note' => 'ICICI corporate API banking — IMPS/NEFT/RTGS payouts + collections.',
        ],
        'sbi' => [
            'name' => 'SBI',
            'use' => 'Corporate banking + API collections + payouts',
            'signup' => 'https://sbi.co.in/',
            'docs' => 'https://developer.sbi.co.in/',
            'email' => 'support@sbi.co.in',
            'payout_note' => 'SBI corporate API banking — collections and bulk payouts.',
        ],
        'worldline' => [
            'name' => 'Worldline',
            'use' => 'Payment gateway + POS + omnichannel checkout',
            'signup' => 'https://worldline.com/',
            'docs' => 'https://docs.worldline.com/',
            'email' => 'support@worldline.com',
            'payout_note' => 'Worldline PG — cards, UPI, wallets (integration scaffold).',
        ],
        'digio' => [
            'name' => 'Digio',
            'use' => 'eSign + DigiLocker + KYC document APIs',
            'signup' => 'https://www.digio.in/',
            'docs' => 'https://documentation.digio.in/',
            'email' => 'support@digio.in',
            'payout_note' => 'Digio — eSign / DigiLocker; not a collection PG.',
        ],
        'toucanpay' => [
            'name' => 'ToucanPay',
            'use' => 'RBI PA/PG — UPI, cards, netbanking, BBPS, cross-border settlement (SuperStream)',
            'signup' => 'https://toucanpay.in/',
            'docs' => 'https://toucanpay.in/',
            'dashboard' => 'https://toucanpay.in/',
            'email' => 'support@toucanpayments.com',
            'payout_note' => 'Regulated PG partner — paste API keys when ToucanPay shares sandbox/live credentials. Checkout adapter follows their API spec.',
        ],
    ];
}

/** Primary + alternate contact emails for a banking partner */
function getPartnerContactEmails(string $partnerKey): array
{
    $p = getBankingPartners()[$partnerKey] ?? null;
    if (!$p) {
        return [];
    }
    $out = [];
    foreach (['email', 'email_support', 'email_business', 'email_alt', 'email_contact', 'email_payroll'] as $field) {
        if (!empty($p[$field])) {
            $out[] = $p[$field];
        }
    }
    return array_values(array_unique($out));
}

function partnerProductionEmail(string $partnerKey): string
{
    $company = COMPANY_LEGAL_NAME;
    $gst = COMPANY_GST;
    $cin = COMPANY_CIN;
    $site = APP_URL;
    $ceo = COMPANY_CEO;
    $phone = COMPANY_PHONE;
    $email = COMPANY_ADMIN_EMAIL;

    return match ($partnerKey) {
        'decentro' => <<<MAIL
Subject: Full-Stack BaaS Partnership — {$company} | UniWeb B2B Payment Aggregator

Dear Decentro Business Team,

We are {$company} (CIN: {$cin}, GST: {$gst}), building UniWeb — a B2B payment aggregation platform at {$site}.

We need Decentro as our PRIMARY banking infrastructure partner (not KYC-only). Please enable the following modules in Sandbox first, then Production after sign-off:

━━━ MODULES REQUIRED ━━━

1. KYC & ONBOARDING
   - PAN, GST, CIN validation
   - Bank account penny drop / reverse penny pull
   - Aadhaar / DigiLocker / CKYC (for merchant onboarding)

2. COLLECTIONS (UPI P2M / Payment Aggregator v3)
   - UPI Payment Links (dynamic amount)
   - UPI QR codes
   - UPI sub-merchant onboarding (each merchant gets own VPA)
   - Collection callbacks: provider-specific signed endpoint to be confirmed during UAT

3. VIRTUAL ACCOUNTS
   - Create VA per merchant (IMPS/NEFT/RTGS/UPI collect)
   - E-collect notifications
   - VA balance settlement

4. PAYOUTS (Instant settlement to merchant bank)
   - IMPS / NEFT / RTGS / UPI payouts
   - Beneficiary management
   - Bulk payout (CSV/JSON)
   - Payout status webhooks

5. SPLIT SETTLEMENTS
   - Platform commission auto-deduct
   - Merchant net settlement rules

6. LEDGER (optional)
   - Merchant wallet ledger / reconciliation

━━━ OUR USE CASE ━━━
- We onboard B2B merchants (shops, distributors, SMEs)
- Each merchant gets: UPI link, VA, card checkout (via PayU/Razorpay alongside)
- Customer pays → UniWeb commission deducted → merchant balance credited → merchant withdraws to bank (instant/T+1)

━━━ COMPANY DETAILS ━━━
Legal: {$company}
Website: {$site}
CEO: {$ceo}
Phone: {$phone}
Email: {$email}

━━━ REQUEST ━━━
1. Schedule a 30-min demo call with your solutions team
2. Share sandbox credentials for ALL modules above
3. Share production onboarding checklist + commercials (pay-per-use)
4. Assign a dedicated business manager

We have staging integration started for KYC. Ready to integrate Collections + Payouts + VA immediately.

Regards,
{$ceo}
{$company}
{$email} | {$phone}
MAIL,
        'payu' => <<<MAIL
Subject: Production Pay-in + Payouts + Split Settlement — {$company} | UniWeb

Dear PayU Partnership Team,

We are {$company} (CIN: {$cin}, GST: {$gst}), operating UniWeb — a B2B payment aggregation platform at {$site}.

We request FULL production onboarding for both COLLECTIONS (pay-in) and PAYOUTS (pay-out) for our merchant network.

━━━ A) PAY-IN (Payment Gateway / Collections) ━━━
Please enable and share LIVE credentials for:
1. Parent Merchant ID (MID) + Merchant Key + Salt (v1/v2 as applicable)
2. Cards, UPI, Net Banking, Wallets, EMI
3. Split / child merchant onboarding API (sub-merchant MID per merchant)
4. Settlement cycle: T+1 standard (quote T+0 / same-day if available)
5. Webhook / callback URLs:
   - Return: {$site}/payment_payu_return.php
   - Server webhook (if supported): {$site}/payu_webhook.php

━━━ B) PAY-OUT (PayU Payouts — separate product) ━━━
Please activate PayU Payouts and share:
1. payoutMerchantId (Payouts MID)
2. Payout API client credentials (if OAuth/client-based)
3. IMPS / NEFT / RTGS / UPI payout modes
4. Beneficiary add + validate + transfer APIs
5. Payout status webhooks
6. IP whitelist procedure for our server

━━━ C) COMMERCIALS & KAM ━━━
1. MDR sheet: UPI, Debit, Credit, Corporate cards
2. Payout pricing: per IMPS/NEFT/UPI transfer
3. Dedicated Key Account Manager contact (name, email, phone)
4. Production go-live checklist + timeline

━━━ COMPANY DETAILS ━━━
Legal Name: {$company}
Brand: UniWeb | Website: {$site}
CEO: {$ceo}
Email: {$email}
Phone: {$phone}
GST: {$gst} | CIN: {$cin}

We can share: Certificate of Incorporation, GST certificate, cancelled cheque, board resolution, and website/screenshots on request.

Please schedule a call this week to complete onboarding.

Regards,
{$ceo}
{$company}
{$email} | {$phone}
MAIL,
        'razorpay' => <<<MAIL
Subject: Production Pay-in — Razorpay Checkout + Route — {$company} | UniWeb

Dear Razorpay Partnerships Team,

We are {$company} (CIN: {$cin}, GST: {$gst}), building UniWeb — a B2B payment aggregator at {$site}.

We request LIVE production access for PAY-IN (collections + Route split settlement). Payouts will be handled separately via RazorpayX.

━━━ PAY-IN — Razorpay Checkout + Route ━━━
Please activate on our production account:
1. Live key_id + key_secret
2. Razorpay Route (Linked Accounts) for sub-merchant onboarding
3. Automatic commission transfer + merchant settlement rules
4. Payment methods: UPI, Cards, Netbanking, Wallets
5. Webhook URL: {$site}/razorpay_webhook.php
6. Webhook events: payment.captured, order.paid, refund.processed, settlement.processed

━━━ ONBOARDING ━━━
1. Assign enterprise / aggregator solutions manager (name, email, phone)
2. Share production activation checklist + KYC document list
3. MDR fee schedule (UPI, Debit, Credit)
4. Sandbox → Production migration support + go-live timeline

━━━ COMPANY ━━━
{$company} | UniWeb ({$site})
{$ceo} | {$email} | {$phone}
GST: {$gst} | CIN: {$cin}

Regards,
{$ceo}
{$company}
MAIL,
        'cashfree' => <<<MAIL
Subject: Production Pay-in + Easy Split + Payouts API — {$company} | UniWeb

Dear Cashfree Partnership Team,

We are {$company} (GST: {$gst}, CIN: {$cin}), operating UniWeb — B2B payment platform at {$site}.

We request FULL production access for PAY-IN and PAY-OUT products.

━━━ A) PAY-IN — Payment Gateway + Easy Split ━━━
1. Live App ID + Secret Key (production)
2. Easy Split / Vendor onboarding API for sub-merchants
3. UPI, Cards, Netbanking, Wallets
4. Settlement + split rules (platform commission + merchant net)
5. Webhook URL: {$site}/cashfree_webhook.php
6. Return URL: {$site}/payment_cashfree_return.php

━━━ B) PAY-OUT — Cashfree Payouts ━━━
1. Payouts product activation (separate credentials if required)
2. Client ID / Client Secret for Payouts API (production)
3. Public key / IP whitelist setup
4. Beneficiary validation (penny drop / account verify)
5. IMPS / NEFT / UPI transfer APIs + bulk payout
6. Payout webhooks + reconciliation reports

━━━ C) REQUEST ━━━
1. Dedicated account manager (name, email, phone)
2. Commercial proposal: PG MDR + payout per-txn pricing
3. Sandbox → Production migration support
4. Go-live timeline

━━━ COMPANY ━━━
{$company} | UniWeb ({$site})
Contact: {$ceo} | {$email} | {$phone}

Regards,
{$ceo}
{$company}
MAIL,
        'phonepe' => <<<MAIL
Subject: PhonePe Payment Gateway — Production Merchant — {$company}

Dear PhonePe Business Team,

{$company} (UniWeb — {$site}) requests PhonePe PG production credentials for UPI and wallet payments on our merchant checkout.

GST: {$gst} | Contact: {$ceo} | {$email} | {$phone}

Regards,
{$company}
MAIL,
        'axis' => <<<MAIL
Subject: UAT → Production — Virtual Account Collections API — {$company} | UniWeb

Dear Axis Bank Corporate API Team,

Application: UNIWEB Collection API
Company: {$company} (GST: {$gst}, CIN: {$cin})
Website: {$site}
OAuth Redirect: {$site}

We have integrated Axis Virtual Account collections in UAT. We request PRODUCTION go-live.

━━━ PAY-IN — Virtual Account Collections ━━━
1. Production Client ID + Client Secret (OAuth)
2. channel_id, corporate_id, app_name
3. Webhook / encryption keys + signature verification method
4. Virtual Account creation API (per merchant VA)
5. E-collect / credit notification webhooks
6. IP whitelist for: our production server (Hostinger)

Webhook URL: {$site}/axis_webhook.php

━━━ SETTLEMENT ━━━
1. VA collections → merchant / platform settlement flow
2. Sweep / transfer to merchant bank (if VA product supports)
3. UAT sign-off checklist + production approval timeline

━━━ CONTACT ━━━
{$ceo} | {$email} | {$phone}
{$company}

We can submit: GST, COI, board resolution, API portal application reference, and UAT test logs.

Regards,
{$ceo}
{$company}
MAIL,
        'razorpayx' => <<<MAIL
Subject: Production Pay-out — RazorpayX Business Account + Payouts API — {$company} | UniWeb

Dear RazorpayX / Razorpay Partnerships Team,

We are {$company} (CIN: {$cin}, GST: {$gst}), operating UniWeb — a B2B payment aggregator at {$site}.

We need RazorpayX as our PRIMARY PAY-OUT partner for merchant/vendor settlements (separate from our Razorpay PG pay-in account).

━━━ A) RAZORPAYX BUSINESS ACCOUNT ━━━
1. RazorpayX Current Account activation (production)
2. RazorpayX account number for payouts
3. Dashboard access: https://x.razorpay.com/
4. Dedicated account manager (name, email, phone)

━━━ B) PAYOUTS API (LIVE) ━━━
1. Payouts API key_id + key_secret (production)
2. Contact creation + Fund account + Payout APIs
3. IMPS (T+0), NEFT, UPI payout modes
4. Bulk payout / vendor payment automation
5. Payout status webhooks + reconciliation reports
6. IP whitelist procedure for our production server

━━━ C) USE CASE ━━━
- Merchants collect via UniWeb (PayU/Razorpay/Cashfree/Decentro)
- Platform commission deducted → merchant wallet balance
- Merchant withdraws to bank via RazorpayX Payouts API (instant IMPS)

━━━ D) ONBOARDING ━━━
1. Commercial proposal: per-txn payout pricing (IMPS/NEFT/UPI)
2. Production go-live checklist + timeline
3. KYC documents list (COI, GST, cancelled cheque — ready to submit)
4. X Partner Network onboarding if applicable: https://razorpay.com/x/partners/

━━━ COMPANY ━━━
Legal: {$company} | Brand: UniWeb
Website: {$site}
CEO: {$ceo} | {$email} | {$phone}
GST: {$gst} | CIN: {$cin}

Regards,
{$ceo}
{$company}
{$email} | {$phone}
MAIL,
        'open' => <<<MAIL
Subject: Open Money — Business Banking + Payouts — {$company}

Dear Open Team,

{$company} (UniWeb — {$site}) requests Open Money business account + payout API for merchant settlements.

GST: {$gst} | Contact: {$ceo} | {$email} | {$phone}

Regards,
{$company}
MAIL,
        'easebuzz' => <<<MAIL
Subject: Easebuzz PG + Payouts — {$company}

Dear Easebuzz Team,

{$company} operates UniWeb B2B platform ({$site}). Request production PG + Payout API access.

GST: {$gst} | Contact: {$ceo} | {$email}

Regards,
{$company}
MAIL,
        default => "Partner email template for {$partnerKey} — contact via signup page.",
    };
}

function partnerMailto(string $partnerKey): string
{
    $p = getBankingPartners()[$partnerKey] ?? null;
    if (!$p) return '#';
    $to = $p['email_support'] ?? $p['email'] ?? $p['email_business'] ?? '';
    $body = rawurlencode(partnerProductionEmail($partnerKey));
    $subject = rawurlencode('Production Access — ' . COMPANY_LEGAL_NAME);
    return "mailto:{$to}?subject={$subject}&body={$body}";
}
