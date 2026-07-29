-- DaVez migration 002
-- Cria a fila principal e os índices usados por ciclo, identidade e posição.
-- Não cria unicidade por dia: o legado ainda não persiste a data operacional.

CREATE TABLE IF NOT EXISTS checkins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(120) NOT NULL,
    client_id VARCHAR(64) NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    data_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ordem INT UNSIGNED NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    closed_at DATETIME NULL,
    obs VARCHAR(120) NULL,
    PRIMARY KEY (id),
    KEY idx_checkins_cycle_order (data_hora, is_closed, ordem, id),
    KEY idx_checkins_client_cycle (client_id, data_hora),
    KEY idx_checkins_name_cycle (nome, data_hora),
    KEY idx_checkins_closed_duration (is_closed, data_hora, closed_at),
    CONSTRAINT chk_checkins_closed CHECK (is_closed IN (0, 1)),
    CONSTRAINT chk_checkins_order CHECK (ordem IS NULL OR ordem >= 1),
    CONSTRAINT chk_checkins_closed_at CHECK (
        (is_closed = 0 AND closed_at IS NULL)
        OR (is_closed = 1 AND closed_at IS NOT NULL)
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
