<?php
declare(strict_types=1);

/**
 * KYC Ops hub — one Owner path: Review → Checker → Forward (tabs on admin_kyc.php).
 */

/** @return list<string> */
function kycOpsValidTabs(): array
{
    return ['review', 'forward', 'tools'];
}

function kycOpsCurrentTab(): string
{
    $tab = strtolower(trim((string)($_GET['tab'] ?? 'review')));
    return in_array($tab, kycOpsValidTabs(), true) ? $tab : 'review';
}

/**
 * @param array<string, scalar|null> $query
 */
function kycOpsUrl(string $tab = 'review', array $query = []): string
{
    $tab = in_array($tab, kycOpsValidTabs(), true) ? $tab : 'review';
    if ($tab !== 'review') {
        $query['tab'] = $tab;
    } else {
        unset($query['tab']);
    }
    $qs = http_build_query(array_filter($query, static fn($v) => $v !== null && $v !== ''));
    return 'admin_kyc.php' . ($qs !== '' ? ('?' . $qs) : '');
}

/** Canonical forward queue URL (hub tab). */
function kycOpsForwardHubUrl(array $query = []): string
{
    return kycOpsUrl('forward', $query);
}

/** Standalone forward page (deep link / bookmark). */
function kycOpsForwardStandaloneUrl(array $query = []): string
{
    $qs = http_build_query(array_filter($query, static fn($v) => $v !== null && $v !== ''));
    return 'admin_forward_queue.php' . ($qs !== '' ? ('?' . $qs) : '');
}

/**
 * Build query string for forward chip links.
 *
 * @param array<string, scalar|null> $overrides
 */
function kycOpsForwardQuery(array $overrides = [], bool $standalone = false): string
{
    $params = [];
    if (!$standalone) {
        $params['tab'] = 'forward';
    }
    foreach (['status', 'view', 'partner', 'q', 'item_id'] as $key) {
        if (array_key_exists($key, $overrides)) {
            $val = $overrides[$key];
            if ($val !== null && $val !== '') {
                $params[$key] = $val;
            }
        } elseif (isset($_GET[$key]) && (string)$_GET[$key] !== '') {
            $params[$key] = $_GET[$key];
        }
    }
    return http_build_query($params);
}

/**
 * Load forward queue view-model for hub or standalone page.
 *
 * @return array<string,mixed>
 */
function kycOpsForwardLoadContext(): array
{
    if (!function_exists('ensurePartnerForwardQueueTable')) {
        require_once __DIR__ . '/partner_forward_queue.php';
    }
    if (!function_exists('holdWindowAdminEducation') && is_file(__DIR__ . '/hold_window_workflow.php')) {
        require_once __DIR__ . '/hold_window_workflow.php';
    }
    if (!function_exists('forwardStagedAdminEducation') && is_file(__DIR__ . '/forward_queue_workflow.php')) {
        require_once __DIR__ . '/forward_queue_workflow.php';
    }

    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $viewFilter = trim((string)($_GET['view'] ?? ''));
    if ($statusFilter === '' && $viewFilter === '') {
        $viewFilter = 'active';
    }
    $partnerFilter = strtolower(trim((string)($_GET['partner'] ?? '')));
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $detailId = (int)($_GET['item_id'] ?? 0);

    return [
        'statusFilter' => $statusFilter,
        'viewFilter' => $viewFilter,
        'partnerFilter' => $partnerFilter,
        'q' => $q,
        'detailId' => $detailId,
        'detailTimeline' => ($detailId > 0 && function_exists('getForwardQueueRowTimeline'))
            ? getForwardQueueRowTimeline($detailId)
            : [],
        'matrix' => getAdminForwardMatrix($statusFilter, $q, $partnerFilter, $viewFilter),
        'fwdStats' => getForwardQueueStats(),
        'adapterRegistry' => getKycForwardAdapterRegistry(),
        'holdWindowEdu' => function_exists('holdWindowAdminEducation') ? holdWindowAdminEducation() : null,
        'forwardStagedEdu' => function_exists('forwardStagedAdminEducation') ? forwardStagedAdminEducation() : null,
        'gwSyncEdu' => function_exists('gatewaySubmitVsForwardQueueEducation') ? gatewaySubmitVsForwardQueueEducation() : null,
    ];
}

