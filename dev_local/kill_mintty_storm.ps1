# Emergency: close runaway Git Bash / mintty windows from Cursor plugin hook storms.
# Safe to run anytime. Does NOT kill Cursor or PowerShell.
$ErrorActionPreference = 'SilentlyContinue'

$targets = @('mintty', 'bash', 'sh')
$killed = 0
foreach ($name in $targets) {
    Get-Process -Name $name -ErrorAction SilentlyContinue | ForEach-Object {
        try {
            Stop-Process -Id $_.Id -Force -ErrorAction Stop
            $killed++
        } catch { }
    }
}

Write-Host "Closed $killed mintty/bash processes. Restart Cursor if it feels stuck."
