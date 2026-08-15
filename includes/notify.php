<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/release_helpers.php')) {
    require_once __DIR__ . '/release_helpers.php';
}

function sendSMS(string $phone, string $message): bool
{
    $apiKey = getSetting('sms_api_key', '');
    $sender = getSetting('sms_sender_id', 'UNIWEB');
    if (!$apiKey) return false;
    $phone = preg_replace('/\D/', '', $phone);
    if (strlen($phone) === 10) $phone = '91' . $phone;
    $templateId = getSetting('sms_template_id', '');
    if ($templateId) {
        $url = 'https://control.msg91.com/api/v5/flow/';
        $payload = json_encode(['template_id' => $templateId, 'recipients' => [['mobiles' => $phone, 'var' => $message]]]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['authkey: ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 15,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $ok = (bool)$res;
    } else {
        $ok = sendTransactionalSms($phone, $message);
    }
    try { getDB()->prepare('INSERT INTO sms_logs (phone, message, status) VALUES (?,?,?)')->execute([$phone, $message, $ok ? 'sent' : 'failed']); } catch (Throwable $e) {}
    return $ok;
}

function sendTransactionalSms(string $phone, string $message): bool
{
    $apiKey = getSetting('sms_api_key', '');
    if (!$apiKey) {
        return false;
    }
    $phone = preg_replace('/\D/', '', $phone);
    if (strlen($phone) === 10) {
        $phone = '91' . $phone;
    }
    $sender = rawurlencode(getSetting('sms_sender_id', 'UNIWEB'));
    $msg = rawurlencode($message);
    $url = "https://api.msg91.com/api/sendhttp.php?authkey={$apiKey}&mobiles={$phone}&message={$msg}&sender={$sender}&route=4&country=91";
    $ctx = stream_context_create(['http' => ['timeout' => 15]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false || $res === '') {
        return false;
    }
    return stripos((string)$res, 'error') === false;
}

function sendWhatsAppReminder(string $phone, string $message): bool
{
    $result = sendWhatsAppTextMessage($phone, $message);
    return $result['ok'];
}

/** Send a plain text message via a Telegram bot when TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID are configured in gateway_settings. */
function sendTelegramMessage(string $message, ?string $botToken = null, ?string $chatId = null): array
{
    $token = trim($botToken ?: getSetting('telegram_bot_token', ''));
    $chat = trim($chatId ?: getSetting('telegram_chat_id', ''));
    if ($token === '' || $chat === '' || !function_exists('curl_init')) {
        return ['ok' => false, 'channel' => 'none', 'http' => 0, 'message' => 'Telegram not configured'];
    }
    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
    $payload = json_encode([
        'chat_id' => $chat,
        'text' => mb_substr($message, 0, 4000),
        'parse_mode' => 'HTML',
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return ['ok' => false, 'channel' => 'telegram', 'http' => $http, 'message' => $err];
    }
    $data = json_decode($body, true);
    if ($http >= 200 && $http < 300 && !empty($data['ok'])) {
        return ['ok' => true, 'channel' => 'telegram', 'http' => $http, 'message' => 'sent'];
    }
    return ['ok' => false, 'channel' => 'telegram', 'http' => $http, 'message' => $data['description'] ?? ('HTTP ' . $http)];
}

function whatsappMessagesApiUrl(): string
{
    $custom = trim(getSetting('whatsapp_api_url', ''));
    if ($custom !== '') {
        return $custom;
    }
    $phoneId = trim(getSetting('whatsapp_phone_id', ''));
    return 'https://graph.facebook.com/v19.0/' . rawurlencode($phoneId) . '/messages';
}

function normalizeWhatsAppPhone(string $phone): string
{
    $phone = preg_replace('/\D/', '', $phone);
    if (strlen($phone) === 10) {
        $phone = '91' . $phone;
    }
    return $phone;
}

/** @return array{ok:bool,channel:string,http:int,message:string} */
function sendWhatsAppApiPayload(string $phone, array $payload): array
{
    $token = trim(getSetting('whatsapp_api_token', ''));
    if ($token === '' || !function_exists('curl_init')) {
        return ['ok' => false, 'channel' => 'none', 'http' => 0, 'message' => 'WhatsApp API not configured'];
    }
    $phone = normalizeWhatsAppPhone($phone);
    $payload['messaging_product'] = 'whatsapp';
    $payload['to'] = $phone;
    $ch = curl_init(whatsappMessagesApiUrl());
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return ['ok' => false, 'channel' => 'api', 'http' => $http, 'message' => $err];
    }
    if ($http >= 200 && $http < 300) {
        return ['ok' => true, 'channel' => 'api', 'http' => $http, 'message' => 'sent'];
    }
    $data = json_decode($body, true);
    $msg = $data['error']['message'] ?? ('HTTP ' . $http);
    return ['ok' => false, 'channel' => 'api', 'http' => $http, 'message' => $msg];
}

/** @return array{ok:bool,channel:string,http:int,message:string} */
function sendWhatsAppTextMessage(string $phone, string $message): array
{
    return sendWhatsAppApiPayload($phone, [
        'type' => 'text',
        'text' => ['body' => $message],
    ]);
}

/** @return array{ok:bool,channel:string,http:int,message:string} */
function sendWhatsAppOtp(string $phone, string $otp): array
{
    if (getSetting('whatsapp_enabled', '0') !== '1') {
        return ['ok' => false, 'channel' => 'disabled', 'http' => 0, 'message' => 'WhatsApp disabled'];
    }

    $template = trim(getSetting('whatsapp_otp_template_name', 'uniweb_otp'));
    $lang = trim(getSetting('whatsapp_otp_template_lang', 'en')) ?: 'en';
    $useTemplate = getSetting('whatsapp_use_otp_template', '1') === '1';

    if ($useTemplate && $template !== '') {
        $templatePayload = [
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $lang],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => $otp]],
                ]],
            ],
        ];
        $result = sendWhatsAppApiPayload($phone, $templatePayload);
        if ($result['ok']) {
            $result['channel'] = 'template';
            return $result;
        }
    }

    $fallback = sendWhatsAppTextMessage($phone, "Your UniWeb login OTP is {$otp}. Valid 10 minutes. Do not share.");
    $fallback['channel'] = 'text_fallback';
    return $fallback;
}

