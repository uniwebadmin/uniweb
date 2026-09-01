# Restore plugin hooks if you re-enable Mem0 / CrowdStrike Foundry / Forge later.
$pluginRoot = Join-Path $env:USERPROFILE '.cursor\plugins\cache\cursor-public'
$restored = 0
Get-ChildItem -Path $pluginRoot -Recurse -Filter 'hooks.json.disabled' -File -ErrorAction SilentlyContinue | ForEach-Object {
    $target = $_.FullName -replace '\.disabled$', ''
    if (-not (Test-Path $target)) {
        Rename-Item -LiteralPath $_.FullName -NewName 'hooks.json' -Force
        $restored++
    }
}
Write-Host "Restored $restored hook file(s). Restart Cursor."
