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
                'axis_va_ifsc' => ['label' => 'Default VA IFSC', 'type' => 'text'],
            ],
            'checklist' => [
                'Subscribe Virtual Account + Collections APIs on Axis portal',
                'Whitelist server IP on Axis UAT',
                'Configure webhook URL in portal',
                'Paste Client ID + Secret in Partner Registry → Keys',
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
                'decentro_consumer_urn' => ['label' => 'Consumer URN (UPI/QR)', 'type' => 'text'],
                'decentro_base_url' => ['label' => 'API Base URL (optional)', 'type' => 'text'],
            ],
            'checklist' => [
                'Sign up on Decentro dashboard',
                'Enable KYC + UPI Collect + VA + Payouts modules',
                'Paste sandbox keys — test connection below',
                'Production keys after partner call',
            ],
        ],
        'rbl' => [
            'name' => 'RBL Bank',
            'type' => 'banking',
            'icon' => '🏦',
            'color' => 'orange',
            'use' => $banking['rbl']['use'] ?? 'VA + UPI Collection + Payouts',
            'signup' => $banking['rbl']['signup'] ?? '',
            'docs' => $banking['rbl']['docs'] ?? '',
            'dashboard' => $banking['rbl']['sandbox'] ?? '',
            'email' => $banking['rbl']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=rbl',
            'webhook' => APP_URL . '/rbl_webhook.php',
            'env_key' => 'rbl_environment',
            'config_keys' => [
                'rbl_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox', 'production' => 'Production']],
                'rbl_client_id' => ['label' => 'Client ID (API Key)', 'type' => 'text'],
                'rbl_client_secret' => ['label' => 'Client Secret (API Secret)', 'type' => 'password'],
                'rbl_app_name' => ['label' => 'App Name', 'type' => 'text'],
                'rbl_corp_id' => ['label' => 'Corp ID', 'type' => 'text'],
                'rbl_master_account' => ['label' => 'Master Account No', 'type' => 'text'],
                'rbl_maker_id' => ['label' => 'Maker ID (optional)', 'type' => 'text'],
                'rbl_checker_id' => ['label' => 'Checker ID (optional)', 'type' => 'text'],
                'rbl_approver_id' => ['label' => 'Approver ID (optional)', 'type' => 'text'],
                'rbl_base_url' => ['label' => 'API Base URL (optional)', 'type' => 'text'],
            ],
            'checklist' => [
                'Create app on RBL sandbox portal',
                'Subscribe to VA + UPI Collection + Payout products',
                'Paste Client ID + Secret + Corp ID + Master Account (no demo defaults)',
                'Run Test Connection in Partner Registry → Test',
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
                'razorpay_webhook_secret' => ['label' => 'Webhook Secret (optional — falls back to Key Secret)', 'type' => 'password'],
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
                'cashfree_payout_client_id' => ['label' => 'Payout Client ID (optional)', 'type' => 'text'],
                'cashfree_payout_client_secret' => ['label' => 'Payout Client Secret (optional)', 'type' => 'password'],
                'cashfree_payout_base_url' => ['label' => 'Payout API Base URL (optional)', 'type' => 'text'],
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
                'pinelabs_merchant_id' => ['label' => 'Merchant ID', 'type' => 'text'],
                'pinelabs_access_code' => ['label' => 'Access Code', 'type' => 'text'],
                'pinelabs_secure_key' => ['label' => 'Secure Key', 'type' => 'password'],
            ],
            'checklist' => [
                'Pine Labs / Plural merchant onboarding',
                'Get Merchant ID + Access Code + Secure Key',
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
        'worldline' => [
            'name' => 'Worldline',
            'type' => 'gateway',
            'icon' => '🌍',
            'color' => 'teal',
            'use' => $banking['worldline']['use'] ?? 'Payment gateway + POS',
            'signup' => $banking['worldline']['signup'] ?? '',
            'docs' => $banking['worldline']['docs'] ?? '',
            'dashboard' => $banking['worldline']['signup'] ?? '',
            'email' => $banking['worldline']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=worldline',
            'webhook' => APP_URL . '/worldline_webhook.php',
            'env_key' => 'worldline_environment',
            'config_keys' => [
                'worldline_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['test' => 'Test', 'production' => 'Production']],
                'worldline_merchant_id' => ['label' => 'Merchant ID', 'type' => 'text'],
                'worldline_access_key' => ['label' => 'Access Key', 'type' => 'text'],
                'worldline_secret_key' => ['label' => 'Secret Key', 'type' => 'password'],
            ],
            'checklist' => [
                'Worldline merchant onboarding',
                'Get Merchant ID + Access Key + Secret Key',
                'Configure webhook URL',
                'Paste test credentials',
            ],
            'flags' => ['integration_matrix' => true, 'merchant_visibility' => true],
        ],
        'digio' => [
            'name' => 'Digio',
            'type' => 'gateway',
            'icon' => '✍️',
            'color' => 'pink',
            'use' => $banking['digio']['use'] ?? 'eSign + DigiLocker',
            'signup' => $banking['digio']['signup'] ?? '',
            'docs' => $banking['digio']['docs'] ?? '',
            'dashboard' => $banking['digio']['signup'] ?? '',
            'email' => $banking['digio']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=digio',
            'webhook' => APP_URL . '/digio_webhook.php',
            'env_key' => 'digio_environment',
            'config_keys' => [
                'digio_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox', 'production' => 'Production']],
                'digio_client_id' => ['label' => 'Client ID', 'type' => 'text'],
                'digio_client_secret' => ['label' => 'Client Secret', 'type' => 'password'],
                'digio_base_url' => ['label' => 'API Base URL (optional)', 'type' => 'text'],
            ],
            'checklist' => [
                'Digio business signup',
                'Enable eSign + DigiLocker products',
                'Paste sandbox Client ID + Secret',
            ],
            'flags' => ['integration_matrix' => true, 'merchant_visibility' => true, 'kyc_forward' => false, 'gateway_submit' => false],
        ],
        'toucanpay' => [
            'name' => 'ToucanPay',
            'type' => 'gateway',
            'icon' => '🦜',
            'color' => 'cyan',
            'use' => $banking['toucanpay']['use'] ?? 'RBI PA/PG — UPI, cards, BBPS, cross-border (SuperStream)',
            'signup' => $banking['toucanpay']['signup'] ?? '',
            'docs' => $banking['toucanpay']['docs'] ?? '',
            'dashboard' => $banking['toucanpay']['dashboard'] ?? '',
            'email' => $banking['toucanpay']['email'] ?? '',
            'admin_page' => 'admin_partner.php?p=toucanpay',
            'webhook' => '',
            'env_key' => 'toucanpay_environment',
            'config_keys' => [
                'toucanpay_environment' => ['label' => 'Environment', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox', 'production' => 'Production']],
                'toucanpay_api_key' => ['label' => 'API Key', 'type' => 'text'],
                'toucanpay_api_secret' => ['label' => 'API Secret', 'type' => 'password'],
                'toucanpay_merchant_id' => ['label' => 'Merchant ID (optional)', 'type' => 'text'],
                'toucanpay_base_url' => ['label' => 'API Base URL (optional)', 'type' => 'text'],
            ],
            'checklist' => [
                'Book demo / contract on toucanpay.in',
                'Get sandbox API credentials from ToucanPay',
                'Paste Test keys in Partner Detail → Keys',
                'Live checkout wiring follows ToucanPay API spec (scaffold — keys first)',
            ],
            'flags' => [
                'integration_matrix' => true,
                'merchant_visibility' => true,
                'gateway_submit' => false,
                'kyc_forward' => false,
                'checkout_pg' => false,
            ],
        ],
    ];
    return $registry;
}

