-- DaVez migration 004
-- Cria o armazenamento dos relatórios operacionais gerados pelo painel.

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
