<?php

declare(strict_types=1);

namespace DaVez\Database;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use mysqli;
use mysqli_sql_exception;
use mysqli_stmt;

final class PublicIdentityStore
{
    private const REVOCATION_REASONS = [
        'logout',
        'recovery',
        'admin',
        'cycle_end',
        'security',
    ];

    /** @var mysqli */
    private $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Persiste somente o HMAC binário do ticket.
     *
     * Tickets de check-in ainda não possuem checkin_id. Tickets de recovery
     * são obrigatoriamente vinculados ao check-in que poderá recuperar.
     */
    public function issueTicket(
        string $ticketHash,
        string $purpose,
        ?int $checkinId,
        string $operationalDate,
        DateTimeInterface $createdAt,
        DateTimeInterface $expiresAt
    ): int {
        self::assertBinaryHash($ticketHash, 'ticket');
        self::assertPurposeAndBinding($purpose, $checkinId);
        self::assertOperationalDate($operationalDate);

        if (
            $expiresAt->getTimestamp() - $createdAt->getTimestamp()
                !== 600
        ) {
            throw new InvalidArgumentException(
                'O ticket deve possuir validade exata de dez minutos.'
            );
        }

        $createdSql = self::toSql($createdAt);
        $expiresSql = self::toSql($expiresAt);
        $statement = $this->prepare(
            'INSERT INTO admission_tickets
                (ticket_hash, purpose, checkin_id, operational_date,
                 created_at, expires_at, consumed_at, revoked_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)',
            'Não foi possível emitir o ticket público.'
        );

        try {
            $statement->bind_param(
                'ssisss',
                $ticketHash,
                $purpose,
                $checkinId,
                $operationalDate,
                $createdSql,
                $expiresSql
            );
            $this->execute(
                $statement,
                'Não foi possível emitir o ticket público.'
            );

            return (int) $this->connection->insert_id;
        } finally {
            $statement->close();
        }
    }

    /**
     * Deve ser chamado dentro da mesma transação que consome o ticket.
     *
     * @return array{
     *   id: int,
     *   purpose: 'checkin'|'recovery',
     *   checkin_id: int|null,
     *   operational_date: string,
     *   created_at: string,
     *   expires_at: string
     * }|null
     */
    public function loadTicketForUpdate(
        string $ticketHash,
        string $purpose,
        string $operationalDate,
        DateTimeInterface $now
    ): ?array {
        self::assertBinaryHash($ticketHash, 'ticket');
        self::assertPurpose($purpose);
        self::assertOperationalDate($operationalDate);
        $nowSql = self::toSql($now);
        $statement = $this->prepare(
            'SELECT id, purpose, checkin_id, operational_date,
                    created_at, expires_at
             FROM admission_tickets
             WHERE ticket_hash = ?
               AND purpose = ?
               AND operational_date = ?
               AND consumed_at IS NULL
               AND revoked_at IS NULL
               AND expires_at > ?
             LIMIT 1
             FOR UPDATE',
            'Não foi possível consultar o ticket público.'
        );

        try {
            $statement->bind_param(
                'ssss',
                $ticketHash,
                $purpose,
                $operationalDate,
                $nowSql
            );
            $this->execute(
                $statement,
                'Não foi possível consultar o ticket público.'
            );

            $id = null;
            $loadedPurpose = null;
            $checkinId = null;
            $loadedOperationalDate = null;
            $createdAt = null;
            $expiresAt = null;
            $statement->bind_result(
                $id,
                $loadedPurpose,
                $checkinId,
                $loadedOperationalDate,
                $createdAt,
                $expiresAt
            );

            if (!$statement->fetch()) {
                return null;
            }

            return [
                'id' => (int) $id,
                'purpose' => (string) $loadedPurpose,
                'checkin_id' => $checkinId === null
                    ? null
                    : (int) $checkinId,
                'operational_date' => (string) $loadedOperationalDate,
                'created_at' => (string) $createdAt,
                'expires_at' => (string) $expiresAt,
            ];
        } finally {
            $statement->close();
        }
    }

