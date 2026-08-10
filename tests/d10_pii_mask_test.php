<?php
// D10 proof test: verify PII masking in log bodies
require_once __DIR__ . '/../includes/partner_payload.php';

$failures = [];

// Test 1: JSON body with PAN, Aadhaar, account, password
$payload = json_encode([
    'merchant' => [
        'name' => 'Test Merchant',
        'pan' => 'ABCDE1234F',
        'aadhaar' => '123456789012',
        'account_number' => '9876543210',
        'password' => 's3cr3tPass!',
        'api_key' => 'live_key_abc123',
        'api_secret' => 'live_secret_xyz',
        'ifsc' => 'HDFC0001234',
    ],
    'amount' => 100.00,
    'currency' => 'INR',
]);

$masked = maskPiiInString($payload);
echo "Test 1: JSON body\n";
echo "  Input:  " . substr($payload, 0, 80) . "...\n";
echo "  Output: " . substr($masked, 0, 80) . "...\n";

// Verify no raw PAN
if (str_contains($masked, 'ABCDE1234F')) {
    $failures[] = "T1: PAN not masked";
} else {
    echo "  PAN masked: OK\n";
}

// Verify no raw Aadhaar
if (str_contains($masked, '123456789012')) {
    $failures[] = "T1: Aadhaar not masked";
} else {
    echo "  Aadhaar masked: OK\n";
}

// Verify no raw account number
if (str_contains($masked, '9876543210')) {
    $failures[] = "T1: Account number not masked";
} else {
    echo "  Account masked: OK\n";
}

// Verify password redacted
if (str_contains($masked, 's3cr3tPass')) {
    $failures[] = "T1: Password not redacted";
} else {
    echo "  Password redacted: OK\n";
}

// Verify api_secret redacted
if (str_contains($masked, 'live_secret_xyz')) {
    $failures[] = "T1: API secret not redacted";
} else {
    echo "  API secret redacted: OK\n";
}

// Verify non-PII preserved
if (!str_contains($masked, 'Test Merchant')) {
    $failures[] = "T1: Non-PII data lost";
} else {
    echo "  Non-PII preserved: OK\n";
}

// Test 2: Plain text with embedded PAN
$plain = "Customer PAN ABCDE1234F verified, aadhaar 123456789012, card 4111111111111111";
$masked2 = maskPiiInString($plain);
echo "\nTest 2: Plain text\n";
echo "  Input:  " . $plain . "\n";
echo "  Output: " . $masked2 . "\n";

if (str_contains($masked2, 'ABCDE1234F')) {
    $failures[] = "T2: PAN not masked in plain text";
} else {
    echo "  PAN masked: OK\n";
}

if (str_contains($masked2, '123456789012')) {
    $failures[] = "T2: Aadhaar not masked in plain text";
} else {
    echo "  Aadhaar masked: OK\n";
}

if (str_contains($masked2, '4111111111111111')) {
    $failures[] = "T2: Card number not masked in plain text";
} else {
    echo "  Card masked: OK\n";
}

// Test 3: Null/empty passthrough
$nullResult = maskPiiInString(null);
if ($nullResult !== null) {
    $failures[] = "T3: Null not passed through";
} else {
    echo "\nTest 3: Null passthrough: OK\n";
}

$emptyResult = maskPiiInString('');
if ($emptyResult !== '') {
    $failures[] = "T3: Empty not passed through";
} else {
    echo "Test 3b: Empty passthrough: OK\n";
}

// Test 4: Nested JSON
$nested = json_encode([
    'data' => [
        'customer' => [
            'pan_number' => 'FGHIJ5678K',
            'aadhaar_number' => '987654321098',
        ],
    ],
    'endpoint' => '/kyc/verify',
]);
$masked4 = maskPiiInString($nested);
echo "\nTest 4: Nested JSON\n";
echo "  Output: " . substr($masked4, 0, 100) . "...\n";

if (str_contains($masked4, 'FGHIJ5678K')) {
    $failures[] = "T4: Nested PAN not masked";
} else {
    echo "  Nested PAN masked: OK\n";
}

if (str_contains($masked4, '987654321098')) {
    $failures[] = "T4: Nested Aadhaar not masked";
} else {
    echo "  Nested Aadhaar masked: OK\n";
}

if (!str_contains($masked4, '/kyc/verify')) {
    $failures[] = "T4: Endpoint lost";
} else {
    echo "  Endpoint preserved: OK\n";
}

echo "\n---\n";
if ($failures) {
    echo "FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "D10 TEST: ALL PASS\n";
