<?php

declare(strict_types=1);

namespace DaVez\Tenancy;

use InvalidArgumentException;
use RuntimeException;

/**
 * Contexto de tenant imutável por request (ADR-003).
 *
 * É derivado EXCLUSIVAMENTE da identidade autenticada — nunca de input do
 * cliente — e é a única fonte de `tenant_id` para os repositórios com escopo.
 *
 * - SUPER_ADMIN: contexto de plataforma, sem tenant fixo; para operar sobre uma
 *   empresa precisa escolhê-la explicitamente (`forExplicitTenant`), nunca de
 *   forma implícita.
 * - ADMIN_EMPRESA / ENTREGADOR: presos ao seu próprio tenant.
 */
final class TenantContext
{
    public const ROLE_SUPER_ADMIN = 'SUPER_ADMIN';
    public const ROLE_ADMIN_EMPRESA = 'ADMIN_EMPRESA';
    public const ROLE_ENTREGADOR = 'ENTREGADOR';

    /** @var int|null nulo apenas para SUPER_ADMIN em contexto de plataforma */
    private $tenantId;

    /** @var string */
    private $role;

    /** @var int|null nulo para ENTREGADOR (identidade pública, sem user) */
    private $userId;

    private function __construct(?int $tenantId, string $role, ?int $userId)
    {
        $this->tenantId = $tenantId;
        $this->role = $role;
        $this->userId = $userId;
    }

    /** SUPER_ADMIN: contexto de plataforma, sem tenant fixo. */
    public static function forPlatform(int $userId): self
    {
        self::assertPositive($userId, 'userId');

        return new self(null, self::ROLE_SUPER_ADMIN, $userId);
    }

    /** ADMIN_EMPRESA: preso ao seu tenant. */
    public static function forTenantAdmin(int $tenantId, int $userId): self
    {
        self::assertPositive($tenantId, 'tenantId');
        self::assertPositive($userId, 'userId');

        return new self($tenantId, self::ROLE_ADMIN_EMPRESA, $userId);
    }

    /** ENTREGADOR: preso ao seu tenant, identidade pública (sem user). */
    public static function forDriver(int $tenantId): self
    {
        self::assertPositive($tenantId, 'tenantId');

        return new self($tenantId, self::ROLE_ENTREGADOR, null);
    }

    public function role(): string
    {
        return $this->role;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    public function isPlatform(): bool
    {
        return $this->tenantId === null;
    }

    public function is(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * `tenant_id` obrigatório para qualquer operação com escopo (negar por
     * padrão). SUPER_ADMIN em contexto de plataforma não tem tenant fixo e
     * precisa selecionar um com `forExplicitTenant` antes.
     */
    public function requireTenantId(): int
    {
        if ($this->tenantId === null) {
            throw new RuntimeException(
                'Operação com escopo de tenant exige um tenant selecionado.'
            );
        }

        return $this->tenantId;
    }

    /**
     * Deriva um contexto preso a um tenant específico. Só o SUPER_ADMIN pode
     * fazer isso, e a escolha é sempre explícita e auditável.
     */
    public function forExplicitTenant(int $tenantId): self
    {
        if ($this->role !== self::ROLE_SUPER_ADMIN) {
            throw new RuntimeException(
                'Somente SUPER_ADMIN pode operar sobre um tenant explícito.'
            );
        }

        self::assertPositive($tenantId, 'tenantId');

        return new self($tenantId, self::ROLE_SUPER_ADMIN, $this->userId);
    }

    private static function assertPositive(int $value, string $label): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(
                $label . ' deve ser um inteiro positivo.'
            );
        }
    }
}
