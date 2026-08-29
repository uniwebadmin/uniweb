<?php
declare(strict_types=1);

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function routeSplitHealthCheck(): array
{
    if (!function_exists('routeSplitLiveEnabled') && is_file(__DIR__ . '/split_settlement.php')) {
        require_once __DIR__ . '/split_settlement.php';
    }
    if (!function_exists('getRouteSplitReadinessChecklist')) {
        return [
            'id' => 'route_split',
            'label' => 'Phase 11 Route / Smart routing',
            'ok' => false,
            'status' => 'Module missing',
            'detail' => 'includes/split_settlement.php not loaded',
            'test_url' => 'gateway_settings.php#live-money-switches',
        ];
    }

    $ready = getRouteSplitReadinessChecklist();
    $live = routeSplitLiveEnabled();

    if (!$live) {
        if (!function_exists('routeSplitParkedDisclaimer')) {
            require_once __DIR__ . '/route_split_workflow.php';
        }
        $detail = function_exists('routeSplitParkedDisclaimer')
            ? routeSplitParkedDisclaimer()
            : 'M/P commission on capture works. Live Route SDK not started.';
        return [
            'id' => 'route_split',
            'label' => 'Phase 11 Route / Smart routing',
            'ok' => true,
            'status' => 'Parked — standard settlement active',
            'detail' => $detail,
            'test_url' => 'gateway_settings.php#live-money-switches',
        ];
    }

    return [
        'id' => 'route_split',
        'label' => 'Route / Split (Phase 11)',
        'ok' => !empty($ready['ready']),
        'status' => !empty($ready['ready']) ? 'Owner ON — SDK build pending' : 'Owner ON — finish partner config',
        'detail' => (int)$ready['done'] . '/' . (int)$ready['total'] . ' readiness checks · transfer SDK not shipped',
        'test_url' => 'admin_gateway_registry.php',
    ];
}

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function recurringAutopayHealthCheck(): array
{
    if (!function_exists('recurringAutopayApproved') && is_file(__DIR__ . '/mandates.php')) {
        require_once __DIR__ . '/mandates.php';
    }
    if (!function_exists('recurringAutopayApproved')) {
        return [
            'id' => 'recurring_autopay',
            'label' => 'Recurring / AutoPay',
            'ok' => false,
            'status' => 'Module missing',
            'detail' => 'includes/mandates.php not loaded',
            'test_url' => 'gateway_settings.php#live-money-switches',
        ];
    }

    $approved = recurringAutopayApproved();
    $keys = recurringAutopayPartnerKeysConfigured();
    $ready = recurringAutopayLiveReady();

    if (!$approved) {
        return [
            'id' => 'recurring_autopay',
            'label' => 'Recurring / AutoPay',
            'ok' => true,
            'status' => 'Switch OFF (default)',
            'detail' => 'Mandates gated until you turn ON in Platform Settings → Live Money Switches.',
            'test_url' => 'gateway_settings.php#live-money-switches',
        ];
    }

    if (!$keys) {
        return [
            'id' => 'recurring_autopay',
            'label' => 'Recurring / AutoPay',
            'ok' => false,
            'status' => 'Partner keys missing',
            'detail' => 'Paste Razorpay / Cashfree / Decentro in Partner Registry',
            'test_url' => 'admin_gateway_registry.php',
        ];
    }

    return [
        'id' => 'recurring_autopay',
        'label' => 'Recurring / AutoPay',
        'ok' => $ready,
        'status' => $ready ? 'Ready for live mandates' : 'Partial setup',
        'detail' => 'UPI Autopay + eNACH · webhooks + cron required for debits',
        'test_url' => 'merchant_recurring.php',
    ];
}

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function payoutLiveHealthCheck(): array
{
    if (!function_exists('payoutLiveMoneyAllowed') && is_file(__DIR__ . '/payout.php')) {
        require_once __DIR__ . '/payout.php';
    }
    if (!function_exists('payoutLiveMoneyAllowed')) {
        return [
            'id' => 'payout_live',
            'label' => 'Payouts to bank',
            'ok' => false,
            'status' => 'Module missing',
            'detail' => 'includes/payout.php not loaded',
            'test_url' => 'gateway_settings.php#live-money-switches',
        ];
    }
    $on = trim((string)getSetting('payout_live_enabled', '0')) === '1';
    $keys = function_exists('payoutPartnerKeysConfigured') && payoutPartnerKeysConfigured();
    $live = payoutLiveMoneyAllowed();
    if (!$on) {
        return [
            'id' => 'payout_live',
            'label' => 'Payouts to bank',
            'ok' => true,
            'status' => 'Switch OFF (default)',
            'detail' => 'Collect first. Turn ON in Platform Settings → Live Money Switches when payout partner keys are ready.',
            'test_url' => 'gateway_settings.php#live-money-switches',
        ];
    }
    if (!$keys) {
        return [
            'id' => 'payout_live',
            'label' => 'Payouts to bank',
            'ok' => false,
            'status' => 'Switch ON — keys missing',
            'detail' => 'Paste payout partner keys in Partner Registry before live bank transfers.',
            'test_url' => 'admin_gateway_registry.php',
        ];
    }
    return [
        'id' => 'payout_live',
        'label' => 'Payouts to bank',
        'ok' => $live,
        'status' => $live ? 'Live rail ON' : 'Partial setup',
        'detail' => function_exists('payoutActivationMessage') ? payoutActivationMessage() : 'Licensed partner dispatch',
        'test_url' => 'admin_payout.php',
    ];
}

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function intelligentRoutingHealthCheck(): array
{
    if (!function_exists('intelligentRoutingEnabled') && is_file(__DIR__ . '/intelligent_routing.php')) {
        require_once __DIR__ . '/intelligent_routing.php';
    }
    if (!function_exists('intelligentRoutingEnabled')) {
        return [
            'id' => 'intelligent_routing',
            'label' => 'Intelligent routing',
            'ok' => false,
            'status' => 'Module missing',
            'detail' => 'includes/intelligent_routing.php not loaded',
            'test_url' => 'gateway_settings.php#live-money-switches',
        ];
    }
    if (!intelligentRoutingEnabled()) {
        return [
            'id' => 'intelligent_routing',
            'label' => 'Intelligent routing',
            'ok' => true,
            'status' => 'Switch OFF (default)',
            'detail' => 'Fixed checkout routing. Turn ON only when 2+ collect partners have Registry keys.',
            'test_url' => 'gateway_settings.php#live-money-switches',
        ];
    }
    $collectReady = isGatewayConfigured('razorpay') || isGatewayConfigured('cashfree') || isGatewayConfigured('payu');
    return [
        'id' => 'intelligent_routing',
        'label' => 'Intelligent routing',
        'ok' => $collectReady,
        'status' => $collectReady ? 'Owner ON — score/rules active' : 'Owner ON — collect keys missing',
        'detail' => 'Strategy: ' . intelligentRoutingStrategy() . ' · overrides Phase 11 at checkout when ON',
        'test_url' => 'gateway_settings.php#live-money-switches',
    ];
}

