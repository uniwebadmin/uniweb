<?php
declare(strict_types=1);

/**
 * Checkout Customize Module
 * 
 * Per-merchant checkout page customization: logo, colors, theme.
 * Merchants can personalize their checkout page appearance.
 * Applied on checkout.php only.
 */

function ensureCheckoutCustomizeTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS merchant_checkout_customize (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL UNIQUE,
            logo_url VARCHAR(500) DEFAULT NULL,
            primary_color VARCHAR(20) DEFAULT NULL,
            accent_color VARCHAR(20) DEFAULT NULL,
            button_color VARCHAR(20) DEFAULT NULL,
            checkout_title VARCHAR(200) DEFAULT NULL,
            checkout_subtitle VARCHAR(300) DEFAULT NULL,
            success_message VARCHAR(300) DEFAULT NULL,
            failure_message VARCHAR(300) DEFAULT NULL,
            redirect_url VARCHAR(500) DEFAULT NULL,
            custom_css TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        getDB()->exec("ALTER TABLE merchant_checkout_customize ADD COLUMN success_message VARCHAR(300) DEFAULT NULL AFTER checkout_subtitle");
        getDB()->exec("ALTER TABLE merchant_checkout_customize ADD COLUMN failure_message VARCHAR(300) DEFAULT NULL AFTER success_message");
        getDB()->exec("ALTER TABLE merchant_checkout_customize ADD COLUMN redirect_url VARCHAR(500) DEFAULT NULL AFTER failure_message");
        getDB()->exec("ALTER TABLE merchant_checkout_customize ADD COLUMN hide_powered_by TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
    } catch (Throwable $e) { error_log('ensureCheckoutCustomizeTable: ' . $e->getMessage()); }
}

function getMerchantCheckoutCustomize(int $merchantId): array
{
    ensureCheckoutCustomizeTable();
    try {
        $st = getDB()->prepare('SELECT * FROM merchant_checkout_customize WHERE merchant_id=?');
        $st->execute([$merchantId]);
        $row = $st->fetch();
        if (!$row) {
            return [
                'merchant_id' => $merchantId,
                'logo_url' => null,
                'primary_color' => null,
                'accent_color' => null,
                'button_color' => null,
                'checkout_title' => null,
                'checkout_subtitle' => null,
                'custom_css' => null,
                'is_active' => 0,
                'hide_powered_by' => 0,
            ];
        }
        $row['hide_powered_by'] = !empty($row['hide_powered_by']) ? 1 : 0;
        return $row;
    } catch (Throwable $e) {
        return ['merchant_id' => $merchantId, 'is_active' => 0, 'hide_powered_by' => 0];
    }
}

