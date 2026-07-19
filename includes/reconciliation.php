<?php
declare(strict_types=1);

function getPgReconciliationReport(int $days = 7): array
{
    ensurePgWebhookTables();
    $db = getDB();
    $days = max(1, min(90, $days));

    $webhooks = $db->query("SELECT gateway, status, COUNT(*) AS c FROM pg_webhook_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) GROUP BY gateway, status")->fetchAll();
    $txnSuccess = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='success' AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)")->fetchColumn();
    $txnPending = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='pending' AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)")->fetchColumn();

    $unmatched = $db->query("SELECT w.id, w.gateway, w.reference, w.link_id, w.status, w.event_type, w.created_at
        FROM pg_webhook_logs w
        LEFT JOIN transactions t ON t.utr = w.reference OR t.txn_id = w.reference
        WHERE w.status IN ('received','processed','success') AND w.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        AND t.id IS NULL
        ORDER BY w.created_at DESC LIMIT 50")->fetchAll();

    $missingWebhooks = $db->query("SELECT t.txn_id, t.amount, t.payment_method, t.utr, t.created_at
        FROM transactions t
        LEFT JOIN pg_webhook_logs w ON w.reference = t.utr OR w.link_id IN (SELECT link_id FROM payment_links WHERE id = t.payment_link_id)
        WHERE t.status='success' AND t.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        AND t.payment_method IN ('razorpay','cashfree','payu','card','netbanking','wallet')
        AND w.id IS NULL
        ORDER BY t.created_at DESC LIMIT 50")->fetchAll();

    $refunds = 0;
    try {
        ensureRefundsEngine();
        $refunds = (int)$db->query("SELECT COUNT(*) FROM refunds WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)")->fetchColumn();
    } catch (Throwable $e) { /* ok */ }

    return [
        'days' => $days,
        'webhook_stats' => $webhooks,
        'transactions_success' => $txnSuccess,
        'transactions_pending' => $txnPending,
        'refunds' => $refunds,
        'unmatched_webhooks' => $unmatched,
        'txns_without_webhook' => $missingWebhooks,
    ];
}
