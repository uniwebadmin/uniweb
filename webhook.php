<?php
require_once __DIR__ . '/config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { jsonResponse(['error' => 'Invalid payload'], 400); }

$event = $input['event'] ?? '';
$txnId = $input['txn_id'] ?? '';
$status = $input['status'] ?? '';
$utr = $input['utr'] ?? '';

$db = getDB();

switch ($event) {
    case 'payment.success':
        if ($txnId) {
            $stmt = $db->prepare("UPDATE transactions SET status = 'success', utr = COALESCE(?, utr), updated_at = NOW() WHERE txn_id = ?");
            $stmt->execute([$utr ?: null, $txnId]);

            $txn = $db->prepare('SELECT merchant_id, amount FROM transactions WHERE txn_id = ?');
            $txn->execute([$txnId]);
            $data = $txn->fetch();
            if ($data) {
                createNotification($data['merchant_id'], 'Payment Confirmed', formatMoney((float)$data['amount']) . ' payment confirmed. UTR: ' . ($utr ?: 'N/A'));
            }
        }
        jsonResponse(['success' => true, 'message' => 'Payment marked successful']);

    case 'payment.failed':
        if ($txnId) {
            $db->prepare("UPDATE transactions SET status = 'failed', updated_at = NOW() WHERE txn_id = ?")->execute([$txnId]);
        }
        jsonResponse(['success' => true, 'message' => 'Payment marked failed']);

    case 'settlement.completed':
        $settlementId = $input['settlement_id'] ?? '';
        if ($settlementId) {
            $db->prepare("UPDATE settlements SET status = 'completed', processed_at = NOW(), utr = ? WHERE settlement_id = ?")->execute([$utr, $settlementId]);
            $s = $db->prepare('SELECT merchant_id, net_amount FROM settlements WHERE settlement_id = ?');
            $s->execute([$settlementId]);
            $data = $s->fetch();
            if ($data) {
                createNotification($data['merchant_id'], 'Settlement Completed', formatMoney((float)$data['net_amount']) . ' transferred to your bank.');
            }
        }
        jsonResponse(['success' => true, 'message' => 'Settlement completed']);

    default:
        jsonResponse(['error' => 'Unknown event'], 400);
}