/**
 * Admin dashboard rows — Live Money Switches at a glance.
 *
 * @return list<array{key:string,label:string,on:bool,status:string,detail:string,url:string}>
 */
function getLiveMoneySwitchDashboardRows(): array
{
    $checks = [
        payoutLiveHealthCheck(),
        recurringAutopayHealthCheck(),
        routeSplitHealthCheck(),
        intelligentRoutingHealthCheck(),
    ];
    $rows = [];
    foreach ($checks as $c) {
        $on = match ((string)($c['id'] ?? '')) {
            'payout_live' => trim((string)getSetting('payout_live_enabled', '0')) === '1',
            'recurring_autopay' => trim((string)getSetting('recurring_autopay_approved', '0')) === '1',
            'route_split' => trim((string)getSetting('route_split_live_enabled', '0')) === '1',
            'intelligent_routing' => trim((string)getSetting('intelligent_routing_enabled', '0')) === '1',
            default => false,
        };
        $rows[] = [
            'key' => (string)($c['id'] ?? ''),
            'label' => (string)($c['label'] ?? ''),
            'on' => $on,
            'status' => (string)($c['status'] ?? ''),
            'detail' => (string)($c['detail'] ?? ''),
            'url' => (string)($c['test_url'] ?? 'gateway_settings.php#live-money-switches'),
        ];
    }
    return $rows;
}

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
            'status' => isGatewayConfigured('razorpay') ? ($activePg === 'razorpay' ? 'New-merchant template' : 'Configured') : 'Not configured',
            'detail' => isGatewayConfigured('razorpay') ? gatewayStatusLabel('razorpay') : 'Add keys in Partner Registry → Keys',
            'test_url' => function_exists('adminPartnerTestUrl') ? adminPartnerTestUrl('razorpay') : 'admin_gateway_detail.php?partner=razorpay&tab=test',
            'registry_url' => 'admin_gateway_registry.php',
        ],
        [
            'id' => 'cashfree',
            'label' => 'Cashfree',
            'ok' => isGatewayConfigured('cashfree'),
            'status' => isGatewayConfigured('cashfree') ? ($activePg === 'cashfree' ? 'New-merchant template' : 'Configured') : 'Not configured',
            'detail' => isGatewayConfigured('cashfree') ? gatewayStatusLabel('cashfree') : 'Add keys in Partner Registry → Keys',
            'test_url' => function_exists('adminPartnerTestUrl') ? adminPartnerTestUrl('cashfree') : 'admin_gateway_detail.php?partner=cashfree&tab=test',
            'registry_url' => 'admin_gateway_registry.php',
        ],
        [
            'id' => 'payu',
            'label' => 'PayU',
            'ok' => isGatewayConfigured('payu'),
            'status' => isGatewayConfigured('payu') ? ($activePg === 'payu' ? 'New-merchant template' : 'Configured') : 'Not configured',
            'detail' => isGatewayConfigured('payu') ? gatewayStatusLabel('payu') : 'Add keys in Partner Registry → Keys',
            'test_url' => function_exists('adminPartnerTestUrl') ? adminPartnerTestUrl('payu') : 'admin_gateway_detail.php?partner=payu&tab=test',
            'registry_url' => 'admin_gateway_registry.php',
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
            'test_url' => function_exists('adminPartnerTestUrl') ? adminPartnerTestUrl('decentro') : 'admin_gateway_detail.php?partner=decentro&tab=test',
        ],
        recurringAutopayHealthCheck(),
        function_exists('autoKycEngineHealthCheck') ? autoKycEngineHealthCheck() : [
            'id' => 'auto_kyc_engine',
            'label' => 'Auto KYC Engine',
            'ok' => false,
            'status' => 'Workflow missing',
            'detail' => 'includes/cloud_modules_workflow.php not loaded',
            'test_url' => 'admin_auto_kyc.php',
        ],
        function_exists('registryKindHealthCheck') ? registryKindHealthCheck() : [
            'id' => 'registry_kind',
            'label' => 'Registry kind (method vs partner)',
            'ok' => false,
            'status' => 'Workflow missing',
            'detail' => 'includes/registry_kind_workflow.php not loaded',
            'test_url' => 'admin_gateway_registry.php',
        ],
        function_exists('gatewaySubmissionsHealthCheck') ? gatewaySubmissionsHealthCheck() : [
            'id' => 'gateway_submissions_varchar',
            'label' => 'Gateway submissions (VARCHAR)',
            'ok' => false,
            'status' => 'Workflow missing',
            'detail' => 'includes/gateway_submissions_workflow.php not loaded',
            'test_url' => 'admin_gateway_submit.php',
        ],
        function_exists('holdWindowHealthCheck') ? holdWindowHealthCheck() : [
            'id' => 'hold_window',
            'label' => 'KYC hold window',
            'ok' => false,
            'status' => 'Workflow missing',
            'detail' => 'includes/hold_window_workflow.php not loaded',
            'test_url' => 'admin_forward_queue.php',
        ],
        function_exists('autoKycRiskHealthCheck') ? autoKycRiskHealthCheck() : [
            'id' => 'auto_kyc_risk',
            'label' => 'Auto-KYC risk (fail-closed)',
            'ok' => false,
            'status' => 'Workflow missing',
            'detail' => 'includes/auto_kyc_risk_workflow.php not loaded',
            'test_url' => 'admin_auto_kyc.php',
        ],
        function_exists('wiringDeepLinkHealthCheck') ? wiringDeepLinkHealthCheck() : [
            'id' => 'wiring_deep_link',
            'label' => 'Wiring / deep-link (B1–B6)',
            'ok' => false,
            'status' => 'Workflow missing',
            'detail' => 'includes/wiring_deep_link_workflow.php not loaded',
            'test_url' => 'admin_disputes.php?q=DSP',
        ],
        function_exists('forwardQueueWorkflowHealthCheck') ? forwardQueueWorkflowHealthCheck() : [
            'id' => 'forward_queue_sync',
            'label' => 'Forward queue / Gateway submit (B7–B8)',
            'ok' => false,
            'status' => 'Workflow missing',
            'detail' => 'includes/forward_queue_workflow.php not loaded',
            'test_url' => 'admin_forward_queue.php?status=staged',
        ],
        function_exists('checkoutCollectionWorkflowHealthCheck') ? checkoutCollectionWorkflowHealthCheck() : [
            'id' => 'checkout_collection_b9',
            'label' => 'Checkout collection label (B9)',
            'ok' => false,
            'status' => 'Workflow missing',
            'detail' => 'includes/checkout_collection_workflow.php not loaded',
            'test_url' => 'payment_links.php',
        ],
        function_exists('wiringDeepLinkHealthCheckB10B25') ? wiringDeepLinkHealthCheckB10B25() : [
            'id' => 'wiring_deep_link_b10_b25',
            'label' => 'Wiring / deep-link (B10–B25)',
            'ok' => false,
            'status' => 'Workflow missing',
            'detail' => 'includes/wiring_deep_link_workflow.php B10–B25 batch',
            'test_url' => 'checkout_customize.php',
        ],
        function_exists('globalSearchWorkflowHealthCheck') ? globalSearchWorkflowHealthCheck() : [
            'id' => 'global_search_srch',
            'label' => 'Global search (SRCH-02/06)',
            'ok' => false,
            'status' => 'Workflow missing',
            'detail' => 'includes/global_search_workflow.php not loaded',
            'test_url' => 'global_search.php',
        ],
        routeSplitHealthCheck(),
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
