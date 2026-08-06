-- DaVez migration 015
-- Fase 2: usuários administrativos no banco (ADR-002).
-- Perfis de painel: SUPER_ADMIN (plataforma, tenant_id NULL) e ADMIN_EMPRESA
-- (preso ao seu tenant). ENTREGADOR NÃO é usuário aqui — continua no fluxo
-- público por código/QR. Senha só como hash (Argon2id); nunca em texto.

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
    -- SUPER_ADMIN é global (sem tenant); ADMIN_EMPRESA é sempre de um tenant.
    CONSTRAINT chk_users_role_tenant CHECK (
        (role = 'SUPER_ADMIN' AND tenant_id IS NULL)
        OR (role = 'ADMIN_EMPRESA' AND tenant_id IS NOT NULL)
    )
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
