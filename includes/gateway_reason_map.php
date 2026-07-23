<?php
declare(strict_types=1);

/**
 * Partner gateway error_code → clear English one-liner for merchants.
 * Covers Razorpay / Cashfree / PayU / Axis-style codes. Never invents fake bank reasons.
 */

const GATEWAY_REASON_FALLBACK = 'Technical issue from bank side. Please try again later.';

/**
 * Canonical map: normalized UPPER_SNAKE / common aliases → merchant-facing English.
 * Keep entries factual and short; prefer "customer/bank" wording over raw PG jargon.
 */
function gatewayFailureReasonDictionary(): array
{
    return [
        // Funds / account
        'INSUFFICIENT_FUNDS' => 'Insufficient balance in the customer\'s account.',
        'BAD_REQUEST_PAYMENT_INSUFFICIENT_BALANCE' => 'Insufficient balance in the customer\'s account.',
        'PAYMENT_DECLINED_INSUFFICIENT_FUNDS' => 'Insufficient balance in the customer\'s account.',
        'E00017' => 'Insufficient balance in the customer\'s account.', // PayU-style
        'INSUFFICIENT_BALANCE' => 'Insufficient balance in the customer\'s account.',
        'ACCOUNT_BLOCKED' => 'Customer account is blocked or frozen by the bank.',
        'ACCOUNT_CLOSED' => 'Customer account is closed. Ask them to pay from another account.',
        'INVALID_ACCOUNT' => 'Bank account details are invalid. Ask the customer to retry with a correct account.',
        'INVALID_VPA' => 'UPI ID (VPA) is invalid or not found.',
        'INVALID_UPI_ID' => 'UPI ID (VPA) is invalid or not found.',
        'VPA_NOT_FOUND' => 'UPI ID (VPA) is invalid or not found.',

        // Timeouts / technical
        'ACQUIRER_TIMEOUT' => 'Bank did not respond in time. Please ask the customer to try again.',
        'GATEWAY_TIMEOUT' => 'Bank did not respond in time. Please ask the customer to try again.',
        'TIMEOUT' => 'Bank did not respond in time. Please ask the customer to try again.',
        'PAYMENT_TIMED_OUT' => 'Bank did not respond in time. Please ask the customer to try again.',
        'BANK_TIMEOUT' => 'Bank did not respond in time. Please ask the customer to try again.',
        'SERVER_ERROR' => 'Technical issue from bank side. Please try again later.',
        'INTERNAL_SERVER_ERROR' => 'Technical issue from bank side. Please try again later.',
        'GATEWAY_ERROR' => 'Technical issue from bank side. Please try again later.',
        'TECHNICAL_ERROR' => 'Technical issue from bank side. Please try again later.',
        'PROCESSING_ERROR' => 'Technical issue from bank side. Please try again later.',

        // Risk / fraud / compliance
        'RISK_REJECTED' => 'Payment blocked by risk checks. Customer may need to use another method.',
        'PAYMENT_RISK_CHECK_FAILED' => 'Payment blocked by risk checks. Customer may need to use another method.',
        'FRAUD_SUSPECTED' => 'Payment blocked by fraud filters. Ask the customer to contact their bank.',
        'SUSPECTED_FRAUD' => 'Payment blocked by fraud filters. Ask the customer to contact their bank.',
        'HIGH_RISK' => 'Payment blocked by risk checks. Customer may need to use another method.',
        'COMPLIANCE_VIOLATION' => 'Payment blocked due to compliance rules.',

        // Declines / auth
        'PAYMENT_DECLINED' => 'Bank declined the payment. Ask the customer to retry or use another method.',
        'CARD_DECLINED' => 'Card was declined by the issuing bank.',
        'DO_NOT_HONOUR' => 'Bank declined the payment (do not honour). Ask the customer to contact their bank.',
        'DO_NOT_HONOR' => 'Bank declined the payment (do not honour). Ask the customer to contact their bank.',
        'AUTHENTICATION_FAILED' => 'Customer authentication failed (OTP / 3-D Secure). Ask them to retry.',
        'AUTH_FAILED' => 'Customer authentication failed (OTP / 3-D Secure). Ask them to retry.',
        '3DS_FAILED' => 'Customer authentication failed (OTP / 3-D Secure). Ask them to retry.',
        'OTP_FAILED' => 'OTP verification failed. Ask the customer to retry with the correct OTP.',
        'INCORRECT_PIN' => 'Incorrect PIN entered. Ask the customer to retry.',
        'PIN_TRIES_EXCEEDED' => 'Too many incorrect PIN attempts. Ask the customer to contact their bank.',
        'CVV_FAILURE' => 'Card security code (CVV) check failed.',
        'EXPIRED_CARD' => 'Card has expired. Ask the customer to use another card.',
        'INVALID_CARD' => 'Card details are invalid. Ask the customer to re-enter or use another card.',
        'CARD_NOT_SUPPORTED' => 'This card type is not supported for this payment.',

        // Limits
        'TRANSACTION_LIMIT_EXCEEDED' => 'Transaction exceeds the bank / UPI limit for this customer.',
        'DAILY_LIMIT_EXCEEDED' => 'Customer has reached their daily payment limit.',
        'AMOUNT_LIMIT_EXCEEDED' => 'Amount exceeds the allowed limit for this method.',
        'MAX_AMOUNT_LIMIT' => 'Amount exceeds the allowed limit for this method.',

        // Cancel / abandon / expire
        'PAYMENT_CANCELLED' => 'Customer cancelled the payment before completion.',
        'PAYMENT_CANCELED' => 'Customer cancelled the payment before completion.',
        'USER_CANCELLED' => 'Customer cancelled the payment before completion.',
        'CUSTOMER_CANCELLED' => 'Customer cancelled the payment before completion.',
        'ABANDONED' => 'Customer left the payment page without completing.',
        'PAYMENT_EXPIRED' => 'Payment session expired before the customer completed payment.',
        'ORDER_EXPIRED' => 'Payment session expired before the customer completed payment.',
        'SESSION_EXPIRED' => 'Payment session expired before the customer completed payment.',

        // Settlement / payout rails (reused for settlement failure_reason)
        'BENEFICIARY_BANK_REJECTED' => 'Beneficiary bank rejected the transfer. Verify IFSC and account number, then retry.',
        'INVALID_IFSC' => 'IFSC code is invalid. Update bank details and retry settlement.',
        'INVALID_ACCOUNT_NUMBER' => 'Bank account number is invalid. Update bank details and retry settlement.',
        'ACCOUNT_NAME_MISMATCH' => 'Account holder name does not match bank records.',
        'NRE_ACCOUNT' => 'NRE accounts cannot receive this settlement. Use a resident savings/current account.',
        'PAYOUT_FAILED' => 'Bank payout failed. Check account details and retry.',
        'REVERSED' => 'Bank reversed the transfer. Funds stay in the merchant wallet until retry.',
        'REJECTED' => 'Bank rejected the transfer. Check account details and retry.',

        // Network / UPI specific
        'UPI_COLLECT_EXPIRED' => 'UPI collect request expired before the customer approved it.',
        'UPI_REQUEST_EXPIRED' => 'UPI collect request expired before the customer approved it.',
        'NPCI_REJECTED' => 'UPI network rejected the payment. Ask the customer to try again.',
        'REMITTER_BANK_TIMEOUT' => 'Customer\'s bank timed out. Please ask them to try again.',
        'ISSUER_DECLINE' => 'Issuing bank declined the payment.',
    ];
}

