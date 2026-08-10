<?php
// D8 unit-style test: verify bcrypt → argon2id upgrade
require_once __DIR__ . '/../includes/ops_security.php';

$plain = 'test123';
$bcrypt = password_hash($plain, PASSWORD_BCRYPT);
echo "bcrypt hash prefix: " . substr($bcrypt, 0, 7) . "\n";

$new = maybeRehashToArgon2id($plain, $bcrypt);
if ($new === null) {
    echo "FAIL: maybeRehashToArgon2id returned null for bcrypt hash\n";
    exit(1);
}
echo "upgraded prefix: " . substr($new, 0, 10) . "\n";

if (!password_verify($plain, $new)) {
    echo "FAIL: password_verify failed on upgraded hash\n";
    exit(1);
}
echo "verify upgraded: OK\n";

if (!str_starts_with($new, '$argon2id$')) {
    echo "FAIL: upgraded hash is not argon2id\n";
    exit(1);
}
echo "is argon2id: YES\n";

// Test that already-argon2id hash does NOT get rehashed
$again = maybeRehashToArgon2id($plain, $new);
if ($again !== null) {
    echo "FAIL: already-argon2id hash was rehashed unnecessarily\n";
    exit(1);
}
echo "already-argon2id: no rehash (correct)\n";

// Test wrong password does not produce a hash (helper should still return a hash,
// but caller must only call after verify — test that verify fails on wrong pass)
if (password_verify('wrongpass', $new)) {
    echo "FAIL: wrong password verified\n";
    exit(1);
}
echo "wrong password: verify fails (correct)\n";

echo "\nD8 TEST: ALL PASS\n";
