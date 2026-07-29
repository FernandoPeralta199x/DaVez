# Arquitetura

## Visão geral

O DaVez é uma aplicação PHP procedural servida diretamente a partir de uma única raiz web.

```text
Navegador/PWA
    ├── index.html
    ├── endpoints de check-in e sessão
    └── DaVez/*
             │
             ▼
       config.php + MySQLi
             │
             ▼
          MySQL

Administrador
    └── admin.php
```

## Componentes atuais

| Componente | Responsabilidade |
|---|---|
| `index.html` | Interface pública e estado do cliente |
| `checkin.php` | Entrada na fila principal |
| `relogin.php` | Recuperação do estado pelo nome |
| `session_info.php` | Informações do ciclo e token |
| `admin.php` | Painel e operações administrativas |
| `DaVez/` | Entrada, listagem, saída e reordenação da fila secundária |
| `log.php` | Persistência de eventos em arquivo |
| `service-worker.js` | Cache e funcionamento PWA |
| `config.php` | Conexão e autenticação administrativa local |

## Restrições do baseline

- Os endpoints dependem de includes e URLs relativas.
- Não existe suíte automatizada.
- Não existe schema ou conjunto de migrations confiável.
- Configuração, regras de negócio e transporte HTTP estão acoplados.
- A raiz do projeto também funciona como webroot.

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
