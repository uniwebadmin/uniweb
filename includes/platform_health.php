<?php
declare(strict_types=1);

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function smtpHealthCheck(): array
{
    $host = trim(getSetting('smtp_host', ''));
    $user = trim(getSetting('smtp_user', ''));
    $pass = trim(getSetting('smtp_pass', ''));
    $from = trim(getSetting('smtp_from_email', getSetting('support_email', COMPANY_SUPPORT_EMAIL)));

    if ($host === '' || $user === '' || $pass === '') {
        return [
            'id' => 'smtp',
            'label' => 'Email (SMTP)',
            'ok' => $from !== '',
            'status' => $host === '' ? 'PHP mail() fallback' : 'Incomplete SMTP config',
            'detail' => $from !== '' ? 'From: ' . $from : 'Set support email in Gateway Settings',
            'test_url' => 'gateway_settings.php',
        ];
    }

    $port = (int)getSetting('smtp_port', '587');
    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
    if ($socket) {
        fclose($socket);
        return [
            'id' => 'smtp',
            'label' => 'Email (SMTP)',
            'ok' => true,
            'status' => 'SMTP host reachable',
            'detail' => "{$host}:{$port}",
            'test_url' => 'gateway_settings.php',
        ];
    }

    return [
        'id' => 'smtp',
        'label' => 'Email (SMTP)',
        'ok' => false,
        'status' => 'SMTP unreachable',
        'detail' => $errstr ?: "Cannot connect to {$host}:{$port}",
        'test_url' => 'gateway_settings.php',
    ];
}

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function whatsappHealthCheck(): array
{
    if (getSetting('whatsapp_enabled', '0') !== '1') {
        return [
            'id' => 'whatsapp',
            'label' => 'WhatsApp OTP',
            'ok' => false,
            'status' => 'Disabled',
            'detail' => 'Enable in Gateway Settings for API OTP delivery',
            'test_url' => 'gateway_settings.php',
        ];
    }

    $token = trim(getSetting('whatsapp_api_token', ''));
    $phoneId = trim(getSetting('whatsapp_phone_id', ''));
    if ($token === '' || $phoneId === '') {
        return [
            'id' => 'whatsapp',
            'label' => 'WhatsApp OTP',
            'ok' => false,
            'status' => 'Not configured',
            'detail' => 'API token or Phone ID missing',
            'test_url' => 'gateway_settings.php',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'id' => 'whatsapp',
            'label' => 'WhatsApp OTP',
            'ok' => false,
            'status' => 'cURL unavailable',
            'detail' => 'PHP curl extension not enabled on server',
            'test_url' => 'gateway_settings.php',
        ];
    }

    $ch = curl_init('https://graph.facebook.com/v18.0/' . rawurlencode($phoneId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $healthy = ($err === '' && $http === 200);
    // Persist so login can fall back to password when Meta token is dead (no OTP lockout)
    try {
        $db = getDB();
        $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
            ->execute(['whatsapp_otp_healthy', $healthy ? '1' : '0', $healthy ? '1' : '0']);
        if (function_exists('clearSettingCache')) {
            clearSettingCache('whatsapp_otp_healthy');
        }
    } catch (Throwable $e) { /* ok */ }

    if ($err) {
        return [
            'id' => 'whatsapp',
            'label' => 'WhatsApp OTP',
            'ok' => false,
            'status' => 'Connection failed',
            'detail' => $err,
            'test_url' => 'gateway_settings.php',
        ];
    }

    return [
        'id' => 'whatsapp',
        'label' => 'WhatsApp OTP',
        'ok' => $healthy,
        'status' => $healthy ? 'Meta API connected' : 'Meta API error — password login active',
        'detail' => 'HTTP ' . $http,
        'test_url' => 'gateway_settings.php',
    ];
}

/** @return array{total:int,failed:int,last_at:?string} */
function pgWebhookStats24h(): array
{
    try {
        ensurePgWebhookTables();
        $row = getDB()->query("SELECT COUNT(*) AS total,
            SUM(CASE WHEN status IN ('failed','invalid_hash','invalid_signature','invalid_json','retry_failed') THEN 1 ELSE 0 END) AS failed,
            MAX(created_at) AS last_at
            FROM pg_webhook_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch();
        return [
            'total' => (int)($row['total'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
            'last_at' => $row['last_at'] ?? null,
        ];
    } catch (Throwable $e) {
        return ['total' => 0, 'failed' => 0, 'last_at' => null];
    }
}

/** @return array{total:int,failed:int,merchants:int,last_at:?string} */
function merchantWebhookStats24h(): array
{
    try {
        ensureMerchantWebhookEngine();
        $row = getDB()->query("SELECT COUNT(*) AS total,
            SUM(CASE WHEN response_code IS NULL OR response_code < 200 OR response_code >= 300 THEN 1 ELSE 0 END) AS failed,
            COUNT(DISTINCT merchant_id) AS merchants,
            MAX(created_at) AS last_at
            FROM merchant_webhook_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch();
        return [
            'total' => (int)($row['total'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
            'merchants' => (int)($row['merchants'] ?? 0),
            'last_at' => $row['last_at'] ?? null,
        ];
    } catch (Throwable $e) {
        return ['total' => 0, 'failed' => 0, 'merchants' => 0, 'last_at' => null];
    }
}

/** @return list<array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string}> */
function getPlatformServiceHealth(): array
{
    $activePg = getSetting('active_payment_gateway', 'razorpay');
    $pgStats = pgWebhookStats24h();
    $mwStats = merchantWebhookStats24h();
    try {
        $cron = getSettlementCronStatus();
    } catch (Throwable $e) {
        $cron = [
            'enabled' => false,
            'last_run' => null,
            'last_total' => 0,
            'last_ok' => 0,
            'due_now' => 0,
            'cron_url' => APP_URL . '/cron_settlements.php',
            'key' => '',
        ];
    }

    $services = [
        [
            'id' => 'razorpay',
            'label' => 'Razorpay',
            'ok' => isGatewayConfigured('razorpay'),
            'status' => isGatewayConfigured('razorpay') ? ($activePg === 'razorpay' ? 'Active primary' : 'Configured') : 'Not configured',
            'detail' => isGatewayConfigured('razorpay') ? gatewayStatusLabel('razorpay') : 'Add keys in Gateway Settings',
            'test_url' => 'gateway_settings.php?test_gateway=razorpay&csrf=' . csrfToken(),
        ],
        [
            'id' => 'cashfree',
            'label' => 'Cashfree',
            'ok' => isGatewayConfigured('cashfree'),
            'status' => isGatewayConfigured('cashfree') ? ($activePg === 'cashfree' ? 'Active primary' : 'Configured') : 'Not configured',
            'detail' => isGatewayConfigured('cashfree') ? gatewayStatusLabel('cashfree') : 'Add keys in Gateway Settings',
            'test_url' => 'gateway_settings.php?test_gateway=cashfree&csrf=' . csrfToken(),
        ],
        [
            'id' => 'payu',
            'label' => 'PayU',
            'ok' => isGatewayConfigured('payu'),
            'status' => isGatewayConfigured('payu') ? ($activePg === 'payu' ? 'Active primary' : 'Configured') : 'Not configured',
            'detail' => isGatewayConfigured('payu') ? gatewayStatusLabel('payu') : 'Add keys in Gateway Settings',
            'test_url' => 'gateway_settings.php?test_gateway=payu&csrf=' . csrfToken(),
        ],
        [
            'id' => 'axis',
            'label' => 'Axis Bank VA',
            'ok' => isGatewayConfigured('axis'),
            'status' => isGatewayConfigured('axis') ? 'Configured' : 'Not configured',
            'detail' => isGatewayConfigured('axis') ? 'Virtual account collections' : 'Client ID / Secret missing',
            'test_url' => 'admin_axis.php',
        ],
        [
            'id' => 'decentro',
            'label' => 'Decentro KYC',
            'ok' => isGatewayConfigured('decentro'),
            'status' => isGatewayConfigured('decentro') ? 'Configured' : 'Not configured',
            'detail' => isGatewayConfigured('decentro') ? 'PAN/GST/Bank verification' : 'Client ID / Secret missing',
            'test_url' => 'gateway_settings.php?test_gateway=decentro&csrf=' . csrfToken(),
        ],
        smtpHealthCheck(),
        whatsappHealthCheck(),
        [
            'id' => 'otp',
            'label' => 'OTP Login',
            'ok' => isOTPEnabled(),
            'status' => isOTPEnabled() ? 'Enabled' : 'Disabled',
            'detail' => isOTPEnabled() ? 'WhatsApp + email delivery' : 'Password-only login',
            'test_url' => 'gateway_settings.php',
        ],
        [
            'id' => 'pg_webhooks',
            'label' => 'PG Webhooks (24h)',
            'ok' => $pgStats['total'] === 0 || $pgStats['failed'] === 0,
            'status' => $pgStats['total'] === 0 ? 'No events yet' : ($pgStats['failed'] === 0 ? 'All processed' : $pgStats['failed'] . ' need attention'),
            'detail' => $pgStats['total'] . ' events' . ($pgStats['last_at'] ? ' · last ' . $pgStats['last_at'] : ''),
            'test_url' => 'admin_pg_webhooks.php',
        ],
        [
            'id' => 'merchant_webhooks',
            'label' => 'Merchant Webhooks (24h)',
            'ok' => $mwStats['total'] === 0 || $mwStats['failed'] === 0,
            'status' => $mwStats['total'] === 0 ? 'No deliveries yet' : ($mwStats['failed'] === 0 ? 'All delivered' : $mwStats['failed'] . ' failed'),
            'detail' => $mwStats['total'] . ' deliveries · ' . $mwStats['merchants'] . ' merchants',
            'test_url' => 'manage_merchant.php',
        ],
        [
            'id' => 'settlement_cron',
            'label' => 'Settlement Cron',
            'ok' => !$cron['enabled'] || ($cron['due_now'] === 0 && ($cron['last_run'] !== null)),
            'status' => !$cron['enabled'] ? 'Disabled' : ($cron['due_now'] ? $cron['due_now'] . ' batch(es) due' : 'On schedule'),
            'detail' => $cron['last_run'] ? 'Last run ' . $cron['last_run'] : 'Never run — set Hostinger cron',
            'test_url' => 'admin_settlement_settings.php',
        ],
    ];

    return $services;
}

function platformHealthSummary(): array
{
    try {
        $services = getPlatformServiceHealth();
    } catch (Throwable $e) {
        error_log('UniWeb platform health: ' . $e->getMessage());
        $services = [[
            'id' => 'health_error',
            'label' => 'Health check error',
            'ok' => false,
            'status' => 'Could not load checks',
            'detail' => $e->getMessage(),
            'test_url' => 'diag_platform.php',
        ]];
    }
    $ok = count(array_filter($services, fn($s) => $s['ok']));
    return [
        'services' => $services,
        'ok' => $ok,
        'total' => count($services),
        'pct' => (int)round($ok / max(1, count($services)) * 100),
    ];
}
