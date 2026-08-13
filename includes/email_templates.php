<?php
declare(strict_types=1);

/**
 * Branded HTML email templates for transactional emails.
 * Usage: sendTemplatedEmail($merchantId, 'payment_received', [...vars])
 */

function renderEmailShell(string $title, string $contentHtml): string
{
    $appName = APP_NAME;
    $supportEmail = getSetting('support_email', COMPANY_SUPPORT_EMAIL ?? 'support@uniweb.co.in');
    $appUrl = APP_URL;
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title} — {$appName}</title>
</head>
<body style="margin:0;padding:0;background:#0f172a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#e2e8f0;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#0f172a;min-height:100vh;">
    <tr>
      <td align="center" style="padding:32px 16px;">
        <table width="560" cellpadding="0" cellspacing="0" style="background:#1e293b;border-radius:12px;overflow:hidden;border:1px solid #334155;">
          <tr>
            <td style="background:#1e40af;padding:20px 28px;">
              <span style="font-size:20px;font-weight:700;color:#fff;">{$appName}</span>
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              <h2 style="margin:0 0 16px;font-size:18px;color:#f1f5f9;">{$title}</h2>
              <div style="font-size:14px;line-height:1.6;color:#cbd5e1;">
                {$contentHtml}
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 28px;border-top:1px solid #334155;">
              <p style="margin:0;font-size:12px;color:#64748b;">
                This is an automated message from {$appName}. Need help? Contact
                <a href="mailto:{$supportEmail}" style="color:#60a5fa;text-decoration:none;">{$supportEmail}</a>
              </p>
              <p style="margin:8px 0 0;font-size:12px;color:#475569;">
                <a href="{$appUrl}" style="color:#475569;text-decoration:none;">{$appUrl}</a>
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

function renderEmailTemplate(string $template, array $vars = []): string
{
    $amount = $vars['amount'] ?? '';
    $txnId = $vars['txn_id'] ?? '';
    $merchantName = $vars['merchant_name'] ?? '';
    $batchCode = $vars['batch_code'] ?? '';
    $txnCount = $vars['txn_count'] ?? '';
    $refundId = $vars['refund_id'] ?? '';
    $reason = $vars['reason'] ?? '';
    $businessName = $vars['business_name'] ?? '';
    $dashboardUrl = APP_URL . '/dashboard.php';

    switch ($template) {
        case 'payment_received':
            $content = "<p>Hi {$merchantName},</p>
<p>You received a payment of <strong style=\"color:#22c55e;font-size:16px;\">{$amount}</strong>.</p>
<table style=\"width:100%;margin:16px 0;font-size:13px;\">
<tr><td style=\"padding:6px 0;color:#94a3b8;\">Transaction ID</td><td style=\"padding:6px 0;font-family:monospace;color:#e2e8f0;\">{$txnId}</td></tr>
</table>
<p><a href=\"{$dashboardUrl}\" style=\"display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-size:13px;\">View Dashboard</a></p>";
            return renderEmailShell('Payment Received', $content);

        case 'settlement_completed':
            $content = "<p>Hi {$merchantName},</p>
<p>Your settlement batch has been completed.</p>
<table style=\"width:100%;margin:16px 0;font-size:13px;\">
<tr><td style=\"padding:6px 0;color:#94a3b8;\">Batch</td><td style=\"padding:6px 0;font-family:monospace;\">{$batchCode}</td></tr>
<tr><td style=\"padding:6px 0;color:#94a3b8;\">Amount</td><td style=\"padding:6px 0;font-weight:600;color:#22c55e;\">{$amount}</td></tr>
<tr><td style=\"padding:6px 0;color:#94a3b8;\">Transactions</td><td style=\"padding:6px 0;\">{$txnCount}</td></tr>
</table>
<p><a href=\"" . APP_URL . "/settlements.php\" style=\"display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-size:13px;\">View Settlements</a></p>";
            return renderEmailShell('Settlement Completed', $content);

        case 'refund_processed':
            $content = "<p>Hi {$merchantName},</p>
<p>A refund has been processed for <strong style=\"color:#f59e0b;\">{$amount}</strong>.</p>
<table style=\"width:100%;margin:16px 0;font-size:13px;\">
<tr><td style=\"padding:6px 0;color:#94a3b8;\">Refund ID</td><td style=\"padding:6px 0;font-family:monospace;\">{$refundId}</td></tr>
<tr><td style=\"padding:6px 0;color:#94a3b8;\">Transaction</td><td style=\"padding:6px 0;font-family:monospace;\">{$txnId}</td></tr>
<tr><td style=\"padding:6px 0;color:#94a3b8;\">Reason</td><td style=\"padding:6px 0;\">{$reason}</td></tr>
</table>
<p><a href=\"" . APP_URL . "/refunds.php\" style=\"display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-size:13px;\">View Refunds</a></p>";
            return renderEmailShell('Refund Processed', $content);

        case 'kyc_approved':
            $content = "<p>Hi {$merchantName},</p>
<p>Great news! Your KYC verification has been <strong style=\"color:#22c55e;\">approved</strong>.</p>
<p>Your account is now eligible for Live Mode. You can activate live payments from your dashboard.</p>
<p><a href=\"{$dashboardUrl}\" style=\"display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-size:13px;\">Go to Dashboard</a></p>";
            return renderEmailShell('KYC Approved', $content);

        case 'kyc_rejected':
            $content = "<p>Hi {$merchantName},</p>
<p>Your KYC submission could not be verified.</p>
<table style=\"width:100%;margin:16px 0;font-size:13px;\">
<tr><td style=\"padding:6px 0;color:#94a3b8;\">Reason</td><td style=\"padding:6px 0;\">{$reason}</td></tr>
</table>
<p>Please log in to your dashboard and re-upload the required documents with clear, readable copies.</p>
<p><a href=\"" . APP_URL . "/kyc.php\" style=\"display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-size:13px;\">Update KYC</a></p>";
            return renderEmailShell('KYC Update Required', $content);

        case 'payout_failed':
            $content = "<p>Hi {$merchantName},</p>
<p>A payout attempt has <strong style=\"color:#ef4444;\">failed</strong>.</p>
<table style=\"width:100%;margin:16px 0;font-size:13px;\">
<tr><td style=\"padding:6px 0;color:#94a3b8;\">Batch</td><td style=\"padding:6px 0;font-family:monospace;\">{$batchCode}</td></tr>
<tr><td style=\"padding:6px 0;color:#94a3b8;\">Amount</td><td style=\"padding:6px 0;\">{$amount}</td></tr>
<tr><td style=\"padding:6px 0;color:#94a3b8;\">Reason</td><td style=\"padding:6px 0;\">{$reason}</td></tr>
</table>
<p>No wallet balance was deducted. Please check your bank details and retry.</p>
<p><a href=\"" . APP_URL . "/settlements.php\" style=\"display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-size:13px;\">View Settlements</a></p>";
            return renderEmailShell('Payout Failed', $content);

        default:
            return renderEmailShell('Notification', '<p>' . e($vars['message'] ?? '') . '</p>');
    }
}

