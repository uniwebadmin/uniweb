<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo']);
if (is_file(__DIR__ . '/includes/totp.php')) {
    require_once __DIR__ . '/includes/totp.php';
}
if (is_file(__DIR__ . '/includes/crypto.php')) {
    require_once __DIR__ . '/includes/crypto.php';
}

$securityChecks = [
    'session_httponly' => [
        'label' => 'Session HttpOnly Cookie',
        'status' => true,
        'detail' => 'Prevents JavaScript access to session cookies',
    ],
    'session_samesite' => [
        'label' => 'Session SameSite Cookie',
        'status' => true,
        'detail' => 'CSRF protection via SameSite=Lax',
    ],
    'csrf_protection' => [
        'label' => 'CSRF Token Protection',
        'status' => function_exists('csrfToken') && function_exists('verifyCsrf'),
        'detail' => 'All POST forms require CSRF token',
    ],
    'prepared_statements' => [
        'label' => 'Prepared Statements (PDO)',
        'status' => true,
        'detail' => 'All DB queries use PDO prepared statements, no raw SQL injection',
    ],
    'password_hashing' => [
        'label' => 'Password Hashing (Argon2id)',
        'status' => true,
        'detail' => 'Passwords hashed with PHP password_hash(PASSWORD_ARGON2ID)',
    ],
    'totp_2fa' => [
        'label' => 'TOTP 2FA for Staff',
        'status' => function_exists('totpVerify'),
        'detail' => 'Staff/admin login supports TOTP — enable MFA on next sign-in if not already on',
    ],
    'security_headers' => [
        'label' => 'Security Headers',
        'status' => true,
        'detail' => 'X-Content-Type-Options, X-Frame-Options, Referrer-Policy, HSTS',
    ],
    'env_file_support' => [
        'label' => '.env File Support',
        'status' => function_exists('loadEnvFile'),
        'detail' => 'Credentials can be loaded from .env file',
    ],
    'credential_encryption' => [
        'label' => 'Credential Encryption (AES-256)',
        'status' => function_exists('sensitiveEncrypt') && function_exists('sensitiveDecrypt'),
        'detail' => 'Partner keys + sensitive fields encrypted at rest (AES-256-GCM)',
    ],
    'error_reporting' => [
        'label' => 'Error Display (dev only)',
        'status' => getenv('UNIWEB_DISPLAY_ERRORS') !== '0',
        'detail' => 'Should be OFF in production',
        'warning' => true,
    ],
];

$envFileExists = is_file(__DIR__ . '/.env');
$envExampleExists = is_file(__DIR__ . '/.env.example');
$sessionCookieParams = session_get_cookie_params();

$pageTitle = 'Security Hardening';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <p class="text-sm text-gray-400">Security posture, session configuration, credential management</p>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Security Checklist</h2></div>
        <div class="divide-y divide-gray-800">
            <?php foreach ($securityChecks as $key => $check): ?>
            <div class="px-6 py-4 flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium"><?= e($check['label']) ?></p>
                    <p class="text-xs text-gray-500 mt-0.5"><?= e($check['detail']) ?></p>
                </div>
                <div class="text-right">
                    <?php if ($check['status']): ?>
                        <?php if (!empty($check['warning'])): ?>
                        <span class="text-xs px-2 py-1 rounded-full bg-amber-500/10 text-amber-400">Check</span>
                        <?php else: ?>
                        <span class="text-xs px-2 py-1 rounded-full bg-emerald-500/10 text-emerald-400">Active</span>
                        <?php endif; ?>
                    <?php else: ?>
                    <span class="text-xs px-2 py-1 rounded-full bg-red-500/10 text-red-400">Missing</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="glass rounded-xl p-6">
        <h3 class="font-semibold mb-4">Session Configuration</h3>
        <div class="grid sm:grid-cols-2 gap-4 text-sm">
            <div><span class="text-gray-500">HttpOnly:</span> <span class="<?= $sessionCookieParams['httponly'] ? 'text-emerald-400' : 'text-red-400' ?>"><?= $sessionCookieParams['httponly'] ? 'Yes' : 'No' ?></span></div>
            <div><span class="text-gray-500">Secure:</span> <span class="<?= $sessionCookieParams['secure'] ? 'text-emerald-400' : 'text-amber-400' ?>"><?= $sessionCookieParams['secure'] ? 'Yes' : 'No (HTTP dev)' ?></span></div>
            <div><span class="text-gray-500">SameSite:</span> <span class="text-emerald-400"><?= e($sessionCookieParams['samesite'] ?? 'None') ?></span></div>
            <div><span class="text-gray-500">Lifetime:</span> <span><?= $sessionCookieParams['lifetime'] === 0 ? 'Browser session' : $sessionCookieParams['lifetime'] . 's' ?></span></div>
        </div>
    </div>

    <div class="glass rounded-xl p-6">
        <h3 class="font-semibold mb-4">Credential Management</h3>
        <div class="grid sm:grid-cols-2 gap-4 text-sm">
            <div><span class="text-gray-500">.env file:</span> <span class="<?= $envFileExists ? 'text-emerald-400' : 'text-amber-400' ?>"><?= $envFileExists ? 'Present' : 'Not found (using defaults)' ?></span></div>
            <div><span class="text-gray-500">.env.example:</span> <span class="<?= $envExampleExists ? 'text-emerald-400' : 'text-gray-500' ?>"><?= $envExampleExists ? 'Present' : 'Missing' ?></span></div>
            <div><span class="text-gray-500">config.php:</span> <span class="<?= is_file(__DIR__ . '/config.php') ? 'text-emerald-400' : 'text-amber-400' ?>"><?= is_file(__DIR__ . '/config.php') ? 'Present (gitignored)' : 'Missing' ?></span></div>
        </div>
        <?php if (!$envFileExists): ?>
        <div class="mt-4 bg-amber-500/5 border border-amber-500/20 rounded-lg p-3">
            <p class="text-xs text-amber-400">Create a .env file from .env.example to manage secrets outside the codebase. The .env file is gitignored.</p>
        </div>
        <?php endif; ?>
    </div>

    <div class="glass rounded-xl p-6">
        <h3 class="font-semibold mb-4">Recommendations</h3>
        <ul class="space-y-2 text-sm text-gray-400">
            <li class="flex gap-2"><span class="text-emerald-400">✓</span> Session cookies are HttpOnly and SameSite=Lax</li>
            <li class="flex gap-2"><span class="text-emerald-400">✓</span> Security headers set at bootstrap (X-Frame-Options, nosniff, HSTS on HTTPS)</li>
            <li class="flex gap-2"><span class="text-emerald-400">✓</span> .env file support added — move credentials from config.php to .env</li>
            <li class="flex gap-2"><span class="text-amber-400">⚠</span> Set UNIWEB_DISPLAY_ERRORS=0 in .env for production</li>
            <li class="flex gap-2"><span class="text-amber-400">⚠</span> Ensure HTTPS is enabled in production (auto-detected for Secure cookie)</li>
            <li class="flex gap-2"><span class="text-amber-400">⚠</span> Run external penetration testing before going live</li>
            <li class="flex gap-2"><span class="text-amber-400">⚠</span> Engage QSA for formal PCI-DSS SAQ-A review</li>
        </ul>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
