<?php
declare(strict_types=1);

/**
 * Global search — Phase 6 SRCH (diagram-first).
 * Payment orders (ORD), txn list deep-links, id_click parity.
 */

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function globalSearchWorkflowHealthCheck(): array
{
    $root = dirname(__DIR__);
    $gs = (string)@file_get_contents($root . '/global_search.php');
    $idClick = (string)@file_get_contents($root . '/includes/id_click.php');
    $checks = [
        'payment_orders_merchant' => str_contains($gs, 'FROM payment_orders po') && str_contains($gs, 'Payment Order'),
        'admin_txn_q_url' => str_contains($gs, 'admin_transactions.php?q='),
        'ord_id_click' => str_contains($idClick, "case 'ORD'") && str_contains($idClick, 'admin_transactions.php?q='),
        'merchant_ord_click' => str_contains($idClick, 'transactions.php?q='),
        'order_search_admin' => str_contains((string)@file_get_contents($root . '/admin_transactions.php'), 'payment_orders'),
        'order_search_merchant' => str_contains((string)@file_get_contents($root . '/transactions.php'), 'payment_orders'),
        'payment_order_aliases' => str_contains($gs, "'payment order'"),
        'srch03_ops_aliases' => str_contains($gs, "'migrations'") && str_contains($gs, "'toucanpay'") && str_contains($gs, "'auto kyc'"),
        'srch01_alias_canpage' => str_contains($gs, '$canPage($baseUrl)'),
    ];
    $ok = !in_array(false, $checks, true);
    $failed = array_keys(array_filter($checks, static fn ($v) => !$v));

    return [
        'id' => 'global_search_srch',
        'label' => 'Global search (SRCH-02/06)',
        'ok' => $ok,
        'status' => $ok ? 'ORD · TXN q= · ops aliases (SRCH-02/03)' : 'Fix SRCH — ' . implode(', ', $failed),
        'detail' => 'payment_orders in search · ORD id_click · admin/merchant txn list q=',
        'test_url' => 'global_search.php?q=ORD',
    ];
}
