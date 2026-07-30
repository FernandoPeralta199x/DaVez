# DaVez

Aplicação web/PWA para check-in, organização de filas e acompanhamento operacional de motoboys.

## Estado atual

Este repositório contém o MVP em remediação incremental. A fundação de
segurança, schema versionado, testes locais e a identidade pública v2 estão no
código, mas a v2 ainda depende de validação integrada antes da ativação.

> **Não utilizar em produção neste estado.** Migrations `005..008`, MySQL real,
> concorrência, HTTPS, E2E, backup/restore, deploy e rollback ainda não foram
> validados em ambiente representativo.

O primeiro commit preserva os caminhos e o comportamento existentes. Mudanças funcionais e de segurança devem ser feitas em branches e pull requests separados.

## Stack

- PHP procedural com MySQLi
- Banco compatível com MySQL
- HTML, CSS e JavaScript sem framework
- Progressive Web App com Service Worker

## Estrutura atual

```text
.
├── .github/               # Templates de colaboração
├── DaVez/                 # Endpoints da fila DaVez
├── database/              # Schema e migrations 001..008
├── docs/                  # Arquitetura, segurança e operação
├── icons/                 # Ícones da PWA
├── img/                   # Imagens da interface
├── logs/                  # Dados de runtime; conteúdo ignorado pelo Git
├── reports/               # Relatórios gerados; conteúdo ignorado pelo Git
├── scripts/               # Validação e build de release
├── src/                   # Domínio, segurança, banco e HTTP
├── tests/                 # Suíte automatizada local
├── admin.php              # Painel administrativo
├── index.html             # Interface pública v2
├── config.example.php     # Template seguro de configuração
├── manifest.json          # Manifesto da PWA
└── service-worker.js      # Service Worker
```

Os arquivos executáveis permanecem na raiz por compatibilidade com os caminhos
relativos. A migração para webroot `public/` continua planejada.

## Configuração local

1. Copie `config.example.php` para `config.php`.
2. Configure as variáveis descritas em `.env.example` no servidor ou processo PHP.
3. Crie um banco MySQL isolado e aplique as migrations conforme
   `docs/DATABASE_OPERATIONS.md`.
4. Aponte um servidor PHP para esta pasta somente em ambiente local isolado.

Exemplo no PowerShell:

```powershell
Copy-Item config.example.php config.php
```

`config.php`, `.env`, logs e relatórios são deliberadamente ignorados pelo Git.

## Banco de dados

O schema e as migrations são versionados, mas não foram ensaiados em MySQL real
neste lote. Consulte [database/README.md](database/README.md) e
[docs/DATABASE_OPERATIONS.md](docs/DATABASE_OPERATIONS.md) antes de executar
qualquer migration.

## Desenvolvimento

Leia:

- [Arquitetura atual](docs/ARCHITECTURE.md)
- [Roadmap de correções](docs/ROADMAP.md)
- [Política de segurança](SECURITY.md)
- [Guia de contribuição](CONTRIBUTING.md)

## Licença

Nenhuma licença foi definida. O código permanece sob os direitos do proprietário do repositório.