/** Payment-method rail keys (not bank/PG partners). */
function paymentMethodRegistryKeys(): array
{
    return [
        'upi_p2m', 'qr_code', 'credit_card', 'debit_card', 'net_banking', 'netbanking',
        'wallet', 'emi', 'payout', 'recurring',
    ];
}

function isPaymentMethodRegistryKey(string $key): bool
{
    return in_array(strtolower(trim($key)), paymentMethodRegistryKeys(), true);
}

function isPartnerRegistryKey(string $key): bool
{
    return isset(getPartnerRegistry()[strtolower(trim($key))]);
}

function getPartnerRegistryKeys(): array
{
    return array_keys(getPartnerRegistry());
}

/**
 * Registry flags on each partner meta['flags'] — defaults below when omitted.
 */
function partnerHasRegistryFlag(string $partnerKey, string $flag): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    $reg = getPartnerRegistry()[$partnerKey] ?? null;
    if (!$reg) {
        return false;
    }
    if (isset($reg['flags'][$flag])) {
        return (bool)$reg['flags'][$flag];
    }
    return match ($flag) {
        'gateway_submit' => in_array($partnerKey, ['razorpay', 'cashfree', 'payu', 'decentro', 'phonepe', 'axis', 'rbl', 'pinelabs'], true),
        'kyc_forward' => in_array($partnerKey, ['payu', 'razorpay', 'cashfree', 'decentro', 'axis', 'phonepe', 'rbl', 'pinelabs'], true),
        'integration_matrix' => true,
        'checkout_pg' => in_array($partnerKey, ['razorpay', 'cashfree', 'payu'], true),
        'merchant_visibility' => in_array($partnerKey, ['decentro', 'axis', 'pinelabs', 'phonepe', 'worldline', 'digio', 'rbl'], true),
        default => false,
    };
}

