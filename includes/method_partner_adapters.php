<?php
declare(strict_types=1);

/**
 * Normalize partner / PG onboarding callbacks into UniWeb method decisions.
 * Keys optional — formats accepted now so live wiring is ready.
 */

/**
 * @param array<string,mixed> $data
 * @return array{partner_ref:string,approved:?bool,note:string,gateway:?string,merchant_hint:?string}
 */
function normalizePartnerMethodWebhookPayload(array $data, string $raw = ''): array
{
    $out = [
        'partner_ref' => '',
        'approved' => null,
        'note' => '',
        'gateway' => null,
        'merchant_hint' => null,
    ];

    // --- UniWeb simple ---
    $out['partner_ref'] = trim((string)($data['partner_ref'] ?? $data['reference'] ?? $data['ref'] ?? $data['submission_ref'] ?? ''));
    $decision = strtolower(trim((string)($data['decision'] ?? $data['status'] ?? $data['result'] ?? '')));
    $out['note'] = trim((string)($data['note'] ?? $data['reason'] ?? $data['message'] ?? ''));
    $gw = trim((string)($data['gateway'] ?? $data['provider'] ?? ''));
    if ($gw !== '') {
        $out['gateway'] = preg_replace('/[^a-z_]/', '', strtolower($gw)) ?: null;
    }
    $out['merchant_hint'] = trim((string)($data['merchant_code'] ?? $data['merchant_id'] ?? $data['account_id'] ?? ''));

    // --- Razorpay-style envelope ---
    $event = strtolower((string)($data['event'] ?? ''));
    if ($event !== '' || isset($data['payload'])) {
        $out['gateway'] = $out['gateway'] ?: 'razorpay';
        $entity = [];
        if (isset($data['payload']) && is_array($data['payload'])) {
            foreach (['account', 'product', 'merchant', 'payment', 'order'] as $k) {
                if (isset($data['payload'][$k]['entity']) && is_array($data['payload'][$k]['entity'])) {
                    $entity = $data['payload'][$k]['entity'];
                    break;
                }
            }
        }
        $notes = is_array($entity['notes'] ?? null) ? $entity['notes'] : [];
        if ($out['partner_ref'] === '') {
            $out['partner_ref'] = trim((string)($notes['partner_ref'] ?? $notes['uniweb_ref'] ?? $notes['method_request_ref'] ?? $entity['id'] ?? ''));
        }
        if ($out['merchant_hint'] === '') {
            $out['merchant_hint'] = trim((string)($notes['merchant_code'] ?? $notes['merchant_id'] ?? $entity['email'] ?? ''));
        }
        if ($out['note'] === '') {
            $out['note'] = $event !== '' ? ('Razorpay event: ' . $event) : '';
        }
        if (str_contains($event, 'activated') || str_contains($event, 'enabled') || str_contains($event, 'approved') || $decision === 'activated') {
            $out['approved'] = true;
        } elseif (str_contains($event, 'rejected') || str_contains($event, 'disabled') || str_contains($event, 'suspended') || str_contains($event, 'failed')) {
            $out['approved'] = false;
        }
    }

    // --- Cashfree-style ---
    if (isset($data['type']) || isset($data['data']['vendor_id']) || isset($data['data']['merchant_vendor_id'])) {
        $out['gateway'] = $out['gateway'] ?: 'cashfree';
        $type = strtolower((string)($data['type'] ?? $data['event'] ?? ''));
        $vendor = is_array($data['data'] ?? null) ? $data['data'] : [];
        if ($out['partner_ref'] === '') {
            $out['partner_ref'] = trim((string)($vendor['partner_ref'] ?? $vendor['vendor_id'] ?? $vendor['merchant_vendor_id'] ?? ''));
        }
        if ($out['merchant_hint'] === '') {
            $out['merchant_hint'] = trim((string)($vendor['email'] ?? $vendor['id'] ?? ''));
        }
        if (str_contains($type, 'success') || str_contains($type, 'active') || str_contains($type, 'verified')) {
            $out['approved'] = true;
        } elseif (str_contains($type, 'reject') || str_contains($type, 'fail') || str_contains($type, 'block')) {
            $out['approved'] = false;
        }
        if ($out['note'] === '') {
            $out['note'] = $type !== '' ? ('Cashfree: ' . $type) : '';
        }
    }

    // --- PayU form / flat ---
    if (isset($data['mihpayid']) || isset($data['txnid']) || isset($data['udf1']) || isset($data['unmappedstatus'])) {
        $out['gateway'] = $out['gateway'] ?: 'payu';
        if ($out['partner_ref'] === '') {
            $out['partner_ref'] = trim((string)($data['udf1'] ?? $data['udf2'] ?? $data['txnid'] ?? $data['mihpayid'] ?? ''));
        }
        $st = strtolower((string)($data['status'] ?? $data['unmappedstatus'] ?? $decision));
        if (in_array($st, ['success', 'captured', 'approved', 'active'], true)) {
            $out['approved'] = true;
        } elseif (in_array($st, ['failure', 'failed', 'rejected', 'bounced'], true)) {
            $out['approved'] = false;
        }
    }

    // --- Generic decision words ---
    if ($out['approved'] === null && $decision !== '') {
        if (in_array($decision, ['approved', 'approve', 'active', 'enabled', 'success', 'ok', 'activated', 'live'], true)) {
            $out['approved'] = true;
        } elseif (in_array($decision, ['rejected', 'reject', 'failed', 'declined', 'inactive', 'disabled', 'suspended'], true)) {
            $out['approved'] = false;
        }
    }

    // GS123 style from anywhere in raw
    if ($out['partner_ref'] === '' && $raw !== '' && preg_match('/\b(GS\d+|MANUAL-[A-Z0-9-]+|MMR-\d+)\b/i', $raw, $m)) {
        $out['partner_ref'] = $m[1];
    }

    return $out;
}

