<?php
require_once __DIR__ . '/config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/includes/payment_methods.php';
}
if (!function_exists('getPartnerRegistry')) {
    require_once __DIR__ . '/includes/partner_engine.php';
}
if (!function_exists('registryKindAdminEducation') && is_file(__DIR__ . '/includes/registry_kind_workflow.php')) {
    require_once __DIR__ . '/includes/registry_kind_workflow.php';
}
if (!function_exists('ensurePartnerControlTables')) {
    require_once __DIR__ . '/includes/partner_control.php';
}
if (!function_exists('adminPartnerDetailUrl')) {
    require_once __DIR__ . '/includes/ui_links.php';
}
if (!function_exists('partnerRegistryV2ControlRoomNote') && is_file(__DIR__ . '/includes/partner_registry_v2.php')) {
    require_once __DIR__ . '/includes/partner_registry_v2.php';
}
requireStaffAccess(['super', 'ceo', 'ops']);

syncPartnerGateways();
ensurePartnerControlTables();
if (function_exists('ensurePartnerRegistryV2Columns')) {
    ensurePartnerRegistryV2Columns();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'register_gateway') {
        $input = [
            'partner_code' => trim((string)($_POST['gateway_key'] ?? '')),
            'display_name' => trim((string)($_POST['gateway_name'] ?? '')),
            'partner_type' => (string)($_POST['partner_type'] ?? 'pg'),
            'contract_mode' => (string)($_POST['contract_mode'] ?? 'platform'),
            'allows_existing_merchant_link' => isset($_POST['allows_existing_merchant_link']),
            'adapter_class' => trim((string)($_POST['adapter_class'] ?? '')),
            'connector_id' => trim((string)($_POST['connector_id'] ?? '')),
            'logo_url' => trim((string)($_POST['logo_url'] ?? '')),
            'webhook_url' => trim((string)($_POST['webhook_url'] ?? '')),
            'api_base_url' => trim((string)($_POST['api_base_url'] ?? '')),
            'countries' => (string)($_POST['countries'] ?? ''),
            'currencies' => (string)($_POST['currencies'] ?? ''),
            'support_email' => trim((string)($_POST['support_email'] ?? '')),
            'support_phone' => trim((string)($_POST['support_phone'] ?? '')),
            'platform_fee_pct_default' => (string)($_POST['platform_fee_pct_default'] ?? ''),
            'platform_fee_flat_default' => (string)($_POST['platform_fee_flat_default'] ?? ''),
            'weight_success' => (string)($_POST['weight_success'] ?? ''),
            'settle_note' => trim((string)($_POST['settle_note'] ?? '')),
            'policy_urls' => [
                'terms' => (string)($_POST['policy_terms'] ?? ''),
                'privacy' => (string)($_POST['policy_privacy'] ?? ''),
                'refund' => (string)($_POST['policy_refund'] ?? ''),
                'support' => (string)($_POST['policy_support'] ?? ''),
            ],
            'routing_priority' => (int)($_POST['routing_priority'] ?? 50),
            'circuit_breaker_on' => isset($_POST['circuit_breaker_on']),
            'connector_notes' => trim((string)($_POST['connector_notes'] ?? '')),
            'capabilities' => [
                'collect' => isset($_POST['cap_collect']) || isset($_POST['supports_collection']),
                'upi' => isset($_POST['cap_upi']),
                'card' => isset($_POST['cap_card']),
                'netbanking' => isset($_POST['cap_netbanking']),
                'refund' => isset($_POST['cap_refund']) || isset($_POST['supports_refund']),
                'pay_later' => isset($_POST['cap_pay_later']),
                'qr' => isset($_POST['cap_qr']),
                'payout' => isset($_POST['cap_payout']),
                'kyc_forward_api' => isset($_POST['cap_kyc_forward_api']),
            ],
            'channels' => [
                'checkout_hosted' => isset($_POST['channel_checkout_hosted']),
                'status_webhook' => isset($_POST['channel_status_webhook']),
                'document_forward' => isset($_POST['channel_document_forward']),
                'notifications' => isset($_POST['channel_notifications']),
                'support_forward' => isset($_POST['channel_support_forward']),
                'dispute_forward' => isset($_POST['channel_dispute_forward']),
                'refunds' => isset($_POST['channel_refunds']),
            ],
            'doc_pack' => $_POST['doc_pack'] ?? [],
            'optional_doc_pack' => $_POST['optional_doc_pack'] ?? [],
            'partner_compliance_docs' => $_POST['partner_compliance_docs'] ?? [],
            'supports_payout' => false,
            'supports_recurring' => isset($_POST['supports_recurring']),
        ];
        if (function_exists('registerPartnerRegistryV2')) {
            $result = registerPartnerRegistryV2($input, (int)($_SESSION['admin_id'] ?? 0));
        } else {
            $key = (string)$input['partner_code'];
            $name = (string)$input['display_name'];
            if ($key === '' || $name === '') {
                flash('error', 'Gateway key and name are required.');
                redirect('admin_gateway_registry.php');
            }
            $result = registerGateway($key, $name, [
                'collection' => $input['capabilities']['collect'] ? 1 : 0,
                'payout' => 0,
                'refund' => $input['capabilities']['refund'] ? 1 : 0,
                'recurring' => $input['supports_recurring'] ? 1 : 0,
                'adapter' => $input['adapter_class'] ?: null,
                'webhook_url' => $input['webhook_url'] ?: null,
            ]);
            $result = ['ok' => !empty($result['ok']), 'message' => $result['error'] ?? '', 'gateway_id' => $result['gateway_id'] ?? 0, 'partner_code' => $key];
        }
        if (!empty($result['ok'])) {
            $code = (string)($result['partner_code'] ?? $input['partner_code']);
            flash('success', "Partner '{$code}' registered. Visible below as Inactive — paste keys, then use Activate for routing when ready.");
            redirect('admin_gateway_registry.php?status=all&highlight=' . rawurlencode($code));
        }
        flash('error', $result['message'] ?? ($result['error'] ?? 'Could not register partner.'));
        redirect('admin_gateway_registry.php');
    }

    if ($action === 'activate') {
        $gatewayId = (int)($_POST['gateway_id'] ?? 0);
        if ($gatewayId > 0) {
            $target = null;
            foreach (getRegisteredGateways() as $g) {
                if ((int)$g['id'] === $gatewayId) {
                    $target = $g;
                    break;
                }
            }
            $gate = function_exists('partnerRegistryActivateGate') && is_array($target)
                ? partnerRegistryActivateGate($target)
                : ['allowed' => true, 'reason' => '', 'warn_keys' => false];
            if (empty($gate['allowed'])) {
                flash('error', (string)($gate['reason'] ?? 'Not wired (adapter missing)') . '. Routing ON is not available until a connector file exists.');
            } else {
                $result = activateGatewayForAllMerchants($gatewayId);
                if ($result['ok'] && function_exists('recordImmutableAudit')) {
                    recordImmutableAudit('gateway_activated', null, 'gateway', (string)$gatewayId, 'Gateway activated by admin: ' . ($result['gateway_name'] ?? ''));
                }
                if ($result['ok'] && !empty($gate['warn_keys'])) {
                    flash('warning', ($result['gateway_name'] ?? 'Partner') . ' is Active (routing ON), but keys are missing. Paste keys on Keys tab before treating this as ready.');
                } else {
                    flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['gateway_name'] . ' activated (routing ON) for ' . $result['merchants'] . ' merchants.' : ($result['error'] ?? 'Error'));
                }
            }
        }
        redirect('admin_gateway_registry.php');
    }

    if ($action === 'deactivate') {
        $gatewayId = (int)($_POST['gateway_id'] ?? 0);
        if ($gatewayId > 0) {
            $gwName = '';
            foreach (getRegisteredGateways() as $g) {
                if ((int)$g['id'] === $gatewayId) { $gwName = $g['gateway_name']; break; }
            }
            $result = deactivateGateway($gatewayId);
            if ($result['ok'] && function_exists('recordImmutableAudit')) {
                recordImmutableAudit('gateway_deactivated', null, 'gateway', (string)$gatewayId, 'Gateway deactivated by admin: ' . $gwName . ' — methods hidden from checkout/QR/links');
            }
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $gwName . ' deactivated. Payment methods from this partner are now hidden from checkout, QR, and payment links.' : ($result['error'] ?? 'Error'));
        }
        redirect('admin_gateway_registry.php');
    }

    if ($action === 'retire' && function_exists('retirePartnerRegistryRow')) {
        $gatewayId = (int)($_POST['gateway_id'] ?? 0);
        $confirmCode = strtolower(trim((string)($_POST['confirm_code'] ?? '')));
        $result = retirePartnerRegistryRow($gatewayId, [
            'confirm_code' => $confirmCode,
            'admin_id' => (int)($_SESSION['admin_id'] ?? 0),
            'admin_email' => (string)($_SESSION['staff_email'] ?? $_SESSION['admin_email'] ?? 'admin'),
        ]);
        flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
            ? ((string)($result['gateway_name'] ?? 'Partner') . ' retired. Hidden from default list and routing.')
            : (string)($result['error'] ?? 'Could not retire.'));
        redirect('admin_gateway_registry.php' . (!empty($result['ok']) ? '?status=retired' : ''));
    }
}

