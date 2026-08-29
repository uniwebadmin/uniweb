<?php
declare(strict_types=1);

function sendPlatformEmail(string $to, string $subject, string $body, bool $isHtml = false): bool
{
    $fromEmail = getSetting('smtp_from_email', getSetting('support_email', 'support@uniweb.co.in'));
    $fromName = getSetting('smtp_from_name', APP_NAME);
    $smtpHost = getSetting('smtp_host', '');
    $smtpPort = (int)getSetting('smtp_port', '587');
    $smtpUser = getSetting('smtp_user', '');
    $smtpPass = getSetting('smtp_pass', '');
    $headers = "From: {$fromName} <{$fromEmail}>\r\nReply-To: {$fromEmail}\r\n";
    $headers .= $isHtml ? "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n" : "Content-Type: text/plain; charset=UTF-8\r\n";

    if ($smtpHost && $smtpUser && $smtpPass) {
        try {
            return smtpSendMail($smtpHost, $smtpPort, $smtpUser, $smtpPass, $fromEmail, $fromName, $to, $subject, $body, $isHtml);
        } catch (Throwable $e) {
            if (function_exists('logPlatformError')) {
                logPlatformError('warning', 'SMTP send failed: ' . $e->getMessage(), ['to' => $to]);
            }
            return false;
        }
    }
    try {
        return @mail($to, $subject, $body, $headers);
    } catch (Throwable $e) {
        return false;
    }
}

