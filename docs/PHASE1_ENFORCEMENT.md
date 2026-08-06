# Fase 1 — Etapa de reforço do tenant (NOT NULL + FK)

**Status:** planejada (NÃO aplicar ainda)
**Pré-requisito:** todos os endpoints escrevem `tenant_id` em toda inserção
(retrofit concluído, atrás da flag `multi_tenant`), e a verificação de produção
mostra **zero linhas com `tenant_id` NULL** em todas as tabelas de negócio.

## Por que não é uma migration numerada ainda

As migrations `011`–`014` (aditivas, `tenant_id` nullable) podem ir para
produção **cedo e com segurança** — o app atual ignora `tenant_id` e continua
funcionando. Já o reforço abaixo torna `tenant_id` **obrigatório**; se aplicado
antes do retrofit, os `INSERT` atuais (que não setam `tenant_id`) **falhariam**.
Por isso esta etapa fica documentada aqui até o momento certo, quando vira a
próxima migration numerada.

## Verificação obrigatória antes de aplicar

```sql
-- Deve retornar 0 para TODAS as tabelas.
SELECT 'settings' t, SUM(tenant_id IS NULL) nulos FROM settings
UNION ALL SELECT 'checkins', SUM(tenant_id IS NULL) FROM checkins
UNION ALL SELECT 'fila_da_vez', SUM(tenant_id IS NULL) FROM fila_da_vez
UNION ALL SELECT 'reports', SUM(tenant_id IS NULL) FROM reports
UNION ALL SELECT 'delivery_events', SUM(tenant_id IS NULL) FROM delivery_events
UNION ALL SELECT 'daily_access_codes', SUM(tenant_id IS NULL) FROM daily_access_codes
UNION ALL SELECT 'admission_tickets', SUM(tenant_id IS NULL) FROM admission_tickets
UNION ALL SELECT 'public_sessions', SUM(tenant_id IS NULL) FROM public_sessions;
```

## SQL de reforço (ensaiado com sucesso na cópia de dados reais)

```sql
ALTER TABLE settings
    MODIFY COLUMN tenant_id BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_settings_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE checkins
    MODIFY COLUMN tenant_id BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_checkins_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE fila_da_vez
    MODIFY COLUMN tenant_id BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_fila_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE reports
    MODIFY COLUMN tenant_id BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_reports_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE delivery_events
    MODIFY COLUMN tenant_id BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_delivery_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE daily_access_codes
    MODIFY COLUMN tenant_id BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_daily_code_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE admission_tickets
    MODIFY COLUMN tenant_id BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_admission_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE public_sessions
    MODIFY COLUMN tenant_id BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_public_session_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;
```

> Ensaiado em `davez_mig_check` (restore dos dados reais de produção):
> aplicou limpo, resultando em 8/8 colunas `tenant_id NOT NULL` e 8/8 FKs para
> `tenants`.