/** @return list<string> */
function getGatewaySubmissionPartnerKeys(): array
{
    $keys = [];
    foreach (getPartnerRegistryKeys() as $key) {
        if (partnerHasRegistryFlag($key, 'gateway_submit')) {
            $keys[] = $key;
        }
    }
    return $keys;
}

/** @return list<string> */
function getKycForwardPartnerKeys(): array
{
    $keys = [];
    foreach (getPartnerRegistryKeys() as $key) {
        if (partnerHasRegistryFlag($key, 'kyc_forward')) {
            $keys[] = $key;
        }
    }
    return $keys;
}

/** @return array<string,string> partner_key => display name */
function getIntegrationMatrixPartnerLabels(): array
{
    $out = [];
    foreach (getPartnerRegistry() as $key => $meta) {
        if (partnerHasRegistryFlag($key, 'integration_matrix')) {
            $out[$key] = (string)($meta['name'] ?? ucfirst($key));
        }
    }
    return $out;
}

/** @return list<string> */
function getCheckoutPgPartnerKeys(): array
{
    $keys = [];
    foreach (getPartnerRegistryKeys() as $key) {
        if (partnerHasRegistryFlag($key, 'checkout_pg')) {
            $keys[] = $key;
        }
    }
    return $keys;
}

function partnerLogApi(string $partnerKey, string $endpoint, string $method, ?string $request, ?string $response, int $httpCode, string $status = 'ok'): void
{
    ensurePartnerEngine();
    // D10: mask PII before writing to log table
    if (!function_exists('maskPiiInString') && is_file(__DIR__ . '/partner_payload.php')) {
        require_once __DIR__ . '/partner_payload.php';
    }
    if (function_exists('maskPiiInString')) {
        $request = maskPiiInString($request);
        $response = maskPiiInString($response);
    }
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

/** True when any secret/key/salt field is saved for this partner (no isGatewayConfigured fallback — avoids infinite loop on active partners). */
function partnerHasSavedCredentials(string $partnerKey): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    $reg = getPartnerRegistry()[$partnerKey] ?? null;
    if (!$reg || empty($reg['config_keys']) || !is_array($reg['config_keys'])) {
        return false;
    }
    if (!function_exists('getPartnerSetting')) {
        return false;
    }
    foreach ($reg['config_keys'] as $key => $meta) {
        $key = (string)$key;
        if (str_contains($key, 'secret') || str_contains($key, 'salt') || str_contains($key, 'key')) {
            if (getPartnerSetting($partnerKey, $key, '') !== '') {
                return true;
            }
        }
    }
    return false;
}

function partnerIsConfigured(string $partnerKey): bool
{
    return partnerHasSavedCredentials($partnerKey);
}

