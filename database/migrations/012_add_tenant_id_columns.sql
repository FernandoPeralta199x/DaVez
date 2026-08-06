-- DaVez migration 012
-- Fundação multi-tenant (Fase 1). Adiciona tenant_id às tabelas de negócio.
-- Etapa ADITIVA e não-destrutiva: as colunas ficam NULLABLE e ainda não recebem
-- FK nem NOT NULL. O reforço (NOT NULL + FK) é uma etapa futura, aplicada só
-- depois do backfill (013) e do retrofit dos endpoints. ADD COLUMN é operação
-- INSTANT no InnoDB do MySQL 8 (não reconstrói a tabela, não bloqueia).

ALTER TABLE settings ADD COLUMN tenant_id BIGINT UNSIGNED NULL;
ALTER TABLE checkins ADD COLUMN tenant_id BIGINT UNSIGNED NULL;
ALTER TABLE fila_da_vez ADD COLUMN tenant_id BIGINT UNSIGNED NULL;
ALTER TABLE reports ADD COLUMN tenant_id BIGINT UNSIGNED NULL;
ALTER TABLE delivery_events ADD COLUMN tenant_id BIGINT UNSIGNED NULL;
ALTER TABLE daily_access_codes ADD COLUMN tenant_id BIGINT UNSIGNED NULL;
ALTER TABLE admission_tickets ADD COLUMN tenant_id BIGINT UNSIGNED NULL;
ALTER TABLE public_sessions ADD COLUMN tenant_id BIGINT UNSIGNED NULL;
