-- DaVez migration 013
-- Fundação multi-tenant (Fase 1). Adota todos os dados monoempresa existentes
-- em um tenant legado. Idempotente: ON DUPLICATE KEY UPDATE + LAST_INSERT_ID(id)
-- retorna o mesmo id numa reexecução, e cada UPDATE só toca linhas com
-- tenant_id ainda NULL.

INSERT INTO tenants (
    name,
    cnpj,
    slug,
    status
) VALUES (
    'Empresa Legado',
    NULL,
    'empresa-legado',
    'active'
)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);

SET @legacy_tenant_id = LAST_INSERT_ID();

UPDATE settings          SET tenant_id = @legacy_tenant_id WHERE tenant_id IS NULL;
UPDATE checkins          SET tenant_id = @legacy_tenant_id WHERE tenant_id IS NULL;
UPDATE fila_da_vez       SET tenant_id = @legacy_tenant_id WHERE tenant_id IS NULL;
UPDATE reports           SET tenant_id = @legacy_tenant_id WHERE tenant_id IS NULL;
UPDATE delivery_events   SET tenant_id = @legacy_tenant_id WHERE tenant_id IS NULL;
UPDATE daily_access_codes SET tenant_id = @legacy_tenant_id WHERE tenant_id IS NULL;
UPDATE admission_tickets SET tenant_id = @legacy_tenant_id WHERE tenant_id IS NULL;
UPDATE public_sessions   SET tenant_id = @legacy_tenant_id WHERE tenant_id IS NULL;
