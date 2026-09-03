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
if (!function_exists('uiCapabilityLegend') && is_file(__DIR__ . '/includes/ui/ui_components.php')) {
    require_once __DIR__ . '/includes/ui/ui_components.php';
    require_once __DIR__ . '/includes/enums/capability_state.php';
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
            'webhook_url' => trim((string)($_POST['webhook_url'] ?? '')),
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
                'kyc_forward_api' => isset($_POST['cap_kyc_forward_api']),
            ],
            'doc_pack' => $_POST['doc_pack'] ?? [],
            'partner_compliance_docs' => $_POST['partner_compliance_docs'] ?? [],
            'supports_payout' => isset($_POST['supports_payout']),
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
                'payout' => $input['supports_payout'] ? 1 : 0,
                'refund' => $input['capabilities']['refund'] ? 1 : 0,
                'recurring' => $input['supports_recurring'] ? 1 : 0,
                'adapter' => $input['adapter_class'] ?: null,
                'webhook_url' => $input['webhook_url'] ?: null,
            ]);
            $result = ['ok' => !empty($result['ok']), 'message' => $result['error'] ?? '', 'gateway_id' => $result['gateway_id'] ?? 0, 'partner_code' => $key];
        }
        if (!empty($result['ok'])) {
            $code = (string)($result['partner_code'] ?? $input['partner_code']);
            flash('success', "Partner '{$code}' registered as INACTIVE. Configure keys on the detail page, then Activate.");
            redirect(adminPartnerDetailUrl($code) . '&tab=profile');
        }
        flash('error', $result['message'] ?? ($result['error'] ?? 'Could not register partner.'));
        redirect('admin_gateway_registry.php');
    }

    if ($action === 'activate') {
        $gatewayId = (int)($_POST['gateway_id'] ?? 0);
        if ($gatewayId > 0) {
            $result = activateGatewayForAllMerchants($gatewayId);
            if ($result['ok'] && function_exists('recordImmutableAudit')) {
                recordImmutableAudit('gateway_activated', null, 'gateway', (string)$gatewayId, 'Gateway activated by admin: ' . ($result['gateway_name'] ?? ''));
            }
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['gateway_name'] . ' activated! Added to ' . $result['merchants'] . ' merchants.' : ($result['error'] ?? 'Error'));
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
}

