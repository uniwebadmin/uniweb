param(
    [Parameter(Mandatory = $false)]
    [string[]]$Paths = @(),
    [switch]$DryRun,
    [switch]$SkipTests
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

function Get-FtpConfig {
    $sftpPath = Join-Path $Root '.vscode\sftp.json'
    if (Test-Path $sftpPath) {
        $config = Get-Content $sftpPath -Raw | ConvertFrom-Json
        $remoteBase = $config.remotePath.TrimEnd('/').TrimStart('/')
        $remoteBase = $remoteBase -replace '^home/[^/]+/', ''
        return @{
            Host = [string]$config.host
            User = [string]$config.username
            Pass = [string]$config.password
            RemoteBase = $remoteBase
        }
    }
    $hostName = $env:UNIWEB_FTP_HOST
    $user = $env:UNIWEB_FTP_USER
    $pass = $env:UNIWEB_FTP_PASS
    if (-not $hostName -or -not $user -or -not $pass) {
        throw 'FTP credentials missing. Configure .vscode/sftp.json or UNIWEB_FTP_* env vars.'
    }
    return @{ Host = $hostName; User = $user; Pass = $pass; RemoteBase = 'public_html' }
}

Write-Host '== UniWeb allowlisted release =='

if (-not $SkipTests) {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if ($php) {
        & php (Join-Path $Root 'tests\run_integrity_tests.php')
        if ($LASTEXITCODE -ne 0) { throw 'Integrity tests failed — release blocked.' }
    } else {
        Write-Host 'php not in PATH — skipping local integrity tests (remote cron can run them).'
    }
}

if ($Paths.Count -eq 0) {
    $Paths = @(
        'config.php',
        'admin_login.php', 'staff_login.php', 'admin_security.php', 'admin_stepup.php',
        'admin_kyc.php', 'admin_kyc_doc.php', 'kyc.php', 'kyc_media_receiver.php',
        'admin_chargebacks.php', 'chargebacks.php', 'admin_financial_reports.php', 'admin_bank_reconciliation.php',
        'header.php', 'cron_auto_audit.php', 'webhook.php',
        'includes/ops_security.php', 'includes/onboarding_security.php', 'includes/chargebacks.php',
        'includes/kyc_upload.php', 'includes/verification.php', 'includes/baas.php',
        'includes/morning_ops.php', 'includes/bank_reconciliation.php', 'includes/merchant_website.php',
        'includes/public_legal_page.php',
        'migrations/008_onboarding_state_machine.sql', 'migrations/009_admin_mfa_audit_ops.sql',
        'tests/run_integrity_tests.php',
        'uploads/kyc/.htaccess',
        'migrate_release.php'
    )
}

$blocked = $Paths | Where-Object { $_ -match '(^|/)(config\.private\.php|\.env$|db_backup)' }
if ($blocked) { throw "Blocked secret path in release set: $($blocked -join ', ')" }

$ftp = Get-FtpConfig
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$manifest = Join-Path $Root ("release_manifest_$stamp.txt")
$Paths | Set-Content -Path $manifest -Encoding utf8
Write-Host "Manifest: $manifest"

foreach ($rel in $Paths) {
    $local = Join-Path $Root ($rel -replace '/', '\')
    if (-not (Test-Path $local)) {
        Write-Warning "Missing local file: $rel"
        continue
    }
    $remoteRel = ($ftp.RemoteBase.TrimEnd('/') + '/' + ($rel -replace '\\', '/')).TrimStart('/')
    $user = [uri]::EscapeDataString($ftp.User)
    $pass = [uri]::EscapeDataString($ftp.Pass)
    $remote = "ftp://${user}:${pass}@$($ftp.Host)/${remoteRel}"
    Write-Host ("{0} -> {1}" -f $(if ($DryRun) { 'DRY' } else { 'PUT' }), $rel)
    if ($DryRun) { continue }
    $out = & curl.exe -sS --ftp-pasv --ftp-create-dirs --connect-timeout 20 --max-time 120 -T $local $remote -w "%{http_code}:%{size_upload}" 2>&1
    if ("$out" -notmatch '^226:') {
        throw "Upload failed: $rel ($out)"
    }
}

Write-Host 'Release upload complete. Run migrate_release.php?key=CRON_KEY on live, then smoke-test.'
Write-Host 'Rollback: re-upload previous release_manifest_* file set from git tag/backup.'
