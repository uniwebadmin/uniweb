<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/owner_portal_hub.php';

if (!ownerPortalHubAllowed()) {
    http_response_code(404);
    $pageTitle = 'Not found';
    $hideNav = true;
    $hideFooter = true;
    require_once __DIR__ . '/header.php';
    echo '<main class="flex-1 flex items-center justify-center p-8"><p class="text-gray-500 text-sm">Page not found.</p></main>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$pageTitle = 'Portal logins';
$hideNav = true;
$hideFooter = true;
$authPortalUi = true;
$bodyClass = trim(($bodyClass ?? '') . ' auth-portal-shell auth-portal--admin');
$footerVariant = 'auth';
$robotsNoIndex = true;

$portals = [
    [
        'href' => 'admin_login.php',
        'label' => 'Owner / Admin',
        'desc' => 'Super Admin control panel',
        'tone' => 'admin',
    ],
    [
        'href' => 'staff_login.php',
        'label' => 'Employee / Staff',
        'desc' => 'Ops, KYC, support, finance staff',
        'tone' => 'staff',
    ],
    [
        'href' => 'login.php',
        'label' => 'Shop / Merchant',
        'desc' => 'Merchant dashboard & payments',
        'tone' => 'merchant',
    ],
    [
        'href' => 'customer_login.php',
        'label' => 'Customer',
        'desc' => 'Pay history & complaints (OTP)',
        'tone' => 'customer',
    ],
];

require_once __DIR__ . '/header.php';
?>
<div class="ap-wrap">
    <div class="ap-panel" style="max-width:520px">
        <div class="ap-card">
            <div class="ap-logo">
                <?php $logoHref = 'index.php'; $logoSize = 'lg'; require __DIR__ . '/includes/brand_logo_safe.php'; ?>
            </div>
            <p class="ap-title">Portal logins</p>
            <p class="ap-sub">Owner-only hub — pick the correct portal. These links are not shown on public login pages.</p>

            <div class="grid gap-3 mt-2">
                <?php foreach ($portals as $p): ?>
                <a href="<?= e($p['href']) ?>" class="owner-portal-card owner-portal-card--<?= e($p['tone']) ?>">
                    <span class="owner-portal-card-label"><?= e($p['label']) ?></span>
                    <span class="owner-portal-card-desc"><?= e($p['desc']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <p class="ap-foot" style="margin-top:1.25rem;font-size:.75rem;opacity:.85">
                Partners (banks / PGs) have no UniWeb login. Keys live in Partner Registry after Owner-Admin sign-in.
            </p>
            <p class="ap-foot" style="margin-top:.35rem;font-size:.75rem">
                <a href="index.php" class="ap-text-link">← Public website</a>
            </p>
        </div>
    </div>
</div>
<style>
.owner-portal-card{display:block;padding:1rem 1.1rem;border-radius:14px;border:1px solid var(--ap-line);text-decoration:none;transition:border-color .15s,box-shadow .15s,transform .1s}
.owner-portal-card:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(0,0,0,.08)}
.owner-portal-card-label{display:block;font-weight:700;font-size:.95rem;color:var(--ap-ink)}
.owner-portal-card-desc{display:block;font-size:.75rem;color:var(--ap-muted);margin-top:.2rem}
.owner-portal-card--admin{border-color:#c4b5fd;background:linear-gradient(135deg,#faf5ff,#fff)}
.owner-portal-card--staff{border-color:#6ee7b7;background:linear-gradient(135deg,#ecfdf5,#fff)}
.owner-portal-card--merchant{border-color:#93c5fd;background:linear-gradient(135deg,#eff6ff,#fff)}
.owner-portal-card--customer{border-color:#7dd3fc;background:linear-gradient(135deg,#f0f9ff,#fff)}
html[data-theme="light"] .owner-portal-card-label{color:#0f172a!important}
html[data-theme="light"] .owner-portal-card-desc{color:#475569!important}
</style>
<?php require_once __DIR__ . '/footer.php'; ?>
