<?php
declare(strict_types=1);

/**
 * Phase 11 — Smart partner routing (checkout PG selection + health failover).
 *
 * Single Owner switch: gateway_settings route_split_live_enabled (default OFF / parked).
 * When OFF: checkout uses fixed merchant-selected partner — no silent routing.
 * When ON: eligible Registry partners ranked by health + priority; honest decision log.
 *
 * Capture-time Route/Split API remains gated separately via canUsePartnerRoute().
 */

function ensureGatewayHealthEventsTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS gateway_health_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gateway VARCHAR(24) NOT NULL,
            outcome ENUM('ok','fail') NOT NULL,
            detail VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_gw_time (gateway, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function ensurePhase11RouteDecisionLogTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS phase11_route_decisions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT DEFAULT NULL,
            link_id VARCHAR(64) DEFAULT NULL,
            method_key VARCHAR(32) DEFAULT NULL,
            chosen_partner VARCHAR(24) DEFAULT NULL,
            reason VARCHAR(255) NOT NULL,
            candidates_json TEXT DEFAULT NULL,
            engine_on TINYINT(1) NOT NULL DEFAULT 0,
            outcome ENUM('selected','failover','none','error') NOT NULL DEFAULT 'selected',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_time (created_at),
            INDEX idx_partner (chosen_partner, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
    try {
        getDB()->exec('ALTER TABLE phase11_route_decisions ADD COLUMN txn_id VARCHAR(40) DEFAULT NULL');
    } catch (Throwable $e) { /* ok */ }
    try {
        getDB()->exec('ALTER TABLE phase11_route_decisions ADD INDEX idx_p11_txn (txn_id, created_at)');
    } catch (Throwable $e) { /* ok */ }
}

/** True when Owner enabled Phase 11 Route (same switch as Route/Split live). */
function phase11RouteEngineActive(): bool
{
    if (!function_exists('routeSplitLiveEnabled')) {
        if (is_file(__DIR__ . '/split_settlement.php')) {
            require_once __DIR__ . '/split_settlement.php';
        }
    }
    return function_exists('routeSplitLiveEnabled') && routeSplitLiveEnabled();
}

/** @return list<string> */
function phase11CheckoutPartnerPriority(): array
{
    if (!function_exists('getBankingPartners') && is_file(__DIR__ . '/partners.php')) {
        require_once __DIR__ . '/partners.php';
    }
    if (!function_exists('getCheckoutPgPartnerKeys')) {
        if (is_file(__DIR__ . '/partner_engine.php')) {
            require_once __DIR__ . '/partner_engine.php';
        }
    }
    if (function_exists('getCheckoutPgPartnerKeys')) {
        try {
            $keys = getCheckoutPgPartnerKeys();
            if ($keys !== []) {
                return array_values(array_map(static fn(string $k): string => strtolower(trim($k)), $keys));
            }
        } catch (Throwable $e) {
            /* fall through to default priority */
        }
    }
    return ['razorpay', 'cashfree', 'payu'];
}

function phase11PartnerSupportsCheckoutMethod(string $partnerKey, string $checkoutMethod): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    $checkoutMethod = strtolower(trim($checkoutMethod));
    return match ($partnerKey) {
        'razorpay', 'cashfree' => in_array($checkoutMethod, ['card', 'razorpay', 'cashfree'], true),
        'payu' => in_array($checkoutMethod, ['card', 'payu', 'payu_upi'], true),
        default => false,
    };
}

