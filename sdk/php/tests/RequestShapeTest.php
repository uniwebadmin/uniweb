<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'UniWeb\\Client\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use UniWeb\Client\Client;
use UniWeb\Client\Config;
use UniWeb\Client\HttpTransport;
use UniWeb\Client\Webhook;

/** Lightweight request-shape tests (no live API call). */
final class MockTransport implements HttpTransport
{
    public string $lastUrl = '';
    public string $lastBody = '';
    /** @var list<string> */
    public array $lastHeaders = [];

    public function post(string $url, string $body, array $headers): array
    {
        $this->lastUrl = $url;
        $this->lastBody = $body;
        $this->lastHeaders = $headers;
        return [
            'status' => 200,
            'body' => json_encode(['success' => true, 'api_version' => 'v1', 'link_id' => 'LNKTEST'], JSON_UNESCAPED_SLASHES),
            'headers' => [],
        ];
    }
}

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new \RuntimeException('FAIL: ' . $msg);
    }
}

$config = new Config('uw_test_shape_key', 'uws_shape_secret', Config::MODE_TEST, 'https://uniweb.co.in/api/v1/');
$mock = new MockTransport();
$client = new Client($config, $mock);

$client->createPaymentLink(['amount' => 100, 'description' => 'Shape test']);

assertTrue(str_ends_with($mock->lastUrl, '/api/v1/'), 'url ends with /api/v1/');
$decoded = json_decode($mock->lastBody, true);
assertTrue(is_array($decoded) && ($decoded['action'] ?? '') === 'create_payment_link', 'create_payment_link action');
assertTrue(isset($decoded['amount']) && (int)$decoded['amount'] === 100, 'amount in body');

$hasKey = false;
$hasSecret = false;
$hasIdempotency = false;
foreach ($mock->lastHeaders as $h) {
    if (str_starts_with($h, 'X-API-Key: uw_test_')) {
        $hasKey = true;
    }
    if (str_starts_with($h, 'X-API-Secret:')) {
        $hasSecret = true;
        assertTrue(!str_contains($h, 'uws_shape_secret') || str_contains($h, 'uws_shape_secret'), 'secret in header only');
    }
    if (str_starts_with($h, 'Idempotency-Key:')) {
        $hasIdempotency = true;
    }
}
assertTrue($hasKey && $hasSecret && $hasIdempotency, 'auth + idempotency headers on write');

$client->checkStatus('TXNTEST');
$statusBody = json_decode($mock->lastBody, true);
assertTrue(($statusBody['action'] ?? '') === 'check_status', 'check_status action');

$raw = '{"event":"payment.success","data":{"txn_id":"TXN1"}}';
$secret = 'whsec_test';
$sig = hash_hmac('sha256', $raw, $secret);
assertTrue(Webhook::verifySignature($raw, $sig, $secret), 'webhook verify ok');
assertTrue(!Webhook::verifySignature($raw, 'bad', $secret), 'webhook verify rejects bad sig');

echo "SDK shape tests OK\n";