function smtpSendMail(string $host, int $port, string $user, string $pass, string $fromEmail, string $fromName, string $to, string $subject, string $body, bool $isHtml): bool
{
    try {
    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15);
    if (!$socket) return false;

    $read = function () use ($socket): string {
        $data = '';
        while ($line = fgets($socket, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $send = function (string $cmd) use ($socket, $read): void {
        fwrite($socket, $cmd . "\r\n");
        $read();
    };

    $read();
    $send("EHLO " . gethostname());
    if ($port === 587) {
        $send('STARTTLS');
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send("EHLO " . gethostname());
    }
    $send('AUTH LOGIN');
    $send(base64_encode($user));
    $send(base64_encode($pass));
    $send('MAIL FROM:<' . $fromEmail . '>');
    $send('RCPT TO:<' . $to . '>');
    $send('DATA');

    $msg = "From: {$fromName} <{$fromEmail}>\r\nTo: <{$to}>\r\nSubject: {$subject}\r\n";
    $msg .= $isHtml ? "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" : "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $msg .= $body . "\r\n.";
    fwrite($socket, $msg . "\r\n");
    $read();
    $send('QUIT');
    fclose($socket);
    return true;
    } catch (Throwable $e) {
        if (function_exists('logPlatformError')) {
            logPlatformError('warning', 'SMTP send failed: ' . $e->getMessage());
        }
        return false;
    }
}

function notifyMerchantEmail(int $merchantId, string $subject, string $message, ?string $event = null, ?string $dedupeKey = null): void
{
    try {
        if ($event !== null && function_exists('merchantWantsNotify') && !merchantWantsNotify($merchantId, $event, 'email')) {
            return;
        }
        $scope = 'merchant:' . $merchantId;
        if ($dedupeKey !== null && $dedupeKey !== '') {
            if (!function_exists('notifyChannelWasSent') && is_file(__DIR__ . '/notifications.php')) {
                require_once __DIR__ . '/notifications.php';
            }
            if (function_exists('notifyChannelWasSent') && notifyChannelWasSent($scope, $dedupeKey)) {
                return;
            }
        }
        $stmt = getDB()->prepare('SELECT email, name FROM merchants WHERE id = ?');
        $stmt->execute([$merchantId]);
        $m = $stmt->fetch();
        if ($m && filter_var($m['email'], FILTER_VALIDATE_EMAIL)) {
            $body = "Hi {$m['name']},\n\n{$message}\n\n— " . APP_NAME . " Team";
            if (sendPlatformEmail($m['email'], $subject, $body)) {
                if ($dedupeKey !== null && $dedupeKey !== '' && function_exists('markNotifyChannelSent')) {
                    markNotifyChannelSent($scope, $dedupeKey);
                }
            }
        }
    } catch (Throwable $e) {
        if (function_exists('logPlatformError')) {
            logPlatformError('warning', 'Merchant email failed: ' . $e->getMessage(), ['merchant_id' => $merchantId]);
        }
    }
}

/** Alert super admin when a merchant completes signup profile — for KYC review */
function notifyAdminNewMerchantSignup(int $merchantId): void
{
    try {
        $stmt = getDB()->prepare('SELECT merchant_code, business_name, name, email, phone, business_entity_type, kyc_status FROM merchants WHERE id = ?');
        $stmt->execute([$merchantId]);
        $m = $stmt->fetch();
        if (!$m) {
            return;
        }
        $adminEmail = getSetting('support_email', COMPANY_ADMIN_EMAIL);
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $adminEmail = COMPANY_ADMIN_EMAIL;
        }
        $verifyUrl = rtrim(APP_URL, '/') . '/admin_kyc.php';
        $viewUrl = rtrim(APP_URL, '/') . '/admin_view_merchant.php?id=' . $merchantId;
        $entity = function_exists('entityTypeLabel') ? entityTypeLabel($m['business_entity_type'] ?? '') : ($m['business_entity_type'] ?? '');
        $subject = '[UniWeb] New merchant signup — ' . ($m['business_name'] ?: $m['merchant_code']);
        $body = "New merchant completed profile setup.\n\n"
            . "Code: {$m['merchant_code']}\n"
            . "Business: {$m['business_name']}\n"
            . "Name: {$m['name']}\n"
            . "Email: {$m['email']}\n"
            . "Phone: {$m['phone']}\n"
            . "Entity: {$entity}\n"
            . "KYC: {$m['kyc_status']}\n\n"
            . "Verify KYC: {$verifyUrl}\n"
            . "View merchant: {$viewUrl}\n";
        sendPlatformEmail($adminEmail, $subject, $body);
    } catch (Throwable $e) {
        if (function_exists('logPlatformError')) {
            logPlatformError('warning', 'Admin signup notify failed: ' . $e->getMessage());
        }
    }
}

/** Alert admin when merchant uploads a KYC document */
function notifyAdminKycDocumentUploaded(int $merchantId, string $docType): void
{
    try {
        $stmt = getDB()->prepare('SELECT merchant_code, business_name, name, email, phone, business_entity_type, kyc_status FROM merchants WHERE id = ?');
        $stmt->execute([$merchantId]);
        $m = $stmt->fetch();
        if (!$m) {
            return;
        }
        $adminEmail = getSetting('support_email', COMPANY_ADMIN_EMAIL);
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $adminEmail = COMPANY_ADMIN_EMAIL;
        }
        $verifyUrl = rtrim(APP_URL, '/') . '/admin_kyc.php';
        $viewUrl = rtrim(APP_URL, '/') . '/admin_view_merchant.php?id=' . $merchantId;
        $docLabel = function_exists('getKycDocLabels') ? (getKycDocLabels()[$docType] ?? $docType) : $docType;
        $entity = function_exists('entityTypeLabel') ? entityTypeLabel($m['business_entity_type'] ?? '') : ($m['business_entity_type'] ?? '');
        $subject = '[UniWeb] KYC document uploaded — ' . ($m['business_name'] ?: $m['merchant_code']);
        $body = "A merchant uploaded a KYC document for review.\n\n"
            . "Document: {$docLabel}\n"
            . "Code: {$m['merchant_code']}\n"
            . "Business: {$m['business_name']}\n"
            . "Name: {$m['name']}\n"
            . "Email: {$m['email']}\n"
            . "Phone: {$m['phone']}\n"
            . "Entity: {$entity}\n"
            . "KYC status: {$m['kyc_status']}\n\n"
            . "Review KYC: {$verifyUrl}\n"
            . "View merchant: {$viewUrl}\n";
        sendPlatformEmail($adminEmail, $subject, $body);
    } catch (Throwable $e) {
        if (function_exists('logPlatformError')) {
            logPlatformError('warning', 'Admin KYC doc notify failed: ' . $e->getMessage());
        }
    }
}

