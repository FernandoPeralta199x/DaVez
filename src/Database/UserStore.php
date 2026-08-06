<?php

declare(strict_types=1);

namespace DaVez\Database;

use DaVez\Security\UserRepository;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use mysqli;
use mysqli_sql_exception;
use mysqli_stmt;

require_once __DIR__ . '/../Security/UserRepository.php';

/**
 * Persistência de usuários administrativos e sessões (ADR-002), com prepared
 * statements. Implementa UserRepository (usado pelo AdminAuthenticator) e
 * adiciona criação de usuário (bootstrap/cadastro) e o ciclo de sessão admin.
 */
final class UserStore implements UserRepository
{
    /** @var mysqli */
    private $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    public function findByLogin(string $login): ?array
    {
        $statement = $this->prepare(
            'SELECT id, tenant_id, login, password_hash, role,
                    must_change_password, mfa_secret, failed_attempts,
                    locked_until, status
             FROM users
             WHERE login = ?
               AND deleted_at IS NULL
             LIMIT 1',
            'Não foi possível consultar o usuário.'
        );

        try {
            $statement->bind_param('s', $login);
            $this->execute($statement, 'Não foi possível consultar o usuário.');
            $result = $statement->get_result();
            $row = $result ? $result->fetch_assoc() : null;

            return is_array($row) ? $row : null;
        } finally {
            $statement->close();
        }
    }

    public function registerFailedLogin(
        int $userId,
        int $maxAttempts,
        DateTimeInterface $lockUntil,
        DateTimeInterface $now
    ): void {
        self::assertPositive($userId, 'usuário');
        $lockSql = self::toSql($lockUntil);
        $statement = $this->prepare(
            'UPDATE users
             SET failed_attempts = failed_attempts + 1,
                 locked_until = CASE
                     WHEN failed_attempts + 1 >= ? THEN ?
                     ELSE locked_until
                 END
             WHERE id = ?',
            'Não foi possível registrar a falha de login.'
        );

        try {
            $statement->bind_param('isi', $maxAttempts, $lockSql, $userId);
            $this->execute(
                $statement,
                'Não foi possível registrar a falha de login.'
            );
        } finally {
            $statement->close();
        }
    }

    public function clearFailedLogins(int $userId, DateTimeInterface $now): void
    {
        self::assertPositive($userId, 'usuário');
        $statement = $this->prepare(
            'UPDATE users
             SET failed_attempts = 0, locked_until = NULL
             WHERE id = ?',
            'Não foi possível limpar as falhas de login.'
        );

        try {
            $statement->bind_param('i', $userId);
            $this->execute(
                $statement,
                'Não foi possível limpar as falhas de login.'
            );
        } finally {
            $statement->close();
        }
    }

    public function updatePasswordHash(
        int $userId,
        string $newHash,
        DateTimeInterface $now
    ): void {
        self::assertPositive($userId, 'usuário');
        $statement = $this->prepare(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            'Não foi possível atualizar o hash da senha.'
        );

        try {
            $statement->bind_param('si', $newHash, $userId);
            $this->execute(
                $statement,
                'Não foi possível atualizar o hash da senha.'
            );
        } finally {
            $statement->close();
        }
    }

    /**
     * Conclui a troca de senha no primeiro acesso: grava o novo hash, zera
     * must_change_password e limpa qualquer bloqueio. As sessões devem ser
     * revogadas em seguida por revokeAllSessionsForUser().
     */
    public function markPasswordChanged(int $userId, string $newHash): void
    {
        self::assertPositive($userId, 'usuário');
        $statement = $this->prepare(
            'UPDATE users
             SET password_hash = ?, must_change_password = 0,
                 failed_attempts = 0, locked_until = NULL
             WHERE id = ?',
            'Não foi possível concluir a troca de senha.'
        );

        try {
            $statement->bind_param('si', $newHash, $userId);
            $this->execute(
                $statement,
                'Não foi possível concluir a troca de senha.'
            );
        } finally {
            $statement->close();
        }
    }

