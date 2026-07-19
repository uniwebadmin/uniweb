<?php
declare(strict_types=1);

function ensureWhatsappWebhookTables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS whatsapp_webhook_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(64) DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'received',
            payload MEDIUMTEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (created_at),
            INDEX (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function whatsappWebhookVerifyToken(): string
{
    $token = trim(getSetting('whatsapp_webhook_verify_token', ''));
    return $token !== '' ? $token : 'uniweb-wa-verify-2026';
}

function whatsappWebhookCallbackUrl(): string
{
    return APP_URL . '/whatsapp_webhook.php';
}

function logWhatsappWebhook(string $status, ?string $eventType, string $payload): void
{
    ensureWhatsappWebhookTables();
    try {
        getDB()->prepare('INSERT INTO whatsapp_webhook_logs (event_type, status, payload) VALUES (?,?,?)')
            ->execute([$eventType, $status, $payload]);
    } catch (Throwable $e) { /* ok */ }
}

function handleWhatsappWebhookVerification(): bool
{
    $mode = (string)($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $token = (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string)($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');

    if ($mode !== 'subscribe' || $challenge === '') {
        return false;
    }

    if (!hash_equals(whatsappWebhookVerifyToken(), $token)) {
        logWhatsappWebhook('verify_failed', 'subscribe', json_encode([
            'mode' => $mode,
            'token_match' => false,
        ]));
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

    logWhatsappWebhook('verified', 'subscribe', json_encode(['mode' => $mode]));
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo $challenge;
    exit;
}

function handleWhatsappWebhookEvent(): void
{
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    $eventType = null;

    if (is_array($payload)) {
        $eventType = (string)($payload['entry'][0]['changes'][0]['field'] ?? $payload['object'] ?? 'whatsapp');
    }

    logWhatsappWebhook('received', $eventType, $raw !== '' ? $raw : '{}');
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    exit;
}