/** @return array{ok:bool,message:string} */
function sendSmtpTestEmail(string $to): array
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter a valid email address.'];
    }

    $host = trim(getSetting('smtp_host', ''));
    $user = trim(getSetting('smtp_user', ''));
    $pass = trim(getSetting('smtp_pass', ''));
    $from = trim(getSetting('smtp_from_email', getSetting('support_email', COMPANY_SUPPORT_EMAIL)));
    $mode = ($host && $user && $pass) ? 'SMTP (' . $host . ')' : 'PHP mail()';

    $subject = APP_NAME . ' — SMTP test ' . date('Y-m-d H:i:s');
    $body = "This is a test email from " . APP_NAME . ".\n\nDelivery mode: {$mode}\nTime: " . date('c') . "\n\nIf you received this, outbound email is working.";
    $sent = sendPlatformEmail($to, $subject, $body);

    return $sent
        ? ['ok' => true, 'message' => 'Test email sent to ' . $to . ' via ' . $mode]
        : ['ok' => false, 'message' => 'Could not send test email. Check SMTP host, port, username, and password.'];
}

/** Send a multipart/mixed email with a single file attachment over SMTP or mail(). */
function sendPlatformEmailWithAttachment(string $to, string $subject, string $body, string $filePath, bool $isHtml = false): bool
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return false;
    }

    $fromEmail = getSetting('smtp_from_email', getSetting('support_email', 'support@uniweb.co.in'));
    $fromName = getSetting('smtp_from_name', APP_NAME);
    $fileName = basename($filePath);
    $fileContent = file_get_contents($filePath);
    $attachment = chunk_split(base64_encode($fileContent));

    $boundary = '----=_Part_' . md5(uniqid('', true));

    $mailBody = "--{$boundary}\r\n";
    $contentType = $isHtml ? 'text/html' : 'text/plain';
    $mailBody .= "Content-Type: {$contentType}; charset=UTF-8\r\n";
    $mailBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $mailBody .= $body . "\r\n\r\n";
    $mailBody .= "--{$boundary}\r\n";
    $mailBody .= "Content-Type: application/x-gzip; name=\"{$fileName}\"\r\n";
    $mailBody .= "Content-Transfer-Encoding: base64\r\n";
    $mailBody .= "Content-Disposition: attachment; filename=\"{$fileName}\"\r\n\r\n";
    $mailBody .= $attachment . "\r\n";
    $mailBody .= "--{$boundary}--\r\n";

    $headers = "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

    $smtpHost = getSetting('smtp_host', '');
    $smtpPort = (int)getSetting('smtp_port', '587');
    $smtpUser = getSetting('smtp_user', '');
    $smtpPass = getSetting('smtp_pass', '');

    if ($smtpHost && $smtpUser && $smtpPass) {
        $fullMessage = "From: {$fromName} <{$fromEmail}>\r\n";
        $fullMessage .= "To: <{$to}>\r\n";
        $fullMessage .= "Subject: {$subject}\r\n";
        $fullMessage .= "MIME-Version: 1.0\r\n";
        $fullMessage .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";
        $fullMessage .= $mailBody;
        try {
            return smtpSendMailRaw($smtpHost, $smtpPort, $smtpUser, $smtpPass, $fromEmail, $to, $fullMessage);
        } catch (Throwable $e) {
            return false;
        }
    }

    try {
        return @mail($to, $subject, $mailBody, $headers);
    } catch (Throwable $e) {
        return false;
    }
}

/** SMTP sender for a pre-built RFC 5322 message. */
function smtpSendMailRaw(string $host, int $port, string $user, string $pass, string $fromEmail, string $to, string $message): bool
{
    try {
    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15);
    if (!$socket) {
        return false;
    }

    $read = function () use ($socket): string {
        $data = '';
        while ($line = fgets($socket, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $send = function (string $cmd) use ($socket, $read): void {
        fwrite($socket, $cmd . "\r\n");
        $read();
    };

    $read();
    $send('EHLO ' . gethostname());
    if ($port === 587) {
        $send('STARTTLS');
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send('EHLO ' . gethostname());
    }
    $send('AUTH LOGIN');
    $send(base64_encode($user));
    $send(base64_encode($pass));
    $send('MAIL FROM:<' . $fromEmail . '>');
    $send('RCPT TO:<' . $to . '>');
    $send('DATA');
    fwrite($socket, $message . "\r\n.\r\n");
    $read();
    $send('QUIT');
    fclose($socket);
    return true;
    } catch (Throwable $e) {
        if (function_exists('logPlatformError')) {
            logPlatformError('warning', 'SMTP raw send failed: ' . $e->getMessage());
        }
        return false;
    }
}

require_once __DIR__ . '/email_templates.php';