    /**
     * Cria um usuário. `tenant_id` deve ser nulo para SUPER_ADMIN e preenchido
     * para ADMIN_EMPRESA (o CHECK do schema também garante isso).
     */
    public function createUser(
        ?int $tenantId,
        string $login,
        ?string $email,
        string $passwordHash,
        string $role,
        bool $mustChangePassword
    ): int {
        if ($tenantId !== null) {
            self::assertPositive($tenantId, 'tenant');
        }

        $mustChange = $mustChangePassword ? 1 : 0;
        $statement = $this->prepare(
            'INSERT INTO users
                (tenant_id, login, email, password_hash, role,
                 must_change_password, status)
             VALUES (?, ?, ?, ?, ?, ?, \'active\')',
            'Não foi possível criar o usuário.'
        );

        try {
            $statement->bind_param(
                'issssi',
                $tenantId,
                $login,
                $email,
                $passwordHash,
                $role,
                $mustChange
            );
            $this->execute($statement, 'Não foi possível criar o usuário.');

            return (int) $this->connection->insert_id;
        } finally {
            $statement->close();
        }
    }

    public function createSession(
        int $userId,
        ?int $tenantId,
        string $role,
        string $tokenHash,
        DateTimeInterface $createdAt,
        DateTimeInterface $expiresAt,
        ?string $ipHash = null
    ): int {
        self::assertPositive($userId, 'usuário');
        self::assertBinaryHash($tokenHash, 'sessão administrativa');

        if ($expiresAt->getTimestamp() <= $createdAt->getTimestamp()) {
            throw new InvalidArgumentException(
                'A expiração da sessão deve ser posterior à criação.'
            );
        }

        $createdSql = self::toSql($createdAt);
        $expiresSql = self::toSql($expiresAt);
        $statement = $this->prepare(
            'INSERT INTO admin_sessions
                (user_id, tenant_id, role, token_hash, created_at,
                 last_seen_at, expires_at, ip_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            'Não foi possível criar a sessão administrativa.'
        );

        try {
            $statement->bind_param(
                'iissssss',
                $userId,
                $tenantId,
                $role,
                $tokenHash,
                $createdSql,
                $createdSql,
                $expiresSql,
                $ipHash
            );
            $this->execute(
                $statement,
                'Não foi possível criar a sessão administrativa.'
            );

            return (int) $this->connection->insert_id;
        } finally {
            $statement->close();
        }
    }

    /**
     * @return array{
     *   id: int, user_id: int, tenant_id: int|null,
     *   role: string, login: string
     * }|null
     */
    public function findValidSession(
        string $tokenHash,
        DateTimeInterface $now
    ): ?array {
        self::assertBinaryHash($tokenHash, 'sessão administrativa');
        $nowSql = self::toSql($now);
        $statement = $this->prepare(
            'SELECT s.id, s.user_id, s.tenant_id, s.role, u.login
             FROM admin_sessions AS s
             INNER JOIN users AS u ON u.id = s.user_id
             WHERE s.token_hash = ?
               AND s.revoked_at IS NULL
               AND s.expires_at > ?
               AND u.status = \'active\'
               AND u.deleted_at IS NULL
             LIMIT 1',
            'Não foi possível consultar a sessão administrativa.'
        );

        try {
            $statement->bind_param('ss', $tokenHash, $nowSql);
            $this->execute(
                $statement,
                'Não foi possível consultar a sessão administrativa.'
            );
            $result = $statement->get_result();
            $row = $result ? $result->fetch_assoc() : null;

            if (!is_array($row)) {
                return null;
            }

            return [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'tenant_id' => $row['tenant_id'] === null
                    ? null
                    : (int) $row['tenant_id'],
                'role' => (string) $row['role'],
                'login' => (string) $row['login'],
            ];
        } finally {
            $statement->close();
        }
    }

    public function revokeSession(
        int $sessionId,
        string $reason,
        DateTimeInterface $revokedAt
    ): bool {
        self::assertPositive($sessionId, 'sessão');
        $revokedSql = self::toSql($revokedAt);
        $statement = $this->prepare(
            'UPDATE admin_sessions
             SET revoked_at = ?, revocation_reason = ?
             WHERE id = ? AND revoked_at IS NULL',
            'Não foi possível revogar a sessão administrativa.'
        );

        try {
            $statement->bind_param('ssi', $revokedSql, $reason, $sessionId);
            $this->execute(
                $statement,
                'Não foi possível revogar a sessão administrativa.'
            );

            return $statement->affected_rows === 1;
        } finally {
            $statement->close();
        }
    }

    public function revokeAllSessionsForUser(
        int $userId,
        string $reason,
        DateTimeInterface $revokedAt
    ): int {
        self::assertPositive($userId, 'usuário');
        $revokedSql = self::toSql($revokedAt);
        $statement = $this->prepare(
            'UPDATE admin_sessions
             SET revoked_at = ?, revocation_reason = ?
             WHERE user_id = ? AND revoked_at IS NULL',
            'Não foi possível revogar as sessões administrativas.'
        );

        try {
            $statement->bind_param('ssi', $revokedSql, $reason, $userId);
            $this->execute(
                $statement,
                'Não foi possível revogar as sessões administrativas.'
            );

            return $statement->affected_rows;
        } finally {
            $statement->close();
        }
    }

    private function prepare(string $sql, string $publicError): mysqli_stmt
    {
        try {
            $statement = $this->connection->prepare($sql);
        } catch (mysqli_sql_exception $exception) {
            throw new RuntimeException($publicError);
        }

        if (!$statement) {
            throw new RuntimeException($publicError);
        }

        return $statement;
    }

    private function execute(mysqli_stmt $statement, string $publicError): void
    {
        try {
            if (!$statement->execute()) {
                throw new RuntimeException($publicError);
            }
        } catch (mysqli_sql_exception $exception) {
            throw new RuntimeException($publicError);
        }
    }

    private static function assertPositive(int $id, string $label): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(sprintf(
                'O identificador de %s é inválido.',
                $label
            ));
        }
    }

    private static function assertBinaryHash(string $hash, string $label): void
    {
        if (strlen($hash) !== 32) {
            throw new InvalidArgumentException(sprintf(
                'O hash binário de %s deve possuir 32 bytes.',
                $label
            ));
        }
    }

    private static function toSql(DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }
}