/**
 * Normalize partner error codes / free-text into a lookup key.
 */
function normalizeGatewayErrorCode(?string $codeOrText): string
{
    $raw = strtoupper(trim((string)$codeOrText));
    if ($raw === '') {
        return '';
    }
    // Prefer the last token if partners send "gateway:CODE" or "BAD_REQUEST_ERROR:INSUFFICIENT_FUNDS"
    if (preg_match('/([A-Z0-9][A-Z0-9_]{2,})$/', $raw, $m)) {
        $raw = $m[1];
    }
    $raw = preg_replace('/[^A-Z0-9_]/', '_', $raw) ?? $raw;
    $raw = preg_replace('/_+/', '_', $raw) ?? $raw;
    return trim($raw, '_');
}

/**
 * Map a partner error_code (and optional raw message) to merchant-facing English.
 * If the raw message is already a clear sentence and code is unknown, keep a sanitized
 * version of the raw message instead of inventing a bank story.
 */
function mapGatewayFailureReason(?string $errorCode = null, ?string $rawMessage = null): string
{
    $dict = gatewayFailureReasonDictionary();
    $code = normalizeGatewayErrorCode($errorCode);
    if ($code !== '' && isset($dict[$code])) {
        return $dict[$code];
    }

    // Sometimes partners put the code in the message field only.
    $msgCode = normalizeGatewayErrorCode($rawMessage);
    if ($msgCode !== '' && isset($dict[$msgCode])) {
        return $dict[$msgCode];
    }

    // Nested codes inside longer strings (e.g. "error: INSUFFICIENT_FUNDS from acquirer")
    if ($rawMessage) {
        foreach ($dict as $key => $text) {
            if ($key !== '' && stripos($rawMessage, $key) !== false) {
                return $text;
            }
        }
    }

    $clean = trim(preg_replace('/\s+/', ' ', (string)$rawMessage) ?? '');
    if ($clean !== '') {
        // Drop pure machine codes / JSON crumbs; otherwise use partner text as-is (English assumed).
        if (preg_match('/^[A-Z0-9_.:\-]{3,80}$/', $clean) || str_starts_with($clean, '{') || str_starts_with($clean, '[')) {
            return GATEWAY_REASON_FALLBACK;
        }
        return mb_substr($clean, 0, 500);
    }

    return GATEWAY_REASON_FALLBACK;
}

