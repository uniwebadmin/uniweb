<?php
declare(strict_types=1);

/**
 * Universal clickable IDs — resolve UniWeb entity codes to detail URLs.
 * Used by PHP helpers (uwIdLink) and id_go.php / assets/js/id-clickable.js.
 */

if (!function_exists('uwIdClickAudience')) {
    function uwIdClickAudience(): string
    {
        if (function_exists('isCustomerLoggedIn') && isCustomerLoggedIn()) {
            return 'customer';
        }
        if (function_exists('isLoggedIn') && isLoggedIn()) {
            return 'merchant';
        }
        if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()) {
            return 'admin';
        }
        return 'public';
    }

    /** Prefix patterns (generateId = PREFIX + 12 hex). Merchant codes = UW + 8 hex. */
    function uwIdClickPatterns(): array
    {
        return [
            'TXN' => '/^TXN[A-F0-9]{8,}$/i',
            'LNK' => '/^LNK[A-F0-9]{8,}$/i',
            'CT' => '/^CT[A-F0-9]{8,}$/i',
            'TKT' => '/^TKT[A-F0-9]{8,}$/i',
            'RFD' => '/^RFD[A-F0-9]{8,}$/i',
            'DSP' => '/^DSP[A-F0-9]{8,}$/i',
            'STL' => '/^STL[A-F0-9]{8,}$/i',
            'BAT' => '/^BAT[A-F0-9]{8,}$/i',
            'INV' => '/^INV[A-F0-9]{8,}$/i',
            'PACK' => '/^PACK[A-F0-9]{8,}$/i',
            'PWL' => '/^PWL[A-F0-9]{8,}$/i',
            'ORD' => '/^ORD[A-F0-9]{8,}$/i',
            'UW' => '/^UW[A-F0-9]{6,12}$/i',
        ];
    }

    function uwDetectIdKind(string $id): ?string
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        foreach (uwIdClickPatterns() as $kind => $re) {
            if (preg_match($re, $id)) {
                return $kind;
            }
        }
        return null;
    }

    /**
     * Resolve an ID string to an in-app URL for the current audience.
     * Returns null when unknown / not allowed for this role.
     */
    function uwResolveIdUrl(string $id): ?string
    {
        $id = trim($id);
        $kind = uwDetectIdKind($id);
        if ($kind === null) {
            return null;
        }
        $audience = uwIdClickAudience();
        $enc = rawurlencode($id);

        switch ($kind) {
            case 'TXN':
                if ($audience === 'public') {
                    return null;
                }
                if ($audience === 'customer') {
                    return 'customer_portal.php#txns';
                }
                if ($audience === 'merchant') {
                    return 'transactions.php?q=' . $enc;
                }
                return function_exists('wiringDeepLinkTxnListUrl')
                    ? wiringDeepLinkTxnListUrl($id, true)
                    : ('admin_transactions.php?q=' . $enc);

            case 'LNK':
                return 'checkout.php?link=' . $enc;

            case 'CT':
                if ($audience === 'customer') {
                    return 'customer_ticket.php?id=' . $enc;
                }
                if ($audience === 'admin') {
                    $dbId = uwLookupCustomerTicketDbId($id);
                    return $dbId ? ('admin_customer_tickets.php?id=' . $dbId) : ('admin_customer_tickets.php?q=' . $enc);
                }
                if ($audience === 'merchant') {
                    $dbId = uwLookupMerchantCustomerTicketDbId($id);
                    return $dbId ? ('merchant_customer_tickets.php?id=' . $dbId) : ('merchant_customer_tickets.php?q=' . $enc);
                }
                return 'customer_login.php';

            case 'TKT':
                if ($audience === 'admin') {
                    return 'admin_support.php?q=' . $enc;
                }
                if ($audience === 'merchant') {
                    return 'support_ticket.php?id=' . $enc;
                }
                return null;

            case 'RFD':
                if ($audience === 'admin') {
                    return 'admin_refunds.php?q=' . $enc;
                }
                if ($audience === 'merchant') {
                    return 'refunds.php?q=' . $enc;
                }
                return null;

            case 'DSP':
                if ($audience === 'admin') {
                    return 'admin_disputes.php?q=' . $enc;
                }
                if ($audience === 'merchant') {
                    return 'disputes.php?q=' . $enc;
                }
                return null;

            case 'STL':
            case 'PWL':
                if ($audience === 'public' || $audience === 'customer') {
                    return null;
                }
                return 'settlement_detail.php?id=' . $enc;

            case 'BAT':
                if ($audience === 'admin') {
                    return 'admin_settlement_batches.php?q=' . $enc;
                }
                return null;

            case 'INV':
                if ($audience === 'merchant' || $audience === 'admin') {
                    return 'invoice_view.php?id=' . $enc;
                }
                return null;

            case 'PACK':
                if ($audience === 'merchant') {
                    return 'merchant_payment_pack.php?q=' . $enc;
                }
                if ($audience === 'admin') {
                    return 'admin_transactions.php?q=' . $enc;
                }
                return null;

            case 'ORD':
                if ($audience === 'admin') {
                    return 'admin_transactions.php?q=' . $enc;
                }
                if ($audience === 'merchant') {
                    return 'transactions.php?q=' . $enc;
                }
                return null;

            case 'UW':
                if ($audience === 'admin') {
                    $mid = uwLookupMerchantIdByCode($id);
                    return $mid ? ('admin_view_merchant.php?id=' . $mid) : ('manage_merchant.php?q=' . $enc);
                }
                if ($audience === 'merchant') {
                    return 'dashboard.php';
                }
                return null;
        }

        return null;
    }

    function uwLookupCustomerTicketDbId(string $ticketId): ?int
    {
        try {
            if (function_exists('ensureCustomerPortalSchema')) {
                ensureCustomerPortalSchema();
            }
            $st = getDB()->prepare('SELECT id FROM customer_tickets WHERE ticket_id = ? LIMIT 1');
            $st->execute([$ticketId]);
            $id = $st->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    function uwLookupMerchantCustomerTicketDbId(string $ticketId): ?int
    {
        try {
            if (!function_exists('isLoggedIn') || !isLoggedIn() || !function_exists('getMerchant')) {
                return null;
            }
            $m = getMerchant();
            if (!$m) {
                return null;
            }
            $st = getDB()->prepare('SELECT id FROM customer_tickets WHERE ticket_id = ? AND merchant_id = ? LIMIT 1');
            $st->execute([$ticketId, (int)$m['id']]);
            $id = $st->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    function uwLookupMerchantIdByCode(string $code): ?int
    {
        try {
            $st = getDB()->prepare('SELECT id FROM merchants WHERE merchant_code = ? LIMIT 1');
            $st->execute([$code]);
            $id = $st->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Render a clickable ID (falls back to escaped plain text if not resolvable). */
    function uwIdLink(string $id, ?string $label = null, string $class = 'uw-id-link text-sky-400 hover:underline'): string
    {
        $id = trim($id);
        $label = $label ?? $id;
        if ($id === '') {
            return e($label);
        }
        $url = uwResolveIdUrl($id);
        if ($url === null) {
            // Still emit data attribute so JS hub can retry via id_go.php when logged in
            return '<span class="font-mono uw-id-plain" data-uw-id="' . e($id) . '">' . e($label) . '</span>';
        }
        return '<a href="' . e($url) . '" class="font-mono ' . e($class) . '" data-uw-id="' . e($id) . '">' . e($label) . '</a>';
    }

    /** Hub URL for JS (always works after login). */
    function uwIdGoUrl(string $id): string
    {
        return 'id_go.php?id=' . rawurlencode(trim($id));
    }
}
