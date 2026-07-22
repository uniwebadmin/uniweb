<?php
declare(strict_types=1);

/** Merchant portal UX helpers — empty states, refund reasons, notify prefs */

function renderMerchantEmptyState(string $title, string $hint, ?string $ctaUrl = null, ?string $ctaLabel = null): string
{
    $html = '<div class="glass rounded-xl px-6 py-14 text-center border border-gray-800">'
        . '<div class="mx-auto mb-4 w-14 h-14 rounded-2xl bg-brand-500/10 border border-brand-500/20 flex items-center justify-center">'
        . '<svg class="w-7 h-7 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">'
        . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V7a2 2 0 00-2-2H6a2 2 0 00-2 2v6m16 0v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4m16 0H4"/>'
        . '</svg></div>'
        . '<p class="text-base font-semibold text-white mb-1">' . e($title) . '</p>'
        . '<p class="text-sm text-gray-500 max-w-md mx-auto">' . e($hint) . '</p>';
    if ($ctaUrl && $ctaLabel) {
        $html .= '<a href="' . e($ctaUrl) . '" class="inline-block mt-5 btn-primary text-sm px-5 py-2.5">' . e($ctaLabel) . '</a>';
    }
    $html .= '</div>';
    return $html;
}

/** Standard refund / dispute reason codes (approval / ops friendly) */
function getRefundReasonOptions(): array
{
    return [
        'Customer cancelled the order',
        'Product / service not delivered',
        'Duplicate payment',
        'Customer requested refund',
        'Partial refund as per T&C',
        'Fraud / unauthorized transaction',
        'Other',
    ];
}

/** Chargeback / dispute reason codes for merchant + admin ops */
function getDisputeReasonOptions(): array
{
    return [
        'Goods / services not received',
        'Goods not as described',
        'Duplicate / incorrect charge',
        'Unauthorized / fraudulent transaction',
        'Refund not processed',
        'Amount mismatch',
        'Cancelled order still charged',
        'Other',
    ];
}

function ensureMerchantNotifyPrefsEngine(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    getDB()->exec("CREATE TABLE IF NOT EXISTS merchant_notify_prefs (
        merchant_id INT UNSIGNED PRIMARY KEY,
        prefs_json JSON NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function defaultMerchantNotifyPrefs(): array
{
    return [
        'payment_success' => ['email' => true, 'webhook' => true, 'whatsapp' => true],
        'payment_failed' => ['email' => true, 'webhook' => false, 'whatsapp' => false],
        'settlement' => ['email' => true, 'webhook' => false, 'whatsapp' => true],
        'refund' => ['email' => true, 'webhook' => true, 'whatsapp' => false],
        'account' => ['email' => true, 'webhook' => false, 'whatsapp' => true],
    ];
}

function getMerchantNotifyPrefs(int $merchantId): array
{
    ensureMerchantNotifyPrefsEngine();
    $st = getDB()->prepare('SELECT prefs_json FROM merchant_notify_prefs WHERE merchant_id = ?');
    $st->execute([$merchantId]);
    $raw = $st->fetchColumn();
    $defaults = defaultMerchantNotifyPrefs();
    if (!$raw) {
        return $defaults;
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    foreach ($defaults as $event => $channels) {
        if (!isset($decoded[$event]) || !is_array($decoded[$event])) {
            $decoded[$event] = $channels;
            continue;
        }
        foreach ($channels as $ch => $on) {
            $decoded[$event][$ch] = !empty($decoded[$event][$ch]);
        }
    }
    return $decoded;
}

function saveMerchantNotifyPrefs(int $merchantId, array $prefs): void
{
    ensureMerchantNotifyPrefsEngine();
    $clean = defaultMerchantNotifyPrefs();
    foreach ($clean as $event => $channels) {
        foreach ($channels as $ch => $_) {
            $clean[$event][$ch] = !empty($prefs[$event][$ch]);
        }
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    getDB()->prepare('INSERT INTO merchant_notify_prefs (merchant_id, prefs_json) VALUES (?,?)
        ON DUPLICATE KEY UPDATE prefs_json = VALUES(prefs_json)')->execute([$merchantId, $json]);
}

function merchantWantsNotify(int $merchantId, string $event, string $channel = 'email'): bool
{
    $prefs = getMerchantNotifyPrefs($merchantId);
    return !empty($prefs[$event][$channel]);
}
