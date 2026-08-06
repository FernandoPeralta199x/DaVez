<?php

declare(strict_types=1);

namespace DaVez\Tenancy;

use InvalidArgumentException;

/**
 * Garante que toda consulta de negócio carregue o escopo do tenant (ADR-003).
 *
 * O `tenant_id` vem SEMPRE do TenantContext e é aplicado por placeholder
 * (prepared statement), nunca interpolado no SQL. Construir um TenantScope a
 * partir de um contexto de plataforma (sem tenant) falha de propósito — negar
 * por padrão.
 */
final class TenantScope
{
    /** @var int */
    private $tenantId;

    public function __construct(TenantContext $context)
    {
        $this->tenantId = $context->requireTenantId();
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    /**
     * Predicado obrigatório do WHERE, com placeholder. Passe um `$alias` de
     * tabela para consultas com JOIN (ex.: 'c' -> 'c.tenant_id = ?').
     */
    public function predicate(string $alias = ''): string
    {
        return $this->column($alias) . ' = ?';
    }

    /** Nome (qualificado) da coluna tenant_id, validando o alias. */
    public function column(string $alias = ''): string
    {
        if ($alias === '') {
            return 'tenant_id';
        }

        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $alias) !== 1) {
            throw new InvalidArgumentException('Alias de tabela inválido.');
        }

        return $alias . '.tenant_id';
    }
}
