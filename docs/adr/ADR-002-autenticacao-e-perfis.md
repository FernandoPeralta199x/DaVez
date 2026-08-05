# ADR-002: Autenticação, usuários no banco e 3 perfis

**Status:** Aceito
**Data:** 2026-08-05
**Deciders:** Fernando (produto/owner) + engenharia
**Relacionado:** [[ADR-001-fundacao-multi-tenant]], [[ADR-003-contexto-de-tenant]]

## Contexto

A autenticação atual reconhece apenas `admin`/`operator` via variáveis de
ambiente — **não há usuário persistido** com `user_id`/`tenant_id`. O SaaS exige
identidade real no banco, três perfis e controles de segurança (OWASP).

## Decisão

### Perfis (exatamente 3)
| Perfil | `tenant_id` | Escopo |
|---|---|---|
| `SUPER_ADMIN` | `NULL` | Plataforma inteira (todas as empresas) |
| `ADMIN_EMPRESA` | da empresa | Somente a própria empresa |
| `ENTREGADOR` | da empresa | Não é usuário de painel; continua no fluxo público por **código/QR** (entidade `drivers`, não `users`) |

### Tabela `users`
`id`, `tenant_id` (NULL só para `SUPER_ADMIN`), `login` (único por escopo),
`email`, `password_hash`, `role`, `must_change_password`, `mfa_secret` (NULL até
ativar), `failed_attempts`, `locked_until`, `status`, `created_at`, `updated_at`.

### Regras de segurança (OWASP)
- **Hash `Argon2id`** (`password_hash(PASSWORD_ARGON2ID)`), fallback `bcrypt`.
- **MFA TOTP obrigatório** para `SUPER_ADMIN`.
- **`must_change_password=1`** no 1º acesso → backend bloqueia tudo até trocar;
  após trocar: zera flag, **revoga demais sessões**, audita.
- **Bloqueio temporário** após N tentativas inválidas (`locked_until`).
- **Revogação de sessões** após redefinição de senha.
- **Nenhum segredo** em migration/seed/commit/`.env.example`. SUPER_ADMINs
  iniciais (`FernandoPeralta`, `RobertMoura`) via **bootstrap seguro** com senha
  temporária + `must_change_password=1`, hash gerado fora do Git.

### Sessão administrativa
Tabela `admin_sessions` (distinta de `public_sessions`): `user_id`, `tenant_id`,
`role`, `token_hash` BINARY(32), `expires_at`, `revoked_at`, device info mínima.

## Opções consideradas
- **A — usuários no banco + 3 perfis (ESCOLHIDA):** base para multi-tenant,
  auditável, escalável.
- **B — manter auth por env:** rejeitada — não suporta multi-tenant nem usuários
  por empresa.

## Consequências
- Migração da auth atual (env) para banco, atrás de **feature flag**, sem
  derrubar o admin atual até o corte.
- Fluxo do **entregador permanece igual** (código/QR) — menor risco.
- Novos endpoints de auth (`/auth/login`, `/auth/logout`, sessões, reset).

## Ações
1. [ ] Migration `users` + `admin_sessions` (nullable/aditivo)
2. [ ] Bootstrap seguro dos 2 SUPER_ADMIN (senha temporária + must_change)
3. [ ] Fluxo de 1º acesso (troca obrigatória) + MFA TOTP
4. [ ] Testes: autorização por perfil, brute force, revogação, must_change
