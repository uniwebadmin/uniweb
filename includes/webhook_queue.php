<?php
declare(strict_types=1);

/**
 * Fast-ack helper for payment gateway webhooks.
 *
 * Real Redis/RabbitMQ queues are not available on this shared PHP hosting
 * (no Composer, no background workers). The practical equivalent here is
 * PHP-FPM's fastcgi_finish_request(): it flushes the HTTP response back to
 * the gateway immediately, then lets the script keep running (provider
 * verification calls, DB writes, notifications) in the background of the
 * same request. This satisfies "webhook aaye to turant 200 OK do, processing
 * baad me karo" without needing an external broker.
 *
 * Call this ONCE, right after idempotency/dedup + signature checks pass and
 * before any slow work (outbound provider-verification HTTP calls, heavy DB
 * writes). Every webhook file's existing jsonResponse()/echo calls at the
 * end are left untouched — they become safe no-ops once the connection is
 * already closed (headers_sent() guards prevent warnings), and behave as
 * a normal synchronous response when fastcgi_finish_request() isn't
 * available (e.g. local `php -S` dev server).
 */
function webhookFastAck(array $data = ['ok' => true, 'received' => true]): void
{
    if (!function_exists('fastcgi_finish_request') || headers_sent()) {
        return;
    }
    try {
        http_response_code(200);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data);
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
        }
        flush();
        fastcgi_finish_request();
    } catch (Throwable $e) {
        // Non-fatal — falls through to the normal synchronous jsonResponse() below.
    }
}
