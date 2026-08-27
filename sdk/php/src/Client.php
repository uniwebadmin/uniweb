<?php
declare(strict_types=1);

namespace UniWeb\Client;

use UniWeb\Client\Exception\ApiException;
use UniWeb\Client\Exception\AuthenticationException;
use UniWeb\Client\Exception\RateLimitException;
use UniWeb\Client\Exception\UniWebException;

/**
 * UniWeb Merchant API client — matches POST https://uniweb.co.in/api/v1/
 * Never logs or exposes apiSecret.
 */
final class Client
{
    private const USER_AGENT = 'UniWeb-PHP-SDK/1.0';

    public function __construct(
        private readonly ClientConfig $config,
        private readonly ?HttpTransport $transport = null,
    ) {
    }

    /** @param array<string,mixed> $params */
    public function createPaymentLink(array $params, ?string $idempotencyKey = null): array
    {
        $body = array_merge($params, ['action' => 'create_payment_link']);
        return $this->request($body, $idempotencyKey ?? $this->newIdempotencyKey('link'));
    }

    public function checkStatus(string $txnId): array
    {
        return $this->request([
            'action' => 'check_status',
            'txn_id' => $txnId,
        ]);
    }

    /** @param array<string,mixed> $params */
    public function createRefund(array $params, ?string $idempotencyKey = null): array
    {
        $body = array_merge($params, ['action' => 'create_refund']);
        return $this->request($body, $idempotencyKey ?? $this->newIdempotencyKey('refund'));
    }

    public function getBalance(): array
    {
        return $this->request(['action' => 'get_balance']);
    }

    /** @param array<string,mixed> $filters */
    public function listTransactions(array $filters = []): array
    {
        return $this->request(array_merge(['action' => 'list_transactions'], $filters));
    }

    /** @param array<string,mixed> $filters */
    public function listRefunds(array $filters = []): array
    {
        return $this->request(array_merge(['action' => 'list_refunds'], $filters));
    }

    /** @param array<string,mixed> $filters */
    public function listPaymentLinks(array $filters = []): array
    {
        return $this->request(array_merge(['action' => 'list_payment_links'], $filters));
    }

    public function getPaymentLink(string $linkId): array
    {
        return $this->request([
            'action' => 'get_payment_link',
            'link_id' => $linkId,
        ]);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function request(array $body, ?string $idempotencyKey = null): array
    {
        $writeActions = ['create_payment_link', 'create_refund'];
        $action = (string)($body['action'] ?? '');
        if (in_array($action, $writeActions, true) && ($idempotencyKey === null || trim($idempotencyKey) === '')) {
            throw new \InvalidArgumentException('Idempotency-Key is required for ' . $action);
        }

        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new UniWebException('Failed to encode request JSON.');
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $this->config->apiKey,
            'X-API-Secret: ' . $this->config->apiSecret,
            'User-Agent: ' . self::USER_AGENT,
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $transport = $this->transport ?? new CurlTransport();
        $result = $transport->post($this->config->baseUrl, $payload, $headers);

        return $this->parseResponse($result['status'], $result['body'], $result['headers']);
    }

    /** @param array<string,string> $headers */
    private function parseResponse(int $status, string $body, array $headers): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ApiException('invalid_response', 'UniWeb returned a non-JSON response.', $status);
        }
        if (($decoded['success'] ?? false) === true) {
            return $decoded;
        }

        $code = (string)($decoded['error_code'] ?? 'api_error');
        $message = (string)($decoded['error'] ?? 'Request could not be completed.');

        if ($status === 429 || $code === 'rate_limited') {
            $retry = isset($headers['retry-after']) ? (int)$headers['retry-after'] : null;
            throw new RateLimitException($code, $message, $status, $retry, $decoded);
        }
        if ($status === 401 || in_array($code, ['missing_credentials', 'auth_failed'], true)) {
            throw new AuthenticationException($code, $message, $status, $decoded);
        }
        throw ApiException::fromResponse($status, $decoded);
    }

    private function newIdempotencyKey(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(8));
    }
}