/**
 * Extract error_code + raw message from heterogeneous partner payloads.
 * @return array{0:?string,1:?string} [code, message]
 */
function extractGatewayErrorFields(array $payload): array
{
    $err = $payload['error'] ?? null;
    $errCode = null;
    $errMsg = null;
    if (is_array($err)) {
        $errCode = $err['code'] ?? $err['reason'] ?? null;
        $errMsg = $err['description'] ?? $err['reason'] ?? $err['message'] ?? null;
    } elseif (is_string($err) && $err !== '') {
        $errMsg = $err;
    }

    $pg = $payload['payment_gateway_details'] ?? null;
    $pgCode = is_array($pg) ? ($pg['error_code'] ?? null) : null;

    $code = $payload['error_code']
        ?? $payload['errorCode']
        ?? $payload['failure_code']
        ?? $payload['failureCode']
        ?? $payload['code']
        ?? $errCode
        ?? $pgCode
        ?? null;

    $message = $payload['error_description']
        ?? $payload['error_message']
        ?? $payload['errorMessage']
        ?? $payload['failure_reason']
        ?? $payload['failure_message']
        ?? $payload['failureReason']
        ?? $payload['status_message']
        ?? $payload['field9'] // PayU sometimes
        ?? $payload['payment_message']
        ?? $errMsg
        ?? null;

    if (is_array($message)) {
        $message = $message['description'] ?? $message['reason'] ?? $message['message'] ?? null;
    }
    if (is_array($code)) {
        $code = $code['code'] ?? $code['reason'] ?? null;
    }

    return [
        $code !== null && $code !== '' ? (string)$code : null,
        $message !== null && $message !== '' ? (string)$message : null,
    ];
}

/**
 * Map from a full partner entity / webhook fragment.
 */
function mapGatewayFailureFromPayload(array $payload): string
{
    [$code, $message] = extractGatewayErrorFields($payload);
    return mapGatewayFailureReason($code, $message);
}
