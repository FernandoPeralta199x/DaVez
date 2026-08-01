-- DaVez migration 010
-- Código de acesso diário reutilizável, vinculado ao motoboy até a virada do ciclo.
-- code_hash recebe HMAC-SHA-256 binário; o código bruto nunca é persistido.
--
-- Diferente de admission_tickets (uso único, TTL de 10 minutos), este código é
-- reutilizável durante todo o ciclo operacional: serve para o primeiro check-in,
-- para re-entrar na fila após uma entrega e para recuperar a sessão em qualquer
-- aparelho. Expira na virada do ciclo (06:00) e é renovado no dia seguinte.

CREATE TABLE IF NOT EXISTS daily_access_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code_hash BINARY(32) NOT NULL,
    operational_date DATE NOT NULL,
    checkin_id BIGINT UNSIGNED NULL,
    label VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    activated_at DATETIME NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_daily_code_hash (code_hash),
    UNIQUE KEY uniq_daily_code_checkin_cycle (checkin_id, operational_date),
    KEY idx_daily_code_cycle (operational_date, expires_at, revoked_at),
    CONSTRAINT fk_daily_code_checkin_cycle
        FOREIGN KEY (checkin_id, operational_date)
        REFERENCES checkins (id, operational_date)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,
    CONSTRAINT chk_daily_code_expiry CHECK (expires_at > created_at),
    CONSTRAINT chk_daily_code_activation CHECK (
        (activated_at IS NULL AND checkin_id IS NULL)
        OR
        (activated_at IS NOT NULL AND checkin_id IS NOT NULL)
    ),
    CONSTRAINT chk_daily_code_revocation CHECK (
        revoked_at IS NULL OR revoked_at >= created_at
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
