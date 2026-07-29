[CmdletBinding()]
param(
    [string]$PhpBinary = $env:DAVEZ_PHP_BINARY
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$projectRoot = Split-Path -Parent $PSScriptRoot

function Write-Step {
    param([string]$Message)
    Write-Host "`n==> $Message" -ForegroundColor Cyan
}

function Resolve-PhpBinary {
    param([string]$RequestedBinary)

    if ($RequestedBinary -and (Test-Path -LiteralPath $RequestedBinary)) {
        return (Resolve-Path -LiteralPath $RequestedBinary).Path
    }

    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) {
        return $phpCommand.Source
    }

    $localPhp = Join-Path $env:LOCALAPPDATA 'Programs\PHP\8.5.9\php.exe'
    if (Test-Path -LiteralPath $localPhp) {
        return $localPhp
    }

    throw 'PHP não encontrado. Defina DAVEZ_PHP_BINARY ou abra um terminal com PHP no PATH.'
}

function Resolve-PhpRuntimeArguments {
    param([string]$ResolvedPhpBinary)

    $arguments = @('-d', 'date.timezone=America/Sao_Paulo')
    $requiredExtensions = @('json', 'mbstring', 'mysqli', 'openssl', 'session')
    $extensionDirectory = Join-Path (Split-Path -Parent $ResolvedPhpBinary) 'ext'

    foreach ($extension in $requiredExtensions) {
        & $ResolvedPhpBinary @arguments -r "exit(extension_loaded('$extension') ? 0 : 1);"

        if ($LASTEXITCODE -eq 0) {
            continue
        }

        $windowsExtension = Join-Path $extensionDirectory "php_$extension.dll"
        if (-not (Test-Path -LiteralPath $windowsExtension -PathType Leaf)) {
            throw "Extensão PHP obrigatória ausente: $extension"
        }

        $arguments += @(
            '-d',
            "extension_dir=$extensionDirectory",
            '-d',
            "extension=$extension"
        )
    }

    foreach ($extension in $requiredExtensions) {
        & $ResolvedPhpBinary @arguments -r "exit(extension_loaded('$extension') ? 0 : 1);"
        if ($LASTEXITCODE -ne 0) {
            throw "Não foi possível carregar a extensão PHP obrigatória: $extension"
        }
    }

    return $arguments
}

function Test-IsExcludedPath {
    param([string]$FullName)

    $relativePath = [IO.Path]::GetRelativePath($projectRoot, $FullName)
    $segments = $relativePath -split '[\\/]'
    $excludedDirectories = @('.git', '.private', 'artifacts', 'coverage', 'logs', 'node_modules', 'reports', 'vendor')

    return $segments | Where-Object { $_ -in $excludedDirectories } | Select-Object -First 1
}

$resolvedPhp = Resolve-PhpBinary -RequestedBinary $PhpBinary
$phpRuntimeArguments = Resolve-PhpRuntimeArguments -ResolvedPhpBinary $resolvedPhp

Write-Step 'Runtime PHP e extensões obrigatórias'
& $resolvedPhp @phpRuntimeArguments -r "echo PHP_VERSION, ' | mysqli=', mysqli_get_client_info(), PHP_EOL;"
if ($LASTEXITCODE -ne 0) {
    throw 'O runtime PHP não possui as extensões obrigatórias.'
}

Write-Step 'Lint dos arquivos PHP rastreáveis'
$phpFiles = Get-ChildItem -LiteralPath $projectRoot -Recurse -File -Filter '*.php' |
    Where-Object {
        $_.Name -ne 'config.php' -and -not (Test-IsExcludedPath -FullName $_.FullName)
    } |
    Sort-Object FullName

foreach ($file in $phpFiles) {
    & $resolvedPhp @phpRuntimeArguments -l $file.FullName
    if ($LASTEXITCODE -ne 0) {
        throw "Lint PHP falhou: $($file.FullName)"
    }
}

Write-Step 'Testes PHP autônomos'
$phpTests = Get-ChildItem -LiteralPath (Join-Path $projectRoot 'tests') -Recurse -File -Filter '*.php' |
    Sort-Object FullName

foreach ($test in $phpTests) {
    & $resolvedPhp @phpRuntimeArguments $test.FullName
    if ($LASTEXITCODE -ne 0) {
        throw "Teste PHP falhou: $($test.FullName)"
    }
}

Write-Step 'Sintaxe e testes Node'
$nodeCommand = Get-Command node -ErrorAction Stop
$javascriptTests = Get-ChildItem -LiteralPath (Join-Path $projectRoot 'tests') -Recurse -File -Filter '*.js' |
    Sort-Object FullName

foreach ($test in $javascriptTests) {
    & $nodeCommand.Source --check $test.FullName
    if ($LASTEXITCODE -ne 0) {
        throw "Sintaxe Node inválida: $($test.FullName)"
    }

    & $nodeCommand.Source $test.FullName
    if ($LASTEXITCODE -ne 0) {
        throw "Teste Node falhou: $($test.FullName)"
    }
}

$serviceWorker = Join-Path $projectRoot 'service-worker.js'
if (Test-Path -LiteralPath $serviceWorker) {
    & $nodeCommand.Source --check $serviceWorker
    if ($LASTEXITCODE -ne 0) {
        throw 'Sintaxe inválida em service-worker.js.'
    }
}

Write-Step 'Manifesto e política do artefato'
Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'manifest.json') | ConvertFrom-Json | Out-Null

$forbiddenPublicEndpoints = @('postteste.php', 'teste_admin.php', 'testedb.php')
foreach ($endpoint in $forbiddenPublicEndpoints) {
    if (Test-Path -LiteralPath (Join-Path $projectRoot $endpoint)) {
        throw "Endpoint de diagnóstico proibido encontrado: $endpoint"
    }
}

Write-Step 'Integridade do diff Git'
$gitCommand = Get-Command git -ErrorAction SilentlyContinue
if ($gitCommand) {
    & $gitCommand.Source -c "safe.directory=$($projectRoot -replace '\\','/')" diff --check
    if ($LASTEXITCODE -ne 0) {
        throw 'git diff --check encontrou problemas.'
    }
}

Write-Host "`nValidação local concluída com sucesso." -ForegroundColor Green
