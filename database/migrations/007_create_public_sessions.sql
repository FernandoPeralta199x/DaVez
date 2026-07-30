-- DaVez migration 007
-- Cria sessões públicas opacas vinculadas ao check-in e ao ciclo.
-- token_hash recebe SHA-256 binário de um token aleatório de pelo menos 32 bytes.

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