/**
 * Handle forward queue POST (requeue / run_now). Returns redirect URL.
 */
function kycOpsForwardHandlePost(bool $standalone = false): ?string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return null;
    }
    $action = (string)($_POST['action'] ?? '');
    if (!in_array($action, ['requeue', 'run_now', 'partner_inbound_log'], true)) {
        return null;
    }
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Session expired. Retry.');
        return $standalone ? kycOpsForwardStandaloneUrl() : kycOpsForwardHubUrl();
    }
    if ($action === 'requeue' && !empty($_POST['item_id'])) {
        $ok = manualRequeueForward((int)$_POST['item_id']);
        flash($ok ? 'success' : 'error', $ok ? 'Item re-queued for processing.' : 'Could not re-queue item.');
    } elseif ($action === 'partner_inbound_log' && !empty($_POST['item_id'])) {
        if (!function_exists('forwardQueueLogPartnerInbound')) {
            require_once __DIR__ . '/partner_forward_queue.php';
        }
        $eventType = (string)($_POST['event_type'] ?? 'need_info');
        $note = trim((string)($_POST['note'] ?? ''));
        $ok = forwardQueueLogPartnerInbound((int)$_POST['item_id'], $eventType, $note, (int)($_SESSION['admin_id'] ?? 0));
        flash($ok ? 'success' : 'error', $ok ? 'Partner message logged — merchant KYC page will show it.' : 'Could not log partner message.');
    } elseif ($action === 'run_now' && isSuperAdmin()) {
        if (function_exists('recordImmutableAudit')) {
            recordImmutableAudit('forward_queue_run_now', 0, 'system', '0', 'Super-admin triggered immediate queue processing');
        }
        if (!function_exists('processPerPartnerForwardQueue')) {
            require_once __DIR__ . '/partner_forward_queue.php';
        }
        $result = processPerPartnerForwardQueue(50);
        flash('success', 'Queue processed: ' . ($result['processed'] ?? 0) . ' items, '
            . ($result['success'] ?? 0) . ' success, '
            . ($result['staged'] ?? 0) . ' staged, '
            . ($result['failed'] ?? 0) . ' failed, '
            . ($result['retry'] ?? 0) . ' retry.');
    }

    $statusFilter = trim((string)($_GET['status'] ?? $_POST['status'] ?? ''));
    $viewFilter = trim((string)($_GET['view'] ?? $_POST['view'] ?? ''));
    $partnerFilter = strtolower(trim((string)($_GET['partner'] ?? $_POST['partner'] ?? '')));
    $q = mb_substr(trim((string)($_GET['q'] ?? $_POST['q'] ?? '')), 0, 100);
    $query = array_filter([
        'status' => $statusFilter !== '' ? $statusFilter : null,
        'view' => $viewFilter !== '' ? $viewFilter : null,
        'partner' => $partnerFilter !== '' ? $partnerFilter : null,
        'q' => $q !== '' ? $q : null,
    ]);

    return $standalone ? kycOpsForwardStandaloneUrl($query) : kycOpsForwardHubUrl($query);
}

/**
 * Tab bar for KYC Ops hub.
 */
