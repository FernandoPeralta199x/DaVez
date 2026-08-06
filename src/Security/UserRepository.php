<?php

declare(strict_types=1);

namespace DaVez\Security;

use DateTimeInterface;

/**
 * Contrato de persistência de usuários administrativos (ADR-002).
 *
 * Existe para desacoplar o AdminAuthenticator (lógica de login) do banco,
 * permitindo testá-lo com um repositório em memória. A implementação real é
 * DaVez\Database\UserStore (prepared statements).
 */
interface UserRepository
{
    /**
     * Busca um usuário pelo login (único na plataforma).
     *
     * @return array{
     *   id: int|string,
     *   tenant_id: int|string|null,
     *   login: string,
     *   password_hash: string,
     *   role: string,
     *   must_change_password: int|string|bool,
     *   mfa_secret: string|null,
     *   failed_attempts: int|string,
     *   locked_until: string|null,
     *   status: string
     * }|null
     */
    public function findByLogin(string $login): ?array;

    /**
     * Registra uma tentativa de login inválida. Incrementa o contador e, ao
     * atingir $maxAttempts, aplica o bloqueio até $lockUntil.
     */
    public function registerFailedLogin(
        int $userId,
        int $maxAttempts,
        DateTimeInterface $lockUntil,
        DateTimeInterface $now
    ): void;

    /** Zera o contador de falhas e o bloqueio após login bem-sucedido. */
    public function clearFailedLogins(int $userId, DateTimeInterface $now): void;

    /** Atualiza o hash da senha (rehash), sem alterar must_change_password. */
    public function updatePasswordHash(
        int $userId,
        string $newHash,
        DateTimeInterface $now
    ): void;
}
