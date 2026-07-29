-- DaVez migration 003
-- Cria a fila secundária por data operacional.

CREATE TABLE IF NOT EXISTS fila_da_vez (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dia DATE NOT NULL,
    client_id VARCHAR(64) NOT NULL,
    nome VARCHAR(120) NOT NULL,
    entered_at DATETIME NOT NULL,
    ordem INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'na_fila',
    last_action_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_fila_dia_client (dia, client_id),
    KEY idx_fila_dia_status (dia, status),
    KEY idx_fila_dia_ordem (dia, ordem),
    KEY idx_fila_dia_entered (dia, entered_at),
    CONSTRAINT chk_fila_order CHECK (ordem >= 0),
    CONSTRAINT chk_fila_status CHECK (status IN ('na_fila', 'em_entrega'))
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