/** @return array{ok:bool,message:string} */
function testWhatsAppOtpDelivery(?string $phone = null): array
{
    $phone = $phone ?: COMPANY_PHONE;
    $otp = (string)random_int(100000, 999999);
    $result = sendWhatsAppOtp($phone, $otp);
    if ($result['ok']) {
        return ['ok' => true, 'message' => 'Test OTP sent via ' . $result['channel'] . ' to ' . normalizeWhatsAppPhone($phone)];
    }
    return ['ok' => false, 'message' => $result['message'] . ' (Meta business verification or template approval may still be pending.)'];
}

/** Try to discover WhatsApp Phone Number ID from Meta Graph API. */
function discoverWhatsappPhoneId(string $token): ?string
{
    if (!function_exists('curl_init') || trim($token) === '') {
        return null;
    }

    $graphGet = function (string $url) use ($token): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if (!$body) {
            return null;
        }
        $data = json_decode((string)$body, true);
        return is_array($data) ? $data : null;
    };

    $businesses = $graphGet('https://graph.facebook.com/v19.0/me/businesses?fields=id')['data'] ?? [];
    foreach ($businesses as $business) {
        $businessId = (string)($business['id'] ?? '');
        if ($businessId === '') {
            continue;
        }
        $wabas = $graphGet("https://graph.facebook.com/v19.0/{$businessId}/owned_whatsapp_business_accounts?fields=id")['data'] ?? [];
        foreach ($wabas as $waba) {
            $wabaId = (string)($waba['id'] ?? '');
            if ($wabaId === '') {
                continue;
            }
            $phones = $graphGet("https://graph.facebook.com/v19.0/{$wabaId}/phone_numbers?fields=id,display_phone_number")['data'] ?? [];
            foreach ($phones as $phone) {
                if (!empty($phone['id'])) {
                    return (string)$phone['id'];
                }
            }
        }
    }

    return null;
}

