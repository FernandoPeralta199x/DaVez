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
    operational_date DATE NULL,
    ordem INT UNSIGNED NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    closed_at DATETIME NULL,
    obs VARCHAR(120) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_checkins_id_operational_date (id, operational_date),
    KEY idx_checkins_cycle_order (data_hora, is_closed, ordem, id),
    KEY idx_checkins_client_cycle (client_id, data_hora),
    KEY idx_checkins_name_cycle (nome, data_hora),
    KEY idx_checkins_closed_duration (is_closed, data_hora, closed_at),
    KEY idx_checkins_operational_date (operational_date, id),
    CONSTRAINT chk_checkins_closed CHECK (is_closed IN (0, 1)),
    CONSTRAINT chk_checkins_order CHECK (ordem IS NULL OR ordem >= 1),
    CONSTRAINT chk_checkins_closed_at CHECK (
        (is_closed = 0 AND closed_at IS NULL)
        OR (is_closed = 1 AND closed_at IS NOT NULL)
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admission_tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_hash BINARY(32) NOT NULL,
    purpose VARCHAR(16) NOT NULL,
    operational_date DATE NOT NULL,
    checkin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_admission_ticket_hash (ticket_hash),
    KEY idx_admission_ticket_expiry (
        expires_at,
        consumed_at,
        revoked_at
    ),
    KEY idx_admission_ticket_checkin_cycle (
        checkin_id,
        operational_date,
        created_at
    ),
    CONSTRAINT fk_admission_ticket_checkin_cycle
        FOREIGN KEY (checkin_id, operational_date)
        REFERENCES checkins (id, operational_date)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,
    CONSTRAINT chk_admission_ticket_purpose CHECK (
        purpose IN ('checkin', 'recovery')
    ),
    CONSTRAINT chk_admission_ticket_ttl CHECK (
        expires_at = DATE_ADD(created_at, INTERVAL 10 MINUTE)
    ),
    CONSTRAINT chk_admission_ticket_consumption CHECK (
        consumed_at IS NULL OR consumed_at <= expires_at
    ),
    CONSTRAINT chk_admission_ticket_revocation CHECK (
        revoked_at IS NULL OR revoked_at >= created_at
    ),
    CONSTRAINT chk_admission_ticket_target CHECK (
        (
            purpose = 'checkin'
            AND (
                (consumed_at IS NULL AND checkin_id IS NULL)
                OR
                (consumed_at IS NOT NULL AND checkin_id IS NOT NULL)
            )
        )
        OR
        (purpose = 'recovery' AND checkin_id IS NOT NULL)
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS public_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    checkin_id BIGINT UNSIGNED NOT NULL,
    operational_date DATE NOT NULL,
    token_hash BINARY(32) NOT NULL,
    active_slot TINYINT UNSIGNED NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    revocation_reason VARCHAR(32) NULL,
    rotated_from_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_public_session_token_hash (token_hash),
    UNIQUE KEY uniq_public_session_active_device (
        checkin_id,
        active_slot
    ),
    KEY idx_public_session_checkin_cycle (
        checkin_id,
        operational_date,
        created_at
    ),
    KEY idx_public_session_expiry (
        operational_date,
        expires_at,
        revoked_at
    ),
    KEY idx_public_session_rotation (rotated_from_id),
    CONSTRAINT fk_public_session_checkin_cycle
        FOREIGN KEY (checkin_id, operational_date)
        REFERENCES checkins (id, operational_date)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,
    CONSTRAINT fk_public_session_rotation
        FOREIGN KEY (rotated_from_id)
        REFERENCES public_sessions (id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL,
    CONSTRAINT chk_public_session_active_slot CHECK (
        (active_slot = 1 AND revoked_at IS NULL)
        OR
        (active_slot IS NULL AND revoked_at IS NOT NULL)
    ),
    CONSTRAINT chk_public_session_expiry CHECK (
        expires_at > created_at
        AND expires_at <= DATE_ADD(created_at, INTERVAL 24 HOUR)
        AND expires_at <= DATE_ADD(
            CAST(operational_date AS DATETIME),
            INTERVAL 30 HOUR
        )
    ),
    CONSTRAINT chk_public_session_revocation CHECK (
        (revoked_at IS NULL AND revocation_reason IS NULL)
        OR
        (revoked_at IS NOT NULL AND revocation_reason IS NOT NULL)
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fila_da_vez (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dia DATE NOT NULL,
    client_id VARCHAR(64) NULL,
    checkin_id BIGINT UNSIGNED NULL,
    nome VARCHAR(120) NOT NULL,
    entered_at DATETIME NOT NULL,
    ordem INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'na_fila',
    last_action_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_fila_dia_client (dia, client_id),
    UNIQUE KEY uniq_fila_dia_checkin (dia, checkin_id),
    KEY idx_fila_checkin_dia (checkin_id, dia),
    KEY idx_fila_dia_status (dia, status),
    KEY idx_fila_dia_ordem (dia, ordem),
    KEY idx_fila_dia_entered (dia, entered_at),
    CONSTRAINT fk_fila_checkin_cycle
        FOREIGN KEY (checkin_id, dia)
        REFERENCES checkins (id, operational_date)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,
    CONSTRAINT chk_fila_identity_source CHECK (
        client_id IS NOT NULL OR checkin_id IS NOT NULL
    ),
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

-- Log durável de despachos (Migration 009). Append-only, sobrevive ao
-- fechamento do ciclo; alimenta o ranking de motoboys. Sem FK para checkins.
CREATE TABLE IF NOT EXISTS delivery_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operational_date DATE NOT NULL,
    checkin_id BIGINT UNSIGNED NULL,
    nome VARCHAR(120) NOT NULL,
    dispatched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    queue_wait_seconds INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_delivery_date_name (operational_date, nome),
    KEY idx_delivery_date_time (operational_date, dispatched_at),
    KEY idx_delivery_checkin (checkin_id, operational_date),
    CONSTRAINT chk_delivery_wait CHECK (
        queue_wait_seconds IS NULL OR queue_wait_seconds >= 0
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Código de acesso diário reutilizável (Migration 010). Vinculado ao motoboy
-- até a virada do ciclo; serve para check-in, re-entrada e recuperação. Guarda
-- somente o HMAC binário do código; o valor bruto nunca é persistido.
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
