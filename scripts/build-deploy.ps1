[CmdletBinding()]
param(
    [string] $OutputDirectory
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Resolve the repository, manifest and disposable artifact directories.
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$manifestPath = Join-Path $repoRoot 'deployment/portal-files.txt'
$distRoot = [System.IO.Path]::GetFullPath((Join-Path $repoRoot 'dist'))
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $distRoot 'portal'
}
$outputPath = [System.IO.Path]::GetFullPath($OutputDirectory)

# Compare paths according to the host filesystem's case sensitivity.
$runningOnWindows = [System.Environment]::OSVersion.Platform -eq [System.PlatformID]::Win32NT
$comparison = if ($runningOnWindows) {
    [System.StringComparison]::OrdinalIgnoreCase
} else {
    [System.StringComparison]::Ordinal
}
$separator = [System.IO.Path]::DirectorySeparatorChar
$distPrefix = $distRoot.TrimEnd($separator) + $separator
$repoPrefix = $repoRoot.TrimEnd($separator) + $separator

# Constrain all cleanup and output operations to the ignored dist/ directory.
if (!$outputPath.StartsWith($distPrefix, $comparison)) {
    throw "OutputDirectory must be inside $distRoot"
}
if (!(Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
    throw "Deployment manifest not found: $manifestPath"
}

# Load the explicit allowlist while ignoring comments and blank lines.
$entries = @(
    Get-Content -LiteralPath $manifestPath |
        ForEach-Object { $_.Trim().Replace('\', '/') } |
        Where-Object { $_ -ne '' -and !$_.StartsWith('#') }
)
if ($entries.Count -eq 0) {
    throw 'Deployment manifest is empty.'
}

# Define files and directories that must never enter the public artifact.
$seen = [System.Collections.Generic.HashSet[string]]::new(
    [System.StringComparer]::OrdinalIgnoreCase
)
$forbiddenExact = @(
    'portal-config.php',
    'api/config.example.php',
    'README.md',
    'POSTGRES-SETUP.md',
    '.gitignore'
)
$forbiddenPrefixes = @(
    '.git/',
    '.github/',
    'database/',
    'deployment/',
    'scripts/',
    'dist/',
    'portal-private/',
    'public_html/'
)

# Validate every manifest entry before deleting or copying any files.
foreach ($entry in $entries) {
    if ([System.IO.Path]::IsPathRooted($entry) -or $entry.Split('/') -contains '..') {
        throw "Unsafe path in deployment manifest: $entry"
    }
    if (!$seen.Add($entry)) {
        throw "Duplicate path in deployment manifest: $entry"
    }
    if ($forbiddenExact -contains $entry) {
        throw "Private or development file in deployment manifest: $entry"
    }
    foreach ($prefix in $forbiddenPrefixes) {
        if ($entry.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
            throw "Forbidden directory in deployment manifest: $entry"
        }
    }

    $sourcePath = [System.IO.Path]::GetFullPath((Join-Path $repoRoot $entry))
    if (!$sourcePath.StartsWith($repoPrefix, $comparison)) {
        throw "Manifest path escapes the repository: $entry"
    }
    if (!(Test-Path -LiteralPath $sourcePath -PathType Leaf)) {
        throw "Manifest file does not exist: $entry"
    }
}

# Catch new conventional public files that were not added to the allowlist.
$trackedFiles = @(& git -C $repoRoot ls-files)
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to read tracked files from Git.'
}
$publicCandidates = @(
    $trackedFiles | Where-Object {
        ($_ -eq '.htaccess') -or
        (($_ -notmatch '/') -and ($_ -match '\.(html|js|css|svg)$')) -or
        (($_ -match '^api/.+\.php$') -and ($_ -ne 'api/config.example.php'))
    }
)
$unlisted = @($publicCandidates | Where-Object { !$seen.Contains($_) })
if ($unlisted.Count -gt 0) {
    throw "Tracked public files are missing from the deployment manifest: $($unlisted -join ', ')"
}

# Recreate the disposable artifact so removed files cannot survive a new build.
if (Test-Path -LiteralPath $outputPath) {
    Remove-Item -LiteralPath $outputPath -Recurse -Force
}
New-Item -ItemType Directory -Path $outputPath -Force | Out-Null

# Copy approved files while preserving their repository-relative paths.
foreach ($entry in $entries) {
    $sourcePath = Join-Path $repoRoot $entry
    $destinationPath = Join-Path $outputPath $entry
    $destinationDirectory = Split-Path -Parent $destinationPath
    New-Item -ItemType Directory -Path $destinationDirectory -Force | Out-Null
    Copy-Item -LiteralPath $sourcePath -Destination $destinationPath -Force
}

# Verify each approved path directly so Unix dotfiles are never filtered out.
$missingEntries = @(
    $entries | Where-Object {
        ![System.IO.File]::Exists((Join-Path $outputPath $_))
    }
)
if ($missingEntries.Count -gt 0) {
    throw "Artifact is missing approved files: $($missingEntries -join ', ')"
}

# The output directory was recreated above, and only approved paths are copied.
# Calculate size from those exact paths rather than platform-specific listings.
$totalBytes = ($entries | ForEach-Object {
    [System.IO.FileInfo]::new((Join-Path $outputPath $_)).Length
} | Measure-Object -Sum).Sum
Write-Output "Built $($entries.Count) files ($totalBytes bytes) in $outputPath"
