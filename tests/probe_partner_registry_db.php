<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/payment_methods.php';
require_once dirname(__DIR__) . '/includes/partner_control.php';
require_once dirname(__DIR__) . '/includes/partner_registry_v2.php';

ensurePaymentMethodsTable();
ensurePartnerControlTables();
ensurePartnerRegistryV2Columns();

$db = getDB();
$requiredColumns = [
    'partner_type',
    'connector_id',
    'logo_url',
    'countries_json',
    'currencies_json',
    'optional_doc_pack_json',
    'cap_qr',
    'cap_payout',
    'platform_fee_pct_default',
    'platform_fee_flat_default',
    'settle_note',
    'updated_by',
    'weight_success',
];
$existingColumns = $db->query('SHOW COLUMNS FROM gateway_registry')->fetchAll(PDO::FETCH_COLUMN);
foreach ($requiredColumns as $column) {
    if (!in_array($column, $existingColumns, true)) {
        throw new RuntimeException('Missing registry column: ' . $column);
    }
}

$requiredMigrations = [
    '098_partner_registry_capability_fields.sql',
    '099_partner_registry_commercial_audit_fields.sql',
    '100_partner_registry_success_weight.sql',
];
$migrationQuery = $db->prepare('SELECT version FROM schema_migrations WHERE version=?');
foreach ($requiredMigrations as $version) {
    $migrationQuery->execute([$version]);
    if (!$migrationQuery->fetchColumn()) {
        throw new RuntimeException('Required migration is not recorded: ' . $version);
    }
}

$partnerCode = 'p1a_probe_' . bin2hex(random_bytes(6));
$input = [
    'partner_code' => $partnerCode,
    'display_name' => 'Point 1A Database Probe',
    'partner_type' => 'bnpl',
    'contract_mode' => 'hybrid',
    'allows_existing_merchant_link' => true,
    'connector_id' => 'generic_rest_v1',
    'logo_url' => 'https://partner.example/logo.svg',
    'api_base_url' => 'https://api.partner.example',
    'webhook_url' => '/partner_webhook.php',
    'countries' => 'IN, AE',
    'currencies' => 'INR, AED',
    'support_email' => 'ops@partner.example',
    'support_phone' => '+91 9876543210',
    'platform_fee_pct_default' => '2.125',
    'platform_fee_flat_default' => '12.5',
    'weight_success' => '75.25',
    'settle_note' => 'Local integration probe only',
    'routing_priority' => 40,
    'circuit_breaker_on' => true,
    'capabilities' => [
        'collect' => true,
        'upi' => true,
        'card' => true,
        'refund' => true,
        'pay_later' => true,
        'qr' => true,
        'payout' => true,
        'kyc_forward_api' => true,
    ],
    'channels' => [
        'checkout_hosted' => true,
        'status_webhook' => true,
        'document_forward' => true,
        'refunds' => true,
    ],
    'doc_pack' => ['pan', 'gst'],
    'optional_doc_pack' => ['pan', 'aadhaar'],
    'partner_compliance_docs' => ['PG_MSA'],
    'policy_urls' => [
        'terms' => 'https://partner.example/terms',
        'privacy' => 'https://partner.example/privacy',
        'refund' => 'https://partner.example/refunds',
    ],
];

$db->beginTransaction();
try {
    $created = registerPartnerRegistryV2($input, 4242);
    if (empty($created['ok'])) {
        throw new RuntimeException('Registry create failed: ' . (string)($created['message'] ?? 'unknown error'));
    }

    $gatewayId = (int)($created['gateway_id'] ?? 0);
    $rowQuery = $db->prepare('SELECT * FROM gateway_registry WHERE id=? LIMIT 1');
    $rowQuery->execute([$gatewayId]);
    $row = $rowQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('New registry profile was not persisted.');
    }
    $profile = partnerRegistryV2ProfileFromRow($row);

    $methodQuery = $db->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(is_enabled),0) AS enabled FROM partner_methods WHERE partner_key=?');
    $methodQuery->execute([$partnerCode]);
    $methodCounts = $methodQuery->fetch(PDO::FETCH_ASSOC);
    $activeMapQuery = $db->prepare('SELECT COUNT(*) FROM gateway_method_map WHERE gateway_id=? AND is_active=1');
    $activeMapQuery->execute([$gatewayId]);

    $checks = [
        'lifecycle_starts_draft' => (string)$row['integration_status'] === 'draft',
        'partner_type_and_mode' => $profile['partner_type'] === 'bnpl' && $profile['contract_mode'] === 'hybrid',
        'connector_and_endpoints' => $profile['connector_id'] === 'generic_rest_v1'
            && $profile['webhook_url'] === '/partner_webhook.php'
            && $profile['api_base_url'] === 'https://api.partner.example',
        'country_currency_and_docs' => $profile['countries'] === ['IN', 'AE']
            && $profile['currencies'] === ['INR', 'AED']
            && $profile['doc_pack'] === ['pan', 'gst']
            && $profile['optional_doc_pack'] === ['aadhaar'],
        'commercial_policy_and_audit' => $profile['platform_fee_pct_default'] === '2.1250'
            && $profile['platform_fee_flat_default'] === '12.50'
            && $profile['weight_success'] === '75.2500'
            && $profile['settle_note'] === 'Local integration probe only'
            && (int)$row['updated_by'] === 4242
            && $profile['policy_urls']['refund'] === 'https://partner.example/refunds',
        'payout_metadata_does_not_enable_payouts' => (int)$row['cap_payout'] === 1
            && (int)$row['supports_payout'] === 0,
        'standard_partner_methods_seeded_disabled' => (int)$methodCounts['total'] === 8
            && (int)$methodCounts['enabled'] === 0
            && (int)$activeMapQuery->fetchColumn() === 0,
        'profile_passes_completeness_gate' => partnerRegistryV2ProfileIsComplete($row),
    ];

    $failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
    if ($failed !== []) {
        throw new RuntimeException('Registry DB probe failed checks: ' . implode(', ', $failed));
    }
    echo 'PARTNER REGISTRY DB PROBE OK ' . json_encode($checks, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
