# Arquitetura

## Visão geral

O DaVez é uma aplicação PHP procedural servida diretamente a partir de uma única raiz web.

```text
Navegador/PWA
    ├── index.html
    ├── checkin/recover/session_info/public_logout
    └── DaVez/entrar + DaVez/listar
             │
             ▼
       sessão pública opaca + MySQLi
             │
             ▼
          MySQL

Administrador
    ├── admin.php
    └── DaVez/listar_admin + mutações protegidas
```

## Componentes atuais

| Componente | Responsabilidade |
|---|---|
| `index.html` | Interface pública v2, sem identidade persistida no Web Storage |
| `checkin.php` | Consumo de ticket individual, check-in e criação da sessão |
| `recover.php` | Recuperação por ticket administrativo vinculado |
| `relogin.php` | Encerramento explícito do re-login legado com HTTP 410 |
| `session_info.php` | Leitura da sessão pública e visão mínima `me` |
| `public_logout.php` | Revogação idempotente da sessão pública |
| `admin.php` | Painel, emissão de tickets e operações administrativas |
| `DaVez/listar.php` | Visão pública mínima, sem IDs internos |
| `DaVez/listar_admin.php` | Fila completa sob autenticação administrativa |
| `DaVez/entrar.php` | Entrada por `checkin_id` derivado da sessão |
| `log.php` | Persistência de eventos em arquivo |
| `service-worker.js` | Cache e funcionamento PWA |
| `database/migrations/` | Evolução aditiva de schema fora do runtime |
| `src/Security/PublicIdentity.php` | Tickets, cookies e hashes da identidade v2 |
| `src/Database/PublicIdentityStore.php` | Persistência de tickets e sessões |
| `config.php` | Conexão e configuração privada local, fora do Git |

## Restrições do baseline

- Os endpoints dependem de includes e URLs relativas.
- A suíte automatizada local existe, mas não substitui MySQL real nem E2E.
- Schema e migrations `001..008` existem, mas `005..008` não foram
  executadas em banco representativo.
- Configuração, regras de negócio e transporte HTTP estão acoplados.
- A raiz do projeto também funciona como webroot.
- O lote de identidade v2 não está ativado até migrations, HTTPS, backup,
  restore, concorrência e E2E passarem.

Por essas razões, o baseline não move os arquivos executáveis. Uma reorganização para `public/`, `src/`, `config/` e `storage/` somente deverá ocorrer depois de testes de caracterização e definição do processo de deploy.

## Arquitetura alvo

```text
public/                 # Único webroot
src/
├── Http/               # Controllers e respostas
├── Application/        # Casos de uso
├── Domain/             # Regras de fila e ciclo
└── Infrastructure/     # MySQL, logs e adapters
config/                 # Configuração sem secrets
database/
├── migrations/
└── seeds/
storage/                # Dados privados fora do webroot
tests/
├── Unit/
├── Integration/
└── Feature/
```

Essa arquitetura é um destino incremental, não uma autorização para refatoração ampla.