    /**
     * Marca o ticket como consumido uma única vez.
     */
    public function consumeTicket(
        int $ticketId,
        int $checkinId,
        DateTimeInterface $consumedAt
    ): bool {
        self::assertPositiveId($ticketId, 'ticket');
        self::assertPositiveId($checkinId, 'check-in');
        $consumedSql = self::toSql($consumedAt);
        $statement = $this->prepare(
            'UPDATE admission_tickets
             SET consumed_at = ?, checkin_id = ?
             WHERE id = ?
               AND consumed_at IS NULL
               AND revoked_at IS NULL
               AND expires_at > ?
               AND (
                    (purpose = \'checkin\' AND checkin_id IS NULL)
                    OR
                    (purpose = \'recovery\' AND checkin_id = ?)
               )',
            'Não foi possível consumir o ticket público.'
        );

        try {
            $statement->bind_param(
                'siisi',
                $consumedSql,
                $checkinId,
                $ticketId,
                $consumedSql,
                $checkinId
            );
            $this->execute(
                $statement,
                'Não foi possível consumir o ticket público.'
            );

            return $statement->affected_rows === 1;
        } finally {
            $statement->close();
        }
    }

    public function revokeTicket(
        int $ticketId,
        DateTimeInterface $revokedAt
    ): bool {
        self::assertPositiveId($ticketId, 'ticket');
        $revokedSql = self::toSql($revokedAt);
        $statement = $this->prepare(
            'UPDATE admission_tickets
             SET revoked_at = ?
             WHERE id = ?
               AND revoked_at IS NULL',
            'Não foi possível revogar o ticket público.'
        );

        try {
            $statement->bind_param('si', $revokedSql, $ticketId);
            $this->execute(
                $statement,
                'Não foi possível revogar o ticket público.'
            );

            return $statement->affected_rows === 1;
        } finally {
            $statement->close();
        }
    }

    /**
     * Cria a única sessão ativa do check-in.
     *
     * A constraint UNIQUE(checkin_id, active_slot) é a autoridade final para
     * impedir duas sessões ativas. Sessões revogadas recebem active_slot=NULL.
     */
    public function createSession(
        int $checkinId,
        string $tokenHash,
        string $operationalDate,
        DateTimeInterface $createdAt,
        DateTimeInterface $expiresAt,
        ?int $rotatedFrom = null
    ): int {
        self::assertPositiveId($checkinId, 'check-in');
        self::assertBinaryHash($tokenHash, 'sessão');
        self::assertOperationalDate($operationalDate);

        if ($rotatedFrom !== null) {
            self::assertPositiveId($rotatedFrom, 'sessão anterior');
        }

        if ($expiresAt->getTimestamp() <= $createdAt->getTimestamp()) {
            throw new InvalidArgumentException(
                'A expiração da sessão deve ser posterior à criação.'
            );
        }

        if (
            $expiresAt->getTimestamp() - $createdAt->getTimestamp()
                > 86400
        ) {
            throw new InvalidArgumentException(
                'A sessão pública não pode ultrapassar 24 horas.'
            );
        }

        $createdSql = self::toSql($createdAt);
        $expiresSql = self::toSql($expiresAt);
        $activeSlot = 1;
        $statement = $this->prepare(
            'INSERT INTO public_sessions
                (checkin_id, token_hash, operational_date, created_at,
                 last_seen_at, expires_at, revoked_at, revocation_reason,
                 rotated_from_id, active_slot)
             VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)',
            'Não foi possível criar a sessão pública.'
        );

        try {
            $statement->bind_param(
                'isssssii',
                $checkinId,
                $tokenHash,
                $operationalDate,
                $createdSql,
                $createdSql,
                $expiresSql,
                $rotatedFrom,
                $activeSlot
            );
            $this->execute(
                $statement,
                'Não foi possível criar a sessão pública.'
            );

            return (int) $this->connection->insert_id;
        } finally {
            $statement->close();
        }
    }

    /**
     * Revoga todas as sessões ainda marcadas como ativas antes da recuperação.
     */
    public function revokeActiveSessions(
        int $checkinId,
        string $reason,
        DateTimeInterface $revokedAt
    ): int {
        self::assertPositiveId($checkinId, 'check-in');
        self::assertRevocationReason($reason);
        $revokedSql = self::toSql($revokedAt);
        $statement = $this->prepare(
            'UPDATE public_sessions
             SET revoked_at = ?, revocation_reason = ?,
                 active_slot = NULL
             WHERE checkin_id = ?
               AND active_slot = 1
               AND revoked_at IS NULL',
            'Não foi possível revogar as sessões públicas.'
        );

        try {
            $statement->bind_param(
                'ssi',
                $revokedSql,
                $reason,
                $checkinId
            );
            $this->execute(
                $statement,
                'Não foi possível revogar as sessões públicas.'
            );

            return $statement->affected_rows;
        } finally {
            $statement->close();
        }
    }

    public function revokeSession(
        int $sessionId,
        string $reason,
        DateTimeInterface $revokedAt
    ): bool {
        self::assertPositiveId($sessionId, 'sessão');
        self::assertRevocationReason($reason);
        $revokedSql = self::toSql($revokedAt);
        $statement = $this->prepare(
            'UPDATE public_sessions
             SET revoked_at = ?, revocation_reason = ?,
                 active_slot = NULL
             WHERE id = ?
               AND active_slot = 1
               AND revoked_at IS NULL',
            'Não foi possível revogar a sessão pública.'
        );

        try {
            $statement->bind_param(
                'ssi',
                $revokedSql,
                $reason,
                $sessionId
            );
            $this->execute(
                $statement,
                'Não foi possível revogar a sessão pública.'
            );

            return $statement->affected_rows === 1;
        } finally {
            $statement->close();
        }
    }

    /**
     * Resolve a identidade somente por hash de sessão, ciclo e validade.
     *
     * @return array{
     *   id: int,
     *   checkin_id: int,
     *   nome: string,
     *   operational_date: string,
     *   created_at: string,
     *   expires_at: string
     * }|null
     */
    public function findValidSession(
        string $tokenHash,
        string $operationalDate,
        DateTimeInterface $now
    ): ?array {
        self::assertBinaryHash($tokenHash, 'sessão');
        self::assertOperationalDate($operationalDate);
        $nowSql = self::toSql($now);
        $statement = $this->prepare(
            'SELECT sessions.id, sessions.checkin_id, checkins.nome,
                    sessions.operational_date, sessions.created_at,
                    sessions.expires_at
             FROM public_sessions AS sessions
             INNER JOIN checkins
                     ON checkins.id = sessions.checkin_id
             WHERE sessions.token_hash = ?
               AND sessions.operational_date = ?
               AND sessions.active_slot = 1
               AND sessions.revoked_at IS NULL
               AND sessions.expires_at > ?
               AND COALESCE(checkins.is_closed, 0) = 0
             LIMIT 1',
            'Não foi possível consultar a sessão pública.'
        );

        try {
            $statement->bind_param(
                'sss',
                $tokenHash,
                $operationalDate,
                $nowSql
            );
            $this->execute(
                $statement,
                'Não foi possível consultar a sessão pública.'
            );

            $id = null;
            $checkinId = null;
            $name = null;
            $loadedOperationalDate = null;
            $createdAt = null;
            $expiresAt = null;
            $statement->bind_result(
                $id,
                $checkinId,
                $name,
                $loadedOperationalDate,
                $createdAt,
                $expiresAt
            );

            if (!$statement->fetch()) {
                return null;
            }

            return [
                'id' => (int) $id,
                'checkin_id' => (int) $checkinId,
                'nome' => (string) $name,
                'operational_date' => (string) $loadedOperationalDate,
                'created_at' => (string) $createdAt,
                'expires_at' => (string) $expiresAt,
            ];
        } finally {
            $statement->close();
        }
    }

    private function prepare(
        string $sql,
        string $publicError
    ): mysqli_stmt {
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

    private function execute(
        mysqli_stmt $statement,
        string $publicError
    ): void {
        try {
            if (!$statement->execute()) {
                throw new RuntimeException($publicError);
            }
        } catch (mysqli_sql_exception $exception) {
            throw new RuntimeException($publicError);
        }
    }

    private static function assertBinaryHash(
        string $hash,
        string $label
    ): void {
        if (strlen($hash) !== 32) {
            throw new InvalidArgumentException(sprintf(
                'O hash binário de %s deve possuir 32 bytes.',
                $label
            ));
        }
    }

    private static function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, ['checkin', 'recovery'], true)) {
            throw new InvalidArgumentException(
                'A finalidade do ticket é inválida.'
            );
        }
    }

    private static function assertPurposeAndBinding(
        string $purpose,
        ?int $checkinId
    ): void {
        self::assertPurpose($purpose);

        if (
            ($purpose === 'checkin' && $checkinId !== null)
            || ($purpose === 'recovery' && ($checkinId === null || $checkinId <= 0))
        ) {
            throw new InvalidArgumentException(
                'O vínculo do ticket não corresponde à finalidade.'
            );
        }
    }

    private static function assertOperationalDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || ($errors !== false && (
                $errors['warning_count'] !== 0
                || $errors['error_count'] !== 0
            ))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException(
                'A data operacional é inválida.'
            );
        }
    }

    private static function assertPositiveId(int $id, string $label): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(sprintf(
                'O identificador de %s é inválido.',
                $label
            ));
        }
    }

    private static function assertRevocationReason(string $reason): void
    {
        if (!in_array($reason, self::REVOCATION_REASONS, true)) {
            throw new InvalidArgumentException(
                'O motivo de revogação é inválido.'
            );
        }
    }

    /**
     * O chamador deve fornecer datas no timezone operacional configurado na
     * conexão MySQL. Não convertemos para UTC porque operational_date e as
     * constraints de ciclo usam DATETIME local.
     */
    private static function toSql(DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }
}
