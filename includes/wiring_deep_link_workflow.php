<?php
declare(strict_types=1);

/**
 * Wiring / deep-link — Audit B (diagram-first).
 *
 * #1 Admin disputes honour ?q=DSP… (filter + highlight + scroll).
 * #2 Customer complaint (CT…) notifications → merchant_customer_tickets (not dashboard).
 * #3 Settlement / batch / payment notifications → transactions.php (not settlements/dashboard).
 * #4 KYC forward notifications include partner display name from registry.
 * #5 Chargebacks silo merged — main lane Disputes; chargebacks.php legacy only.
 */

function wiringDeepLinkIdPattern(string $prefix): string
{
    $p = strtoupper(preg_replace('/[^A-Z]/', '', $prefix));
    return match ($p) {
        'DSP' => '/^DSP[A-F0-9]{8,}$/i',
        'CT' => '/^CT[A-F0-9]{8,}$/i',
        'TKT' => '/^TKT[A-F0-9]{8,}$/i',
        'TXN' => '/^TXN[A-F0-9]{8,}$/i',
        default => '/^' . preg_quote($p, '/') . '[A-F0-9]{8,}$/i',
    };
}

function wiringDeepLinkNormalizeId(string $raw, string $prefix): string
{
    $trim = mb_substr(trim($raw), 0, 100);
    if ($trim === '') {
        return '';
    }
    $pattern = wiringDeepLinkIdPattern($prefix);
    if (!preg_match($pattern, $trim)) {
        return $trim;
    }
    return strtoupper($trim);
}

/** @return array{disputeQ:string,highlightDisputeId:string} */
function wiringAdminDisputesQueryState(array $get): array
{
    $disputeQ = mb_substr(trim((string)($get['q'] ?? ($get['id'] ?? ''))), 0, 100);
    $highlight = '';
    if ($disputeQ !== '' && preg_match(wiringDeepLinkIdPattern('DSP'), $disputeQ)) {
        $highlight = wiringDeepLinkNormalizeId($disputeQ, 'DSP');
    }
    return ['disputeQ' => $disputeQ, 'highlightDisputeId' => $highlight];
}

/**
 * Ensure exact DSP row is present in list (fetch from DB if filtered out).
 *
 * @param array<int,array<string,mixed>> $disputes
 * @return array<int,array<string,mixed>>
 */
function wiringAdminDisputesEnsureHighlightedRow(PDO $db, array $disputes, string $highlightDisputeId): array
{
    if ($highlightDisputeId === '') {
        return $disputes;
    }
    foreach ($disputes as $d) {
        if (strcasecmp((string)($d['dispute_id'] ?? ''), $highlightDisputeId) === 0) {
            return $disputes;
        }
    }
    try {
        $exSt = $db->prepare(
            'SELECT d.*, m.business_name, m.id AS merchant_row_id, t.txn_id, t.amount
             FROM disputes d
             JOIN merchants m ON d.merchant_id=m.id
             JOIN transactions t ON t.id=d.transaction_id
             WHERE d.dispute_id = ? LIMIT 1'
        );
        $exSt->execute([$highlightDisputeId]);
        $exact = $exSt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($exact) {
            array_unshift($disputes, $exact);
            return array_values(array_unique($disputes, SORT_REGULAR));
        }
    } catch (Throwable $e) {
        /* ok */
    }
    return $disputes;
}

/** Merchant notification target for customer complaints — never dashboard when CT or complaint title. */
function wiringDeepLinkComplaintActionUrl(string $title, string $message): ?string
{
    $hay = $title . ' ' . $message;
    $titleLower = strtolower($title);
    if (preg_match('/\b(CT[A-F0-9]{8,})\b/i', $hay, $m)) {
        return 'merchant_customer_tickets.php?q=' . rawurlencode(strtoupper($m[1]));
    }
    if (
        str_contains($titleLower, 'customer complaint')
        || str_contains($titleLower, 'new customer complaint')
        || str_contains($titleLower, 'complaint')
        || str_contains($titleLower, 'grievance')
        || str_contains($titleLower, 'customer ticket')
    ) {
        return 'merchant_customer_tickets.php';
    }
    return null;
}