function renderKycOpsTabs(string $activeTab): string
{
    $activeTab = in_array($activeTab, kycOpsValidTabs(), true) ? $activeTab : 'review';
    $tabs = [
        'review' => 'Review & checker',
        'forward' => 'Forward queue',
        'tools' => 'Auto KYC & submissions',
    ];
    $fwdStats = [];
    try {
        if (!function_exists('getForwardQueueStats')) {
            require_once __DIR__ . '/partner_forward_queue.php';
        }
        $fwdStats = getForwardQueueStats()['by_status'] ?? [];
    } catch (Throwable $e) {
        $fwdStats = [];
    }
    $fwdBadge = function_exists('getForwardQueueNeedsActionCount')
        ? getForwardQueueNeedsActionCount()
        : (int)($fwdStats['staged'] ?? 0) + (int)($fwdStats['waiting_keys'] ?? 0) + (int)($fwdStats['queued'] ?? 0);

    $html = '<nav class="mb-6 flex flex-wrap gap-2 border-b border-gray-800 pb-3" aria-label="KYC Ops sections">';
    foreach ($tabs as $key => $label) {
        $active = $key === $activeTab;
        $cls = $active
            ? 'bg-brand-600 text-white border-brand-500'
            : 'bg-dark-800 text-gray-400 border-gray-700 hover:text-white hover:border-gray-600';
        $badge = '';
        if ($key === 'forward' && $fwdBadge > 0 && !$active) {
            $badge = ' <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full bg-amber-500/30 text-amber-200">' . $fwdBadge . '</span>';
        }
        $html .= '<a href="' . e(kycOpsUrl($key)) . '" class="px-4 py-2 rounded-lg border text-sm font-medium whitespace-nowrap ' . $cls . '">' . e($label) . $badge . '</a>';
    }
    $html .= '</nav>';
    return $html;
}

/**
 * After checker approve — redirect hint + optional forward enqueue for verified merchants.
 *
 * @return array{tab:string,message:string}
 */
function kycOpsAfterCheckerApprove(string $actionType, int $merchantId): array
{
    $tab = 'review';
    $message = 'Independent checker approval completed.';
    if ($merchantId < 1) {
        return ['tab' => $tab, 'message' => $message];
    }
    if (!function_exists('merchantKycReadinessReport') && is_file(__DIR__ . '/kyc_workflow.php')) {
        require_once __DIR__ . '/kyc_workflow.php';
    }
    if ($actionType === 'kyc_merchant_verify') {
        if (!function_exists('advanceMerchantForwardAfterVerify') && is_file(__DIR__ . '/kyc_workflow.php')) {
            require_once __DIR__ . '/kyc_workflow.php';
        }
        if (function_exists('advanceMerchantForwardAfterVerify')) {
            advanceMerchantForwardAfterVerify($merchantId);
        }
        $tab = 'forward';
        $message = 'KYC verified — new forward rows queued. Open Forward tab (Needs action filter).';
        return ['tab' => $tab, 'message' => $message];
    }
    if ($actionType === 'kyc_document_approve') {
        try {
            $st = getDB()->prepare('SELECT kyc_status FROM merchants WHERE id=? LIMIT 1');
            $st->execute([$merchantId]);
            $kycStatus = strtolower(trim((string)$st->fetchColumn()));
        } catch (Throwable $e) {
            $kycStatus = '';
        }
        if ($kycStatus === 'verified') {
            if (!function_exists('advanceMerchantForwardAfterVerify') && is_file(__DIR__ . '/partner_forward_queue.php')) {
                require_once __DIR__ . '/partner_forward_queue.php';
            }
            if (function_exists('advanceMerchantForwardAfterVerify')) {
                advanceMerchantForwardAfterVerify($merchantId);
            }
            $tab = 'forward';
            $message = 'Document approved — forward queue updated for verified merchant.';
        } elseif (function_exists('merchantKycReadinessReport')) {
            $report = merchantKycReadinessReport($merchantId);
            if (!empty($report['ok'])) {
                $message = 'Document approved — merchant ready for KYC verify. After verify, open Forward queue tab.';
            } else {
                $message = 'Document approved — complete remaining docs/video, then verify KYC.';
            }
        }
    }
    return ['tab' => $tab, 'message' => $message];
}
