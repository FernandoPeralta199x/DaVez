-- DaVez migration 011
-- Fundação multi-tenant (Fase 1). Cria a entidade de isolamento.
-- Decisão travada (ADR-001): empresa = loja, então tenant_id é a única chave de
-- isolamento; não existe store_id nesta fase. O login do administrador da
-- empresa vive em `users` (ADR-002), não aqui.

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
