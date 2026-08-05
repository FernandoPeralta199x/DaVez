[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker não foi encontrado no PATH. Abra ou reinicie o Docker Desktop.'
}
if (-not (Test-Path -LiteralPath '.env.local' -PathType Leaf)) {
    Copy-Item -LiteralPath '.env.local.example' -Destination '.env.local'
    throw 'Foi criado .env.local. Substitua todos os valores CHANGE_ME e execute novamente.'
}
$environment = Get-Content -Raw -LiteralPath '.env.local'
if ($environment -match 'CHANGE_ME') {
    throw '.env.local ainda contém valores CHANGE_ME.'
}

docker info | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Docker Desktop não está pronto.' }

docker compose --env-file .env.local -f docker-compose.local.yml up --build -d
if ($LASTEXITCODE -ne 0) { throw 'Não foi possível iniciar o ambiente local.' }

docker compose --env-file .env.local -f docker-compose.local.yml ps
Write-Host ''
Write-Host 'DaVez:   http://127.0.0.1:8787/' -ForegroundColor Green
Write-Host 'Admin:   http://127.0.0.1:8787/admin.php' -ForegroundColor Green
Write-Host 'Adminer: http://127.0.0.1:8788/' -ForegroundColor Green