/**
 * Resolve open sent_to_partner request when partner_ref is weak but merchant hint exists.
 */
function resolveMethodRequestIdFromPartnerHint(string $partnerRef, ?string $merchantHint, ?string $gateway): int
{
    ensureMethodRequestSchema();
    if ($partnerRef !== '') {
        $sql = 'SELECT id FROM merchant_method_requests WHERE partner_ref=? AND status="sent_to_partner"';
        $params = [$partnerRef];
        if ($gateway) {
            $sql .= ' AND partner_gateway=?';
            $params[] = $gateway;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $st = getDB()->prepare($sql);
        $st->execute($params);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        if (preg_match('/(?:MMR-|GS)?(\d+)/i', $partnerRef, $m)) {
            $maybe = (int)$m[1];
            $chk = getMethodRequestById($maybe);
            if ($chk && (string)$chk['status'] === 'sent_to_partner') {
                return $maybe;
            }
        }
    }

    $hint = trim((string)$merchantHint);
    if ($hint === '') {
        return 0;
    }
    $merchantId = 0;
    if (ctype_digit($hint)) {
        $merchantId = (int)$hint;
    } else {
        try {
            $st = getDB()->prepare('SELECT id FROM merchants WHERE merchant_code=? OR email=? LIMIT 1');
            $st->execute([$hint, $hint]);
            $merchantId = (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $merchantId = 0;
        }
    }
    if ($merchantId < 1) {
        return 0;
    }
    $sql = 'SELECT id FROM merchant_method_requests WHERE merchant_id=? AND status="sent_to_partner"';
    $params = [$merchantId];
    if ($gateway) {
        $sql .= ' AND partner_gateway=?';
        $params[] = $gateway;
    }
    $sql .= ' ORDER BY id ASC LIMIT 1';
    $st = getDB()->prepare($sql);
    $st->execute($params);
    return (int)($st->fetchColumn() ?: 0);
}

/**
 * Apply a normalized / native partner payload to method requests.
 *
 * @param array<string,mixed> $data
 */
function applyNormalizedPartnerMethodWebhook(array $data, string $raw = '', string $actor = 'partner_webhook'): array
{
    $n = normalizePartnerMethodWebhookPayload($data, $raw);
    if ($n['approved'] === null) {
        return ['ok' => false, 'error' => 'Could not read approve/reject from partner payload.', 'normalized' => $n];
    }
    $reqId = resolveMethodRequestIdFromPartnerHint($n['partner_ref'], $n['merchant_hint'], $n['gateway']);
    if ($reqId < 1) {
        // Fall back to ref-based helper (may still parse MMR-/GS).
        return applyPartnerMethodDecisionByRef(
            $n['partner_ref'] !== '' ? $n['partner_ref'] : 'UNKNOWN',
            (bool)$n['approved'],
            $actor,
            $n['note'],
            $n['gateway']
        );
    }
    return recordMethodRequestPartnerDecision($reqId, (bool)$n['approved'], $actor, $n['note'] !== '' ? $n['note'] : 'Partner native webhook');
}

/**
 * Optional hook from gateway payment webhooks when event looks like onboarding.
 *
 * @param array<string,mixed> $payload
 */
function tryApplyMethodDecisionFromGatewayWebhook(string $gateway, string $event, array $payload): ?array
{
    $event = strtolower($event);
    $keywords = ['account', 'product', 'vendor', 'merchant', 'route', 'activated', 'rejected', 'kyc', 'onboard'];
    $hit = false;
    foreach ($keywords as $kw) {
        if (str_contains($event, $kw)) {
            $hit = true;
            break;
        }
    }
    if (!$hit) {
        return null;
    }
    $payload['event'] = $event;
    $payload['gateway'] = $gateway;
    return applyNormalizedPartnerMethodWebhook($payload, json_encode($payload) ?: '', $gateway . '_webhook');
}
