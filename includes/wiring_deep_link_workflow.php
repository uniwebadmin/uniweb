<?php
declare(strict_types=1);

/**
 * Wiring / deep-link — Audit B points #1–2 (diagram-first).
 *
 * #1 Admin disputes honour ?q=DSP… (filter + highlight + scroll).
 * #2 Customer complaint (CT…) notifications → merchant_customer_tickets (not dashboard).
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

function wiringDeepLinkAdminEducation(): array
{
    return [
        'title' => 'Deep-link wiring — disputes & complaints',
        'rule' => 'Diagram first, then code. Search / notify must open the same row as global search.',
        'disputes' => 'admin_disputes.php?q=DSP… → filter list + highlight + scroll. POST keeps q via adminDisputesReturnUrl.',
        'complaints' => 'CT… notify → merchant_customer_tickets.php (not dashboard). Exact CT auto-opens ticket detail.',
        'must_not' => [
            'Ignore q= on admin disputes',
            'Send complaint notifications to dashboard.php',
            'Open another merchant’s CT ticket',
        ],
        'diagram_phone' => '_inbox/chat/daigram/29-wiring-deep-link-b1-b2-phone.html',
        'diagram_full' => '_inbox/chat/daigram/30-wiring-deep-link-b1-b2-full-diagrams.md',
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
        'notif_not_dashboard_only' => str_contains((string)@file_get_contents($root . '/includes/notifications.php'), 'wiringDeepLinkComplaintActionUrl')
            || str_contains((string)@file_get_contents($root . '/includes/notifications.php'), 'customer complaint'),
        'merchant_ct_redirect' => str_contains((string)@file_get_contents($root . '/merchant_customer_tickets.php'), 'wiringMerchantComplaintQueryState')
            || (str_contains((string)@file_get_contents($root . '/merchant_customer_tickets.php'), 'CT[A-F0-9]')
                && str_contains((string)@file_get_contents($root . '/merchant_customer_tickets.php'), "redirect('merchant_customer_tickets.php?id=")),
    ];
    $ok = !in_array(false, $checks, true);
    $failed = array_keys(array_filter($checks, static fn ($v) => !$v));

    return [
        'id' => 'wiring_deep_link',
        'label' => 'Wiring / deep-link (B1–B2)',
        'ok' => $ok,
        'status' => $ok ? 'Disputes q= + CT notify wired' : 'Fix wiring — ' . implode(', ', $failed),
        'detail' => 'DSP search highlight · CT notify → Customer Complaints · not dashboard',
        'test_url' => 'admin_disputes.php?q=DSP',
    ];
}
