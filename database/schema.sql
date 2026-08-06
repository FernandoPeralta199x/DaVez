-- Schema canônico do DaVez para uma instalação nova.
-- Derivado exclusivamente das consultas presentes no código rastreado.
-- Não aplique sobre um banco legado sem executar o preflight documentado em
-- docs/DATABASE_OPERATIONS.md.
--
-- Estado multi-tenant (Fase 1/2): tenant_id é NULLABLE nas tabelas de negócio.
-- O reforço NOT NULL + FK é uma etapa futura (docs/PHASE1_ENFORCEMENT.md),
-- aplicada só após o retrofit dos endpoints.

SET NAMES utf8mb4;

-- Entidade de isolamento SaaS (Migration 011). empresa = loja: só tenant_id.
CREATE TABLE IF NOT EXISTS tenants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    cnpj VARCHAR(14) NULL,
    slug VARCHAR(120) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
    operational_cycle_time TIME NOT NULL DEFAULT '01:30:00',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenants_slug (slug),
    KEY idx_tenants_status (status),
    CONSTRAINT chk_tenants_status CHECK (
        status IN ('active', 'paused', 'archived', 'soft_deleted')
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    id TINYINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NULL,
    token VARCHAR(64) NOT NULL DEFAULT '',
    token_data DATE NULL,
    chamada_aberta TINYINT(1) NOT NULL DEFAULT 0,
    chamada_inicio DATETIME NULL,
    chamada_fim DATETIME NULL,
    lat_base DECIMAL(10, 7) NOT NULL DEFAULT 0.0000000,
    lng_base DECIMAL(10, 7) NOT NULL DEFAULT 0.0000000,
    raio INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_settings_tenant (tenant_id),
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
    tenant_id BIGINT UNSIGNED NULL,
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
    KEY idx_checkins_tenant_operational_date (tenant_id, operational_date),
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
    tenant_id BIGINT UNSIGNED NULL,
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
    KEY idx_admission_tenant_cycle_expiry (
        tenant_id,
        operational_date,
        expires_at
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
    tenant_id BIGINT UNSIGNED NULL,
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
    KEY idx_public_session_tenant_cycle_expiry (
        tenant_id,
        operational_date,
        expires_at,
        revoked_at
    ),
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
    tenant_id BIGINT UNSIGNED NULL,
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
    KEY idx_fila_tenant_day_status_order (tenant_id, dia, status, ordem),
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
    tenant_id BIGINT UNSIGNED NULL,
    periodo_inicio DATETIME NOT NULL,
    periodo_fim DATETIME NOT NULL,
    total_checkins INT UNSIGNED NOT NULL DEFAULT 0,
    motoboys_unicos INT UNSIGNED NOT NULL DEFAULT 0,
    total_fechados INT UNSIGNED NOT NULL DEFAULT 0,
    payload_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reports_period (periodo_inicio, periodo_fim),
    KEY idx_reports_tenant_period (tenant_id, periodo_inicio, periodo_fim),
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
    tenant_id BIGINT UNSIGNED NULL,
    operational_date DATE NOT NULL,
    checkin_id BIGINT UNSIGNED NULL,
    nome VARCHAR(120) NOT NULL,
    dispatched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    queue_wait_seconds INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_delivery_date_name (operational_date, nome),
    KEY idx_delivery_date_time (operational_date, dispatched_at),
    KEY idx_delivery_checkin (checkin_id, operational_date),
    KEY idx_delivery_tenant_operational_date (tenant_id, operational_date),
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
    tenant_id BIGINT UNSIGNED NULL,
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
    KEY idx_daily_code_tenant_operational_date (tenant_id, operational_date),
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

-- Usuários administrativos (Migration 015). SUPER_ADMIN (tenant_id NULL) e
-- ADMIN_EMPRESA (com tenant). Senha só como hash Argon2id.
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NULL,
    login VARCHAR(120) NOT NULL,
    email VARCHAR(190) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(16) NOT NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    mfa_secret VARCHAR(64) NULL,
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_login (login),
    KEY idx_users_tenant_role_status (tenant_id, role, status),
    CONSTRAINT fk_users_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_users_role CHECK (
        role IN ('SUPER_ADMIN', 'ADMIN_EMPRESA')
    ),
    CONSTRAINT chk_users_status CHECK (
        status IN ('active', 'suspended', 'deleted')
    ),
    CONSTRAINT chk_users_must_change CHECK (must_change_password IN (0, 1)),
    CONSTRAINT chk_users_role_tenant CHECK (
        (role = 'SUPER_ADMIN' AND tenant_id IS NULL)
        OR (role = 'ADMIN_EMPRESA' AND tenant_id IS NOT NULL)
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Sessões administrativas (Migration 016). Guarda só o SHA-256 do token opaco.
CREATE TABLE IF NOT EXISTS admin_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NULL,
    role VARCHAR(16) NOT NULL,
    token_hash BINARY(32) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    revocation_reason VARCHAR(32) NULL,
    ip_hash BINARY(32) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_admin_session_token (token_hash),
    KEY idx_admin_session_user (user_id, revoked_at, expires_at),
    KEY idx_admin_session_tenant (tenant_id, expires_at),
    CONSTRAINT fk_admin_session_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_admin_session_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_admin_session_expiry CHECK (expires_at > created_at),
    CONSTRAINT chk_admin_session_revocation CHECK (
        (revoked_at IS NULL AND revocation_reason IS NULL)
        OR (revoked_at IS NOT NULL AND revocation_reason IS NOT NULL)
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
