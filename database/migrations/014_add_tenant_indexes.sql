-- DaVez migration 014
-- Fundação multi-tenant (Fase 1). Índices compostos liderados por tenant_id,
-- para que toda consulta com escopo de tenant use índice desde a primeira
-- coluna. Aditivo e não-destrutivo.

ALTER TABLE settings
    ADD KEY idx_settings_tenant (tenant_id);

ALTER TABLE checkins
    ADD KEY idx_checkins_tenant_operational_date (tenant_id, operational_date);

ALTER TABLE fila_da_vez
    ADD KEY idx_fila_tenant_day_status_order (tenant_id, dia, status, ordem);

ALTER TABLE reports
    ADD KEY idx_reports_tenant_period (tenant_id, periodo_inicio, periodo_fim);

ALTER TABLE delivery_events
    ADD KEY idx_delivery_tenant_operational_date (tenant_id, operational_date);

ALTER TABLE daily_access_codes
    ADD KEY idx_daily_code_tenant_operational_date (tenant_id, operational_date);

ALTER TABLE admission_tickets
    ADD KEY idx_admission_tenant_cycle_expiry (tenant_id, operational_date, expires_at);

ALTER TABLE public_sessions
    ADD KEY idx_public_session_tenant_cycle_expiry (tenant_id, operational_date, expires_at, revoked_at);