function wiringDeepLinkDisputeActionUrl(string $title, string $message): ?string
{
    $hay = $title . ' ' . $message;
    $titleLower = strtolower($title);
    if (preg_match('/\b(DSP[A-F0-9]{8,})\b/i', $hay, $m)) {
        return 'disputes.php?id=' . rawurlencode(strtoupper($m[1]));
    }
    if (str_contains($titleLower, 'dispute') || str_contains($titleLower, 'chargeback')) {
        return 'disputes.php';
    }
    return null;
}

/**
 * Merchant complaints: exact CT in q= → redirect to ticket detail (scoped).
 *
 * @return array{redirect:string,focusTicketId:string}
 */
function wiringMerchantComplaintQueryState(int $merchantId, string $ticketQ, bool $hasDetailView): array
{
    $focus = '';
    $redirect = '';
    if (!$hasDetailView && $ticketQ !== '' && preg_match(wiringDeepLinkIdPattern('CT'), $ticketQ)) {
        $focus = wiringDeepLinkNormalizeId($ticketQ, 'CT');
        try {
            $st = getDB()->prepare('SELECT id FROM customer_tickets WHERE ticket_id = ? AND merchant_id = ? LIMIT 1');
            $st->execute([$focus, $merchantId]);
            $found = (int)($st->fetchColumn() ?: 0);
            if ($found > 0) {
                $redirect = 'merchant_customer_tickets.php?id=' . $found;
            }
        } catch (Throwable $e) {
            /* ok */
        }
    }
    return ['redirect' => $redirect, 'focusTicketId' => $focus];
}

/** Settlement / batch / payment money alerts → transaction ledger (not settlements page). */
function wiringDeepLinkSettlementActionUrl(string $title, string $message = ''): ?string
{
    $titleLower = strtolower($title);
    $hay = strtolower($title . ' ' . $message);
    $needles = [
        'settlement',
        'batch complete',
        'batch submitted',
        'payment received',
        'payment approved',
    ];
    foreach ($needles as $needle) {
        if (str_contains($titleLower, $needle) || str_contains($hay, $needle)) {
            return 'transactions.php';
        }
    }
    if (str_contains($titleLower, 'payment')) {
        return 'transactions.php';
    }
    return null;
}

function wiringKycForwardPartnerLabel(string $partnerKey): string
{
    $partnerKey = strtolower(trim($partnerKey));
    if ($partnerKey === '') {
        return 'Partner';
    }
    try {
        if (function_exists('getPartnerRegistry')) {
            $reg = getPartnerRegistry();
            return (string)($reg[$partnerKey]['name'] ?? ucfirst($partnerKey));
        }
    } catch (Throwable $e) {
        /* smoke / offline — fall through */
    }
    if (function_exists('partnerDisplayName')) {
        try {
            return partnerDisplayName($partnerKey);
        } catch (Throwable $e) {
            /* ok */
        }
    }
    return ucfirst($partnerKey);
}

function wiringKycForwardNotifyBody(string $partnerKey, string $kind = 'forward', int $attempts = 0): string
{
    $label = wiringKycForwardPartnerLabel($partnerKey);
    return match ($kind) {
        'fail' => 'KYC submission to ' . $label . ' failed after ' . max(1, $attempts) . ' attempt(s). Staff will assist manually.',
        'gateway' => 'Your KYC documents were submitted to ' . $label . ' for onboarding.',
        default => 'Your KYC package has been submitted to ' . $label . '.',
    };
}

function wiringDeepLinkKycActionUrl(string $title): ?string
{
    $titleLower = strtolower($title);
    if (
        str_contains($titleLower, 'kyc forwarded')
        || str_contains($titleLower, 'kyc forward failed')
        || str_contains($titleLower, 'gateway submission')
        || str_contains($titleLower, 'kyc')
    ) {
        return 'kyc.php';
    }
    return null;
}

function wiringChargebackMerchantLaneUrl(): string
{
    return 'disputes.php';
}

function wiringChargebackAdminLaneUrl(): string
{
    return 'admin_disputes.php';
}

/** @param array<int,mixed> $legacyRows */
function wiringChargebackMerchantShouldRedirect(array $legacyRows, array $query): bool
{
    if (!empty($query['legacy'])) {
        return false;
    }
    return $legacyRows === [];
}

