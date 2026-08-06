<?php

declare(strict_types=1);

namespace DaVez\Security;

use DaVez\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Resultado imutável de uma tentativa de autenticação administrativa.
 *
 * Em caso de sucesso carrega o TenantContext derivado (nunca de input do
 * cliente) e se a senha precisa ser trocada no primeiro acesso.
 */
final class AdminAuthResult
{
    public const SUCCESS = 'success';
    public const INVALID_CREDENTIALS = 'invalid_credentials';
    public const LOCKED = 'locked';
    public const SUSPENDED = 'suspended';

    /** @var string */
    private $status;

    /** @var TenantContext|null */
    private $context;

    /** @var int|null */
    private $userId;

    /** @var bool */
    private $mustChangePassword;

    private function __construct(
        string $status,
        ?TenantContext $context,
        ?int $userId,
        bool $mustChangePassword
    ) {
        $this->status = $status;
        $this->context = $context;
        $this->userId = $userId;
        $this->mustChangePassword = $mustChangePassword;
    }

    public static function success(
        TenantContext $context,
        int $userId,
        bool $mustChangePassword
    ): self {
        return new self(self::SUCCESS, $context, $userId, $mustChangePassword);
    }

    public static function failure(string $status): self
    {
        if ($status === self::SUCCESS) {
            throw new InvalidArgumentException('Falha não pode ter status de sucesso.');
        }

        return new self($status, null, null, false);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function context(): TenantContext
    {
        if ($this->context === null) {
            throw new \RuntimeException('Sem contexto: a autenticação não teve sucesso.');
        }

        return $this->context;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }
}
