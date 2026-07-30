-- DaVez migration 006
-- Cria tickets individuais de check-in e recuperação.
-- ticket_hash recebe HMAC-SHA-256 binário; o ticket bruto nunca é persistido.

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
