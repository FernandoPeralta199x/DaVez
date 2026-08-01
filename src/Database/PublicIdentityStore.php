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
     * Consulta o estado operacional sem retornar o hash ou IDs internos.
     *
     * @return array{
     *   purpose: 'checkin'|'recovery',
     *   state: 'active'|'consumed'|'expired'|'revoked',
     *   expires_at: DateTimeImmutable
     * }|null
     */
    public function findTicketStatus(
        string $ticketHash,
        string $operationalDate,
        DateTimeInterface $now
    ): ?array {
        self::assertBinaryHash($ticketHash, 'ticket');
        self::assertOperationalDate($operationalDate);
        $statement = $this->prepare(
            'SELECT purpose, expires_at, consumed_at, revoked_at
             FROM admission_tickets
             WHERE ticket_hash = ?
               AND operational_date = ?
             LIMIT 1',
            'Não foi possível consultar o estado do ticket público.'
        );

        try {
            $statement->bind_param(
                'ss',
                $ticketHash,
                $operationalDate
            );
            $this->execute(
                $statement,
                'Não foi possível consultar o estado do ticket público.'
            );

            $purpose = null;
            $expiresAt = null;
            $consumedAt = null;
            $revokedAt = null;
            $statement->bind_result(
                $purpose,
                $expiresAt,
                $consumedAt,
                $revokedAt
            );

            if (!$statement->fetch()) {
                return null;
            }

            self::assertPurpose((string) $purpose);
            $parsedExpiry = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                (string) $expiresAt,
                $now->getTimezone()
            );

            if ($parsedExpiry === false) {
                throw new RuntimeException(
                    'Não foi possível consultar o estado do ticket público.'
                );
            }

            $state = 'active';
            if ($revokedAt !== null) {
                $state = 'revoked';
            } elseif ($consumedAt !== null) {
                $state = 'consumed';
            } elseif ($parsedExpiry <= $now) {
                $state = 'expired';
            }

            return [
                'purpose' => (string) $purpose,
                'state' => $state,
                'expires_at' => $parsedExpiry,
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
     * Emite um código diário reutilizável, sem vínculo inicial de check-in.
     *
     * O vínculo é estabelecido na primeira ativação (primeiro check-in). O
     * código é válido até a virada do ciclo e guarda apenas o HMAC binário.
     */
    public function issueDailyCode(
        string $codeHash,
        string $operationalDate,
        ?string $label,
        DateTimeInterface $createdAt,
        DateTimeInterface $expiresAt
    ): int {
        self::assertBinaryHash($codeHash, 'código diário');
        self::assertOperationalDate($operationalDate);

        if ($expiresAt->getTimestamp() <= $createdAt->getTimestamp()) {
            throw new InvalidArgumentException(
                'O código diário deve expirar após a criação.'
            );
        }

        $normalizedLabel = null;
        if ($label !== null) {
            $trimmed = trim($label);
            $normalizedLabel = $trimmed === ''
                ? null
                : mb_substr($trimmed, 0, 120);
        }

        $createdSql = self::toSql($createdAt);
        $expiresSql = self::toSql($expiresAt);
        $statement = $this->prepare(
            'INSERT INTO daily_access_codes
                (code_hash, operational_date, checkin_id, label,
                 created_at, expires_at, activated_at, last_used_at,
                 revoked_at)
             VALUES (?, ?, NULL, ?, ?, ?, NULL, NULL, NULL)',
            'Não foi possível emitir o código diário.'
        );

        try {
            $statement->bind_param(
                'sssss',
                $codeHash,
                $operationalDate,
                $normalizedLabel,
                $createdSql,
                $expiresSql
            );
            $this->execute(
                $statement,
                'Não foi possível emitir o código diário.'
            );

            return (int) $this->connection->insert_id;
        } finally {
            $statement->close();
        }
    }

    /**
     * Carrega o código diário válido para uso, travando a linha na transação.
     *
     * checkin_id nulo indica código ainda não ativado (aguardando o primeiro
     * check-in). Um checkin_id preenchido indica re-login da mesma identidade.
     *
     * @return array{
     *   id: int,
     *   checkin_id: int|null,
     *   label: string|null,
     *   activated_at: string|null,
     *   expires_at: string
     * }|null
     */
    public function loadDailyCodeForUpdate(
        string $codeHash,
        string $operationalDate,
        DateTimeInterface $now
    ): ?array {
        self::assertBinaryHash($codeHash, 'código diário');
        self::assertOperationalDate($operationalDate);
        $nowSql = self::toSql($now);
        $statement = $this->prepare(
            'SELECT id, checkin_id, label, activated_at, expires_at
             FROM daily_access_codes
             WHERE code_hash = ?
               AND operational_date = ?
               AND revoked_at IS NULL
               AND expires_at > ?
             LIMIT 1
             FOR UPDATE',
            'Não foi possível consultar o código diário.'
        );

        try {
            $statement->bind_param(
                'sss',
                $codeHash,
                $operationalDate,
                $nowSql
            );
            $this->execute(
                $statement,
                'Não foi possível consultar o código diário.'
            );

            $id = null;
            $checkinId = null;
            $label = null;
            $activatedAt = null;
            $expiresAt = null;
            $statement->bind_result(
                $id,
                $checkinId,
                $label,
                $activatedAt,
                $expiresAt
            );

            if (!$statement->fetch()) {
                return null;
            }

            return [
                'id' => (int) $id,
                'checkin_id' => $checkinId === null
                    ? null
                    : (int) $checkinId,
                'label' => $label === null ? null : (string) $label,
                'activated_at' => $activatedAt === null
                    ? null
                    : (string) $activatedAt,
                'expires_at' => (string) $expiresAt,
            ];
        } finally {
            $statement->close();
        }
    }

    /**
     * Vincula o código ao check-in na primeira ativação. Idempotente por linha.
     */
    public function activateDailyCode(
        int $codeId,
        int $checkinId,
        DateTimeInterface $now
    ): bool {
        self::assertPositiveId($codeId, 'código diário');
        self::assertPositiveId($checkinId, 'check-in');
        $nowSql = self::toSql($now);
        $statement = $this->prepare(
            'UPDATE daily_access_codes
             SET checkin_id = ?, activated_at = ?, last_used_at = ?
             WHERE id = ?
               AND checkin_id IS NULL
               AND activated_at IS NULL
               AND revoked_at IS NULL
               AND expires_at > ?',
            'Não foi possível ativar o código diário.'
        );

        try {
            $statement->bind_param(
                'issis',
                $checkinId,
                $nowSql,
                $nowSql,
                $codeId,
                $nowSql
            );
            $this->execute(
                $statement,
                'Não foi possível ativar o código diário.'
            );

            return $statement->affected_rows === 1;
        } finally {
            $statement->close();
        }
    }

    /**
     * Registra o uso mais recente do código diário (re-login).
     */
    public function touchDailyCode(
        int $codeId,
        DateTimeInterface $now
    ): void {
        self::assertPositiveId($codeId, 'código diário');
        $nowSql = self::toSql($now);
        $statement = $this->prepare(
            'UPDATE daily_access_codes
             SET last_used_at = ?
             WHERE id = ?
               AND revoked_at IS NULL',
            'Não foi possível atualizar o código diário.'
        );

        try {
            $statement->bind_param('si', $nowSql, $codeId);
            $this->execute(
                $statement,
                'Não foi possível atualizar o código diário.'
            );
        } finally {
            $statement->close();
        }
    }

    /**
     * Consulta o estado operacional do código diário sem revelar IDs internos.
     *
     * @return array{
     *   label: string|null,
     *   state: 'active'|'expired'|'revoked',
     *   activated: bool,
     *   expires_at: DateTimeImmutable
     * }|null
     */
    public function findDailyCodeStatus(
        string $codeHash,
        string $operationalDate,
        DateTimeInterface $now
    ): ?array {
        self::assertBinaryHash($codeHash, 'código diário');
        self::assertOperationalDate($operationalDate);
        $statement = $this->prepare(
            'SELECT label, expires_at, activated_at, revoked_at
             FROM daily_access_codes
             WHERE code_hash = ?
               AND operational_date = ?
             LIMIT 1',
            'Não foi possível consultar o estado do código diário.'
        );

        try {
            $statement->bind_param('ss', $codeHash, $operationalDate);
            $this->execute(
                $statement,
                'Não foi possível consultar o estado do código diário.'
            );

            $label = null;
            $expiresAt = null;
            $activatedAt = null;
            $revokedAt = null;
            $statement->bind_result(
                $label,
                $expiresAt,
                $activatedAt,
                $revokedAt
            );

            if (!$statement->fetch()) {
                return null;
            }

            $parsedExpiry = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                (string) $expiresAt,
                $now->getTimezone()
            );

            if ($parsedExpiry === false) {
                throw new RuntimeException(
                    'Não foi possível consultar o estado do código diário.'
                );
            }

            $state = 'active';
            if ($revokedAt !== null) {
                $state = 'revoked';
            } elseif ($parsedExpiry <= $now) {
                $state = 'expired';
            }

            return [
                'label' => $label === null ? null : (string) $label,
                'state' => $state,
                'activated' => $activatedAt !== null,
                'expires_at' => $parsedExpiry,
            ];
        } finally {
            $statement->close();
        }
    }

    /**
     * Revoga um código diário, invalidando-o antes da virada do ciclo.
     */
    public function revokeDailyCode(
        int $codeId,
        DateTimeInterface $revokedAt
    ): bool {
        self::assertPositiveId($codeId, 'código diário');
        $revokedSql = self::toSql($revokedAt);
        $statement = $this->prepare(
            'UPDATE daily_access_codes
             SET revoked_at = ?
             WHERE id = ?
               AND revoked_at IS NULL',
            'Não foi possível revogar o código diário.'
        );

        try {
            $statement->bind_param('si', $revokedSql, $codeId);
            $this->execute(
                $statement,
                'Não foi possível revogar o código diário.'
            );

            return $statement->affected_rows === 1;
        } finally {
            $statement->close();
        }
    }

    /**
     * Renova o hash do código diário de um check-in (motoboy perdeu o código).
     *
     * Mantém uma única linha por check-in no dia; apenas o HMAC é rotacionado.
     */
    public function rotateDailyCodeHash(
        int $checkinId,
        string $operationalDate,
        string $newCodeHash,
        DateTimeInterface $now
    ): bool {
        self::assertPositiveId($checkinId, 'check-in');
        self::assertOperationalDate($operationalDate);
        self::assertBinaryHash($newCodeHash, 'código diário');
        $nowSql = self::toSql($now);
        $statement = $this->prepare(
            'UPDATE daily_access_codes
             SET code_hash = ?, last_used_at = ?
             WHERE checkin_id = ?
               AND operational_date = ?
               AND revoked_at IS NULL
               AND expires_at > ?',
            'Não foi possível renovar o código diário.'
        );

        try {
            $statement->bind_param(
                'ssiss',
                $newCodeHash,
                $nowSql,
                $checkinId,
                $operationalDate,
                $nowSql
            );
            $this->execute(
                $statement,
                'Não foi possível renovar o código diário.'
            );

            return $statement->affected_rows === 1;
        } finally {
            $statement->close();
        }
    }

    /**
     * Emite um código diário já vinculado e ativado a um check-in existente.
     *
     * Usado quando o check-in ainda não possui um código diário (ex.: inclusão
     * manual pelo administrador) e precisa de um código para recuperação.
     */
    public function issueActivatedDailyCode(
        string $codeHash,
        int $checkinId,
        string $operationalDate,
        ?string $label,
        DateTimeInterface $createdAt,
        DateTimeInterface $expiresAt
    ): int {
        self::assertBinaryHash($codeHash, 'código diário');
        self::assertPositiveId($checkinId, 'check-in');
        self::assertOperationalDate($operationalDate);

        if ($expiresAt->getTimestamp() <= $createdAt->getTimestamp()) {
            throw new InvalidArgumentException(
                'O código diário deve expirar após a criação.'
            );
        }

        $normalizedLabel = null;
        if ($label !== null) {
            $trimmed = trim($label);
            $normalizedLabel = $trimmed === ''
                ? null
                : mb_substr($trimmed, 0, 120);
        }

        $createdSql = self::toSql($createdAt);
        $expiresSql = self::toSql($expiresAt);
        $statement = $this->prepare(
            'INSERT INTO daily_access_codes
                (code_hash, operational_date, checkin_id, label,
                 created_at, expires_at, activated_at, last_used_at,
                 revoked_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
            'Não foi possível emitir o código diário.'
        );

        try {
            $statement->bind_param(
                'ssisssss',
                $codeHash,
                $operationalDate,
                $checkinId,
                $normalizedLabel,
                $createdSql,
                $expiresSql,
                $createdSql,
                $createdSql
            );
            $this->execute(
                $statement,
                'Não foi possível emitir o código diário.'
            );

            return (int) $this->connection->insert_id;
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