function phase11MerchantAllowsPartnerCheckout(int $merchantId, string $partnerKey, string $checkoutMethod): bool
{
    if ($merchantId <= 0) {
        return true;
    }
    if (!function_exists('getMerchantEnabledMethods') && is_file(__DIR__ . '/provision.php')) {
        require_once __DIR__ . '/provision.php';
    }
    $enabled = [];
    if (function_exists('getMerchantEnabledMethods')) {
        try {
            $st = getDB()->prepare('SELECT enabled_methods FROM merchants WHERE id=? LIMIT 1');
            $st->execute([$merchantId]);
            $row = $st->fetch();
            if ($row && !empty($row['enabled_methods'])) {
                $decoded = json_decode((string)$row['enabled_methods'], true);
                if (is_array($decoded)) {
                    $enabled = $decoded;
                }
            }
        } catch (Throwable $e) {
            return true;
        }
    }
    if ($enabled === []) {
        return true;
    }
    $partnerKey = strtolower(trim($partnerKey));
    $need = match ($partnerKey) {
        'razorpay' => ['razorpay', 'credit_card', 'debit_card'],
        'cashfree' => ['cashfree', 'credit_card', 'debit_card'],
        'payu' => ['payu_upi', 'credit_card', 'debit_card', 'netbanking', 'wallet', 'emi'],
        default => [],
    };
    foreach ($need as $k) {
        if (in_array($k, $enabled, true)) {
            return true;
        }
    }
    return $checkoutMethod === 'card' && (
        in_array('credit_card', $enabled, true) || in_array('debit_card', $enabled, true)
    );
}

/**
 * Registry partners with keys + merchant method eligibility.
 *
 * @return list<string>
 */
function phase11EligibleCheckoutPartners(int $merchantId, string $checkoutMethod = 'card'): array
{
    $sandbox = true;
    if ($merchantId > 0) {
        try {
            $st = getDB()->prepare('SELECT account_mode FROM merchants WHERE id=? LIMIT 1');
            $st->execute([$merchantId]);
            $sandbox = strtolower((string)$st->fetchColumn()) !== 'live';
        } catch (Throwable $e) {
            $sandbox = true;
        }
    }
    $collectOk = collectEligibleCheckoutPartners($merchantId, $sandbox);
    $eligible = [];
    foreach (phase11CheckoutPartnerPriority() as $partner) {
        if (!in_array($partner, $collectOk, true)) {
            continue;
        }
        if (!function_exists('isGatewayConfigured') || !isGatewayConfigured($partner)) {
            continue;
        }
        if (!phase11PartnerSupportsCheckoutMethod($partner, $checkoutMethod)) {
            continue;
        }
        if (!phase11MerchantAllowsPartnerCheckout($merchantId, $partner, $checkoutMethod)) {
            continue;
        }
        $eligible[] = $partner;
    }
    return $eligible;
}

function collectCheckoutNoneEligibleMessage(): string
{
    return 'No payment partner is ready for this checkout. A partner must be Active, with valid keys (sandbox is OK), a connector, and enable on this merchant.';
}

/**
 * Merchant-side collect gate: coverage enable or already-live Valid. No silent bypass.
 */
function merchantMayCollectViaPartner(int $merchantId, string $partnerKey): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    if ($merchantId < 1 || $partnerKey === '') {
        return false;
    }
    if (!function_exists('getMerchantPartnerLinkRow')) {
        if (is_file(__DIR__ . '/partner_control.php')) {
            require_once __DIR__ . '/partner_control.php';
        }
    }
    $link = function_exists('getMerchantPartnerLinkRow') ? getMerchantPartnerLinkRow($merchantId, $partnerKey) : null;
    if (!$link) {
        return true;
    }
    if ((int)($link['checkout_enabled'] ?? 0) === 1) {
        return true;
    }
    if (function_exists('partnerDocCoverageIsLinkedValid') && partnerDocCoverageIsLinkedValid($link)) {
        return true;
    }
    return false;
}

/**
 * Collect partners: routing Active + keys Valid (sandbox configured OK) + connector + merchant enable.
 *
 * @return list<string>
 */
