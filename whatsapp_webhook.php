<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/whatsapp_webhooks.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    handleWhatsappWebhookVerification();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    handleWhatsappWebhookEvent();
}

http_response_code(405);
header('Content-Type: text/plain; charset=utf-8');
echo 'Method not allowed';
