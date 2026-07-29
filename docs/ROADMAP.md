# Roadmap técnico

## Fase 0 — Baseline e contenção

- organizar o repositório;
- impedir versionamento de secrets e dados de runtime;
- documentar arquitetura e riscos;
- restringir acesso a logs, relatórios e scripts de teste;
- rotacionar credenciais fora do código.

## Fase 1 — Autenticação e privacidade

- substituir o token compartilhado por sessões individuais;
- adicionar autorização administrativa;
- adicionar proteção CSRF;
- remover tokens, POST bruto e dados pessoais dos logs;
- definir política de cookies e expiração.

## Fase 2 — Integridade das filas

- centralizar cálculo e atualização de posição;
- adicionar transactions, locks e constraints;
- validar reordenações completas;
- tornar limpeza e geração de relatório atômicas.

## Fase 3 — Banco e operação

- recuperar e revisar o schema;
- criar migrations e rollback;
- retirar DDL das requisições;
- separar privilégios do banco;
- adicionar backup, restore, logs e monitoramento.

## Fase 4 — Arquitetura e testes

- criar testes de caracterização;
- separar webroot, aplicação e storage;
- extrair controllers, services e repositories;
- adicionar integração contínua;
- validar responsividade, acessibilidade e PWA.
