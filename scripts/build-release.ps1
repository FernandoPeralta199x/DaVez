[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9A-Za-z][0-9A-Za-z._-]{0,63}$')]
    [string]$Version
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$projectRoot = Split-Path -Parent $PSScriptRoot
$artifactsRoot = Join-Path $projectRoot 'artifacts'
$releaseName = "davez-$Version"
$stageRoot = Join-Path $artifactsRoot $releaseName
$archivePath = Join-Path $artifactsRoot "$releaseName.zip"

if ((Test-Path -LiteralPath $stageRoot) -or (Test-Path -LiteralPath $archivePath)) {
    throw "O artefato '$releaseName' já existe. Use outra versão; nenhum arquivo foi sobrescrito."
}

$releaseFiles = @(
    '.htaccess',
    '.env.example',
    'README.md',
    'SECURITY.md',
    'admin.php',
    'checkin.php',
    'client_log.php',
    'config.example.php',
    'database/schema.sql',
    'index.html',
    'log.php',
    'manifest.json',
    'panel.php',
    'public_logout.php',
    'recover.php',
    'relogin.php',
    'service-worker.js',
    'session_info.php'
)

$releaseDirectories = @(
    'DaVez',
    'database/migrations',
    'docs',
    'icons',
    'img',
    'js',
    'src'
)

New-Item -ItemType Directory -Path $stageRoot -Force:$false | Out-Null

foreach ($relativePath in $releaseFiles) {
    $source = Join-Path $projectRoot $relativePath
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
        throw "Arquivo obrigatório ausente: $relativePath"
    }

    $destination = Join-Path $stageRoot $relativePath
    $destinationParent = Split-Path -Parent $destination
    if (-not (Test-Path -LiteralPath $destinationParent)) {
        New-Item -ItemType Directory -Path $destinationParent -Force | Out-Null
    }

    Copy-Item -LiteralPath $source -Destination $destination
}

foreach ($relativePath in $releaseDirectories) {
    $source = Join-Path $projectRoot $relativePath
    if (-not (Test-Path -LiteralPath $source -PathType Container)) {
        throw "Diretório obrigatório ausente: $relativePath"
    }

    $destination = Join-Path $stageRoot $relativePath
    $destinationParent = Split-Path -Parent $destination
    New-Item -ItemType Directory -Path $destinationParent -Force | Out-Null
    Copy-Item -LiteralPath $source -Destination $destination -Recurse
}

$forbiddenDirectoryNames = @(
    '.git',
    '.private',
    'artifacts',
    'coverage',
    'logs',
    'node_modules',
    'reports',
    'tests',
    'vendor'
)
$forbiddenDirectories = Get-ChildItem -LiteralPath $stageRoot -Recurse -Force -Directory |
    Where-Object { $_.Name -in $forbiddenDirectoryNames }

$forbiddenNames = @(
    '.git',
    'config.php',
    'CLAUDE_DAVEZ_AUDITORIA_E_PLANO_CORRECAO-1.md'
)
$forbiddenExtensions = @('.bak', '.dump', '.log', '.zip')
$forbiddenFiles = Get-ChildItem -LiteralPath $stageRoot -Recurse -Force -File |
    Where-Object {
        $relativePath = [IO.Path]::GetRelativePath($stageRoot, $_.FullName) -replace '\\', '/'
        $isAllowedEnvExample = $relativePath -eq '.env.example'
        $isEnvironmentFile = $_.Name -eq '.env' -or $_.Name -like '.env.*'
        $isCompressedSql = $_.Name -like '*.sql.gz'

        $_.Name -in $forbiddenNames -or
        ($isEnvironmentFile -and -not $isAllowedEnvExample) -or
        $_.Extension -in $forbiddenExtensions -or
        $isCompressedSql
    }

if ($forbiddenDirectories -or $forbiddenFiles) {
    $forbiddenEntries = @($forbiddenDirectories) + @($forbiddenFiles)
    $relativeForbidden = $forbiddenEntries |
        ForEach-Object { [IO.Path]::GetRelativePath($stageRoot, $_.FullName) }
    throw "Caminhos proibidos no artefato: $($relativeForbidden -join ', ')"
}

$manifestLines = Get-ChildItem -LiteralPath $stageRoot -Recurse -File |
    Sort-Object FullName |
    ForEach-Object {
        $relativePath = [IO.Path]::GetRelativePath($stageRoot, $_.FullName) -replace '\\', '/'
        $hash = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        "$hash  $relativePath"
    }

$manifestPath = Join-Path $stageRoot 'RELEASE_MANIFEST.sha256'
$manifestLines | Set-Content -LiteralPath $manifestPath -Encoding utf8NoBOM

Compress-Archive -LiteralPath $stageRoot -DestinationPath $archivePath -CompressionLevel Optimal

Write-Host "Artefato criado: $archivePath" -ForegroundColor Green
Write-Host "Diretório de inspeção: $stageRoot"
