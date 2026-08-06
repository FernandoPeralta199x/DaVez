<?php

declare(strict_types=1);

namespace DaVez\Security;

use DaVez\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeInterface;

require_once __DIR__ . '/PasswordHasher.php';
require_once __DIR__ . '/UserRepository.php';
require_once __DIR__ . '/AdminAuthResult.php';
require_once __DIR__ . '/../Tenancy/TenantContext.php';

/**
 * Fluxo de login administrativo (ADR-002).
 *
 * Verifica a senha com Argon2id, respeita o bloqueio por tentativas, força a
 * troca no primeiro acesso e deriva o TenantContext a partir do usuário
 * autenticado. Não cria a sessão nem seta cookie — isso é responsabilidade do
 * endpoint, que usa o resultado retornado.
 */
final class AdminAuthenticator
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;

    /** @var UserRepository */
    private $users;

    public function __construct(UserRepository $users)
    {
        $this->users = $users;
    }

    public function authenticate(
        string $login,
        string $password,
        DateTimeInterface $now
    ): AdminAuthResult {
        $user = $this->users->findByLogin($login);

        // Usuário inexistente: não revela a diferença.
        if ($user === null) {
            return AdminAuthResult::failure(AdminAuthResult::INVALID_CREDENTIALS);
        }

        $userId = (int) $user['id'];

        // Conta bloqueada por tentativas: nega antes de verificar a senha.
        if (self::isLocked($user['locked_until'] ?? null, $now)) {
            return AdminAuthResult::failure(AdminAuthResult::LOCKED);
        }

        if (!PasswordHasher::verify($password, (string) $user['password_hash'])) {
            $lockUntil = self::nowImmutable($now)->modify(
                '+' . self::LOCK_MINUTES . ' minutes'
            );
            $this->users->registerFailedLogin(
                $userId,
                self::MAX_ATTEMPTS,
                $lockUntil,
                $now
            );

            return AdminAuthResult::failure(AdminAuthResult::INVALID_CREDENTIALS);
        }

        // Senha correta: usuário precisa estar ativo.
        if ((string) $user['status'] !== 'active') {
            return AdminAuthResult::failure(AdminAuthResult::SUSPENDED);
        }

        $this->users->clearFailedLogins($userId, $now);

        // Migra hashes antigos para o algoritmo preferido, de forma transparente.
        if (PasswordHasher::needsRehash((string) $user['password_hash'])) {
            $this->users->updatePasswordHash(
                $userId,
                PasswordHasher::hash($password),
                $now
            );
        }

        $context = $this->contextFor($user, $userId);
        $mustChange = self::asBool($user['must_change_password'] ?? false);

        return AdminAuthResult::success($context, $userId, $mustChange);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function contextFor(array $user, int $userId): TenantContext
    {
        $role = (string) $user['role'];

        if ($role === TenantContext::ROLE_SUPER_ADMIN) {
            return TenantContext::forPlatform($userId);
        }

        return TenantContext::forTenantAdmin((int) $user['tenant_id'], $userId);
    }

    private static function isLocked($lockedUntil, DateTimeInterface $now): bool
    {
        if ($lockedUntil === null || $lockedUntil === '') {
            return false;
        }

        $until = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            (string) $lockedUntil,
            self::nowImmutable($now)->getTimezone()
        );

        return $until !== false && $until > $now;
    }

    private static function nowImmutable(DateTimeInterface $now): DateTimeImmutable
    {
        return $now instanceof DateTimeImmutable
            ? $now
            : DateTimeImmutable::createFromInterface($now);
    }

    private static function asBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return (int) $value === 1;
    }
}