function sendTemplatedEmail(int $merchantId, string $template, array $vars = []): void
{
    try {
        $smtpHost = getSetting('smtp_host', '');
        $smtpUser = getSetting('smtp_user', '');
        $smtpPass = getSetting('smtp_pass', '');
        if (!$smtpHost || !$smtpUser || !$smtpPass) {
            if (function_exists('logPlatformError')) {
                logPlatformError('warning', 'Templated email skipped: SMTP not configured', [
                    'template' => $template,
                    'merchant_id' => $merchantId,
                ]);
            }
            return;
        }

        $stmt = getDB()->prepare('SELECT email, name, business_name FROM merchants WHERE id = ?');
        $stmt->execute([$merchantId]);
        $m = $stmt->fetch();
        if (!$m || !filter_var($m['email'], FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (function_exists('merchantWantsNotify') && !merchantWantsNotify($merchantId, $template, 'email')) {
            return;
        }
        $vars['merchant_name'] = $vars['merchant_name'] ?? ($m['name'] ?: $m['business_name']);
        $subjectMap = [
            'payment_received' => 'Payment Received — ' . APP_NAME,
            'settlement_completed' => 'Settlement Completed — ' . APP_NAME,
            'refund_processed' => 'Refund Processed — ' . APP_NAME,
            'kyc_approved' => 'KYC Approved — ' . APP_NAME,
            'kyc_rejected' => 'KYC Update Required — ' . APP_NAME,
            'payout_failed' => 'Payout Failed — ' . APP_NAME,
        ];
        $subject = $subjectMap[$template] ?? 'Notification — ' . APP_NAME;
        $html = renderEmailTemplate($template, $vars);
        sendPlatformEmail($m['email'], $subject, $html, true);
    } catch (Throwable $e) {
        if (function_exists('logPlatformError')) {
            logPlatformError('warning', 'Templated email send failed: ' . $template, ['error' => $e->getMessage()]);
        }
    }
}