function partnerTestConnection(string $partnerKey): array
{
    ensurePartnerEngine();
    $reg = getPartnerRegistry()[$partnerKey] ?? null;
    if (!$reg) {
        $has = function_exists('partnerHasSavedCredentials') && partnerHasSavedCredentials($partnerKey);
        if ($has) {
            return ['ok' => false, 'message' => 'Credentials saved (encrypted). No live adapter probe for this custom partner yet — status stays INVALID until a connector is wired. Keys tab fields work.'];
        }
        return ['ok' => false, 'message' => 'Custom partner — paste keys on the Keys tab first.'];
    }

    if ($partnerKey === 'axis') {
        $test = axisTestConnection();
        return ['ok' => (bool)($test['token_ok'] ?? false), 'message' => $test['message'] ?? 'Axis test done.'];
    }
    if ($partnerKey === 'payu') {
        $ok = (bool)getPartnerSetting('payu', 'payu_merchant_key', '');
        $msg = $ok ? 'PayU keys saved.' : 'Add payu_merchant_key + payu_merchant_salt in Partner Detail → Keys.';
        partnerLogApi('payu', 'config_check', 'GET', null, $msg, $ok ? 200 : 0, $ok ? 'ok' : 'pending');
        return ['ok' => $ok, 'message' => $msg];
    }
    if ($partnerKey === 'razorpay') {
        $ok = (bool)getPartnerSetting('razorpay', 'razorpay_key_id', '');
        $msg = $ok ? 'Razorpay keys saved.' : 'Add razorpay_key_id + razorpay_key_secret in Partner Detail → Keys.';
        partnerLogApi('razorpay', 'config_check', 'GET', null, $msg, $ok ? 200 : 0, $ok ? 'ok' : 'pending');
        return ['ok' => $ok, 'message' => $msg];
    }
    if ($partnerKey === 'cashfree') {
        $ok = (bool)getPartnerSetting('cashfree', 'cashfree_app_id', '');
        $msg = $ok ? 'Cashfree keys saved.' : 'Add cashfree_app_id + cashfree_secret_key in Partner Detail → Keys.';
        partnerLogApi('cashfree', 'config_check', 'GET', null, $msg, $ok ? 200 : 0, $ok ? 'ok' : 'pending');
        return ['ok' => $ok, 'message' => $msg];
    }
    if ($partnerKey === 'toucanpay') {
        $ok = (bool)getPartnerSetting('toucanpay', 'toucanpay_api_key', '');
        $msg = $ok
            ? 'ToucanPay keys saved — checkout API wiring follows ToucanPay spec when live.'
            : 'Add toucanpay_api_key + toucanpay_api_secret in Partner Detail → Keys (from ToucanPay sandbox).';
        partnerLogApi('toucanpay', 'config_check', 'GET', null, $msg, $ok ? 200 : 0, $ok ? 'ok' : 'pending');
        return ['ok' => $ok, 'message' => $msg];
    }
    if ($partnerKey === 'decentro') {
        $test = testDecentroConnection();
        partnerLogApi('decentro', 'test_connection', 'POST', 'v3/payments/upi/qr', $test['message'] ?? '', ($test['ok'] ?? false) ? 200 : 0, ($test['ok'] ?? false) ? 'ok' : 'failed');
        return $test;
    }
    if ($partnerKey === 'rbl') {
        $test = testRblConnection();
        partnerLogApi('rbl', 'test_connection', 'POST', '/virtual/account', $test['message'] ?? '', ($test['ok'] ?? false) ? 200 : 0, ($test['ok'] ?? false) ? 'ok' : 'failed');
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
    if (!$reg) {
        return;
    }
    if (!function_exists('savePartnerCredentials')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $env = partnerCredentialEnvBucket($partnerKey);
    savePartnerCredentials($partnerKey, $env, $data, $reg['config_keys'] ?? []);
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

/**
 * Honest integration state for admin UI — LIVE / STUB / PARKED only (NEVER products removed from code).
 *
 * @return array{state:string,label:string,hint:string}
 */
function partnerIntegrationState(string $partnerKey): array
{
    $partnerKey = strtolower(trim($partnerKey));
    $checkoutPg = partnerHasRegistryFlag($partnerKey, 'checkout_pg');
    $explicitStub = in_array($partnerKey, ['phonepe', 'pinelabs', 'worldline', 'toucanpay', 'digio'], true);
    $flags = getPartnerRegistry()[$partnerKey]['flags'] ?? [];

    if (isset($flags['checkout_pg']) && $flags['checkout_pg'] === false) {
        return [
            'state' => 'STUB',
            'label' => 'STUB',
            'hint' => 'Registry + keys UI — live web checkout/API not wired yet.',
        ];
    }
    if ($explicitStub && !$checkoutPg) {
        return [
            'state' => 'STUB',
            'label' => 'STUB',
            'hint' => 'Partner scaffold — paste keys; collect checkout follows in a later release.',
        ];
    }
    if ($partnerKey === 'rbl') {
        return [
            'state' => 'STUB',
            'label' => 'STUB',
            'hint' => 'RBL operational gate — VA/payout when bank rail is live.',
        ];
    }
    if ($checkoutPg) {
        return [
            'state' => 'LIVE',
            'label' => 'LIVE',
            'hint' => 'Collect path works when Registry keys are saved (Test or Live).',
        ];
    }
    if (partnerHasRegistryFlag($partnerKey, 'kyc_forward') || partnerHasRegistryFlag($partnerKey, 'gateway_submit')) {
        return [
            'state' => 'STUB',
            'label' => 'STUB',
            'hint' => 'Forward/KYC rail — staged queue until live partner API succeeds.',
        ];
    }
    return [
        'state' => 'PARKED',
        'label' => 'PARKED',
        'hint' => 'Not on the live collect path — configure when commercial deal starts.',
    ];
}

function partnerIntegrationStateBadgeHtml(string $partnerKey): string
{
    if (!function_exists('uiCapabilityPill') && is_file(__DIR__ . '/ui/ui_components.php')) {
        require_once __DIR__ . '/ui/ui_components.php';
    }
    $s = partnerIntegrationState($partnerKey);
    if (function_exists('uiCapabilityPill')) {
        return uiCapabilityPill($s['state'], $s['hint']);
    }
    $color = match ($s['state']) {
        'LIVE' => 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
        'STUB' => 'bg-amber-500/15 text-amber-300 border-amber-500/30',
        'PARKED' => 'bg-slate-500/15 text-slate-300 border-slate-500/30',
        default => 'bg-gray-700/50 text-gray-400 border-gray-600',
    };
    $title = htmlspecialchars($s['hint'], ENT_QUOTES, 'UTF-8');
    return '<span class="text-[10px] px-2 py-0.5 rounded border ' . $color . '" title="' . $title . '">' . htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8') . '</span>';
}
