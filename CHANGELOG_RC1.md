# DaVez Tech UX RC1 — Changelog

**Versão:** `1.1.0-rc1`  
**Base:** commit `8bc1c10391babc4c49c93eb415dcc5b1c2d50a70`  
**Estado:** release candidate para staging; não é promoção automática de produção.

## Correções críticas

- Corrigido o escopo de `$codeHash` em `recover.php`, que fazia a recuperação de acesso cair no tratamento genérico de erro.
- Adicionado teste de regressão específico para impedir o retorno do identificador legado `$ticketHash` na closure.
- Centralizado o fuso operacional entre PHP e a sessão MySQL, removendo o offset fixo `-03:00` dos endpoints.

## Ciclo operacional

- Novo padrão: `01:30`.
- Configuração por ambiente:
  - `APP_TIMEZONE=America/Sao_Paulo`
  - `APP_OPERATIONAL_CYCLE_TIME=01:30`
- `OperationalCycle` agora suporta hora e minuto, incluindo testes nas fronteiras `01:29:59`, `01:30:00` e `01:30:01`.
- O cache da PWA foi incrementado para evitar mistura entre frontend anterior e novo.

## UI/UX

- Interface pública modernizada com hierarquia de acesso em três etapas, estado do sistema, relógio, indicadores de proteção, visual tecnológico e melhor adaptação móvel.
- Botão de atualização integrado ao rail superior, sem sobrepor informações em telas pequenas.
- Login administrativo modernizado com identidade visual, indicador de console seguro e melhor hierarquia.
- Painel administrativo recebeu nova camada visual tecnológica, filtros compactos e componentes de paginação.

## Ranking e relatórios

- Ranking com intervalo personalizado de datas, limite máximo de 366 dias e paginação.
- Relatórios com paginação no backend, 15 itens por página e filtros pelo período operacional.
- Geração de PDF autocontida, sem executar binários externos ou depender de pacotes de terceiros no servidor.
- Rate limit específico para geração de PDF.

## Testes e qualidade

- Novo teste do gerador PDF e da paginação automática.
- Testes ampliados para ranking por intervalo e ciclo operacional com minuto.
- Todos os testes PHP e Node autônomos passam neste pacote.
- Todos os arquivos PHP passam no lint.

## Não implementado neste RC

Este RC **não** implementa a fundação multi-tenant completa do plano, usuários persistidos no banco, os três papéis finais, contratos, trial, auditoria por tenant ou exclusão segura de empresas. Essas mudanças exigem migrations, backfill, banco de staging e testes de isolamento antes de serem consideradas seguras.
