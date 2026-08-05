# ADR-003: Contexto de tenant e repositórios com escopo obrigatório

**Status:** Aceito
**Data:** 2026-08-05
**Deciders:** Fernando (produto/owner) + engenharia
**Relacionado:** [[ADR-001-fundacao-multi-tenant]], [[ADR-002-autenticacao-e-perfis]]

## Contexto

No modelo **pooled** (banco compartilhado), o isolamento entre empresas é
responsabilidade da **aplicação**: uma única query de negócio que esqueça o
escopo do tenant vaza dados de outra empresa (OWASP BOLA/IDOR — o risco central
de APIs). Precisamos de um mecanismo que torne o escopo **difícil de esquecer**
e **impossível de burlar pelo cliente**.

## Decisão

### `TenantContext` imutável por request
- Criado **uma vez por request**, derivado **exclusivamente da sessão
  autenticada** (`tenant_id`, `user_id`, `role`).
- `ADMIN_EMPRESA` → contexto preso ao seu `tenant_id`.
- `SUPER_ADMIN` → contexto "plataforma"; acesso cross-tenant é **explícito e
  auditado** (nunca implícito).
- O **frontend nunca envia `tenant_id`**. Qualquer `tenant_id` vindo do cliente
  é ignorado.

### Repositórios com escopo obrigatório
- Toda consulta de negócio passa por um **repositório** que recebe o
  `TenantContext` e **injeta `WHERE tenant_id = ?`** — não existe caminho para
  consultar tabela de negócio sem escopo.
- **Negar por padrão:** sem tenant resolvido → 403; empresa `PAUSED`/`ARCHIVED`
  → bloqueia mutações e novos logins (middleware global).
- Validar **ownership** do recurso (relatório, código, motoboy) contra o tenant
  do contexto em toda requisição.

### Middleware (ordem)
`autenticação → resolve TenantContext → status da empresa → autorização por
perfil → validação de input/CSRF/rate-limit → repositório com escopo`.

## Opções consideradas
- **A — TenantContext + repositórios com escopo (ESCOLHIDA):** escopo
  centralizado, testável, difícil de burlar.
- **B — filtrar `tenant_id` ad-hoc em cada endpoint:** rejeitada — frágil, fácil
  esquecer, gera BOLA/IDOR.
- **C — Row-Level Security no MySQL:** MySQL não tem RLS nativo robusto;
  rejeitada nesta fase.

## Consequências
- Refactor **gradual** dos endpoints para passar pelos repositórios (após
  testes de caracterização, atrás de feature flag).
- **Portão da Fase 1:** teste automático de **acesso cruzado** — "Empresa A
  nunca vê dados da Empresa B" — em cada recurso (ranking, relatórios, códigos,
  fila, motoboys, logs).
- Base pronta para **sharding futuro** por `tenant_id` sem reescrita.

## Ações
1. [ ] Implementar `TenantContext` + resolvedor a partir da sessão
2. [ ] Camada de repositório com escopo obrigatório (começar por 1 recurso)
3. [ ] Middleware de status da empresa (pausada/arquivada)
4. [ ] Suíte de testes de acesso cruzado (portão da Fase 1)
