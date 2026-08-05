# Status de implementação — DaVez 1.2.0 RC2

## Concluído

- [x] correção de recuperação de acesso preservada;
- [x] ciclo operacional padrão às 01:30;
- [x] filtro de ranking por período e intervalo;
- [x] paginação do ranking no banco;
- [x] PDF do ranking;
- [x] filtro e paginação de relatórios, 15 por página;
- [x] PDF da lista de relatórios;
- [x] PDF de relatório individual;
- [x] campos de data com calendário e digitação manual;
- [x] indicador de relógio do servidor;
- [x] modernização visual pública e administrativa;
- [x] remoção da assinatura de canto;
- [x] revisão estática e políticas de segurança;
- [x] testes autônomos atualizados;
- [x] validação final: 78 arquivos PHP em lint, 25 testes PHP e 13 testes Node.

## Bloqueado até staging com MySQL

- [ ] teste integrado de check-in;
- [ ] recuperação com sessão real;
- [ ] concorrência de fila;
- [ ] geração de PDFs a partir de dados reais;
- [ ] `EXPLAIN` das consultas de ranking e relatórios;
- [ ] teste E2E de navegador autenticado;
- [ ] backup e restore.

## Não implementado neste RC

- [ ] tenants e lojas;
- [ ] isolamento multiempresa;
- [ ] `SUPER_ADMIN`, `ADMIN_EMPRESA` e usuários persistidos;
- [ ] contratos, trial e pausa por empresa;
- [ ] auditoria multi-tenant;
- [ ] `driver_id` permanente.

Não é seguro simular esses itens apenas na interface. Eles exigem alteração de
schema, migração dos dados legados e testes automáticos de acesso cruzado.