function collectEligibleCheckoutPartners(int $merchantId, bool $sandbox = true): array
{
    if (!function_exists('getRegisteredGateways') && is_file(__DIR__ . '/payment_methods.php')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    if (!function_exists('partnerAdapterIsWired') && is_file(__DIR__ . '/partner_registry_v2.php')) {
        require_once __DIR__ . '/partner_registry_v2.php';
    }
    if (!function_exists('partnerHasRegistryFlag') && is_file(__DIR__ . '/partner_engine.php')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    if (!function_exists('isGatewayConfigured') && is_file(__DIR__ . '/gateways.php')) {
        require_once __DIR__ . '/gateways.php';
    }
    $priority = phase11CheckoutPartnerPriority();
    $eligible = [];
    foreach (function_exists('getRegisteredGateways') ? getRegisteredGateways(false) : [] as $g) {
        $key = strtolower(trim((string)($g['gateway_key'] ?? '')));
        if ($key === '') {
            continue;
        }
        if ((int)($g['is_active'] ?? 0) !== 1) {
            continue;
        }
        if (function_exists('partnerRegistryRowIsRetired') && partnerRegistryRowIsRetired($g)) {
            continue;
        }
        $collectCapable = (function_exists('partnerHasRegistryFlag') && partnerHasRegistryFlag($key, 'checkout_pg'))
            || (function_exists('gatewaySupportsLiveCheckout') && gatewaySupportsLiveCheckout($key))
            || in_array($key, $priority, true);
        if (!$collectCapable) {
            continue;
        }
        if (function_exists('partnerAdapterIsWired') && !partnerAdapterIsWired($key, $g)) {
            continue;
        }
        $env = $sandbox ? 'test' : 'live';
        $vault = function_exists('partnerCredentialVaultStatus') ? partnerCredentialVaultStatus($key, $env) : 'missing';
        $configured = function_exists('isGatewayConfigured') && isGatewayConfigured($key);
        if ($vault !== 'valid' && !($sandbox && $configured)) {
            continue;
        }
        if (!merchantMayCollectViaPartner($merchantId, $key)) {
            continue;
        }
        $eligible[] = $key;
    }
    usort($eligible, static function (string $a, string $b) use ($priority): int {
        $ia = array_search($a, $priority, true);
        $ib = array_search($b, $priority, true);
        $ia = $ia === false ? 99 : (int)$ia;
        $ib = $ib === false ? 99 : (int)$ib;
        return $ia <=> $ib;
    });
    return array_values(array_unique($eligible));
}

function collectCheckoutPartnerIsEligible(int $merchantId, string $partnerKey, bool $sandbox = true): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    return in_array($partnerKey, collectEligibleCheckoutPartners($merchantId, $sandbox), true);
}

/**
 * Rank partners: healthy first, then registry priority order.
 *
 * @param list<string> $partners
 * @return list<string>
 */
function phase11RankPartnersForRouting(array $partners): array
{
    $priority = phase11CheckoutPartnerPriority();
    $rank = static function (string $p) use ($priority): int {
        $idx = array_search(strtolower($p), $priority, true);
        return $idx === false ? 99 : (int)$idx;
    };
    usort($partners, static function (string $a, string $b) use ($rank): int {
        $ha = isGatewayHealthy($a) ? 0 : 1;
        $hb = isGatewayHealthy($b) ? 0 : 1;
        if ($ha !== $hb) {
            return $ha <=> $hb;
        }
        return $rank($a) <=> $rank($b);
    });
    return array_values($partners);
}

/**
 * @return array{partner:?string,reason:string,candidates:list<string>,ranked:list<string>}
 */
function phase11SelectCheckoutPartner(int $merchantId, string $checkoutMethod = 'card', ?string $preferredTab = null): array
{
    $eligible = phase11EligibleCheckoutPartners($merchantId, $checkoutMethod);
    if ($eligible === []) {
        return [
            'partner' => null,
            'reason' => 'No partner with Registry keys + enabled methods for this checkout.',
            'candidates' => [],
            'ranked' => [],
        ];
    }
    $ranked = phase11RankPartnersForRouting($eligible);
    $preferredTab = $preferredTab !== null ? strtolower(trim($preferredTab)) : '';
    if ($preferredTab !== '' && in_array($preferredTab, $ranked, true)) {
        $healthy = isGatewayHealthy($preferredTab) ? 'healthy' : 'degraded';
        return [
            'partner' => $preferredTab,
            'reason' => 'Merchant tab ' . $preferredTab . ' (' . $healthy . ', priority order)',
            'candidates' => $eligible,
            'ranked' => $ranked,
        ];
    }
    $chosen = $ranked[0];
    $reason = count($ranked) === 1
        ? 'Only partner with keys — ' . $chosen
        : (isGatewayHealthy($chosen)
            ? 'Health + priority — chose ' . $chosen . ' over ' . implode(', ', array_slice($ranked, 1))
            : 'Failover rank — best available ' . $chosen);

    return [
        'partner' => $chosen,
        'reason' => $reason,
        'candidates' => $eligible,
        'ranked' => $ranked,
    ];
}

function phase11LogRouteDecision(
    ?string $chosenPartner,
    string $reason,
    array $candidates,
    string $outcome,
    int $merchantId = 0,
    ?string $linkId = null,
    ?string $methodKey = null
): void {
    ensurePhase11RouteDecisionLogTable();
    try {
        getDB()->prepare(
            'INSERT INTO phase11_route_decisions
             (merchant_id, link_id, method_key, chosen_partner, reason, candidates_json, engine_on, outcome)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $merchantId > 0 ? $merchantId : null,
            $linkId !== null && $linkId !== '' ? mb_substr($linkId, 0, 64) : null,
            $methodKey !== null && $methodKey !== '' ? mb_substr($methodKey, 0, 32) : null,
            $chosenPartner !== null && $chosenPartner !== '' ? mb_substr($chosenPartner, 0, 24) : null,
            mb_substr($reason, 0, 255),
            $candidates !== [] ? json_encode(array_values($candidates), JSON_UNESCAPED_UNICODE) : null,
            phase11RouteEngineActive() ? 1 : 0,
            in_array($outcome, ['selected', 'failover', 'none', 'error'], true) ? $outcome : 'selected',
        ]);
    } catch (Throwable $e) { /* non-fatal */ }
}

