<?php
declare(strict_types=1);

/** Ops snapshot metrics for admin Reports hub (view=ops). */
function adminReportsOpsData(PDO $db, int $days): array
{
    if ($days < 1 || $days > 365) {
        $days = 30;
    }
    $since = date('Y-m-d H:i:s', time() - ($days * 86400));

    $txSummary = ['total' => 0, 'success' => 0, 'failed' => 0, 'pending' => 0, 'volume' => 0.0, 'fees' => 0.0];
    try {
        $st = $db->prepare("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as success,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
            COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) as volume,
            COALESCE(SUM(CASE WHEN status='success' THEN platform_fee ELSE 0 END),0) as fees
            FROM transactions WHERE created_at >= ?");
        $st->execute([$since]);
        $row = $st->fetch();
        if ($row) {
            $txSummary = [
                'total' => (int)$row['total'],
                'success' => (int)$row['success'],
                'failed' => (int)$row['failed'],
                'pending' => (int)$row['pending'],
                'volume' => (float)$row['volume'],
                'fees' => (float)$row['fees'],
            ];
        }
    } catch (Throwable $e) {
    }

    $stlSummary = ['total' => 0, 'completed' => 0, 'pending' => 0, 'failed' => 0, 'amount' => 0.0];
    try {
        $st = $db->prepare("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status IN ('pending','processing') THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
            COALESCE(SUM(net_amount),0) as amount
            FROM settlements WHERE created_at >= ?");
        $st->execute([$since]);
        $row = $st->fetch();
        if ($row) {
            $stlSummary = [
                'total' => (int)$row['total'],
                'completed' => (int)$row['completed'],
                'pending' => (int)$row['pending'],
                'failed' => (int)$row['failed'],
                'amount' => (float)$row['amount'],
            ];
        }
    } catch (Throwable $e) {
    }

    $merchantSummary = ['total' => 0, 'active' => 0, 'pending_kyc' => 0, 'new_this_period' => 0];
    try {
        $merchantSummary['total'] = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE status != 'deleted'")->fetchColumn();
        $merchantSummary['active'] = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE status='active'")->fetchColumn();
        $merchantSummary['pending_kyc'] = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE kyc_status='pending'")->fetchColumn();
        $st = $db->prepare("SELECT COUNT(*) FROM merchants WHERE created_at >= ? AND status != 'deleted'");
        $st->execute([$since]);
        $merchantSummary['new_this_period'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {
    }

    $disputeSummary = ['open' => 0, 'resolved' => 0, 'total' => 0];
    try {
        $disputeSummary['open'] = (int)$db->query("SELECT COUNT(*) FROM chargebacks WHERE status IN ('opened','evidence_required','submitted')")->fetchColumn();
        $disputeSummary['resolved'] = (int)$db->query("SELECT COUNT(*) FROM chargebacks WHERE status IN ('won','lost','withdrawn','expired')")->fetchColumn();
        $disputeSummary['total'] = $disputeSummary['open'] + $disputeSummary['resolved'];
    } catch (Throwable $e) {
    }

    $refundSummary = ['total' => 0, 'amount' => 0.0];
    try {
        $st = $db->prepare('SELECT COUNT(*) as total, COALESCE(SUM(amount),0) as amount FROM refunds WHERE created_at >= ?');
        $st->execute([$since]);
        $row = $st->fetch();
        if ($row) {
            $refundSummary = ['total' => (int)$row['total'], 'amount' => (float)$row['amount']];
        }
    } catch (Throwable $e) {
    }

    $topMerchants = [];
    try {
        $st = $db->prepare("SELECT m.business_name, m.merchant_code,
            COUNT(t.id) as txn_count, COALESCE(SUM(t.amount),0) as volume
            FROM transactions t JOIN merchants m ON m.id=t.merchant_id
            WHERE t.status='success' AND t.created_at >= ?
            GROUP BY t.merchant_id ORDER BY volume DESC LIMIT 10");
        $st->execute([$since]);
        $topMerchants = $st->fetchAll();
    } catch (Throwable $e) {
    }

    $dailyTrend = [];
    try {
        $dailyTrend = $db->query("SELECT DATE(created_at) as d,
            COUNT(*) as txns, COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) as volume
            FROM transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
            GROUP BY DATE(created_at) ORDER BY d ASC")->fetchAll();
    } catch (Throwable $e) {
    }

    $supportSummary = ['open' => 0, 'closed' => 0];
    try {
        $supportSummary['open'] = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status='open'")->fetchColumn();
        $supportSummary['closed'] = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status='closed'")->fetchColumn();
    } catch (Throwable $e) {
    }

    return compact(
        'days',
        'txSummary',
        'stlSummary',
        'merchantSummary',
        'disputeSummary',
        'refundSummary',
        'topMerchants',
        'dailyTrend',
        'supportSummary'
    );
}
