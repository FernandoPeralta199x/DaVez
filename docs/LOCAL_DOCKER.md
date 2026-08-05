# Ambiente local com Docker

O ambiente é isolado e usa MySQL 8.4, PHP 8.4 com `mysqli`/`mbstring` e Adminer.
Não conecte esse ambiente à produção.

## Preparar

```powershell
Copy-Item .env.local.example .env.local
```

Gere o hash da senha administrativa local:

```powershell
docker run --rm php:8.4-cli php -r "echo password_hash('SUA_SENHA_LOCAL', PASSWORD_DEFAULT), PHP_EOL;"
```

Preencha `.env.local`. Para os dois segredos, use pelo menos 32 bytes aleatórios.
Exemplo de geração no PowerShell:

```powershell
-join ((48..57)+(65..90)+(97..122) | Get-Random -Count 48 | ForEach-Object {[char]$_})
```

## Iniciar

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\scripts\local-docker-up.ps1
```

Acessos:

- app: `http://127.0.0.1:8787/`
- admin: `http://127.0.0.1:8787/admin.php`
- Adminer: `http://127.0.0.1:8788/`, servidor `db`, banco `davez`

## Parar e resetar

```powershell
.\scripts\local-docker-down.ps1
.\scripts\local-docker-reset.ps1 -Force
```

O reset apaga somente os volumes locais e reaplica `database/schema.sql`.
