<?php
declare(strict_types=1);

/** Unified banking + gateway partner engine — plug API keys later */

function ensurePartnerEngine(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS partner_api_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_key VARCHAR(32) NOT NULL,
            endpoint VARCHAR(255) NOT NULL,
            method VARCHAR(10) DEFAULT 'GET',
            request_body TEXT,
            response_body TEXT,
            http_code INT DEFAULT 0,
            status VARCHAR(32) DEFAULT 'ok',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (partner_key),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function getPartnerRegistry(): array
{
    $banking = getBankingPartners();
    $registry = [
        'axis' => [
            'name' => 'Axis Bank',
            'type' => 'banking',
            'icon' => '🏦',
            'color' => 'rose',
            'use' => $banking['axis']['use'] ?? 'Virtual Account + Collections',
            'signup' => $banking['axis']['signup'] ?? '',
            'docs' => $banking['axis']['docs'] ?? '',
            'dashboard' => $banking['axis']['signup'] ?? '',
            'email' => $banking['axis']['email'] ?? '',
            'admin_page' => 'admin_axis.php',
            'webhook' => APP_URL . '/axis_webhook.php',
            'env_key' => 'axis_environment',
            'config_keys' => [
                'axis_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['uat' => 'UAT', 'production' => 'Production']],
                'axis_client_id' => ['label' => 'Client ID', 'type' => 'text'],
                'axis_client_secret' => ['label' => 'Client Secret', 'type' => 'password'],
                'axis_app_name' => ['label' => 'App Name', 'type' => 'text'],
                'axis_channel_id' => ['label' => 'Channel ID', 'type' => 'text'],
                'axis_corporate_id' => ['label' => 'Corporate ID', 'type' => 'text'],
                'axis_webhook_secret' => ['label' => 'Webhook Secret', 'type' => 'password'],
                'axis_base_url' => ['label' => 'API Base URL (optional)', 'type' => 'text'],
            ],
            'checklist' => [
                'Subscribe Virtual Account + Collections APIs on Axis portal',
                'Whitelist server IP on Axis UAT',
                'Configure webhook URL in portal',
                'Paste Client ID + Secret in Gateway Settings',
                'Run Test Token on Axis UAT page',
            ],
        ],
        'decentro' => [
            'name' => 'Decentro',
            'type' => 'banking',
            'icon' => '⚡',
            'color' => 'violet',
            'use' => $banking['decentro']['use'] ?? 'Full BaaS stack',
            'signup' => $banking['decentro']['signup'] ?? '',
            'docs' => $banking['decentro']['docs'] ?? '',
            'dashboard' => $banking['decentro']['dashboard'] ?? '',
            'email' => $banking['decentro']['email_business'] ?? '',
            'admin_page' => 'admin_partner.php?p=decentro',
            'webhook' => '',
            'env_key' => 'decentro_environment',
            'config_keys' => [
                'decentro_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox', 'production' => 'Production']],
                'decentro_client_id' => ['label' => 'Client ID', 'type' => 'text'],
                'decentro_client_secret' => ['label' => 'Client Secret', 'type' => 'password'],
                'decentro_module_secret' => ['label' => 'Module Secret', 'type' => 'password'],
                'decentro_provider_secret' => ['label' => 'Provider Secret', 'type' => 'password'],
            ],
            'checklist' => [
                'Sign up on Decentro dashboard',
                'Enable KYC + UPI Collect + VA + Payouts modules',
                'Paste sandbox keys — test connection below',
                'Production keys after partner call',
            ],
        ],
        'payu' => [
            'name' => 'PayU',
            'type' => 'gateway',
            'icon' => '💳',
            'color' => 'sky',
            'use' => $banking['payu']['use'] ?? 'Collections + Split',
            'signup' => $banking['payu']['signup'] ?? '',
            'docs' => $banking['payu']['docs'] ?? '',
            'dashboard' => $banking['payu']['dashboard'] ?? '',
            'email' => $banking['payu']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=payu',
            'webhook' => APP_URL . '/payu_webhook.php',
            'env_key' => 'payu_environment',
            'config_keys' => [
                'payu_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['test' => 'Test', 'production' => 'Production']],
                'payu_merchant_key' => ['label' => 'Merchant Key', 'type' => 'text'],
                'payu_merchant_salt' => ['label' => 'Merchant Salt', 'type' => 'password'],
                'payu_child_merchant_key' => ['label' => 'Default Child Key (split)', 'type' => 'text'],
            ],
            'checklist' => [
                'Create PayU merchant account',
                'Enable Split Settlement product',
                'Add test key + salt',
                'Configure return URL: payment_payu_return.php',
            ],
        ],
        'razorpay' => [
            'name' => 'Razorpay',
            'type' => 'gateway',
            'icon' => '🔒',
            'color' => 'indigo',
            'use' => $banking['razorpay']['use'] ?? 'Checkout + Route',
            'signup' => $banking['razorpay']['signup'] ?? '',
            'docs' => $banking['razorpay']['docs'] ?? '',
            'dashboard' => $banking['razorpay']['dashboard'] ?? '',
            'email' => $banking['razorpay']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=razorpay',
            'webhook' => APP_URL . '/razorpay_webhook.php',
            'env_key' => 'razorpay_environment',
            'config_keys' => [
                'razorpay_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['test' => 'Test', 'live' => 'Live']],
                'razorpay_key_id' => ['label' => 'Key ID', 'type' => 'text'],
                'razorpay_key_secret' => ['label' => 'Key Secret', 'type' => 'password'],
            ],
            'checklist' => [
                'Create Razorpay account',
                'Enable Route (Linked Accounts) for split',
                'Paste test keys — verify connection',
            ],
        ],
        'cashfree' => [
            'name' => 'Cashfree',
            'type' => 'gateway',
            'icon' => '💰',
            'color' => 'emerald',
            'use' => $banking['cashfree']['use'] ?? 'Easy Split + Payouts',
            'signup' => $banking['cashfree']['signup'] ?? '',
            'docs' => $banking['cashfree']['docs'] ?? '',
            'dashboard' => $banking['cashfree']['dashboard'] ?? '',
            'email' => $banking['cashfree']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=cashfree',
            'webhook' => APP_URL . '/cashfree_webhook.php',
            'env_key' => 'cashfree_environment',
            'config_keys' => [
                'cashfree_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox', 'production' => 'Production']],
                'cashfree_app_id' => ['label' => 'App ID', 'type' => 'text'],
                'cashfree_secret_key' => ['label' => 'Secret Key', 'type' => 'password'],
            ],
            'checklist' => [
                'Cashfree merchant signup',
                'Enable Easy Split + vendor onboarding',
                'Paste sandbox App ID + Secret',
            ],
        ],
        'phonepe' => [
            'name' => 'PhonePe PG',
            'type' => 'gateway',
            'icon' => '📱',
            'color' => 'purple',
            'use' => $banking['phonepe']['use'] ?? 'UPI + Wallets',
            'signup' => $banking['phonepe']['signup'] ?? '',
            'docs' => $banking['phonepe']['docs'] ?? '',
            'dashboard' => $banking['phonepe']['signup'] ?? '',
            'email' => $banking['phonepe']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=phonepe',
            'webhook' => '',
            'env_key' => 'phonepe_environment',
            'config_keys' => [
                'phonepe_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox', 'production' => 'Production']],
                'phonepe_merchant_id' => ['label' => 'Merchant ID', 'type' => 'text'],
                'phonepe_salt_key' => ['label' => 'Salt Key', 'type' => 'password'],
                'phonepe_salt_index' => ['label' => 'Salt Index', 'type' => 'text'],
            ],
            'checklist' => [
                'PhonePe PG business signup',
                'Get merchant ID + salt from dashboard',
                'Integrate checkout redirect',
            ],
        ],
        'razorpayx' => [
            'name' => 'RazorpayX',
            'type' => 'banking',
            'icon' => '🏧',
            'color' => 'cyan',
            'use' => $banking['razorpayx']['use'] ?? 'Payouts + Business Banking',
            'signup' => $banking['razorpayx']['signup'] ?? '',
            'docs' => $banking['razorpayx']['docs'] ?? '',
            'dashboard' => $banking['razorpayx']['dashboard'] ?? '',
            'email' => $banking['razorpayx']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=razorpayx',
            'webhook' => '',
            'env_key' => 'razorpayx_environment',
            'config_keys' => [
                'razorpayx_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['test' => 'Test', 'live' => 'Live']],
                'razorpayx_account_number' => ['label' => 'RazorpayX Account Number', 'type' => 'text'],
                'razorpayx_key_id' => ['label' => 'Key ID', 'type' => 'text'],
                'razorpayx_key_secret' => ['label' => 'Key Secret', 'type' => 'password'],
            ],
            'checklist' => [
                'Open RazorpayX business account',
                'Enable Payouts API',
                'Paste keys for vendor/merchant payouts',
            ],
        ],
        'open' => [
            'name' => 'Open Money',
            'type' => 'banking',
            'icon' => '🌐',
            'color' => 'amber',
            'use' => $banking['open']['use'] ?? 'Business Account + Payouts',
            'signup' => $banking['open']['signup'] ?? '',
            'docs' => $banking['open']['docs'] ?? '',
            'dashboard' => $banking['open']['signup'] ?? '',
            'email' => $banking['open']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=open',
            'webhook' => '',
            'env_key' => 'open_environment',
            'config_keys' => [
                'open_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox', 'production' => 'Production']],
                'open_client_id' => ['label' => 'Client ID', 'type' => 'text'],
                'open_client_secret' => ['label' => 'Client Secret', 'type' => 'password'],
                'open_api_key' => ['label' => 'API Key', 'type' => 'password'],
            ],
            'checklist' => [
                'Open Money business account signup',
                'Connected banking + payout API access',
                'Paste API credentials',
            ],
        ],
        'easebuzz' => [
            'name' => 'Easebuzz',
            'type' => 'gateway',
            'icon' => '🚀',
            'color' => 'orange',
            'use' => $banking['easebuzz']['use'] ?? 'PG + Payouts',
            'signup' => $banking['easebuzz']['signup'] ?? '',
            'docs' => $banking['easebuzz']['docs'] ?? '',
            'dashboard' => $banking['easebuzz']['signup'] ?? '',
            'email' => $banking['easebuzz']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=easebuzz',
            'webhook' => '',
            'env_key' => 'easebuzz_environment',
            'config_keys' => [
                'easebuzz_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['test' => 'Test', 'production' => 'Production']],
                'easebuzz_merchant_key' => ['label' => 'Merchant Key', 'type' => 'text'],
                'easebuzz_salt' => ['label' => 'Salt', 'type' => 'password'],
            ],
            'checklist' => [
                'Easebuzz merchant onboarding',
                'PG + Payout product activation',
                'Paste test keys',
            ],
        ],
        'rbl' => [
            'name' => 'RBL Bank',
            'type' => 'banking',
            'icon' => '🏦',
            'color' => 'rose',
            'use' => $banking['rbl']['use'] ?? 'Current account + corporate API',
            'signup' => $banking['rbl']['signup'] ?? '',
            'docs' => $banking['rbl']['docs'] ?? '',
            'dashboard' => $banking['rbl']['signup'] ?? '',
            'email' => $banking['rbl']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=rbl',
            'webhook' => '',
            'env_key' => 'rbl_environment',
            'config_keys' => [
                'rbl_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['uat' => 'UAT', 'production' => 'Production']],
                'rbl_client_id' => ['label' => 'Client ID', 'type' => 'text'],
                'rbl_client_secret' => ['label' => 'Client Secret', 'type' => 'password'],
                'rbl_corporate_id' => ['label' => 'Corporate ID', 'type' => 'text'],
                'rbl_base_url' => ['label' => 'API Base URL (optional)', 'type' => 'text'],
            ],
            'checklist' => [
                'Open RBL corporate current account',
                'Subscribe to API banking package',
                'Whitelist server IP',
                'Paste Client ID + Secret',
            ],
        ],
        'yesbank' => [
            'name' => 'Yes Bank',
            'type' => 'banking',
            'icon' => '🏦',
            'color' => 'sky',
            'use' => $banking['yesbank']['use'] ?? 'API banking + payouts',
            'signup' => $banking['yesbank']['signup'] ?? '',
            'docs' => $banking['yesbank']['docs'] ?? '',
            'dashboard' => $banking['yesbank']['signup'] ?? '',
            'email' => $banking['yesbank']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=yesbank',
            'webhook' => '',
            'env_key' => 'yesbank_environment',
            'config_keys' => [
                'yesbank_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['uat' => 'UAT', 'production' => 'Production']],
                'yesbank_client_id' => ['label' => 'Client ID', 'type' => 'text'],
                'yesbank_client_secret' => ['label' => 'Client Secret', 'type' => 'password'],
                'yesbank_api_key' => ['label' => 'API Key', 'type' => 'password'],
                'yesbank_base_url' => ['label' => 'API Base URL (optional)', 'type' => 'text'],
            ],
            'checklist' => [
                'Yes Bank business account + API access',
                'Get client ID + API key',
                'Whitelist server IP',
                'Paste credentials',
            ],
        ],
        'billdesk' => [
            'name' => 'BillDesk',
            'type' => 'gateway',
            'icon' => '🧾',
            'color' => 'emerald',
            'use' => $banking['billdesk']['use'] ?? 'BBPS + PG',
            'signup' => $banking['billdesk']['signup'] ?? '',
            'docs' => $banking['billdesk']['docs'] ?? '',
            'dashboard' => $banking['billdesk']['signup'] ?? '',
            'email' => $banking['billdesk']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=billdesk',
            'webhook' => APP_URL . '/billdesk_webhook.php',
            'env_key' => 'billdesk_environment',
            'config_keys' => [
                'billdesk_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['test' => 'Test', 'production' => 'Production']],
                'billdesk_merchant_id' => ['label' => 'Merchant ID', 'type' => 'text'],
                'billdesk_checksum_key' => ['label' => 'Checksum Key', 'type' => 'password'],
                'billdesk_security_key' => ['label' => 'Security Key', 'type' => 'password'],
            ],
            'checklist' => [
                'BillDesk merchant onboarding',
                'Get Merchant ID + checksum key',
                'Configure webhook URL',
                'Paste test credentials',
            ],
        ],
        'ccavenue' => [
            'name' => 'CCAvenue',
            'type' => 'gateway',
            'icon' => '💳',
            'color' => 'indigo',
            'use' => $banking['ccavenue']['use'] ?? 'Multi-currency PG',
            'signup' => $banking['ccavenue']['signup'] ?? '',
            'docs' => $banking['ccavenue']['docs'] ?? '',
            'dashboard' => $banking['ccavenue']['signup'] ?? '',
            'email' => $banking['ccavenue']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=ccavenue',
            'webhook' => APP_URL . '/ccavenue_webhook.php',
            'env_key' => 'ccavenue_environment',
            'config_keys' => [
                'ccavenue_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['test' => 'Test', 'production' => 'Production']],
                'ccavenue_merchant_id' => ['label' => 'Merchant ID', 'type' => 'text'],
                'ccavenue_access_code' => ['label' => 'Access Code', 'type' => 'text'],
                'ccavenue_working_key' => ['label' => 'Working Key', 'type' => 'password'],
            ],
            'checklist' => [
                'CCAvenue merchant signup',
                'Get Merchant ID + Access Code + Working Key',
                'Configure return + webhook URL',
                'Paste test credentials',
            ],
        ],
        'setu' => [
            'name' => 'Setu',
            'type' => 'gateway',
            'icon' => '🔗',
            'color' => 'violet',
            'use' => $banking['setu']['use'] ?? 'BBPS + UPI DeepLinks',
            'signup' => $banking['setu']['signup'] ?? '',
            'docs' => $banking['setu']['docs'] ?? '',
            'dashboard' => $banking['setu']['signup'] ?? '',
            'email' => $banking['setu']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=setu',
            'webhook' => APP_URL . '/setu_webhook.php',
            'env_key' => 'setu_environment',
            'config_keys' => [
                'setu_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox', 'production' => 'Production']],
                'setu_client_id' => ['label' => 'Client ID', 'type' => 'text'],
                'setu_client_secret' => ['label' => 'Client Secret', 'type' => 'password'],
                'setu_product_id' => ['label' => 'Product ID', 'type' => 'text'],
            ],
            'checklist' => [
                'Setu developer signup',
                'Create BBPS / UPI product',
                'Paste Client ID + Secret + Product ID',
            ],
        ],
        'pinelabs' => [
            'name' => 'Pine Labs',
            'type' => 'gateway',
            'icon' => '🌲',
            'color' => 'cyan',
            'use' => $banking['pinelabs']['use'] ?? 'Plural checkout + payouts',
            'signup' => $banking['pinelabs']['signup'] ?? '',
            'docs' => $banking['pinelabs']['docs'] ?? '',
            'dashboard' => $banking['pinelabs']['signup'] ?? '',
            'email' => $banking['pinelabs']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=pinelabs',
            'webhook' => APP_URL . '/pinelabs_webhook.php',
            'env_key' => 'pinelabs_environment',
            'config_keys' => [
                'pinelabs_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['test' => 'Test', 'production' => 'Production']],
                'pinelabs_api_key' => ['label' => 'API Key', 'type' => 'text'],
                'pinelabs_api_secret' => ['label' => 'API Secret', 'type' => 'password'],
                'pinelabs_merchant_id' => ['label' => 'Merchant ID', 'type' => 'text'],
            ],
            'checklist' => [
                'Pine Labs / Plural merchant onboarding',
                'Get API Key + Secret + Merchant ID',
                'Configure webhook URL',
                'Paste test credentials',
            ],
        ],
        'zwitch' => [
            'name' => 'Zwitch',
            'type' => 'banking',
            'icon' => '⚡',
            'color' => 'amber',
            'use' => $banking['zwitch']['use'] ?? 'Neo-banking + payouts',
            'signup' => $banking['zwitch']['signup'] ?? '',
            'docs' => $banking['zwitch']['docs'] ?? '',
            'dashboard' => $banking['zwitch']['signup'] ?? '',
            'email' => $banking['zwitch']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=zwitch',
            'webhook' => '',
            'env_key' => 'zwitch_environment',
            'config_keys' => [
                'zwitch_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox', 'production' => 'Production']],
                'zwitch_client_id' => ['label' => 'Client ID', 'type' => 'text'],
                'zwitch_client_secret' => ['label' => 'Client Secret', 'type' => 'password'],
                'zwitch_api_key' => ['label' => 'API Key', 'type' => 'password'],
            ],
            'checklist' => [
                'Zwitch business account signup',
                'Get API credentials',
                'Paste Client ID + Secret + API Key',
            ],
        ],
        'icici' => [
            'name' => 'ICICI Bank',
            'type' => 'banking',
            'icon' => '🏦',
            'color' => 'red',
            'use' => $banking['icici']['use'] ?? 'Corporate API banking',
            'signup' => $banking['icici']['signup'] ?? '',
            'docs' => $banking['icici']['docs'] ?? '',
            'dashboard' => $banking['icici']['signup'] ?? '',
            'email' => $banking['icici']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=icici',
            'webhook' => '',
            'env_key' => 'icici_environment',
            'config_keys' => [
                'icici_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['uat' => 'UAT', 'production' => 'Production']],
                'icici_client_id' => ['label' => 'Client ID', 'type' => 'text'],
                'icici_client_secret' => ['label' => 'Client Secret', 'type' => 'password'],
                'icici_corporate_id' => ['label' => 'Corporate ID', 'type' => 'text'],
                'icici_base_url' => ['label' => 'API Base URL (optional)', 'type' => 'text'],
            ],
            'checklist' => [
                'ICICI corporate current account + API access',
                'Get client ID + secret',
                'Whitelist server IP',
                'Paste credentials',
            ],
        ],
        'sbi' => [
            'name' => 'SBI',
            'type' => 'banking',
            'icon' => '🏦',
            'color' => 'blue',
            'use' => $banking['sbi']['use'] ?? 'Corporate collections + payouts',
            'signup' => $banking['sbi']['signup'] ?? '',
            'docs' => $banking['sbi']['docs'] ?? '',
            'dashboard' => $banking['sbi']['signup'] ?? '',
            'email' => $banking['sbi']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=sbi',
            'webhook' => '',
            'env_key' => 'sbi_environment',
            'config_keys' => [
                'sbi_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['uat' => 'UAT', 'production' => 'Production']],
                'sbi_client_id' => ['label' => 'Client ID', 'type' => 'text'],
                'sbi_client_secret' => ['label' => 'Client Secret', 'type' => 'password'],
                'sbi_api_key' => ['label' => 'API Key', 'type' => 'password'],
                'sbi_base_url' => ['label' => 'API Base URL (optional)', 'type' => 'text'],
            ],
            'checklist' => [
                'SBI corporate API onboarding',
                'Get client ID + API key',
                'Whitelist server IP',
                'Paste credentials',
            ],
        ],
        'sapaisa' => [
            'name' => 'SavaPay / Sapaisa',
            'type' => 'gateway',
            'icon' => '💰',
            'color' => 'green',
            'use' => $banking['sapaisa']['use'] ?? 'Payment gateway + settlement',
            'signup' => $banking['sapaisa']['signup'] ?? '',
            'docs' => $banking['sapaisa']['docs'] ?? '',
            'dashboard' => $banking['sapaisa']['signup'] ?? '',
            'email' => $banking['sapaisa']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=sapaisa',
            'webhook' => APP_URL . '/sapaisa_webhook.php',
            'env_key' => 'sapaisa_environment',
            'config_keys' => [
                'sapaisa_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['test' => 'Test', 'production' => 'Production']],
                'sapaisa_merchant_id' => ['label' => 'Merchant ID', 'type' => 'text'],
                'sapaisa_api_key' => ['label' => 'API Key', 'type' => 'text'],
                'sapaisa_secret_key' => ['label' => 'Secret Key', 'type' => 'password'],
            ],
            'checklist' => [
                'SavaPay / Sapaisa merchant signup',
                'Get Merchant ID + API Key + Secret',
                'Configure webhook URL',
                'Paste test credentials',
            ],
        ],
    ];
    return $registry;
}

