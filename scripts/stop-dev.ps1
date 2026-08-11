$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$repoRoot = Split-Path -Parent $PSScriptRoot
$serverPidPath = Join-Path $repoRoot '.dev/php-server.pid'
Set-Location $repoRoot

if (Test-Path -LiteralPath $serverPidPath) {
    $serverPid = [int] (Get-Content -LiteralPath $serverPidPath)
    $server = Get-Process -Id $serverPid -ErrorAction SilentlyContinue
    if ($server -and $server.ProcessName -eq 'php') {
        Stop-Process -Id $serverPid
        Write-Output 'Background PHP server stopped.'
    }
    Remove-Item -LiteralPath $serverPidPath -Force
}

& docker compose stop database
if ($LASTEXITCODE -ne 0) {
    throw 'The local PostgreSQL container could not be stopped.'
}

Write-Output 'Local PostgreSQL stopped. Its data volume has been preserved.'
