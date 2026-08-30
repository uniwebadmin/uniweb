<?php
declare(strict_types=1);

/**
 * Stable Merchant API error codes — UniWeb voice (not partner voice).
 */

/** @return array<string, array{http:int, message:string}> */
function merchantApiErrorCatalog(): array
{
    return [
        'invalid_json' => ['http' => 400, 'message' => 'Request body must be valid JSON.'],
        'unknown_action' => ['http' => 400, 'message' => 'Unknown action. See API documentation for supported actions.'],
        'missing_credentials' => ['http' => 401, 'message' => 'X-API-Key and X-API-Secret headers are required.'],
        'auth_failed' => ['http' => 401, 'message' => 'Invalid API credentials or insufficient scope.'],
        'auth_invalid' => ['http' => 401, 'message' => 'Partner rejected credentials during refund processing. Contact UniWeb support.'],
        'origin_not_allowed' => ['http' => 403, 'message' => 'Origin not allowed for this API key.'],
        'mode_mismatch' => ['http' => 403, 'message' => 'Account is in Test Mode. Use a test API key or complete KYC for live operations.'],
        'not_found' => ['http' => 404, 'message' => 'Resource not found.'],
        'validation_error' => ['http' => 400, 'message' => 'One or more fields failed validation.'],
        'amount_out_of_range' => ['http' => 400, 'message' => 'Amount must be between 1 and 200000000.'],
        'description_too_long' => ['http' => 400, 'message' => 'Description is too long (max 255 characters).'],
        'missing_txn_id' => ['http' => 400, 'message' => 'txn_id is required.'],
        'missing_link_id' => ['http' => 400, 'message' => 'link_id is required.'],
        'missing_idempotency_key' => ['http' => 400, 'message' => 'Idempotency-Key header is required for this action.'],
        'idempotency_conflict' => ['http' => 409, 'message' => 'Idempotency-Key conflict — different request body or an identical request is already in progress.'],
        'rate_limited' => ['http' => 429, 'message' => 'API rate limit exceeded. Retry after the Retry-After interval.'],
        'method_not_allowed' => ['http' => 405, 'message' => 'Only POST is supported.'],
        'refund_failed' => ['http' => 400, 'message' => 'Refund could not be processed.'],
        'refund_not_allowed' => ['http' => 400, 'message' => 'Refund is not allowed for this payment.'],
        'txn_not_refundable' => ['http' => 404, 'message' => 'Successful transaction not found for refund.'],
        'partner_unavailable' => ['http' => 503, 'message' => 'Payment partner is temporarily unavailable. Try again shortly.'],
        'internal_error' => ['http' => 500, 'message' => 'An internal error occurred. Support has been notified.'],
    ];
}

/** Public Merchant API actions — must match api.php switch and openapi.json action enum. */
function merchantApiPublicActions(): array
{
    return [
        'create_payment_link',
        'check_status',
        'list_transactions',
        'get_balance',
        'create_refund',
        'list_refunds',
        'list_payment_links',
        'get_payment_link',
    ];
}

/** Stable error_code values for OpenAPI enum + smoke sync. */
function merchantApiOpenApiErrorCodes(): array
{
    return array_keys(merchantApiErrorCatalog());
}

/**
 * Build JSON error payload without exiting — used by tests and merchantApiRespondError.
 *
 * @param array<string,mixed> $extra
 * @return array{success:false,error_code:string,error:string,api_version:string}
 */
function merchantApiBuildErrorPayload(string $code, ?string $overrideMessage = null, array $extra = []): array
{
    $catalog = merchantApiErrorCatalog();
    $meta = $catalog[$code] ?? ['http' => 400, 'message' => 'Request could not be completed.'];
    $message = $overrideMessage ?? (string)$meta['message'];
    return array_merge([
        'success' => false,
        'error_code' => $code,
        'error' => $message,
        'api_version' => defined('API_VERSION') ? API_VERSION : 'v1',
    ], $extra);
}

/** HTTP status for a catalog error code. */
function merchantApiErrorHttpStatus(string $code): int
{
    $catalog = merchantApiErrorCatalog();
    return (int)(($catalog[$code] ?? ['http' => 400])['http']);
}

/**
 * @param array<string,mixed> $extra
 * @return never
 */
function merchantApiRespondError(string $code, ?string $overrideMessage = null, array $extra = []): void
{
    $http = merchantApiErrorHttpStatus($code);
    $payload = merchantApiBuildErrorPayload($code, $overrideMessage, $extra);
    if (!function_exists('jsonResponse')) {
        http_response_code($http);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
    jsonResponse($payload, $http);
}
