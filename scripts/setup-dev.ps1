[CmdletBinding()]
param(
    [switch]$SkipSeed
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$repoRoot = Split-Path -Parent $PSScriptRoot
$devPath = Join-Path $repoRoot '.dev'
$localConfigPath = Join-Path $repoRoot 'portal-config.local.php'
$localConfigExamplePath = Join-Path $repoRoot 'portal-config.local.php.example'
$phpIniPath = Join-Path $devPath 'php.ini'

Set-Location $repoRoot

$phpCommand = Get-Command php -ErrorAction Stop
$phpPath = $phpCommand.Source
$phpRoot = Split-Path -Parent $phpPath
$phpIniTemplate = Join-Path $phpRoot 'php.ini-development'
if (!(Test-Path -LiteralPath $phpIniTemplate)) {
    throw "PHP's php.ini-development template was not found beside $phpPath."
}

New-Item -ItemType Directory -Path $devPath -Force | Out-Null
$phpIni = Get-Content -LiteralPath $phpIniTemplate -Raw
$extensionDirectory = (Join-Path $phpRoot 'ext').Replace('\', '/')
$phpIni = [regex]::Replace($phpIni, '(?m)^;?extension_dir\s*=.*$', "extension_dir = `"$extensionDirectory`"")
foreach ($extension in @('fileinfo', 'gd', 'pdo_pgsql', 'pgsql', 'zip')) {
    $phpIni = [regex]::Replace($phpIni, "(?m)^;extension=$extension\s*$", "extension=$extension")
}
$phpIni = [regex]::Replace($phpIni, '(?m)^display_errors\s*=.*$', 'display_errors = On')
Set-Content -LiteralPath $phpIniPath -Value $phpIni -Encoding UTF8

$enabledModules = & $phpPath -c $phpIniPath -m
if ($LASTEXITCODE -ne 0) {
    throw 'PHP could not load the local development configuration.'
}
foreach ($module in @('fileinfo', 'gd', 'pdo_pgsql', 'pgsql', 'zip')) {
    if ($enabledModules -notcontains $module) {
        throw "Required PHP extension '$module' is not available."
    }
}

if (!(Test-Path -LiteralPath $localConfigPath)) {
    Copy-Item -LiteralPath $localConfigExamplePath -Destination $localConfigPath
    Write-Output 'Created portal-config.local.php from the local example.'
}

Get-Command docker -ErrorAction Stop | Out-Null
& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw 'Docker Desktop is not running. Start Docker Desktop and run this script again.'
}

& docker compose up -d database
if ($LASTEXITCODE -ne 0) {
    throw 'The local PostgreSQL container could not be started.'
}

$databaseReady = $false
for ($attempt = 1; $attempt -le 30; $attempt++) {
    & docker compose exec -T database psql -U portal -d partner_portal -c 'SELECT 1' *> $null
    if ($LASTEXITCODE -eq 0) {
        $databaseReady = $true
        break
    }
    Start-Sleep -Seconds 2
}
if (!$databaseReady) {
    throw 'PostgreSQL did not become ready in time.'
}

Get-Content -LiteralPath (Join-Path $repoRoot 'database/schema.sql') -Raw |
    & docker compose exec -T database psql -v ON_ERROR_STOP=1 -U portal -d partner_portal
if ($LASTEXITCODE -ne 0) {
    throw 'The local database schema could not be applied.'
}

if (!$SkipSeed) {
    Get-Content -LiteralPath (Join-Path $repoRoot 'database/seed-dev.sql') -Raw |
        & docker compose exec -T database psql -v ON_ERROR_STOP=1 -U portal -d partner_portal
    if ($LASTEXITCODE -ne 0) {
        throw 'The local sample data could not be applied.'
    }
}

$previousConfigPath = $env:PORTAL_CONFIG_PATH
try {
    $env:PORTAL_CONFIG_PATH = $localConfigPath
    & $phpPath -c $phpIniPath (Join-Path $repoRoot 'database/create-admin.php') 'admin@local.test' 'LocalDev123!'
    if ($LASTEXITCODE -ne 0) {
        throw 'The local administrator could not be created.'
    }
} finally {
    $env:PORTAL_CONFIG_PATH = $previousConfigPath
}

Write-Output ''
Write-Output 'Local development is ready.'
Write-Output 'Start:    pwsh -File scripts/start-dev.ps1'
Write-Output 'Login:    admin@local.test / LocalDev123!'
Write-Output 'Meeting:  http://127.0.0.1:8000/moede.html?id=lokalt-testmoede'
