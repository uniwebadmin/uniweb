<?php
declare(strict_types=1);

if (!function_exists('merchantApiErrorCatalog') && is_file(__DIR__ . '/merchant_api_errors.php')) {
    require_once __DIR__ . '/merchant_api_errors.php';
}

/** Strip secrets / stack traces from partner payloads before logging. */
function sanitizePartnerErrorPayload(mixed $raw): string
{
    if (is_string($raw)) {
        $text = $raw;
    } else {
        $text = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }
    $text = preg_replace('/"(key|secret|salt|password|token|authorization|api_key|client_secret)"\s*:\s*"[^"]*"/i', '"$1":"[redacted]"', $text) ?? $text;
    $text = preg_replace('/\b(key|secret|salt|password|token)=([^&\s]+)/i', '$1=[redacted]', $text) ?? $text;
    return mb_substr($text, 0, 2000);
}

/** Map partner HTTP / body hints to stable public error_code. */
function mapPartnerErrorToPublicCode(string $provider, int $httpStatus = 0, mixed $raw = null): string
{
    $provider = strtolower(trim($provider));
    $blob = strtolower(is_string($raw) ? $raw : json_encode($raw, JSON_UNESCAPED_UNICODE) ?: '');

    if ($httpStatus === 401 || $httpStatus === 403 || str_contains($blob, 'unauthorized') || str_contains($blob, 'authentication')) {
        return 'auth_invalid';
    }
    if ($httpStatus === 429 || str_contains($blob, 'rate limit') || str_contains($blob, 'too many')) {
        return 'rate_limited';
    }
    if ($httpStatus >= 500 || str_contains($blob, 'timeout') || str_contains($blob, 'unavailable') || str_contains($blob, 'gateway')) {
        return 'partner_unavailable';
    }
    if (str_contains($blob, 'refund') && (str_contains($blob, 'not allowed') || str_contains($blob, 'cannot refund') || str_contains($blob, 'already refunded'))) {
        return 'refund_not_allowed';
    }
    if ($httpStatus === 404 || str_contains($blob, 'not found') || str_contains($blob, 'invalid id')) {
        return 'not_found';
    }
    if ($httpStatus === 409 || str_contains($blob, 'duplicate') || str_contains($blob, 'idempotency')) {
        return 'idempotency_conflict';
    }
    if ($httpStatus >= 400 && $httpStatus < 500) {
        return 'validation_error';
    }
    if ($provider !== '') {
        return 'partner_unavailable';
    }
    return 'internal_error';
}

/** Log partner failure internally; return safe public code + message for merchants. */
function logPartnerErrorAndMap(string $provider, string $operation, mixed $raw, int $httpStatus = 0): array
{
    $publicCode = mapPartnerErrorToPublicCode($provider, $httpStatus, $raw);
    $catalog = function_exists('merchantApiErrorCatalog') ? merchantApiErrorCatalog() : [];
    $publicMessage = (string)($catalog[$publicCode]['message'] ?? 'Request could not be completed.');
    $sanitized = sanitizePartnerErrorPayload($raw);
    if (function_exists('logPlatformError')) {
        logPlatformError('warning', ucfirst($provider) . ' ' . $operation . ' failed.', [
            'provider' => $provider,
            'operation' => $operation,
            'http_status' => $httpStatus,
            'public_code' => $publicCode,
            'partner_detail' => $sanitized,
        ]);
    }
    return ['error_code' => $publicCode, 'error' => $publicMessage];
}
