# Permanent fix: disable Cursor plugin hooks that spawn visible Git Bash (mintty) on Windows.
# These plugins fire bash on EVERY agent read/tool/prompt → 200+ windows.
# Run once as Owner. Re-run after Cursor plugin updates if storm returns.
# Restore: dev_local\restore_cursor_bash_hooks.ps1

$ErrorActionPreference = 'Stop'
$pluginRoot = Join-Path $env:USERPROFILE '.cursor\plugins\cache\cursor-public'
if (-not (Test-Path $pluginRoot)) {
    Write-Error "Plugin folder not found: $pluginRoot"
}

# Plugins confirmed in mintty storm (screenshots + hook audit)
$pluginDirs = @(
    'mem0',
    'crowdstrike-falcon-foundry',
    'forge'
)

$disabled = @()
Get-ChildItem -Path $pluginRoot -Directory | ForEach-Object {
    $parent = $_.Name
    foreach ($prefix in $pluginDirs) {
        if ($parent -notlike "$prefix*") { continue }
        $hooksFiles = Get-ChildItem -Path $_.FullName -Recurse -Filter 'hooks.json' -File -ErrorAction SilentlyContinue
        foreach ($hf in $hooksFiles) {
            $off = $hf.FullName + '.disabled'
            if (Test-Path $off) { continue }
            if ($hf.Name -eq 'hooks.json') {
                Rename-Item -LiteralPath $hf.FullName -NewName ($hf.Name + '.disabled') -Force
                $disabled += $hf.FullName
            }
        }
    }
}

Write-Host "Disabled $($disabled.Count) plugin hook file(s):"
$disabled | ForEach-Object { Write-Host "  $_" }
Write-Host ""
Write-Host "Next: close Cursor completely, run kill_mintty_storm.ps1, reopen Cursor."
Write-Host "UniWeb agent work should no longer open hundreds of Git Bash windows."
