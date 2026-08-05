[CmdletBinding()]
param([switch]$Force)
$ErrorActionPreference = 'Stop'
if (-not $Force) { throw 'Este comando apaga o banco LOCAL. Execute novamente com -Force.' }
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot
docker compose --env-file .env.local -f docker-compose.local.yml down -v --remove-orphans
docker compose --env-file .env.local -f docker-compose.local.yml up --build -d
