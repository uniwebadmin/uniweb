<?php
declare(strict_types=1);

/** Shared checkout test/live banner for checkout and payment return pages. */

function checkoutLinkIsTest(array $link): bool
{
    if (!empty($link['is_test'])) {
        return true;
    }
    if (isset($link['account_mode'])) {
        return merchantAccountMode($link) === 'test';
    }
    if (isset($link['mode'])) {
        return (string)$link['mode'] === 'test';
    }
    return false;
}

function renderCheckoutModeBanner(?array $link = null, bool $forceTest = false): void
{
    if ($link !== null) {
        renderCheckoutModeAndCollectionBanner($link, false, null, null, $forceTest);
        return;
    }
    if ($forceTest) {
        echo '<div class="mode-test-stripe">⚡ UniWeb Test Mode — sandbox payment · no real money</div>';
        return;
    }
    echo '<div class="bg-emerald-600/20 border-b border-emerald-500/30 text-center text-xs text-emerald-300 py-2 px-4">● LIVE MODE — Real payment settlement</div>';
}

/**
 * Audit B #9 — Test/Live stripe + honest collection rail label on checkout.
 *
 * @param array<string,mixed> $link
 */
function renderCheckoutModeAndCollectionBanner(array $link, bool $allowInstantPay = false, ?string $handler = null, ?string $lockedMethod = null, bool $forceTest = false): void
{
    $isTest = $forceTest || checkoutLinkIsTest($link);

    if ($allowInstantPay) {
        echo '<div class="bg-amber-500 text-dark-900 text-center text-sm font-semibold py-2 px-4">⚡ UniWeb Test Mode — sandbox · use UniWeb Test Pay · no real money</div>';
    } elseif ($isTest) {
        echo '<div class="mode-test-stripe">⚡ UniWeb Test Mode — sandbox payment · no real money</div>';
    } else {
        echo '<div class="bg-emerald-600/20 border-b border-emerald-500/30 text-center text-xs text-emerald-300 py-2 px-4">● LIVE MODE — Real payment settlement</div>';
    }

    $collLabel = function_exists('checkoutCollectionCustomerLabel')
        ? checkoutCollectionCustomerLabel($link)
        : '';
    if ($collLabel === '') {
        return;
    }

    $parts = [$collLabel];
    if ($lockedMethod !== null && $lockedMethod !== '') {
        $parts[] = 'Dedicated link: ' . $lockedMethod;
    } elseif ($handler !== null && $handler !== '' && function_exists('checkoutHandlerLabel')) {
        $handlerLabel = checkoutHandlerLabel($handler);
        if ($handlerLabel !== '' && stripos($collLabel, $handlerLabel) === false) {
            $parts[] = $handlerLabel;
        }
    }
    if ($isTest) {
        $parts[] = 'Sandbox — no real settlement';
    }

    echo '<div class="bg-dark-900 border-b border-gray-800 text-center text-xs text-gray-400 py-2 px-4">' . e(implode(' · ', $parts)) . '</div>';
}

function gatewaySettingIsSecret(string $key): bool
{
    static $secrets = [
        'smtp_pass', 'razorpay_key_secret', 'razorpay_webhook_secret', 'cashfree_secret_key',
        'payu_merchant_salt', 'decentro_client_secret', 'phonepe_salt_key',
        'axis_client_secret', 'axis_api_secret', 'whatsapp_api_token',
    ];
    return in_array($key, $secrets, true) || str_contains($key, '_secret') || str_contains($key, '_pass')
        || str_contains($key, '_salt') || str_contains($key, '_token');
}

function gatewaySettingFieldAttrs(string $key, array $settingsMap, string $type): array
{
    $configured = trim((string)($settingsMap[$key] ?? '')) !== '';
    if ($type === 'password' || gatewaySettingIsSecret($key)) {
        return [
            'type' => 'password',
            'value' => '',
            'placeholder' => $configured ? 'Saved — leave blank to keep current value' : 'Paste secret key',
            'autocomplete' => 'new-password',
        ];
    }
    return [
        'type' => $type,
        'value' => (string)($settingsMap[$key] ?? ''),
        'placeholder' => '',
        'autocomplete' => '',
    ];
}

function renderGatewaySettingInput(string $key, string $label, string $type, array $settingsMap): void
{
    $attrs = gatewaySettingFieldAttrs($key, $settingsMap, $type);
    echo '<div><label class="text-sm text-gray-400">' . e($label) . '</label>';
    echo '<input type="' . e($attrs['type']) . '" name="settings[' . e($key) . ']" value="' . e($attrs['value']) . '"';
    if ($attrs['placeholder'] !== '') {
        echo ' placeholder="' . e($attrs['placeholder']) . '"';
    }
    if ($attrs['autocomplete'] !== '') {
        echo ' autocomplete="' . e($attrs['autocomplete']) . '"';
    }
    if ($type === 'number') {
        echo ' step="0.01"';
    }
    echo ' class="input-field mt-1"></div>';
}

function renderCheckoutCustomerFields(array $link): void
{
    $phone = preg_replace('/\D/', '', (string)($link['customer_phone'] ?? ''));
    if (strlen($phone) > 10) {
        $phone = substr($phone, -10);
    }
    $email = (string)($link['customer_email'] ?? '');
    echo '<div class="grid sm:grid-cols-2 gap-2 mb-3">';
    echo '<input type="text" name="customer_name" placeholder="Your name (optional)" class="input-field text-sm" value="' . e((string)($link['customer_name'] ?? '')) . '">';
    echo '<input type="tel" name="customer_phone" inputmode="numeric" pattern="[6-9][0-9]{9}" maxlength="10" required placeholder="Mobile number *" class="input-field text-sm" value="' . e($phone) . '" autocomplete="tel">';
    echo '</div>';
    echo '<div class="mb-3">';
    echo '<input type="email" name="customer_email" placeholder="Email (optional)" class="input-field text-sm" value="' . e($email) . '" autocomplete="email">';
    echo '<p class="text-[11px] text-gray-500 mt-1">Mobile is required for receipt lookup. No OTP on checkout.</p>';
    echo '</div>';
}

function saveGatewaySettingsPreservingSecrets(array $posted, PDO $db): void
{
    if (!function_exists('isPartnerCredentialSettingKey') && is_file(__DIR__ . '/partner_control.php')) {
        require_once __DIR__ . '/partner_control.php';
    }
    foreach ($posted as $key => $value) {
        $key = preg_replace('/[^a-z0-9_]/', '', (string)$key);
        if ($key === '') {
            continue;
        }
        // P1-01: live PG secrets belong in Partner Registry → Keys, never gateway_settings.
        if (function_exists('isPartnerCredentialSettingKey') && isPartnerCredentialSettingKey($key)) {
            continue;
        }
        $val = trim((string)$value);
        if ($val === '' && gatewaySettingIsSecret($key)) {
            continue;
        }
        if ($key === 'min_settlement_amount') {
            $n = (float)$val;
            $val = (string)(($n > 0 && $n <= 100) ? $n : 100);
        }
        if ($key === 'min_platform_settlement') {
            $n = (float)$val;
            $val = (string)(($n > 0 && $n <= 1) ? $n : 1);
        }
        if ($key === 'auto_audit_interval_minutes') {
            $n = (int)$val;
            $val = (string)(($n >= 5 && $n <= 120) ? $n : 10);
        }
        $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
            ->execute([$key, $val, $val]);
    }
}
