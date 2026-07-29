-- DaVez migration 001
-- Cria a configuração singleton exigida pelos endpoints legados.
-- Idempotente para instalações novas. Não valida uma tabela legada já existente.

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
