<?php
require_once __DIR__ . '/../config.php';
if (!function_exists('redactPartnerPayload')) {
    require_once __DIR__ . '/../includes/partner_payload.php';
}

$payload = [
    'merchant' => [
        'id' => 1,
        'name' => 'Test Merchant',
        'email' => 'test@example.com',
        'phone' => '9876543210',
        'pan' => 'ABCDE1234F',
        'gstin' => '27ABCDE1234F1Z5',
        'cin_llpin' => 'U12345MH2020PTC123456',
        'password' => 'secret_hash',
        'api_secret' => 'sk_live_12345',
        'bank_account_number' => '12345678901234',
    ],
    'bank_verification' => [
        'account_number' => '12345678901234',
        'account_holder' => 'John Doe',
        'ifsc_code' => 'HDFC0001234',
    ],
    'password' => 'top_level_hash',
    'api_secret' => 'sk_test_999',
    'gateway' => 'razorpay',
];

$redacted = redactPartnerPayload($payload);
$json = json_encode($redacted);

echo "Redacted JSON:\n$json\n\n";

$dangerous = [
    'ABCDE1234F', '27ABCDE1234F1Z5', 'U12345MH2020PTC123456',
    'secret_hash', 'sk_live_12345', 'sk_test_999', 'top_level_hash',
    '12345678901234', 'John Doe', '9876543210',
];

$found = [];
foreach ($dangerous as $d) {
    if (str_contains($json, $d)) {
        $found[] = $d;
    }
}

if ($found) {
    echo "FAIL: Found plaintext secrets: " . implode(', ', $found) . "\n";
    exit(1);
} else {
    echo "PASS: No plaintext secrets found in redacted payload\n";
    exit(0);
}
