# ADR-001: Fundação multi-tenant do DaVez

**Status:** Aceito
**Data:** 2026-08-05
**Deciders:** Fernando (produto/owner) + engenharia

## Contexto

O DaVez é hoje **monoempresa** (`settings` singleton `id=1`, nenhuma tabela com
`tenant_id`). O objetivo é transformá-lo em **SaaS multi-tenant de alta escala**
(meta: 1000+ empresas, ~1 milhão de entregadores cadastrados), **mantendo a
produção atual (`davez.cloud`) intacta** durante a migração.

Decisões de negócio travadas nesta sessão:

- **empresa = loja (1:1)** — uma empresa é uma loja;
- **entregador = 1 sessão ativa** (comportamento atual);
- **alta escala desde já** (projetar infra pesada).

## Decisão

Adotar tenancy **pooled** (aplicação e banco compartilhados) com **uma única
chave de isolamento `tenant_id`** em todas as tabelas de negócio. O `tenant_id`
é **sempre derivado da identidade autenticada** (nunca de input do cliente) e
aplicado por uma camada de **contexto de tenant + repositórios com escopo
obrigatório**. Como empresa=loja (1:1), **não** haverá `store_id` separado nesta
fase.

## Opções consideradas

### Opção A — Pooled, `tenant_id` único (ESCOLHIDA)
| Dimensão | Avaliação |
|---|---|
| Complexidade | Média |
| Custo | Baixo |
| Escalabilidade | Alta (índices por tenant + Redis/cache; sharding futuro por `tenant_id`) |
| Familiaridade | Alta |

**Prós:** um só banco; migração incremental do legado; barato no piloto e
escalável com índices/Redis; `tenant_id` já é a chave natural de sharding.
**Contras:** o isolamento é responsabilidade da aplicação (uma query que
esqueça o escopo vaza dados) — mitigado por camada de repositório + testes de
acesso cruzado.

### Opção B — Pooled com `tenant_id` + `store_id` (plano original)
**Prós:** multi-loja pronto. **Contras:** complexidade prematura (empresa=loja
hoje); toda query e índice carregam duas chaves. **Rejeitada por YAGNI.**

### Opção C — Silo (um banco por empresa)
**Prós:** isolamento físico forte. **Contras:** inviável para 1000+ empresas
(operação, custo, migrations em massa). **Rejeitada.**

## Análise de trade-offs

Escolhemos **simplicidade + escala econômica (A)** aceitando que o isolamento
depende de disciplina de código. Mitigações obrigatórias:

- contexto de tenant **imutável** por request, derivado da sessão autenticada;
- repositórios que **exigem** `tenant_id` (não é possível consultar sem escopo);
- **negar por padrão** e validar ownership em toda requisição (OWASP BOLA/IDOR);
- **teste automático de acesso cruzado** ("Empresa A nunca vê dados da B") como
  **portão** obrigatório da Fase 1.

## Consequências

- Toda tabela de negócio ganha `tenant_id` por migração **não-destrutiva**
  (`nullable` → backfill → índice → `NOT NULL`/FK), **nunca** destrutiva.
- Um **"tenant legado"** é criado para adotar os dados de produção atuais.
- Índices compostos **liderados por `tenant_id`**
  (`(tenant_id, operational_date)`, `(tenant_id, created_at)`, etc.).
- **Multi-loja futuro** exigirá adicionar `store_id` (nova migração) — aceito.
- **Alta escala:** planejar Redis (rate-limit/cache/sessões), fila para PDF,
  MySQL gerenciado; sharding por `tenant_id` é evolução futura sem reescrita.

## Ações

1. [ ] ADR-002 — Autenticação: usuários no banco, 3 perfis, Argon2id, MFA TOTP
2. [ ] ADR-003 — Contexto de tenant e camada de repositórios com escopo
3. [ ] Fase 0 — Backup **restaurável** da produção + inventário read-only
4. [ ] Fase 1 — Migração `tenants` + backfill do tenant legado (atrás de feature flag)
5. [ ] Testes de acesso cruzado como portão de fase
