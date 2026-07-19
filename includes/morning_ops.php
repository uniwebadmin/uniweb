<?php
declare(strict_types=1);

/** Automated morning / ops fixes — run from admin dashboard or cron */

function runMorningPlatformOps(): array
{
    $results = [];

    try {
        $demo = ensureDemoMerchant();
        $demoRow = getDB()->prepare('SELECT * FROM merchants WHERE email = ?');
        $demoRow->execute(['demo@uniweb.co.in']);
        $demoM = $demoRow->fetch();
        $results[] = [
            'id' => 'demo_merchant',
            'ok' => $demoM && isMerchantLive($demoM),
            'label' => 'Demo merchant ready',
            'detail' => $demoM
                ? ($demoM['merchant_code'] ?? '') . ' · Test/Live toggle enabled'
                : 'demo@uniweb.co.in missing',
        ];
    } catch (Throwable $e) {
        $results[] = ['id' => 'demo_merchant', 'ok' => false, 'label' => 'Demo merchant', 'detail' => $e->getMessage()];
    }

    try {
        $fixed = fixVerifiedMerchantsNotLive();
        $results[] = [
            'id' => 'verified_live',
            'ok' => true,
            'label' => 'KYC verified → Live mode',
            'detail' => $fixed > 0 ? "Activated $fixed merchant(s)" : 'All verified merchants already live',
        ];
    } catch (Throwable $e) {
        $results[] = ['id' => 'verified_live', 'ok' => false, 'label' => 'Verified merchants', 'detail' => $e->getMessage()];
    }

    try {
        $db = getDB();
        $pending = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE kyc_status IN ('pending','submitted') AND status='active'")->fetchColumn();
        $recent = $db->query("SELECT id, merchant_code, business_name, email, business_entity_type, created_at
            FROM merchants WHERE status != 'deleted' ORDER BY created_at DESC LIMIT 5")->fetchAll();
        $results[] = [
            'id' => 'pending_kyc',
            'ok' => $pending === 0,
            'optional' => true,
            'label' => 'Pending KYC queue',
            'detail' => $pending === 0 ? 'Queue empty' : "$pending merchant(s) need review in admin_kyc.php",
            'meta' => $recent,
        ];
    } catch (Throwable $e) {
        $results[] = ['id' => 'pending_kyc', 'ok' => false, 'label' => 'KYC queue', 'detail' => $e->getMessage()];
    }

    if (function_exists('runFullLinkWatchdog')) {
        try {
            $scan = runFullLinkWatchdog(false);
            $issues = (int)$scan['summary']['broken_links'] + (int)$scan['summary']['missing_files'] + (int)$scan['summary']['syntax_fail'];
            $results[] = [
                'id' => 'link_scan',
                'ok' => $issues === 0,
                'optional' => true,
                'label' => 'Link watchdog (files)',
                'detail' => $issues === 0 ? 'No broken internal links' : "$issues issue(s) — open Link Watchdog",
            ];
            $_SESSION['watchdog_quick_scan'] = $scan;
        } catch (Throwable $e) {
            $results[] = ['id' => 'link_scan', 'ok' => false, 'label' => 'Link scan', 'detail' => $e->getMessage()];
        }
    }

    if (function_exists('countUnresolvedPlatformErrors')) {
        $errN = countUnresolvedPlatformErrors();
        $results[] = [
            'id' => 'errors',
            'ok' => $errN === 0,
            'optional' => true,
            'label' => 'Platform error log',
            'detail' => $errN === 0 ? 'No unresolved errors' : "$errN error(s) in Error Log",
        ];
    }

    if (function_exists('platformHealthSummary')) {
        try {
            $health = platformHealthSummary();
            $failing = array_filter($health['services'], static fn($s) => empty($s['ok']));
            $optionalIds = ['axis', 'decentro', 'whatsapp', 'otp', 'settlement_cron'];
            $criticalFail = array_filter($failing, static fn($s) => !in_array($s['id'] ?? '', $optionalIds, true));
            $results[] = [
                'id' => 'platform_health',
                'ok' => count($criticalFail) === 0,
                'optional' => true,
                'label' => 'Platform health',
                'detail' => $health['pct'] . '% · ' . count($criticalFail) . ' critical · ' . count($failing) . ' total optional/config',
                'meta' => array_values($failing),
            ];
        } catch (Throwable $e) {
            $results[] = ['id' => 'platform_health', 'ok' => false, 'label' => 'Platform health', 'detail' => $e->getMessage()];
        }
    }

    $failed = count(array_filter($results, static fn($r) => empty($r['ok']) && empty($r['optional'])));
    return ['results' => $results, 'failed' => $failed, 'ok' => $failed === 0, 'ran_at' => date('Y-m-d H:i:s')];
}

/** Merchants approved KYC but still account_mode=test — enables Test/Live toggle */
function fixVerifiedMerchantsNotLive(): int
{
    $db = getDB();
    $rows = $db->query("SELECT id FROM merchants WHERE kyc_status='verified' AND account_mode='test' AND status='active'")->fetchAll();
    $n = 0;
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $db->prepare("UPDATE merchants SET account_mode='live' WHERE id=?")->execute([$id]);
        createNotification($id, 'Account Live!', 'Your account is now in Live mode. You can switch Test/Live from the dashboard header.');
        $n++;
    }
    return $n;
}

function getRecentSignupQueue(int $limit = 15): array
{
    $db = getDB();
    $stmt = $db->prepare("SELECT id, merchant_code, business_name, name, email, phone, business_entity_type,
        kyc_status, account_mode, status, created_at
        FROM merchants WHERE status != 'deleted' ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

/** Merchants waiting for admin KYC verify — Individual / Freelancer first */
function getPendingKycQueue(int $limit = 15): array
{
    $db = getDB();
    $stmt = $db->prepare("SELECT id, merchant_code, business_name, name, email, phone, business_entity_type,
        kyc_status, account_mode, status, created_at
        FROM merchants
        WHERE status != 'deleted' AND kyc_status IN ('pending','submitted')
        ORDER BY FIELD(COALESCE(business_entity_type,''), 'individual','freelancer','sole_proprietor','partnership','private_limited','public_limited','llp','other'),
        created_at ASC
        LIMIT ?");
    $stmt->execute([max(1, min(50, $limit))]);
    return $stmt->fetchAll();
}
