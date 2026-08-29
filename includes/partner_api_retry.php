<?php
declare(strict_types=1);

/**
 * Outbound partner API retry policy (UniWeb → partner).
 * Retry transient failures only — never tight-loop clear 4xx business rejects.
 */

function partnerApiRetryMaxAttempts(): int
{
    return 3;
}

function partnerApiRetryBaseDelayMs(): int
{
    return 500;
}

function partnerApiRetryMaxDelayMs(): int
{
    return 8000;
}

/** Exponential backoff with jitter for attempt 1..N. */
function partnerApiRetryDelayMs(int $attempt): int
{
    $attempt = max(1, $attempt);
    $base = partnerApiRetryBaseDelayMs();
    $delay = min(partnerApiRetryMaxDelayMs(), (int)($base * (2 ** ($attempt - 1))));
    try {
        $jitter = random_int(0, (int)max(1, floor($delay * 0.2)));
    } catch (Throwable $e) {
        $jitter = (int)floor($delay * 0.1);
    }
    return $delay + $jitter;
}

function partnerApiHttpRetryable(int $httpStatus): bool
{
    if ($httpStatus === 0 || $httpStatus === 408 || $httpStatus === 429) {
        return true;
    }
    return $httpStatus >= 500 && $httpStatus <= 599;
}

function partnerApiCurlErrorRetryable(?string $curlError): bool
{
    if ($curlError === null || $curlError === '') {
        return false;
    }
    $e = strtolower($curlError);
    return str_contains($e, 'timed out')
        || str_contains($e, 'timeout')
        || str_contains($e, 'could not resolve')
        || str_contains($e, 'connection reset')
        || str_contains($e, 'failed to connect');
}

/**
 * @param callable(int $attempt): array{http:int,body:string,curl_error?:string|null} $requestFn
 * @return array{http:int,body:string,attempts:int,retryable_fail:bool}
 */
function partnerApiExecuteWithRetry(callable $requestFn): array
{
    $max = partnerApiRetryMaxAttempts();
    $last = ['http' => 0, 'body' => '', 'curl_error' => null];
    for ($attempt = 1; $attempt <= $max; $attempt++) {
        $last = $requestFn($attempt);
        $http = (int)($last['http'] ?? 0);
        $curlErr = $last['curl_error'] ?? null;
        $retryable = partnerApiHttpRetryable($http) || partnerApiCurlErrorRetryable($curlErr);
        if (!$retryable || $attempt >= $max) {
            return [
                'http' => $http,
                'body' => (string)($last['body'] ?? ''),
                'attempts' => $attempt,
                'retryable_fail' => $retryable && $attempt >= $max,
            ];
        }
        usleep(partnerApiRetryDelayMs($attempt) * 1000);
    }
    return [
        'http' => (int)($last['http'] ?? 0),
        'body' => (string)($last['body'] ?? ''),
        'attempts' => $max,
        'retryable_fail' => true,
    ];
}
