# DaVez 1.2.0 RC2 — changelog

Base: commit `8bc1c10391babc4c49c93eb415dcc5b1c2d50a70`.

## Interface e experiência

- camada visual tecnológica compartilhada em `assets/css/davez-tech-rc2.css`;
- interface pública e painel com fundos técnicos, superfícies translúcidas,
  foco visível, responsividade e redução de movimento;
- remoção definitiva da assinatura visual `YD 808 • CORE v1.5`;
- filtros de data com digitação `MM/DD/YYYY` e abertura do calendário nativo;
- resumo visual de ranking e relatórios;
- relatórios organizados em tabela profissional com 15 registros por página;
- correção do texto do código individual: o código é diário e reutilizável,
  não um ticket de uso único.

## Ranking e relatórios

- nova consulta `RankingQuery`, com ordenação e paginação no MySQL;
- séries e comparação histórica limitadas aos entregadores da página atual;
- botão **Gerar PDF do ranking**;
- botão **Gerar lista em PDF** na aba Relatórios;
- botão individual **Gerar Arquivo do Relatório**;
- novos endpoints `ranking_pdf.php` e `reports_pdf.php` com autenticação,
  allowlist de parâmetros, limites de intervalo, rate limit e `no-store`;
- nova `ReportListQuery` compartilhada pelo painel e pela exportação, evitando
  divergência de filtros e SQL duplicado;
- consultas de relatório preservam possibilidade de uso de índice, evitando
  aplicar `DATE()` na coluna filtrada.

## Operação e PWA

- indicador de sincronização entre relógio do servidor e navegador;
- cache estático atualizado para `motoboys-static-v11`;
- nova folha de estilos incluída na precache list;
- versão atualizada para `1.2.0-rc2`.

## Segurança e qualidade

- política automatizada para os três endpoints PDF;
- contrato da consulta paginada de ranking;
- inspeção de segredos, SQL, sinks DOM, autenticação, CSRF, sessão,
  rate limiting e acesso a arquivos;
- threat model e relatório de segurança do RC2;
- allowlist de release atualizada para os novos arquivos e diretório `assets`;
- validação final: 78 arquivos PHP em lint, 25 testes PHP e 13 testes Node.

## Fora do escopo deste RC

- migração multi-tenant;
- usuários persistidos no banco e novos perfis;
- contratos e trials;
- alteração do schema;
- deploy em produção.

Esses itens exigem migrations, backfill, banco de staging e testes de isolamento.
