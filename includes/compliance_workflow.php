<?php
declare(strict_types=1);

/**
 * Compliance / business honesty helpers — audit points #59–#64 (India fintech).
 * Existing pages only; English UI copy.
 */

/** @return list<array{key:string,lane:string,id_prefix:string,who:string,portal:string,admin:string,note:string}> */
function complianceSupportPathRows(): array
{
    return [
        [
            'key' => 'tkt',
            'lane' => 'Merchant support',
            'id_prefix' => 'TKT…',
            'who' => 'Signed-in merchant → UniWeb Admin',
            'portal' => 'support.php',
            'admin' => 'admin_support.php',
            'note' => 'KYC, settlement, API, portal bugs — not bank chargebacks.',
        ],
        [
            'key' => 'ct',
            'lane' => 'Customer complaint',
            'id_prefix' => 'CT…',
            'who' => 'Payer (Customer Portal) → merchant first',
            'portal' => 'merchant_customer_tickets.php',
            'admin' => 'admin_customer_tickets.php',
            'note' => 'Refund questions start with the merchant you paid.',
        ],
        [
            'key' => 'gr',
            'lane' => 'Grievance escalation',
            'id_prefix' => 'GR… / complaint ref',
            'who' => 'Customer or merchant (public form)',
            'portal' => 'grievance.php',
            'admin' => 'admin_grievance.php',
            'note' => 'After support SLA — named Grievance Officer queue.',
        ],
        [
            'key' => 'dsp',
            'lane' => 'Payment dispute / chargeback',
            'id_prefix' => 'DSP…',
            'who' => 'Merchant → UniWeb Admin → partner (if forwarded)',
            'portal' => 'disputes.php',
            'admin' => 'admin_disputes.php',
            'note' => 'Network / chargeback lane — not the same as a refund.',
        ],
    ];
}

function renderComplianceCustomerSupportNote(): string
{
    return '<div class="cp-panel p-4 text-xs text-slate-600 border border-teal-100 compliance-customer-support-note">'
        . '<p class="font-semibold text-slate-800 mb-1">Need help with a payment?</p>'
        . '<ul class="space-y-1 list-disc list-inside text-slate-600">'
        . '<li><strong>Refund</strong> — ask the merchant you paid; they decide per their policy.</li>'
        . '<li><strong>Complaint (CT…)</strong> — raise here; merchant replies first, then UniWeb if needed.</li>'
        . '<li><strong>Chargeback / bank dispute</strong> — contact your bank; merchant handles network review separately.</li>'
        . '<li>Escalation: <a href="grievance.php" class="text-teal-700 underline">Grievance Redressal</a> · <a href="contact.php" class="text-teal-700 underline">Contact</a> (ack target 1 business day).</li>'
        . '</ul></div>';
}

/**
 * @param string $highlight One of: tkt, ct, gr, dsp, customer, admin
 */