/** @return array{title:string,main_lane:string,legacy_page:string,rule:string} */
function wiringChargebackSiloEducation(bool $forAdmin = false): array
{
    return [
        'title' => $forAdmin ? 'Chargebacks — legacy ingest only' : 'Disputes — one main lane',
        'main_lane' => $forAdmin ? wiringChargebackAdminLaneUrl() : wiringChargebackMerchantLaneUrl(),
        'legacy_page' => $forAdmin ? 'admin_chargebacks.php' : 'chargebacks.php?legacy=1',
        'rule' => 'New payment disputes and chargebacks → Disputes. Chargebacks page = old bank evidence rows only.',
    ];
}

function wiringDeepLinkAdminEducation(): array
{
    return [
        'title' => 'Deep-link wiring — Audit B (#1–5)',
        'rule' => 'Diagram first, then code. Search / notify must open the same destination as global search.',
        'disputes' => 'admin_disputes.php?q=DSP… → filter + highlight + scroll.',
        'complaints' => 'CT… notify → merchant_customer_tickets.php (not dashboard).',
        'settlement' => 'Settlement / batch / payment notify → transactions.php (not settlements list).',
        'kyc' => 'KYC forward notify body includes partner display name from Partner Registry.',
        'chargebacks' => 'Main lane = Disputes; chargebacks.php legacy only; search chargeback → admin_disputes.',
        'must_not' => [
            'Ignore q= on admin disputes',
            'Complaint or settlement notify → dashboard',
            'KYC Forwarded without partner name',
            'Chargeback search → admin_chargebacks.php',
        ],
        'diagram_phone_b12' => '_inbox/chat/daigram/29-wiring-deep-link-b1-b2-phone.html',
        'diagram_phone_b345' => '_inbox/chat/daigram/31-wiring-deep-link-b3-b5-phone.html',
        'diagram_full_b345' => '_inbox/chat/daigram/32-wiring-deep-link-b3-b5-full-diagrams.md',
    ];
}

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function wiringDeepLinkHealthCheck(): array
{
    $root = dirname(__DIR__);
    $checks = [
        'workflow' => is_file($root . '/includes/wiring_deep_link_workflow.php'),
        'admin_disputes_q' => str_contains((string)@file_get_contents($root . '/admin_disputes.php'), 'highlightDisputeId'),
        'notif_ct' => str_contains((string)@file_get_contents($root . '/includes/notifications.php'), 'merchant_customer_tickets.php'),
        'notif_settlement_txn' => str_contains((string)@file_get_contents($root . '/includes/notifications.php'), 'wiringDeepLinkSettlementActionUrl')
            || (str_contains((string)@file_get_contents($root . '/includes/notifications.php'), 'batch complete')
                && str_contains((string)@file_get_contents($root . '/includes/notifications.php'), 'transactions.php')),
        'kyc_partner_label' => str_contains((string)@file_get_contents($root . '/includes/wiring_deep_link_workflow.php'), 'wiringKycForwardPartnerLabel')
            && (str_contains((string)@file_get_contents($root . '/includes/partner_forward_queue.php'), 'wiringKycForwardNotifyBody')
                || str_contains((string)@file_get_contents($root . '/includes/partner_forward_queue.php'), 'wiringKycForwardPartnerLabel')),
        'chargeback_silo' => str_contains((string)@file_get_contents($root . '/global_search.php'), 'admin_disputes.php?q=')
            && !str_contains((string)@file_get_contents($root . '/global_search.php'), 'admin_chargebacks.php?q=')
            && str_contains((string)@file_get_contents($root . '/chargebacks.php'), 'disputes.php'),
        'merchant_ct_redirect' => str_contains((string)@file_get_contents($root . '/merchant_customer_tickets.php'), 'wiringMerchantComplaintQueryState'),
    ];
    $ok = !in_array(false, $checks, true);
    $failed = array_keys(array_filter($checks, static fn ($v) => !$v));

    return [
        'id' => 'wiring_deep_link',
        'label' => 'Wiring / deep-link (B1–B5)',
        'ok' => $ok,
        'status' => $ok ? 'Disputes · CT · settlement · KYC label · chargeback lane' : 'Fix wiring — ' . implode(', ', $failed),
        'detail' => 'DSP q= · CT complaints · settlement→transactions · KYC partner name · disputes main lane',
        'test_url' => 'admin_disputes.php?q=DSP',
    ];
}
