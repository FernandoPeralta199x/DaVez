-- DaVez migration 005
-- Adiciona o ciclo explícito necessário à identidade pública v2.
-- Registros legados permanecem compatíveis com operational_date nulo.
-- Execute uma única vez, após preflight e restore testado.

ALTER TABLE checkins
    ADD COLUMN operational_date DATE NULL AFTER data_hora,
    ADD UNIQUE KEY uniq_checkins_id_operational_date (
        id,
        operational_date
    ),
    ADD KEY idx_checkins_operational_date (
        operational_date,
        id
    );