/** Map payment_method to Phase 11 checkout partner key for audit correlation. */
function phase11PartnerKeyFromPaymentMethod(string $method): ?string
{
    $method = strtolower(trim($method));
    if (in_array($method, ['razorpay', 'cashfree', 'payu'], true)) {
        return $method;
    }
    if (str_starts_with($method, 'payu')) {
        return 'payu';
    }
    return null;
}

/**
 * After payment success, link TXN id to the latest Phase 11 routing decision for this link + partner.
 */
function attachPhase11RouteDecisionTxnId(?string $linkId, string $partner, string $txnId): bool
{
    $linkId = trim((string)$linkId);
    $txnId = trim($txnId);
    $partner = phase11PartnerKeyFromPaymentMethod($partner) ?? strtolower(trim($partner));
    if ($linkId === '' || $txnId === '' || $partner === '') {
        return false;
    }
    ensurePhase11RouteDecisionLogTable();
    try {
        $find = getDB()->prepare(
            "SELECT id FROM phase11_route_decisions
             WHERE link_id = ? AND chosen_partner = ? AND (txn_id IS NULL OR txn_id = '')
               AND outcome IN ('selected','failover')
             ORDER BY id DESC LIMIT 1"
        );
        $find->execute([$linkId, $partner]);
        $row = $find->fetch();
        if (!$row) {
            return false;
        }
        getDB()->prepare('UPDATE phase11_route_decisions SET txn_id = ? WHERE id = ? AND (txn_id IS NULL OR txn_id = \'\')')
            ->execute([mb_substr($txnId, 0, 40), (int)$row['id']]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Recent honest routing log for Admin (Gateway Settings). */
function getPhase11RouteDecisionLog(int $limit = 15): array
{
    ensurePhase11RouteDecisionLogTable();
    try {
        $st = getDB()->prepare(
            'SELECT id, merchant_id, link_id, txn_id, method_key, chosen_partner, reason, outcome, engine_on, created_at
             FROM phase11_route_decisions ORDER BY id DESC LIMIT ?'
        );
        $st->bindValue(1, max(1, min(50, $limit)), PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function recordGatewayOutcome(string $gateway, bool $ok, ?string $detail = null): void
{
    ensureGatewayHealthEventsTable();
    try {
        getDB()->prepare('INSERT INTO gateway_health_events (gateway, outcome, detail) VALUES (?,?,?)')
            ->execute([$gateway, $ok ? 'ok' : 'fail', $detail ? mb_substr($detail, 0, 255) : null]);
    } catch (Throwable $e) { /* ok */ }
}

/** Unhealthy = 3+ consecutive/recent failures in the last 10 minutes with no success since. */
function isGatewayHealthy(string $gateway): bool
{
    ensureGatewayHealthEventsTable();
    try {
        $st = getDB()->prepare("SELECT outcome FROM gateway_health_events WHERE gateway = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 3");
        $st->execute([$gateway]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) < 3) {
            return true;
        }
        foreach ($rows as $r) {
            if ($r === 'ok') {
                return true;
            }
        }
        return false;
    } catch (Throwable $e) {
        return true;
    }
}

function gatewayHealthSummary(): array
{
    ensureGatewayHealthEventsTable();
    $out = [];
    foreach (phase11CheckoutPartnerPriority() as $gw) {
        $out[$gw] = [
            'configured' => function_exists('isGatewayConfigured') && isGatewayConfigured($gw),
            'healthy' => isGatewayHealthy($gw),
        ];
    }
    return $out;
}

/**
 * Phase 11 checkout order creation — only when route_split_live_enabled=1.
 * When parked (OFF), returns routed_to=null immediately (caller uses fixed partner path).
 */
function createCardOrderWithSmartRouting(float $amount, array $link, string $returnUrl): array
{
    $merchantId = (int)($link['merchant_id'] ?? 0);
    $linkId = (string)($link['link_id'] ?? '');
    $order = ['razorpay' => null, 'cashfree' => null, 'payu' => null, 'routed_to' => null, 'diverted' => false, 'phase11' => false, 'reason' => ''];

    if (!phase11RouteEngineActive()) {
        $order['reason'] = 'Phase 11 parked — fixed partner path (no smart routing).';
        return $order;
    }

    $order['phase11'] = true;
    $selection = phase11SelectCheckoutPartner($merchantId, 'card');
    $ranked = $selection['ranked'];

    if ($selection['partner'] === null || $ranked === []) {
        $order['reason'] = $selection['reason'];
        phase11LogRouteDecision(null, $selection['reason'], [], 'none', $merchantId, $linkId, 'card');
        return $order;
    }

    $cbAvailable = function_exists('isCircuitBreakerAllowed');
    $firstPreferred = $ranked[0];
    $tryOrder = function (string $gw) use ($link, $returnUrl, $cbAvailable) {
        if (!isGatewayConfigured($gw)) {
            return null;
        }
        if ($cbAvailable && !isCircuitBreakerAllowed($gw)) {
            return null;
        }
        try {
            $res = createBoundGatewayCheckoutOrder($link, $gw, $returnUrl);
        } catch (Throwable $e) {
            recordGatewayOutcome($gw, false, $e->getMessage());
            if ($cbAvailable) {
                recordCircuitBreakerFailure($gw);
            }
            return null;
        }
        $ok = is_array($res) && ($gw === 'razorpay' ? !empty($res['id']) : !empty($res['payment_session_id']));
        recordGatewayOutcome($gw, $ok, $ok ? null : 'no_response');
        if ($cbAvailable) {
            if ($ok) {
                recordCircuitBreakerSuccess($gw);
            } else {
                recordCircuitBreakerFailure($gw);
            }
        }
        return $ok ? $res : null;
    };

    $attempted = [];
    foreach ($ranked as $idx => $gw) {
        $attempted[] = $gw;
        $result = $tryOrder($gw);
        if ($result) {
            $order[$gw] = $result;
            $order['routed_to'] = $gw;
            $order['diverted'] = $idx > 0;
            $reason = $idx === 0
                ? $selection['reason']
                : ('Failover to ' . $gw . ' after ' . implode(', ', array_slice($ranked, 0, $idx)) . ' failed');
            $order['reason'] = $reason;
            phase11LogRouteDecision(
                $gw,
                $reason,
                $ranked,
                $idx === 0 ? 'selected' : 'failover',
                $merchantId,
                $linkId,
                'card'
            );
            return $order;
        }
    }

    $failReason = 'All eligible partners failed order create: ' . implode(', ', $attempted);
    $order['reason'] = $failReason;
    phase11LogRouteDecision(null, $failReason, $ranked, 'error', $merchantId, $linkId, 'card');
    return $order;
}
