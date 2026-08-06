-- DaVez migration 016
-- Fase 2: sessões administrativas no banco (ADR-002). Distinta de
-- public_sessions (entregador). Guarda somente o SHA-256 binário do token
-- opaco; o token bruto nunca é persistido. Carrega tenant_id e role para o
-- TenantContext ser derivado da sessão autenticada.

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
