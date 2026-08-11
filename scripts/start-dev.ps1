[CmdletBinding()]
param(
    [ValidateRange(1, 65535)]
    [int]$Port = 8000,
    [switch]$Background
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$repoRoot = Split-Path -Parent $PSScriptRoot
$phpIniPath = Join-Path $repoRoot '.dev/php.ini'
$localConfigPath = Join-Path $repoRoot 'portal-config.local.php'
$serverPidPath = Join-Path $repoRoot '.dev/php-server.pid'

if (!(Test-Path -LiteralPath $phpIniPath) -or !(Test-Path -LiteralPath $localConfigPath)) {
    throw 'Local development is not initialized. Run scripts/setup-dev.ps1 first.'
}

Set-Location $repoRoot
& docker compose up -d database
if ($LASTEXITCODE -ne 0) {
    throw 'The local PostgreSQL container could not be started.'
}

$env:PORTAL_CONFIG_PATH = $localConfigPath
Write-Output "Portal:  http://127.0.0.1:$Port/index.html"
Write-Output 'Login:   admin@local.test / LocalDev123!'

if ($Background) {
    if (Test-Path -LiteralPath $serverPidPath) {
        $existingPid = [int] (Get-Content -LiteralPath $serverPidPath)
        if (Get-Process -Id $existingPid -ErrorAction SilentlyContinue) {
            throw "A background PHP server is already recorded with PID $existingPid."
        }
        Remove-Item -LiteralPath $serverPidPath -Force
    }
    $phpArguments = "-c `"$phpIniPath`" -S 127.0.0.1:$Port -t `"$repoRoot`""
    $server = Start-Process -FilePath (Get-Command php).Source -ArgumentList $phpArguments `
        -WorkingDirectory $repoRoot -WindowStyle Hidden -PassThru
    Set-Content -LiteralPath $serverPidPath -Value $server.Id
    Write-Output "PHP server started in the background with PID $($server.Id)."
    return
}

Write-Output 'Stop the PHP server with Ctrl+C.'
& php -c $phpIniPath -S "127.0.0.1:$Port" -t $repoRoot
