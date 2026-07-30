-- DaVez migration 008
-- Vincula a fila futura ao check-in/ciclo e preserva a identidade legada.
-- client_id torna-se opcional somente quando checkin_id estiver presente.

ALTER TABLE fila_da_vez
    ADD COLUMN checkin_id BIGINT UNSIGNED NULL AFTER client_id,
    MODIFY COLUMN client_id VARCHAR(64) NULL,
    ADD UNIQUE KEY uniq_fila_dia_checkin (
        dia,
        checkin_id
    ),
    ADD KEY idx_fila_checkin_dia (
        checkin_id,
        dia
    ),
    ADD CONSTRAINT fk_fila_checkin_cycle
        FOREIGN KEY (checkin_id, dia)
        REFERENCES checkins (id, operational_date)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,
    ADD CONSTRAINT chk_fila_identity_source CHECK (
        client_id IS NOT NULL OR checkin_id IS NOT NULL
    );