$gateways = getRegisteredGateways();
$partnerRegistry = getPartnerRegistry();
$registryKindEdu = function_exists('registryKindAdminEducation') ? registryKindAdminEducation() : null;
$registryKindReady = function_exists('registryKindReadinessReport') ? registryKindReadinessReport() : null;
$activeCount = 0;
$inactiveCount = 0;
foreach ($gateways as $g) {
    if ((int)$g['is_active']) $activeCount++;
    else $inactiveCount++;
}
$pageTitle = 'Partner Registry';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-4xl space-y-6">
    <div class="glass rounded-xl p-6 border border-gray-800">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="font-semibold text-lg">Partner Registry — Global Control Room</h2>
                <p class="text-xs text-gray-500 mt-1">Bank and PG <strong class="text-gray-400">tech partners</strong> — keys, methods, activate. Flow: <strong class="text-gray-400">Test keys → Test Connection → Live keys</strong>. Per-merchant checkout methods are toggled on each partner’s <strong class="text-gray-400">Detail → Methods</strong> page (not bulk-edited on this list). Partners do not own merchants; every merchant stays under UniWeb Admin.</p>
                <?php if (function_exists('partnerRegistryV2ControlRoomNote')): ?>
                <p class="text-[11px] text-violet-300/90 mt-2 border border-violet-500/20 rounded-lg px-3 py-2"><?= e(partnerRegistryV2ControlRoomNote()) ?></p>
                <?php endif; ?>
                <?php if (is_array($registryKindEdu)): ?>
                <p class="text-[11px] text-violet-300/90 mt-2"><?= e($registryKindEdu['summary']) ?> UPI/Card/QR live under <strong class="text-gray-400">Payment Methods</strong>, not here.</p>
                <?php endif; ?>
                <?php if (function_exists('uiCapabilityLegend')): ?>
                <div class="mt-3"><?= uiCapabilityLegend() ?></div>
                <?php
                $capCounts = function_exists('platformPartnerCapabilityCounts') ? platformPartnerCapabilityCounts() : null;
                if ($capCounts): ?>
                <p class="text-[10px] text-gray-600 mt-2">Partners: <?= (int)$capCounts['live'] ?> LIVE · <?= (int)$capCounts['stub'] ?> STUB · <?= (int)$capCounts['parked'] ?> PARKED</p>
                <?php endif; ?>
                <?php endif; ?>
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
            </div>
        </div>
    </div>

    <div class="glass rounded-xl overflow-hidden border border-gray-800">
        <div class="px-6 py-4 border-b border-gray-800 flex items-center justify-between gap-4">
            <h3 class="font-semibold">Registered Gateways</h3>
            <div class="flex items-center gap-3">
                <input type="search" id="gateway-filter" placeholder="Filter by name / key…" class="bg-dark-900 border border-gray-700 rounded-lg px-3 py-1.5 text-xs text-gray-300 focus:border-sky-500 focus:outline-none" oninput="filterGateways(this.value)">
                <span class="text-xs text-gray-500"><?= count($gateways) ?> total</span>
            </div>
        </div>
        <?php if (empty($gateways)): ?>
        <div class="p-8 text-center text-sm text-gray-500">No gateways registered yet.</div>
        <?php else: ?>
        <div class="divide-y divide-gray-800">
            <?php foreach ($gateways as $g):
                $partnerInfo = $partnerRegistry[$g['gateway_key']] ?? null;
                $isActive = (int)$g['is_active'] === 1;
                $hasKeys = $partnerInfo && partnerIsConfigured($g['gateway_key']);
                $credStat = getPartnerCredentialStatus($g['gateway_key']);
                $vaultTest = function_exists('partnerCredentialVaultStatus') ? partnerCredentialVaultStatus((string)$g['gateway_key'], 'test') : 'missing';
                $vaultLive = function_exists('partnerCredentialVaultStatus') ? partnerCredentialVaultStatus((string)$g['gateway_key'], 'live') : 'missing';
                $v2Profile = function_exists('partnerRegistryV2ProfileFromRow') ? partnerRegistryV2ProfileFromRow($g) : null;
                $enabledMethods = getEnabledPartnerMethods($g['gateway_key']);
            ?>
            <div class="px-6 py-4 flex items-center justify-between gap-4 hover:bg-white/5 transition-colors" data-gw-name="<?= e(mb_strtolower($g['gateway_name'] . ' ' . $g['gateway_key'])) ?>">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-lg bg-dark-900/80 flex items-center justify-center text-xl flex-shrink-0">
                        <?= e($partnerInfo['icon'] ?? '⚙️') ?>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <a href="<?= e(adminPartnerDetailUrl((string)$g['gateway_key'])) ?>" class="text-sm font-medium text-gray-200 hover:text-sky-300"><?= e($g['gateway_name']) ?></a>
                            <span class="text-[10px] px-2 py-0.5 rounded-full <?= $isActive ? 'bg-emerald-500/20 text-emerald-400' : 'bg-gray-700/50 text-gray-400' ?>">
                                <?= $isActive ? '● Active' : '○ Inactive' ?>
                            </span>
                            <?= function_exists('partnerIntegrationStateBadgeHtml') ? partnerIntegrationStateBadgeHtml((string)$g['gateway_key']) : '' ?>
                            <?php if ($hasKeys): ?>
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-sky-500/10 text-sky-400">Keys Saved</span>
                            <?php elseif ($partnerInfo): ?>
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-400">Awaiting Keys</span>
                            <?php endif; ?>
                            <?php if (function_exists('partnerCredentialVaultStatusBadge')): ?>
                            <span title="Test vault"><?= partnerCredentialVaultStatusBadge($vaultTest) ?></span>
                            <span title="Live vault"><?= partnerCredentialVaultStatusBadge($vaultLive) ?></span>
                            <?php endif; ?>
                            <?php if (is_array($v2Profile)): ?>
                            <span class="text-[10px] px-2 py-0.5 rounded bg-gray-800 text-gray-400"><?= e(strtoupper((string)$v2Profile['partner_type'])) ?></span>
                            <span class="text-[10px] px-2 py-0.5 rounded bg-gray-800 text-gray-400"><?= e(str_replace('_', ' ', (string)$v2Profile['contract_mode'])) ?></span>
                            <?php endif; ?>
                            <?php if ($credStat['test']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-sky-500/10 text-sky-400">Test</span><?php endif; ?>
                            <?php if ($credStat['live']): ?><span class="text-[10px] px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400">Live</span><?php endif; ?>
                            <?php if (!empty($enabledMethods)): ?><span class="text-[10px] px-2 py-0.5 rounded bg-violet-500/10 text-violet-400"><?= count($enabledMethods) ?> methods</span><?php endif; ?>
                        </div>
                        <p class="text-xs text-gray-500 font-mono mt-0.5"><a href="<?= e(adminPartnerDetailUrl((string)$g['gateway_key'])) ?>" class="hover:text-sky-300"><?= e($g['gateway_key']) ?></a></p>
                        <div class="flex gap-1.5 mt-1">
                            <?php if ((int)$g['supports_collection']): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-400">Collection</span><?php endif; ?>
                            <?php if ((int)$g['supports_payout']): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-sky-500/10 text-sky-400">Payout</span><?php endif; ?>
                            <?php if ((int)$g['supports_refund']): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400">Refund</span><?php endif; ?>
                            <?php if ((int)$g['supports_recurring']): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-violet-500/10 text-violet-400">Recurring</span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <a href="<?= e(adminPartnerDetailUrl((string)$g['gateway_key'])) ?>" class="text-xs px-3 py-1.5 rounded-lg bg-dark-900/80 text-gray-300 border border-gray-700 hover:border-gray-500">Configure →</a>
                    <?php if (!$isActive): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="activate">
                        <input type="hidden" name="gateway_id" value="<?= (int)$g['id'] ?>">
                        <button type="submit" class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-600/30" onclick="return confirm('Activate <?= e($g['gateway_name']) ?>? Payment method will be added to all merchants (OFF by default).')">Activate</button>
                    </form>
                    <?php else: ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="deactivate">
                        <input type="hidden" name="gateway_id" value="<?= (int)$g['id'] ?>">
                        <button type="submit" class="text-xs px-3 py-1.5 rounded-lg bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/20" onclick="return confirm('Disable <?= e($g['gateway_name']) ?>? Payment methods from this partner will be hidden from checkout, QR, and payment links. History is retained.')">Disable</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="glass rounded-xl p-6 border border-gray-800">
        <h3 class="font-semibold mb-2">Add partner (online collect only)</h3>
        <p class="text-xs text-gray-500 mb-4">Payment Gateway or other online collect rail — no PPI / offline wallet product. Registered inactive until keys + Activate.</p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="register_gateway">
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm text-gray-400">Partner code *</label>
                    <input type="text" name="gateway_key" required placeholder="e.g. razorpay, payu" class="input-field mt-1 font-mono text-sm" pattern="[a-z0-9_]+">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Display name *</label>
                    <input type="text" name="gateway_name" required placeholder="e.g. Razorpay" class="input-field mt-1">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Partner type</label>
                    <select name="partner_type" class="input-field mt-1 text-sm">
                        <option value="pg">Payment Gateway (PG)</option>
                        <option value="other_online">Other online collect</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-gray-400">Commercial mode</label>
                    <select name="contract_mode" class="input-field mt-1 text-sm">
                        <option value="platform">Platform</option>
                        <option value="linked_existing">Linked existing</option>
                        <option value="hybrid">Hybrid</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-gray-400">Routing priority</label>
                    <input type="number" name="routing_priority" value="50" min="1" max="999" class="input-field mt-1 text-sm">
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
                    <label class="text-sm text-gray-400">Webhook URL (optional)</label>
                    <input type="text" name="webhook_url" placeholder="<?= e(APP_URL) ?>/partner_webhook.php" class="input-field mt-1 font-mono text-xs">
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
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="cap_pay_later" class="rounded border-gray-600"> Pay later (PG method)</label>
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="cap_kyc_forward_api" class="rounded border-gray-600"> KYC forward API</label>
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="supports_payout" class="rounded border-gray-600"> Payout rail</label>
                    <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="supports_recurring" class="rounded border-gray-600"> Recurring</label>
                </div>
            </div>
            <?php if (function_exists('partnerRegistryV2MerchantDocPackCatalog')): ?>
            <div>
                <p class="text-xs text-gray-500 mb-1 font-medium text-gray-400">Merchant KYC doc pack</p>
                <p class="text-[11px] text-gray-600 mb-2">Onboarding documents required for progressive coverage (Phase 3). Uses same codes as merchant KYC.</p>
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
            <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="allows_existing_merchant_link" class="rounded border-gray-600"> Allows existing merchant link (later phase)</label>
            <label class="flex items-center gap-2 text-sm text-gray-300"><input type="checkbox" name="circuit_breaker_on" checked class="rounded border-gray-600"> Circuit breaker ON for outbound calls</label>
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
</script>
<?php require_once __DIR__ . '/footer.php';