function renderComplianceSupportPathPanel(string $highlight = 'tkt'): string
{
    $rows = complianceSupportPathRows();
    $html = '<div class="glass rounded-xl p-4 mb-6 border border-sky-500/25 text-sm text-gray-300 compliance-support-path">';
    $html .= '<p class="font-semibold text-sky-300 mb-1">Support &amp; grievance paths (pick the right lane)</p>';
    $html .= '<p class="text-xs text-gray-500 mb-3">Each ticket type has its own ID prefix and queue. Using the wrong lane delays resolution.</p>';
    $html .= '<div class="overflow-x-auto"><table class="w-full text-xs min-w-[640px]">';
    $html .= '<thead class="text-gray-500 uppercase"><tr>';
    $html .= '<th class="py-2 pr-3 text-left">Lane</th><th class="py-2 pr-3 text-left">ID</th><th class="py-2 pr-3 text-left">Who</th><th class="py-2 pr-3 text-left">Portal</th><th class="py-2 text-left">Note</th>';
    $html .= '</tr></thead><tbody class="divide-y divide-gray-800">';
    foreach ($rows as $row) {
        $isActive = $highlight === $row['key']
            || ($highlight === 'customer' && $row['key'] === 'ct')
            || ($highlight === 'admin' && in_array($row['key'], ['tkt', 'ct', 'gr', 'dsp'], true));
        $rowClass = $isActive ? ' bg-sky-500/10' : '';
        $html .= '<tr class="' . $rowClass . '">';
        $html .= '<td class="py-2 pr-3 font-medium text-gray-200">' . e($row['lane']) . '</td>';
        $html .= '<td class="py-2 pr-3 font-mono text-sky-400">' . e($row['id_prefix']) . '</td>';
        $html .= '<td class="py-2 pr-3 text-gray-400">' . e($row['who']) . '</td>';
        $portalLink = '<a href="' . e($row['portal']) . '" class="text-sky-400 hover:underline">' . e(basename($row['portal'], '.php')) . '</a>';
        $adminLink = '<a href="' . e($row['admin']) . '" class="text-gray-500 hover:underline">' . e(basename($row['admin'], '.php')) . '</a>';
        $html .= '<td class="py-2 pr-3">' . $portalLink . ' · ' . $adminLink . '</td>';
        $html .= '<td class="py-2 text-gray-500">' . e($row['note']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    $html .= '<p class="text-[11px] text-gray-600 mt-3">Public customers: <a href="contact.php" class="text-sky-400 hover:underline">Contact</a> or <a href="grievance.php" class="text-sky-400 hover:underline">Grievance</a>. Refund money → merchant decides; chargeback → bank/network via <strong class="text-gray-400">Disputes</strong>, not Refunds.</p>';
    $html .= '</div>';
    return $html;
}

function renderComplianceRefundSlaPanel(): string
{
    return '<div class="glass rounded-xl p-4 mb-6 border border-amber-500/25 text-sm text-gray-300 compliance-refund-sla">'
        . '<p class="font-semibold text-amber-300 mb-1">Refund SLA (portal matches public policy)</p>'
        . '<ul class="text-xs text-gray-500 space-y-1 list-disc list-inside">'
        . '<li><strong class="text-gray-400">You (merchant)</strong> approve and submit here — status shows <em>pending</em> or <em>completed</em> in this list.</li>'
        . '<li><strong class="text-gray-400">Bank / UPI credit</strong> to the customer: typically <strong class="text-gray-300">3–7 business days</strong> after completion (issuer timing).</li>'
        . '<li><strong class="text-gray-400">Customer acknowledgement</strong> on public enquiries: target <strong class="text-gray-300">1 business day</strong> (<a href="refund_policy.php" class="text-sky-400 hover:underline">Refund policy</a> · <a href="contact.php" class="text-sky-400 hover:underline">Contact</a>).</li>'
        . '<li>Chargeback or card-network dispute is <strong class="text-gray-300">not</strong> a refund — use <a href="disputes.php" class="text-sky-400 hover:underline">Disputes</a>.</li>'
        . '</ul></div>';
}

/** @param 'refunds'|'disputes' $page */
function renderComplianceDisputeVsRefundPanel(string $page = 'disputes'): string
{
    if ($page === 'refunds') {
        return '<div class="glass rounded-xl p-4 mb-6 border border-violet-500/25 text-xs text-gray-400 compliance-dispute-vs-refund">'
            . '<p class="font-semibold text-violet-300 mb-1">Refund ≠ chargeback / dispute</p>'
            . '<p>This page <strong class="text-gray-300">returns money</strong> when you approve a refund. Bank or card-network chargebacks, issuer disputes, and “staged forward to partner” reviews belong on '
            . '<a href="disputes.php" class="text-sky-400 hover:underline">Disputes</a> — labels there say <em>dispute</em> or <em>chargeback</em>, not refund.</p></div>';
    }
    return '<div class="glass rounded-xl p-4 mb-6 border border-violet-500/25 text-xs text-gray-400 compliance-dispute-vs-refund">'
        . '<p class="font-semibold text-violet-300 mb-1">Dispute / chargeback ≠ refund</p>'
        . '<p>This lane is for <strong class="text-gray-300">payment-network or chargeback review</strong> — Admin first, then optional single partner forward. To voluntarily return money to a customer, use '
        . '<a href="refunds.php" class="text-sky-400 hover:underline">Refunds</a>. Status <em>forwarded</em> means sent for partner review — not “bank already paid customer”.</p></div>';
}

function compliancePrivacyDeleteRequestSection(): string
{
    $email = defined('COMPANY_SUPPORT_EMAIL') ? COMPANY_SUPPORT_EMAIL : 'support@uniweb.co.in';
    return '<p>Email <a href="mailto:' . e($email) . '">' . e($email) . '</a> with subject line <strong>DPDP request</strong>. Include your name, phone, merchant code (if any), and what you want (access, correction, or erasure).</p>'
        . '<p><strong>Ops process:</strong> we acknowledge within <strong>30 days</strong>, verify identity, then action the request. We cannot erase records we must keep by law or partner contract — for example settled payment history, tax/audit logs, open disputes, or KYC while your merchant account is active. Where erasure is not allowed we anonymize or restrict access where possible.</p>'
        . '<p>For unresolved privacy complaints after support, use <a href="grievance.php">Grievance Redressal</a>.</p>';
}

function renderCompliancePartnerClaimBanner(): string
{
    if (!function_exists('routeSplitIsParked')) {
        require_once __DIR__ . '/route_split_workflow.php';
    }
    $parked = routeSplitIsParked();
    $phaseNote = $parked
        ? 'Route / Split (Razorpay Route, Cashfree Easy Split, PayU Split) is <strong class="text-amber-200">OFF</strong> — checkout uses standard collect + M/P settlement.'
        : 'Route / Split owner gate is ON — still subject to partner keys and readiness; we do not claim full marketplace routing until your account shows live transfer legs.';
    return '<div class="glass rounded-xl p-4 border border-amber-500/30 text-sm text-gray-300 compliance-partner-claim">'
        . '<p class="font-semibold text-amber-300 mb-1">Product claims vs partner contract</p>'
        . '<p class="text-xs text-gray-500">' . $phaseNote . ' '
        . 'Merchants: see <a href="collection_settings.php" class="text-sky-400 hover:underline">Collection settings</a>. '
        . 'We do not advertise live smart routing or capture-time vendor splits while Phase 11 is parked.</p></div>';
}

/**
 * Three-level MDR clarity: M (merchant total), P (partner base), UniWeb margin (M−P).
 *
 * @param array<string,mixed> $demo Output of commissionSplitRealtimePreview()
 */
function renderComplianceThreeLevelMdrPanel(array $demo, float $gross = 100.0): string
{
    $m = (float)($demo['mdr_m'] ?? 0);
    $p = (float)($demo['mdr_p'] ?? 0);
    $margin = max(0, round($m - $p, 4));
    $partnerFee = (float)($demo['partner_cut'] ?? $demo['partner_fee'] ?? 0);
    $platformFee = (float)($demo['admin_cut'] ?? $demo['platform_fee'] ?? 0);
    $merchantNet = (float)($demo['merchant_net'] ?? 0);
    $grossAmt = (float)($demo['gross'] ?? $gross);

    $html = '<div class="compliance-three-level-mdr">';
    $html .= '<h3 class="font-semibold mb-2">Commission preview — M / P / UniWeb</h3>';
    $html .= '<p class="text-gray-400 mb-2">On ' . e(formatMoney($grossAmt)) . ' success payment (Admin-saved rates):</p>';
    $html .= '<dl class="text-xs text-gray-500 space-y-1">';
    $html .= '<div class="flex justify-between gap-2"><dt>M — your total MDR</dt><dd class="font-mono text-gray-300">' . e(number_format($m, 2)) . '%</dd></div>';
    $html .= '<div class="flex justify-between gap-2"><dt>P — partner base MDR</dt><dd class="font-mono text-gray-300">' . e(number_format($p, 2)) . '%</dd></div>';
    $html .= '<div class="flex justify-between gap-2"><dt>UniWeb margin (M − P)</dt><dd class="font-mono text-amber-400">' . e(number_format($margin, 2)) . '%</dd></div>';
    $html .= '</dl>';
    $html .= '<ul class="text-xs text-gray-500 space-y-1 mt-3 border-t border-gray-800 pt-3">';
    $html .= '<li>Partner fee (P on gross): <span class="text-gray-300">' . e(formatMoney($partnerFee)) . '</span></li>';
    $html .= '<li>UniWeb platform fee (margin on gross): <span class="text-amber-400">' . e(formatMoney($platformFee)) . '</span></li>';
    $html .= '<li>You receive (net): <span class="text-emerald-400">' . e(formatMoney($merchantNet)) . '</span></li>';
    $html .= '</ul>';
    $html .= '<p class="text-[11px] text-gray-600 mt-3">Public stack: <a href="pricing.php" class="text-sky-400 hover:underline">Partner MDR + UniWeb commission + GST</a>. Your portal schedule is authoritative.</p>';
    $html .= '</div>';
    return $html;
}