$includeRetired = true;
$allGateways = getRegisteredGateways($includeRetired);
$partnerRegistry = getPartnerRegistry();
$registryKindEdu = function_exists('registryKindAdminEducation') ? registryKindAdminEducation() : null;
$registryKindReady = function_exists('registryKindReadinessReport') ? registryKindReadinessReport() : null;
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'active', 'inactive', 'retired'], true)) {
    $statusFilter = 'all';
}
$highlightKey = strtolower(trim((string)($_GET['highlight'] ?? '')));
$activeCount = 0;
$inactiveCount = 0;
$retiredCount = 0;
$gateways = [];
foreach ($allGateways as $g) {
    $isRetired = function_exists('partnerRegistryRowIsRetired') && partnerRegistryRowIsRetired($g);
    if ($isRetired) {
        $retiredCount++;
    } elseif ((int)$g['is_active']) {
        $activeCount++;
        $gateways[] = $g;
    } else {
        $inactiveCount++;
        $gateways[] = $g;
    }
}
$filteredGateways = [];
$sourceRows = $statusFilter === 'retired' ? $allGateways : $gateways;
foreach ($sourceRows as $g) {
    $isRetired = function_exists('partnerRegistryRowIsRetired') && partnerRegistryRowIsRetired($g);
    $isActiveRow = (int)$g['is_active'] === 1 && !$isRetired;
    if ($statusFilter === 'retired' && !$isRetired) {
        continue;
    }
    if ($statusFilter === 'all' && $isRetired) {
        continue;
    }
    if ($statusFilter === 'active' && !$isActiveRow) {
        continue;
    }
    if ($statusFilter === 'inactive' && ($isActiveRow || $isRetired)) {
        continue;
    }
    $filteredGateways[] = $g;
}
$routeCounts = function_exists('partnerRegistryRouteReadinessCounts') ? partnerRegistryRouteReadinessCounts($filteredGateways) : ['platform_ready' => 0, 'linked_ready' => 0, 'blocked' => 0, 'hybrid_ready' => 0];
$pageTitle = 'Partner Registry';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-4xl space-y-6">
    <div class="glass rounded-xl p-6 border border-gray-800">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="font-semibold text-lg">Partner Registry — Global Control Room</h2>
                <p class="text-xs text-gray-500 mt-1">Inactive / Active · Keys Missing / Valid / Invalid · Not wired. Test keys → Test Connection → Live keys → Activate for routing. Partners do not own merchants.</p>
            </div>
            <div class="flex gap-3">
                <div class="text-center">
                    <p class="text-2xl font-bold text-emerald-400"><?= $activeCount ?></p>
                    <p class="text-[10px] text-gray-500 uppercase">Active</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-gray-400"><?= $inactiveCount ?></p>
                    <p class="text-[10px] text-gray-500 uppercase">Inactive</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-slate-400"><?= $retiredCount ?></p>
                    <p class="text-[10px] text-gray-500 uppercase">Retired</p>
                </div>
            </div>
        </div>
        <div class="grid sm:grid-cols-4 gap-3 mt-4">
            <div class="rounded-lg border border-emerald-500/20 bg-emerald-500/5 p-3">
                <p class="text-[10px] uppercase tracking-wide text-emerald-300/80">Platform ready</p>
                <p class="text-2xl font-bold text-emerald-300 mt-1"><?= (int)($routeCounts['platform_ready'] ?? 0) ?></p>
            </div>
            <div class="rounded-lg border border-sky-500/20 bg-sky-500/5 p-3">
                <p class="text-[10px] uppercase tracking-wide text-sky-300/80">Linked ready</p>
                <p class="text-2xl font-bold text-sky-300 mt-1"><?= (int)($routeCounts['linked_ready'] ?? 0) ?></p>
            </div>
            <div class="rounded-lg border border-violet-500/20 bg-violet-500/5 p-3">
                <p class="text-[10px] uppercase tracking-wide text-violet-300/80">Hybrid ready</p>
                <p class="text-2xl font-bold text-violet-300 mt-1"><?= (int)($routeCounts['hybrid_ready'] ?? 0) ?></p>
            </div>
            <div class="rounded-lg border border-amber-500/20 bg-amber-500/5 p-3">
                <p class="text-[10px] uppercase tracking-wide text-amber-300/80">Blocked</p>
                <p class="text-2xl font-bold text-amber-300 mt-1"><?= (int)($routeCounts['blocked'] ?? 0) ?></p>
            </div>
        </div>
    </div>

    <div class="glass rounded-xl overflow-hidden border border-gray-800">
        <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
            <h3 class="font-semibold">Registered partners</h3>
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex gap-1 text-[11px]">
                    <?php foreach (['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'retired' => 'Retired'] as $sf => $sfLabel): ?>
                    <a href="admin_gateway_registry.php?status=<?= e($sf) ?><?= $highlightKey !== '' ? '&highlight=' . rawurlencode($highlightKey) : '' ?>" class="px-2.5 py-1 rounded-lg border <?= $statusFilter === $sf ? 'border-sky-500/50 bg-sky-500/15 text-sky-300' : 'border-gray-700 text-gray-500 hover:text-gray-300' ?>"><?= e($sfLabel) ?></a>
                    <?php endforeach; ?>
                </div>
                <input type="search" id="gateway-filter" placeholder="Filter by name / key…" class="bg-dark-900 border border-gray-700 rounded-lg px-3 py-1.5 text-xs text-gray-300 focus:border-sky-500 focus:outline-none" oninput="filterGateways(this.value)">
                <span class="text-xs text-gray-500"><?= count($filteredGateways) ?> shown · <?= $activeCount + $inactiveCount ?> in default list<?= $retiredCount > 0 ? ' · ' . $retiredCount . ' retired' : '' ?></span>
            </div>
        </div>
        <?php if (empty($filteredGateways)): ?>
        <div class="p-8 text-center text-sm text-gray-500">No partners in this filter. Try <a href="admin_gateway_registry.php?status=all" class="text-sky-400 hover:underline">All</a> — Inactive partners are included by default.</div>
        <?php else: ?>
        <div class="divide-y divide-gray-800">
            <?php foreach ($filteredGateways as $g):
                $partnerInfo = function_exists('resolvePartnerAdminMeta')
                    ? resolvePartnerAdminMeta((string)$g['gateway_key'], $g)
                    : ($partnerRegistry[$g['gateway_key']] ?? null);
                $isActive = (int)$g['is_active'] === 1;
                $vaultTest = function_exists('partnerCredentialVaultStatus') ? partnerCredentialVaultStatus((string)$g['gateway_key'], 'test') : 'missing';
                $vaultLive = function_exists('partnerCredentialVaultStatus') ? partnerCredentialVaultStatus((string)$g['gateway_key'], 'live') : 'missing';
                $adapterWired = function_exists('partnerAdapterIsWired') ? partnerAdapterIsWired((string)$g['gateway_key'], $g) : true;
                $activateGate = function_exists('partnerRegistryActivateGate') ? partnerRegistryActivateGate($g) : ['allowed' => true, 'reason' => '', 'warn_keys' => false];
                $v2Profile = function_exists('partnerRegistryV2ProfileFromRow') ? partnerRegistryV2ProfileFromRow($g) : null;
                $routeReadiness = function_exists('partnerRegistryRouteReadinessSummary') ? partnerRegistryRouteReadinessSummary($g) : ['platform_ready' => false, 'linked_ready' => false, 'summary' => ''];
                $enabledMethods = getEnabledPartnerMethods($g['gateway_key']);
                $isHighlight = $highlightKey !== '' && strtolower((string)$g['gateway_key']) === $highlightKey;
            ?>
            <div class="px-6 py-4 flex flex-col sm:flex-row sm:items-start justify-between gap-4 hover:bg-white/5 transition-colors <?= $isHighlight ? 'bg-sky-500/10 ring-1 ring-sky-500/40' : '' ?>" data-gw-name="<?= e(mb_strtolower($g['gateway_name'] . ' ' . $g['gateway_key'])) ?>" data-gw-status="<?= $isActive ? 'active' : 'inactive' ?>" <?= $isHighlight ? 'id="highlighted-partner"' : '' ?>>
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-lg bg-dark-900/80 flex items-center justify-center text-xl flex-shrink-0">
                        <?= e($partnerInfo['icon'] ?? '⚙️') ?>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <a href="<?= e(adminPartnerDetailUrl((string)$g['gateway_key'])) ?>" class="text-sm font-medium text-gray-200 hover:text-sky-300"><?= e($g['gateway_name']) ?></a>
                            <?= function_exists('partnerRegistryListStatusBadge') ? partnerRegistryListStatusBadge($g) : '<span class="text-[10px] px-2 py-0.5 rounded-full ' . ($isActive ? 'bg-emerald-500/20 text-emerald-400' : 'bg-gray-700/50 text-gray-400') . '">' . ($isActive ? 'Active' : 'Inactive') . '</span>' ?>
                            <?php if (function_exists('partnerRegistryLifecycleStatusBadge')): ?>
                            <?= partnerRegistryLifecycleStatusBadge($g) ?>
                            <?php endif; ?>
                            <?php if (function_exists('partnerRegistryV2ProfileIsComplete')): ?>
                            <?php $profileComplete = partnerRegistryV2ProfileIsComplete($g); ?>
                            <span class="text-[10px] px-2 py-0.5 rounded border <?= $profileComplete ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-amber-500/30 bg-amber-500/10 text-amber-300' ?>" title="Required workbook identity and onboarding fields"><?= $profileComplete ? 'Profile complete' : 'Profile incomplete' ?></span>
                            <?php endif; ?>
                            <?php if (!$adapterWired && function_exists('partnerRegistryNotWiredBadgeHtml')): ?>
                            <?= partnerRegistryNotWiredBadgeHtml() ?>
                            <?php endif; ?>
                            <?php if (function_exists('partnerCredentialVaultStatusBadge')): ?>
                            <span title="Test keys"><?= partnerCredentialVaultStatusBadge($vaultTest) ?></span>
                            <span title="Production keys"><?= partnerCredentialVaultStatusBadge($vaultLive) ?></span>
                            <?php endif; ?>
                            <?php if (is_array($v2Profile)): ?>
                            <span class="text-[10px] px-2 py-0.5 rounded bg-gray-800 text-gray-400"><?= e(strtoupper((string)$v2Profile['partner_type'])) ?></span>
                            <span class="text-[10px] px-2 py-0.5 rounded bg-gray-800 text-gray-400"><?= e(str_replace('_', ' ', (string)$v2Profile['contract_mode'])) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($enabledMethods)): ?><span class="text-[10px] px-2 py-0.5 rounded bg-violet-500/10 text-violet-400"><?= count($enabledMethods) ?> methods</span><?php endif; ?>
                        </div>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            <span class="text-[9px] px-2 py-1 rounded-full <?= !empty($routeReadiness['platform_ready']) ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/20' : 'bg-amber-500/10 text-amber-300 border border-amber-500/20' ?>">Platform: <?= !empty($routeReadiness['platform_ready']) ? 'ready' : 'blocked' ?></span>
                            <span class="text-[9px] px-2 py-1 rounded-full <?= !empty($routeReadiness['linked_ready']) ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/20' : 'bg-amber-500/10 text-amber-300 border border-amber-500/20' ?>">Linked existing: <?= !empty($routeReadiness['linked_ready']) ? 'ready' : 'blocked' ?></span>
                        </div>
                        <p class="text-xs text-gray-500 font-mono mt-0.5"><a href="<?= e(adminPartnerDetailUrl((string)$g['gateway_key'])) ?>" class="hover:text-sky-300"><?= e($g['gateway_key']) ?></a></p>
                        <?php if (!empty($routeReadiness['summary'])): ?>
                        <p class="text-[10px] text-gray-400 mt-1"><?= e($routeReadiness['summary']) ?></p>
                        <?php endif; ?>
                        <div class="flex gap-1.5 mt-1">
                            <?php if ((int)$g['supports_collection']): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-400">Collection</span><?php endif; ?>
                            <?php if ((int)$g['supports_payout']): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-sky-500/10 text-sky-400">Payout</span><?php endif; ?>
                            <?php if (!empty($g['cap_payout'])): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-gray-700/60 text-gray-300" title="Registry metadata only; no payout route or execution is enabled">Payout metadata only</span><?php endif; ?>
                            <?php if ((int)$g['supports_refund']): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400">Refund</span><?php endif; ?>
                            <?php if ((int)$g['supports_recurring']): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-violet-500/10 text-violet-400">Recurring</span><?php endif; ?>
                            <?php if (is_array($v2Profile)): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-gray-800 text-gray-400">Priority <?= (int)$v2Profile['routing_priority'] ?> · metadata</span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col items-stretch sm:items-end gap-2 w-full sm:w-auto min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                    <a href="<?= e(adminPartnerDetailUrl((string)$g['gateway_key'])) ?>" class="text-xs px-3 py-1.5 rounded-lg bg-dark-900/80 text-gray-300 border border-gray-700 hover:border-gray-500">Configure →</a>
                    <?php if (!$isActive): ?>
                    <?php if (empty($activateGate['allowed'])): ?>
                    <span class="text-xs px-3 py-1.5 rounded-lg bg-gray-800 text-gray-500 border border-gray-700 cursor-not-allowed" title="<?= e((string)($activateGate['reason'] ?? 'Not wired (adapter missing)')) ?>">Activate — Not available</span>
                    <?php else: ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="activate">
                        <input type="hidden" name="gateway_id" value="<?= (int)$g['id'] ?>">
                        <button type="submit" class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-600/30" title="Turns routing ON. Partner is already visible as Inactive." onclick="return confirm('<?= !empty($activateGate['warn_keys']) ? 'Keys are missing. Activate for routing anyway? Partner stays listed. Paste keys next.' : 'Activate ' . e($g['gateway_name']) . ' for routing? Methods are added to merchants (OFF by default). Partner is already listed as Inactive.' ?>')">Activate for routing</button>
                    </form>
                    <?php endif; ?>
                    <?php else: ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="deactivate">
                        <input type="hidden" name="gateway_id" value="<?= (int)$g['id'] ?>">
                        <button type="submit" class="text-xs px-3 py-1.5 rounded-lg bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/20" onclick="return confirm('Turn OFF routing for <?= e($g['gateway_name']) ?>? Methods hide from checkout/QR/links. Partner stays in this list as Inactive.')">Turn OFF routing</button>
                    </form>
                    <?php endif; ?>
                    </div>
                    <?php
                    $rowRetired = function_exists('partnerRegistryRowIsRetired') && partnerRegistryRowIsRetired($g);
                    $rowBuiltin = function_exists('partnerRegistryIsProtectedFromRetire')
                        ? partnerRegistryIsProtectedFromRetire((string)$g['gateway_key'])
                        : (function_exists('isPartnerRegistryKey') && isPartnerRegistryKey((string)$g['gateway_key']));
                    $retireBlockers = (!$rowBuiltin && !$isActive && !$rowRetired && function_exists('partnerRegistryRetireBlockers'))
                        ? partnerRegistryRetireBlockers((string)$g['gateway_key']) : [];
                    ?>
                    <?php if ($rowRetired): ?>
                    <p class="text-[11px] text-gray-500">Already retired.</p>
                    <?php elseif ($rowBuiltin): ?>
                    <p class="text-[11px] text-gray-500">Retire not available — built-in partner. Use Turn OFF routing only.</p>
                    <?php elseif ($isActive): ?>
                    <p class="text-[11px] text-amber-300/90">Retire not available until Turn OFF routing.</p>
                    <?php elseif ($retireBlockers !== []): ?>
                    <p class="text-[11px] text-amber-300/90">Retire blocked: <?= e(implode('; ', $retireBlockers)) ?></p>
                    <?php else: ?>
                    <form method="POST" class="w-full space-y-2 border border-red-500/25 rounded-lg p-3 bg-red-500/5">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="retire">
                        <input type="hidden" name="gateway_id" value="<?= (int)$g['id'] ?>">
                        <label class="block text-[11px] text-gray-400">Type partner code <span class="font-mono text-gray-200"><?= e($g['gateway_key']) ?></span> then Retire</label>
                        <input type="text" name="confirm_code" required placeholder="<?= e($g['gateway_key']) ?>" autocomplete="off" inputmode="text" class="w-full bg-dark-900 border border-red-500/40 rounded-lg px-3 py-2.5 text-sm font-mono text-gray-200">
                        <button type="submit" class="w-full text-sm px-3 py-2.5 rounded-lg bg-red-500/15 text-red-300 border border-red-500/40">Retire partner</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-2">Add partner (online collection)</h3>
        <p class="text-xs text-gray-500 mb-4">Payment Gateway, partner-hosted BNPL, or other online collection rail — no PPI / offline wallet product. Complete the required connector, UniWeb webhook path, country, and merchant KYC doc pack. New partners remain <strong class="text-gray-300">Draft / Inactive</strong> until separately certified and activated.</p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="register_gateway">
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm text-gray-400">Partner code *</label>
                    <input type="text" name="gateway_key" required maxlength="40" placeholder="e.g. razorpay, payu" class="input-field mt-1 font-mono text-sm" pattern="[a-z0-9_]+">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Display name *</label>
                    <input type="text" name="gateway_name" required maxlength="80" placeholder="e.g. Razorpay" class="input-field mt-1">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Partner type</label>
                    <select name="partner_type" class="input-field mt-1 text-sm">
                        <option value="pg">Payment Gateway (PG)</option>
                        <option value="bnpl">BNPL provider (partner-hosted)</option>
                        <option value="other_online">Other online collect</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-gray-400">Account operating mode</label>
                    <select name="contract_mode" class="input-field mt-1 text-sm">
                        <option value="platform">Platform</option>
                        <option value="linked_existing">Linked existing</option>
                        <option value="hybrid">Hybrid</option>
                    </select>
                    <p class="text-[10px] text-gray-500 mt-1">Platform: new partner onboarding. Linked existing: use the merchant's existing partner account only. Hybrid: both routes.</p>
                </div>
                <div>
                    <label class="text-sm text-gray-400">Connector notes</label>
                    <input type="text" name="connector_notes" placeholder="Adapter / webhook path hint" class="input-field mt-1 text-sm">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Adapter class (optional)</label>
                    <input type="text" name="adapter_class" placeholder="includes/gateways/..." class="input-field mt-1 font-mono text-xs">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Connector ID</label>
                    <input type="text" name="connector_id" required maxlength="80" placeholder="razorpay_v1" class="input-field mt-1 font-mono text-xs">
                </div>
                <div>
                    <label class="text-sm text-gray-400">UniWeb webhook endpoint *</label>
                    <input type="text" name="webhook_url" required value="<?= e(rtrim(APP_URL, '/')) ?>/partner_webhook.php" placeholder="/partner_webhook.php" class="input-field mt-1 font-mono text-xs">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Partner API base URL</label>
                    <input type="url" name="api_base_url" placeholder="https://api.partner.example" class="input-field mt-1 font-mono text-xs">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Countries (ISO codes)</label>
                    <input type="text" name="countries" required placeholder="IN" class="input-field mt-1 text-sm">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Currencies (ISO 4217 alpha-3 format)</label>
                    <input type="text" name="currencies" placeholder="INR, AED" class="input-field mt-1 text-sm">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Partner logo URL (HTTPS)</label>
                    <input type="url" name="logo_url" placeholder="https://partner.example/logo.svg" class="input-field mt-1 text-sm">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Partner support email</label>
                    <input type="email" name="support_email" class="input-field mt-1 text-sm">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Partner support phone</label>
                    <input type="tel" name="support_phone" class="input-field mt-1 text-sm">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Default platform fee (%)</label>
                    <input type="number" name="platform_fee_pct_default" min="0" max="100" step="0.0001" value="0" class="input-field mt-1 text-sm">
                    <p class="text-[10px] text-gray-500 mt-1">Stored metadata only; does not change checkout fees.</p>
                </div>
                <div>
                    <label class="text-sm text-gray-400">Default platform flat fee (partner currency)</label>
                    <input type="number" name="platform_fee_flat_default" min="0" step="0.01" value="0" class="input-field mt-1 text-sm">
                    <p class="text-[10px] text-gray-500 mt-1">Stored metadata only; does not change checkout fees.</p>
                </div>
                <div>
                    <label class="text-sm text-gray-400">Success weight (0–100)</label>
                    <input type="number" name="weight_success" min="0" max="100" step="0.0001" value="0" class="input-field mt-1 text-sm">
                    <p class="text-[10px] text-gray-500 mt-1">Stored only; smart-routing use is not enabled yet.</p>
                </div>
                <div>
                    <label class="text-sm text-gray-400">Routing priority</label>
                    <input type="number" name="routing_priority" min="1" max="999" step="1" value="50" class="input-field mt-1 text-sm">
                    <p class="text-[10px] text-gray-500 mt-1">Stored for routing work; does not change live traffic yet.</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="text-sm text-gray-400">Settlement note (internal)</label>
                    <input type="text" name="settle_note" maxlength="500" class="input-field mt-1 text-sm">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Merchant terms URL (HTTPS)</label>
                    <input type="url" name="policy_terms" class="input-field mt-1 text-sm">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Privacy policy URL (HTTPS)</label>
                    <input type="url" name="policy_privacy" class="input-field mt-1 text-sm">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Refund policy URL (HTTPS)</label>
                    <input type="url" name="policy_refund" class="input-field mt-1 text-sm">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Support policy URL (HTTPS)</label>
                    <input type="url" name="policy_support" class="input-field mt-1 text-sm">
                </div>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-2">Capabilities (method flags — not a separate wallet product)</p>
                <div class="flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="cap_collect" checked class="rounded border-gray-600"> Collect</label>
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="cap_upi" class="rounded border-gray-600"> UPI</label>
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="cap_card" class="rounded border-gray-600"> Card</label>
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="cap_netbanking" class="rounded border-gray-600"> Net banking</label>
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="cap_refund" class="rounded border-gray-600"> Refund</label>
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="cap_pay_later" class="rounded border-gray-600"> Pay later (partner-hosted)</label>
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="cap_qr" class="rounded border-gray-600"> QR</label>
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="cap_kyc_forward_api" class="rounded border-gray-600"> KYC forward API</label>
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="cap_payout" class="rounded border-gray-600"> Payout capability metadata (does not enable payouts)</label>
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="supports_recurring" class="rounded border-gray-600"> Recurring</label>
                </div>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-2">Global platform channels</p>
                <div class="flex flex-wrap gap-4">
                    <?php foreach (['checkout_hosted' => 'Hosted checkout', 'status_webhook' => 'Status webhook', 'document_forward' => 'Document forwarding', 'notifications' => 'Notifications', 'support_forward' => 'Support forwarding', 'dispute_forward' => 'Dispute forwarding', 'refunds' => 'Refunds'] as $channelKey => $channelLabel): ?>
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="channel_<?= e($channelKey) ?>" class="rounded border-gray-600"> <?= e($channelLabel) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (function_exists('partnerRegistryV2MerchantDocPackCatalog')): ?>
            <div>
                <p class="text-xs text-gray-500 mb-1 font-medium text-gray-400">Merchant KYC doc pack</p>
                <p class="text-[11px] text-gray-500 mb-2">Choose at least one required document. Optional documents are stored separately. Merchant KYC UI filters to the merchant’s entity type.</p>
                <div class="flex flex-wrap gap-3 max-h-36 overflow-y-auto">
                    <?php foreach (partnerRegistryV2MerchantDocPackCatalog() as $code => $label): ?>
                    <label class="flex items-center gap-1.5 text-xs text-gray-400" title="<?= e($label) ?>">
                        <input type="checkbox" name="doc_pack[]" value="<?= e($code) ?>" class="rounded border-gray-600">
                        <span class="font-mono text-gray-300"><?= e($code) ?></span>
                        <span class="text-gray-600 hidden sm:inline">— <?= e($label) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-1 font-medium text-gray-400">Optional merchant KYC documents</p>
                <div class="flex flex-wrap gap-3 max-h-36 overflow-y-auto">
                    <?php foreach (partnerRegistryV2MerchantDocPackCatalog() as $code => $label): ?>
                    <label class="flex items-center gap-1.5 text-xs text-gray-400" title="<?= e($label) ?>">
                        <input type="checkbox" name="optional_doc_pack[]" value="<?= e($code) ?>" class="rounded border-gray-600">
                        <span class="font-mono text-gray-300"><?= e($code) ?></span>
                        <span class="text-gray-600 hidden sm:inline">— <?= e($label) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (function_exists('partnerRegistryV2ComplianceDocCatalog')): ?>
            <div>
                <p class="text-xs text-gray-500 mb-1 font-medium text-gray-400">Partner compliance docs (optional)</p>
                <p class="text-[11px] text-gray-600 mb-2">PG agreements / PCI / SOC2 — separate from merchant KYC coverage.</p>
                <div class="flex flex-wrap gap-3">
                    <?php foreach (partnerRegistryV2ComplianceDocCatalog() as $code => $label): ?>
                    <label class="flex items-center gap-1.5 text-xs text-gray-400" title="<?= e($label) ?>">
                        <input type="checkbox" name="partner_compliance_docs[]" value="<?= e($code) ?>" class="rounded border-gray-600">
                        <span class="font-mono text-gray-300"><?= e($code) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="checkbox" name="allows_existing_merchant_link" class="rounded border-gray-600">
                Allow already-live merchant link (merchant has their own account on this partner)
            </label>
            <label class="flex items-center gap-2 text-xs text-gray-300">
                <input type="checkbox" name="circuit_breaker_on" checked class="rounded border-gray-600">
                Registry circuit-breaker metadata enabled (does not control outbound calls)
            </label>
            <p class="text-xs text-gray-500">Pause outbound calls using <a href="admin_circuit_breaker.php" class="text-sky-400 hover:underline">Circuit Breaker</a>.</p>
            <button type="submit" class="btn-primary px-6 py-2.5">Register partner</button>
        </form>
    </div>
</div>
<script>
function filterGateways(q){
    q=(q||'').toLowerCase().trim();
    document.querySelectorAll('[data-gw-name]').forEach(function(el){
        var name=el.getAttribute('data-gw-name')||'';
        el.style.display=(!q||name.indexOf(q)>-1)?'':'none';
    });
}
var hl=document.getElementById('highlighted-partner');
if(hl){ try{ hl.scrollIntoView({behavior:'smooth',block:'center'}); }catch(e){} }
</script>
<?php require_once __DIR__ . '/footer.php';
