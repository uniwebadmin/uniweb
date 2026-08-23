<?php
declare(strict_types=1);

/**
 * Checkout collection mode label — Audit B #9 (diagram-first).
 *
 * Customer checkout must show honest Test/Live stripe + actual collection rail
 * (Direct UPI / Platform checkout / Virtual account) derived from link → method → merchant.
 */

/** @return array{title:string,rule:string,checkout:string,merchant:string,must_not:list<string>} */
function checkoutCollectionAdminEducation(): array
{
    return [
        'title' => 'Checkout mode + collection label — Audit B (#9)',
        'rule' => 'Stripe = Test or Live from link + merchant account_mode. Sub-line = resolveEffectiveCollectionModeKey() — never hardcoded partner pool text.',
        'checkout' => 'checkout.php → renderCheckoutModeAndCollectionBanner($link, allowInstantPay, handler)',
        'merchant' => 'payment_links.php list shows Test/Live · collection rail per link.',
        'must_not' => [
            'Generic "Secure checkout" without collection rail context',
            'Live stripe on test link (or reverse)',
            'Razorpay/Cashfree pool label on checkout',
        ],
    ];
}

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function checkoutCollectionWorkflowHealthCheck(): array
{
    $root = dirname(__DIR__);
    $coll = (string)@file_get_contents($root . '/includes/collection.php');
    $banner = (string)@file_get_contents($root . '/includes/checkout_mode_banner.php');
    $checkout = (string)@file_get_contents($root . '/checkout.php');
    $links = (string)@file_get_contents($root . '/payment_links.php');
    $checks = [
        'resolve_mode' => str_contains($coll, 'function resolveEffectiveCollectionModeKey'),
        'customer_label' => str_contains($coll, 'function checkoutCollectionCustomerLabel'),
        'unified_banner' => str_contains($banner, 'function renderCheckoutModeAndCollectionBanner'),
        'checkout_wired' => str_contains($checkout, 'renderCheckoutModeAndCollectionBanner'),
        'merchant_list' => str_contains($links, 'checkoutLinkModeCollectionSummary'),
        'no_inline_live_banner' => !str_contains($checkout, 'LIVE MODE — Real UPI settlement'),
    ];
    $ok = !in_array(false, $checks, true);
    $failed = array_keys(array_filter($checks, static fn ($v) => !$v));

    return [
        'id' => 'checkout_collection_b9',
        'label' => 'Checkout collection label (B9)',
        'ok' => $ok,
        'status' => $ok ? 'Test/Live stripe + collection rail on checkout' : 'Fix B9 — ' . implode(', ', $failed),
        'detail' => 'resolveEffectiveCollectionModeKey · checkout banner · payment_links summary',
        'test_url' => 'payment_links.php',
    ];
}