function saveGatewaySetting(string $key, string $value): void
{
    getDB()->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
        ->execute([$key, $value, $value]);
    clearSettingCache($key);
}

/** @return array{ok:bool,message:string,phone_id?:string} */
function testWhatsAppConnection(): array
{
    $token = trim(getSetting('whatsapp_api_token', ''));
    $phoneId = trim(getSetting('whatsapp_phone_id', ''));
    if ($token === '') {
        return ['ok' => false, 'message' => 'WhatsApp API token not set.'];
    }
    if ($phoneId === '') {
        $discovered = discoverWhatsappPhoneId($token);
        if ($discovered) {
            saveGatewaySetting('whatsapp_phone_id', $discovered);
            $phoneId = $discovered;
        }
    }
    if ($phoneId === '') {
        return ['ok' => false, 'message' => 'WhatsApp Phone ID missing — paste it from Meta Business Manager.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'PHP curl extension not enabled on server.'];
    }

    $ch = curl_init('https://graph.facebook.com/v19.0/' . rawurlencode($phoneId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'message' => 'Connection failed: ' . $err];
    }
    if ($http === 200) {
        $data = json_decode((string)$body, true);
        $label = $data['verified_name'] ?? $data['display_phone_number'] ?? 'connected';
        return ['ok' => true, 'message' => 'WhatsApp API connected — ' . $label, 'phone_id' => $phoneId];
    }

    $data = json_decode((string)$body, true);
    $msg = $data['error']['message'] ?? ('HTTP ' . $http);
    return ['ok' => false, 'message' => $msg];
}

function ensureOtpVerificationsSchema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS otp_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(190) NOT NULL,
            otp_code VARCHAR(12) NOT NULL,
            otp_type VARCHAR(40) NOT NULL DEFAULT 'login',
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_otp_lookup (identifier, otp_type, used, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function generateOTP(string $identifier, string $type = 'login'): string
{
    ensureOtpVerificationsSchema();
    $code = (string)random_int(100000, 999999);
    $expires = date('Y-m-d H:i:s', time() + 600);
    $db = getDB();
    $db->prepare('DELETE FROM otp_verifications WHERE identifier=? AND otp_type=?')->execute([$identifier, $type]);
    $db->prepare('INSERT INTO otp_verifications (identifier, otp_code, otp_type, expires_at) VALUES (?,?,?,?)')->execute([$identifier, $code, $type, $expires]);
    return $code;
}

function verifyOTP(string $identifier, string $code, string $type = 'login'): bool
{
    $stmt = getDB()->prepare('SELECT id FROM otp_verifications WHERE identifier=? AND otp_code=? AND otp_type=? AND expires_at > NOW() AND used=0');
    $stmt->execute([$identifier, $code, $type]);
    $row = $stmt->fetch();
    if ($row) {
        getDB()->prepare('UPDATE otp_verifications SET used=1 WHERE id=?')->execute([$row['id']]);
        return true;
    }
    return false;
}

function isOTPEnabled(): bool
{
    if (getSetting('otp_login_enabled', '0') !== '1') {
        return false;
    }
    // OTP login only when WhatsApp API is configured AND Meta health is green
    if (getSetting('whatsapp_enabled', '0') !== '1'
        || trim(getSetting('whatsapp_api_token', '')) === ''
        || trim(getSetting('whatsapp_phone_id', '')) === '') {
        return false;
    }
    // Fail closed until health check proves Meta is green (set by platformHealthSummary)
    return getSetting('whatsapp_otp_healthy', '0') === '1';
}

/** Send login OTP via WhatsApp API (if configured) + email backup. Returns wa.me link when API not used. */
function sendLoginOtpViaWhatsAppAndEmail(array $merchant, string $otp): array
{
    $msg = "Your UniWeb login OTP is {$otp}. Valid 10 minutes.";
    $phone = preg_replace('/\D/', '', (string)($merchant['phone'] ?? ''));
    $waSent = false;
    $waUrl = null;
    if (strlen($phone) >= 10) {
        if (getSetting('whatsapp_enabled', '0') === '1') {
            $waResult = sendWhatsAppOtp($phone, $otp);
            $waSent = $waResult['ok'];
        }
        if (!$waSent) {
            $p = strlen($phone) === 10 ? '91' . $phone : $phone;
            $waUrl = 'https://wa.me/' . $p . '?text=' . rawurlencode($msg);
        }
    }
    $emailSent = false;
    $email = trim((string)($merchant['email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $emailSent = sendPlatformEmail($email, 'Your UniWeb login OTP', $msg);
    }
    return ['whatsapp_sent' => $waSent, 'whatsapp_url' => $waUrl, 'email_sent' => $emailSent];
}

/**
 * Map in-app notification titles to merchant notify-pref event keys.
 * Unknown titles map to null (WhatsApp skipped — avoid spam).
 */
function notificationEventFromTitle(string $title): ?string
{
    $t = strtolower($title);
    if (str_contains($t, 'payment') || str_contains($t, 'received') || str_contains($t, 'recurring payment')) {
        return 'payment_success';
    }
    if (str_contains($t, 'fail') || str_contains($t, 'failed') || str_contains($t, 'debit failed')) {
        return 'payment_failed';
    }
    if (str_contains($t, 'settlement') || str_contains($t, 'payout access') || str_contains($t, 'payout sent') || str_contains($t, 'payout successful') || str_contains($t, 'payout failed')) {
        return 'settlement';
    }
    if (str_contains($t, 'refund')) {
        return 'refund';
    }
    if (str_contains($t, 'kyc') || str_contains($t, 'video kyc') || str_contains($t, 'account live') || str_contains($t, '2fa') || str_contains($t, 'mandate')) {
        return 'account';
    }
    return null;
}

/** Fan-out from createNotification — WhatsApp when prefs + Meta keys allow. */
function onMerchantNotificationCreated(int $merchantId, string $title, string $body): void
{
    $event = notificationEventFromTitle($title);
    if ($event === null) {
        return;
    }
    maybeSendWhatsAppMerchantAlert($merchantId, $event, $title . "\n" . $body);
}

/**
 * Send a WhatsApp text alert when:
 *  - platform whatsapp_enabled=1 and token/phone configured
 *  - merchant prefs allow WhatsApp for this event (default off except payment_success)
 */
function maybeSendWhatsAppMerchantAlert(int $merchantId, string $event, string $message): void
{
    if (getSetting('whatsapp_enabled', '0') !== '1') {
        return;
    }
    if (trim(getSetting('whatsapp_api_token', '')) === '' || trim(getSetting('whatsapp_phone_id', '')) === '') {
        return;
    }
    if (function_exists('merchantWantsNotify') && !merchantWantsNotify($merchantId, $event, 'whatsapp')) {
        return;
    }
    try {
        $st = getDB()->prepare('SELECT phone FROM merchants WHERE id=? LIMIT 1');
        $st->execute([$merchantId]);
        $phone = (string)($st->fetchColumn() ?: '');
        if (strlen(preg_replace('/\D/', '', $phone)) < 10) {
            return;
        }
        $text = trim($message);
        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, 900);
        } else {
            $text = substr($text, 0, 900);
        }
        if ($text === '') {
            return;
        }
        sendWhatsAppTextMessage($phone, 'UniWeb: ' . $text);
    } catch (Throwable $e) {
        // non-fatal
    }
}
