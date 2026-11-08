#Requires -RunAsAdministrator
$ErrorActionPreference = 'Stop'
$hostsPath = Join-Path $env:SystemRoot 'System32/drivers/etc/hosts'
$entries = @('main.rline.test', 'testing.rline.test', 'demo.rline.test')
$lines = @(Get-Content -LiteralPath $hostsPath)
$missing = @()
foreach ($hostname in $entries) {
    $found = $false
    foreach ($line in $lines) {
        $parts = @(($line -split '#', 2)[0].Trim() -split '\s+')
        if ($parts.Count -gt 1 -and $parts[1..($parts.Count - 1)] -contains $hostname) {
            if ($parts[0] -ne '127.0.0.1') { throw "$hostname already points to $($parts[0]); correct that entry first." }
            $found = $true
        }
    }
    if (-not $found) { $missing += "127.0.0.1 $hostname" }
}
if ($missing.Count) {
    $backup = "$hostsPath.rline-$(Get-Date -Format 'yyyyMMdd-HHmmss').bak"
    Copy-Item -LiteralPath $hostsPath -Destination $backup
    Add-Content -LiteralPath $hostsPath -Value (@('') + $missing) -Encoding ascii
    Write-Host "Backup: $backup"
}
& ipconfig /flushdns
if ($LASTEXITCODE -ne 0) { throw 'Could not flush DNS cache.' }
Write-Host 'Ready: main.rline.test, testing.rline.test, demo.rline.test -> 127.0.0.1'
