-- Schema canônico do DaVez para uma instalação nova.
-- Derivado exclusivamente das consultas presentes no código rastreado.
-- Não aplique sobre um banco legado sem executar o preflight documentado em
-- docs/DATABASE_OPERATIONS.md.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    id TINYINT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL DEFAULT '',
    token_data DATE NULL,
    chamada_aberta TINYINT(1) NOT NULL DEFAULT 0,
    chamada_inicio DATETIME NULL,
    chamada_fim DATETIME NULL,
    lat_base DECIMAL(10, 7) NOT NULL DEFAULT 0.0000000,
    lng_base DECIMAL(10, 7) NOT NULL DEFAULT 0.0000000,
    raio INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    CONSTRAINT chk_settings_singleton CHECK (id = 1),
    CONSTRAINT chk_settings_chamada_aberta CHECK (chamada_aberta IN (0, 1)),
    CONSTRAINT chk_settings_latitude CHECK (lat_base BETWEEN -90 AND 90),
    CONSTRAINT chk_settings_longitude CHECK (lng_base BETWEEN -180 AND 180),
    CONSTRAINT chk_settings_positive_radius CHECK (raio > 0)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

INSERT INTO settings (id)
VALUES (1)
ON DUPLICATE KEY UPDATE id = VALUES(id);

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

CREATE TABLE IF NOT EXISTS reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    periodo_inicio DATETIME NOT NULL,
    periodo_fim DATETIME NOT NULL,
    total_checkins INT UNSIGNED NOT NULL DEFAULT 0,
    motoboys_unicos INT UNSIGNED NOT NULL DEFAULT 0,
    total_fechados INT UNSIGNED NOT NULL DEFAULT 0,
    payload_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reports_period (periodo_inicio, periodo_fim),
    CONSTRAINT chk_reports_period CHECK (periodo_fim >= periodo_inicio),
    CONSTRAINT chk_reports_closed_total CHECK (total_fechados <= total_checkins),
    CONSTRAINT chk_reports_unique_total CHECK (motoboys_unicos <= total_checkins),
    CONSTRAINT chk_reports_json CHECK (JSON_VALID(payload_json))
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
