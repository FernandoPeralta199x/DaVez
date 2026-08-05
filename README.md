# DaVez

Aplicação web/PWA para check-in, organização de filas, despacho, ranking e
relatórios operacionais de motoboys.

## Release candidate atual

Esta cópia é o **DaVez Tech UX 1.2.0 RC2**, derivado do commit
`8bc1c10391babc4c49c93eb415dcc5b1c2d50a70` e produzido sem push para o GitHub.

O RC2 adiciona:

- interface pública e administrativa modernizada;
- ciclo operacional padrão às 01:30;
- ranking paginado por data e PDF;
- relatórios filtráveis, 15 por página e PDFs;
- correção do fluxo de recuperação;
- revisão estática de segurança e novos contratos automatizados.

> **Release candidate não é autorização de produção.** MySQL real,
> concorrência, E2E, backup/restore, Nginx/PHP-FPM e rollback ainda exigem
> validação representativa.

## Limite arquitetural

O produto permanece monoempresa. A especificação multiempresa exige primeiro
`tenant_id`, `store_id`, usuários persistidos, autorização sistemática e
migrations seguras. Não foram criadas telas que fingem esse isolamento.

## Stack

- PHP 8.1+ com MySQLi;
- MySQL/Percona/MariaDB com InnoDB;
- HTML, CSS e JavaScript sem framework principal;
- PWA com Service Worker;
- testes PHP e Node.

## Estrutura

```text
.
├── assets/                # camada visual RC2
├── DaVez/                 # endpoints da fila
├── database/              # schema e migrations 001..010
├── docs/                  # arquitetura, segurança, deploy e validação
├── icons/ e img/          # assets da PWA
├── scripts/               # validação e build de release
├── src/                   # domínio, aplicação, segurança, banco e PDF
├── tests/                 # testes autônomos e contratos
├── admin.php              # painel administrativo
├── ranking_pdf.php        # PDF do ranking
├── reports_pdf.php        # PDF do índice de relatórios
├── report_pdf.php         # PDF de relatório individual
└── index.html             # interface pública
```

## Documentos principais

- [Status do RC2](docs/IMPLEMENTATION_STATUS_RC2.md)
- [Changelog](CHANGELOG_RC2.md)
- [Pesquisa de UX](docs/UX_RESEARCH_RC2.md)
- [Threat model](docs/SECURITY_THREAT_MODEL_RC2.md)
- [Revisão de segurança](docs/SECURITY_REVIEW_RC2.md)
- [Deploy e rollback](docs/DEPLOYMENT_RC2.md)
- [Operações de banco](docs/DATABASE_OPERATIONS.md)

## Configuração local

Nunca use banco ou credenciais de produção. Copie `config.example.php` para
`config.php`, configure variáveis locais e importe `database/schema.sql` em um
banco isolado.

## Licença

Nenhuma licença foi definida. O código permanece sob os direitos do proprietário.