function partnerLogApi(string $partnerKey, string $endpoint, string $method, ?string $request, ?string $response, int $httpCode, string $status = 'ok'): void
{
    ensurePartnerEngine();
    try {
        getDB()->prepare('INSERT INTO partner_api_logs (partner_key, endpoint, method, request_body, response_body, http_code, status) VALUES (?,?,?,?,?,?,?)')
            ->execute([$partnerKey, $endpoint, $method, $request, $response, $httpCode, $status]);
    } catch (Throwable $e) { /* ok */ }
}

function partnerGetRecentLogs(string $partnerKey, int $limit = 30): array
{
    ensurePartnerEngine();
    try {
        $stmt = getDB()->prepare('SELECT * FROM partner_api_logs WHERE partner_key = ? ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $partnerKey, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function partnerIsConfigured(string $partnerKey): bool
{
    $reg = getPartnerRegistry()[$partnerKey] ?? null;
    if (!$reg) return false;
    foreach ($reg['config_keys'] as $key => $meta) {
        if (str_contains($key, 'secret') || str_contains($key, 'salt') || str_contains($key, 'key')) {
            if (getSetting($key, '') !== '') {
                return true;
            }
        }
    }
    return isGatewayConfigured($partnerKey);
}

function partnerTestConnection(string $partnerKey): array
{
    ensurePartnerEngine();
    $reg = getPartnerRegistry()[$partnerKey] ?? null;
    if (!$reg) {
        return ['ok' => false, 'message' => 'Unknown partner.'];
    }

    if ($partnerKey === 'axis') {
        $test = axisTestConnection();
        return ['ok' => (bool)($test['token_ok'] ?? false), 'message' => $test['message'] ?? 'Axis test done.'];
    }
    if ($partnerKey === 'payu') {
        $ok = (bool)getSetting('payu_merchant_key', '');
        $msg = $ok ? 'PayU keys saved. Live hash test on checkout.' : 'Add payu_merchant_key + payu_merchant_salt in Gateway Settings.';
        partnerLogApi('payu', 'config_check', 'GET', null, $msg, $ok ? 200 : 0, $ok ? 'ok' : 'pending');
        return ['ok' => $ok, 'message' => $msg];
    }
    if ($partnerKey === 'razorpay') {
        $ok = (bool)getSetting('razorpay_key_id', '');
        $msg = $ok ? 'Razorpay keys saved.' : 'Add razorpay_key_id + razorpay_key_secret.';
        partnerLogApi('razorpay', 'config_check', 'GET', null, $msg, $ok ? 200 : 0, $ok ? 'ok' : 'pending');
        return ['ok' => $ok, 'message' => $msg];
    }
    if ($partnerKey === 'cashfree') {
        $ok = (bool)getSetting('cashfree_app_id', '');
        $msg = $ok ? 'Cashfree keys saved.' : 'Add cashfree_app_id + cashfree_secret_key.';
        partnerLogApi('cashfree', 'config_check', 'GET', null, $msg, $ok ? 200 : 0, $ok ? 'ok' : 'pending');
        return ['ok' => $ok, 'message' => $msg];
    }
    if ($partnerKey === 'decentro') {
        $test = testDecentroConnection();
        partnerLogApi('decentro', 'test_connection', 'POST', 'v3/payments/upi/qr', $test['message'] ?? '', ($test['ok'] ?? false) ? 200 : 0, ($test['ok'] ?? false) ? 'ok' : 'failed');
        return $test;
    }

    $configured = partnerIsConfigured($partnerKey);
    $msg = $configured
        ? $reg['name'] . ' credentials saved — ready for API integration when keys are live.'
        : $reg['name'] . ' — paste API keys below. Structure ready, awaiting partner credentials.';
    partnerLogApi($partnerKey, 'config_check', 'GET', null, $msg, $configured ? 200 : 0, $configured ? 'ok' : 'pending');
    return ['ok' => $configured, 'message' => $msg];
}

function partnerSaveConfig(string $partnerKey, array $data): void
{
    $reg = getPartnerRegistry()[$partnerKey] ?? null;
    if (!$reg) return;
    $db = getDB();
    foreach ($reg['config_keys'] as $key => $meta) {
        if (!array_key_exists($key, $data)) continue;
        $v = trim((string)$data[$key]);
        $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
            ->execute([$key, $v, $v]);
    }
}

function partnerConfiguredCount(): array
{
    $total = 0;
    $ready = 0;
    foreach (getPartnerRegistry() as $key => $reg) {
        $total++;
        if (partnerIsConfigured($key)) $ready++;
    }
    return ['total' => $total, 'ready' => $ready];
}