function saveMerchantCheckoutCustomize(int $merchantId, array $data): array
{
    ensureCheckoutCustomizeTable();
    $logoUrl = trim((string)($data['logo_url'] ?? '')) ?: null;
    $primaryColor = trim((string)($data['primary_color'] ?? '')) ?: null;
    $accentColor = trim((string)($data['accent_color'] ?? '')) ?: null;
    $buttonColor = trim((string)($data['button_color'] ?? '')) ?: null;
    $checkoutTitle = trim((string)($data['checkout_title'] ?? '')) ?: null;
    $checkoutSubtitle = trim((string)($data['checkout_subtitle'] ?? '')) ?: null;
    $successMessage = trim((string)($data['success_message'] ?? '')) ?: null;
    $failureMessage = trim((string)($data['failure_message'] ?? '')) ?: null;
    $redirectUrl = trim((string)($data['redirect_url'] ?? '')) ?: null;
    $customCss = trim((string)($data['custom_css'] ?? '')) ?: null;
    $isActive = !empty($data['is_active']) ? 1 : 0;
    $hidePoweredBy = !empty($data['hide_powered_by']) ? 1 : 0;

    foreach ([['Primary', $primaryColor], ['Accent', $accentColor], ['Button', $buttonColor]] as [$label, $color]) {
        if ($color && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return ['ok' => false, 'error' => "{$label} color must be a valid hex color (e.g. #1a73e8)."];
        }
    }

    if ($logoUrl && !filter_var($logoUrl, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'error' => 'Logo URL must be a valid URL.'];
    }

    if ($redirectUrl && !filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'error' => 'Redirect URL must be a valid URL.'];
    }

    try {
        getDB()->prepare('INSERT INTO merchant_checkout_customize
            (merchant_id, logo_url, primary_color, accent_color, button_color, checkout_title, checkout_subtitle, success_message, failure_message, redirect_url, custom_css, is_active, hide_powered_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
            logo_url=VALUES(logo_url), primary_color=VALUES(primary_color), accent_color=VALUES(accent_color),
            button_color=VALUES(button_color), checkout_title=VALUES(checkout_title), checkout_subtitle=VALUES(checkout_subtitle),
            success_message=VALUES(success_message), failure_message=VALUES(failure_message), redirect_url=VALUES(redirect_url),
            custom_css=VALUES(custom_css), is_active=VALUES(is_active), hide_powered_by=VALUES(hide_powered_by)')
            ->execute([
                $merchantId, $logoUrl, $primaryColor, $accentColor, $buttonColor,
                $checkoutTitle, $checkoutSubtitle, $successMessage, $failureMessage, $redirectUrl, $customCss, $isActive, $hidePoweredBy,
            ]);
        return ['ok' => true, 'message' => 'Checkout customization saved.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function renderCheckoutCustomizeCss(array $cc): string
{
    if (empty($cc['is_active'])) return '';
    $primary = $cc['primary_color'] ?? '';
    $accent = $cc['accent_color'] ?? '';
    $button = $cc['button_color'] ?? '';
    $css = '';
    if ($primary) {
        $css .= ":root{--brand-color:{$primary};}";
        $css .= ".text-brand-400{color:{$primary};}.bg-brand-500{background-color:{$primary};}.border-brand-500{border-color:{$primary};}.bg-brand-500\/20{background-color:" . hexToRgba($primary, 0.2) . ";}";
    }
    if ($button) {
        $css .= ".btn-primary{background-color:{$button};border-color:{$button};}";
        $css .= ".btn-primary:hover{background-color:" . darkenColor($button, 10) . ";border-color:" . darkenColor($button, 10) . ";}";
    }
    if ($accent) {
        $css .= "a{color:{$accent};}";
    }
    if (!empty($cc['custom_css'])) {
        $css .= "\n/* Merchant Custom CSS */\n" . $cc['custom_css'];
    }
    return $css;
}

function darkenColor(string $hex, int $percent): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return $hex;
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $factor = 1 - ($percent / 100);
    $r = max(0, (int)round($r * $factor));
    $g = max(0, (int)round($g * $factor));
    $b = max(0, (int)round($b * $factor));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

function hexToRgba(string $hex, float $alpha): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return $hex;
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return sprintf('rgba(%d,%d,%d,%.2f)', $r, $g, $b, $alpha);
}

function checkoutHidePoweredBy(array $brand): bool
{
    return !empty($brand['hide_powered_by']);
}

function resolveCheckoutCustomize(array $merchant): array
{
    $cc = getMerchantCheckoutCustomize((int)$merchant['id']);
    $hide = checkoutHidePoweredBy($cc);
    if (empty($cc['is_active'])) {
        return [
            'active' => false,
            'logo_url' => null,
            'css' => '',
            'checkout_title' => null,
            'checkout_subtitle' => null,
            'hide_powered_by' => $hide,
        ];
    }
    return [
        'active' => true,
        'logo_url' => $cc['logo_url'] ?? null,
        'css' => renderCheckoutCustomizeCss($cc),
        'checkout_title' => $cc['checkout_title'] ?? null,
        'checkout_subtitle' => $cc['checkout_subtitle'] ?? null,
        'success_message' => $cc['success_message'] ?? null,
        'failure_message' => $cc['failure_message'] ?? null,
        'redirect_url' => $cc['redirect_url'] ?? null,
        'brand_name' => trim((string)($merchant['business_name'] ?? '')) ?: APP_NAME,
        'hide_powered_by' => $hide,
    ];
}

/** Audit B #10 — partner display name from Partner Registry (not hardcoded). */
function checkoutCustomizePartnerLabel(string $partnerKey): string
{
    $partnerKey = strtolower(trim($partnerKey));
    if ($partnerKey === '' || $partnerKey === 'direct' || $partnerKey === 'instant') {
        return 'Direct UPI (your VPA)';
    }
    if (!function_exists('wiringKycForwardPartnerLabel') && is_file(__DIR__ . '/wiring_deep_link_workflow.php')) {
        require_once __DIR__ . '/wiring_deep_link_workflow.php';
    }
    if (function_exists('wiringKycForwardPartnerLabel')) {
        return wiringKycForwardPartnerLabel($partnerKey);
    }
    if (function_exists('getPartnerRegistry')) {
        $reg = getPartnerRegistry();
        return (string)($reg[$partnerKey]['name'] ?? ucfirst($partnerKey));
    }
    return ucfirst($partnerKey);
}

/**
 * Audit B #10 — enabled checkout methods with registry partner names for merchant customize page.
 *
 * @return list<array{method_key:string,method_label:string,partner_key:string,partner_name:string}>
 */
function checkoutCustomizeMerchantMethodPartners(int $merchantId): array
{
    if (!function_exists('get_available_pay_methods')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    if (!function_exists('getPaymentMethodCatalog')) {
        require_once __DIR__ . '/provision.php';
    }
    $methods = get_available_pay_methods($merchantId);
    $catalog = getPaymentMethodCatalog();
    $rows = [];
    foreach ($methods as $m) {
        $key = (string)($m['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $cat = $catalog[$key] ?? null;
        $gw = strtolower((string)($cat['gateway'] ?? 'direct'));
        if ($gw === 'direct' || $gw === 'instant') {
            $partnerKey = 'direct';
        } else {
            $partnerKey = $gw;
        }
        $rows[] = [
            'method_key' => $key,
            'method_label' => function_exists('merchantPaymentMethodLabel')
                ? merchantPaymentMethodLabel($key, (string)($m['label'] ?? ''))
                : (string)($m['label'] ?? $key),
            'partner_key' => $partnerKey,
            'partner_name' => checkoutCustomizePartnerLabel($partnerKey),
        ];
    }
    return $rows;
}
