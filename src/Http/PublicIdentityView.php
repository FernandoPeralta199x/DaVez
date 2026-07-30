<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Security/PublicIdentity.php';
require_once dirname(__DIR__) . '/Domain/OperationalContext.php';

use DaVez\Domain\OperationalContext;

/**
 * Monta somente a visão pertencente à sessão autenticada.
 *
 * @param array{checkin_id: int, nome: string} $identity
 * @return array<string, mixed>
 */
function davez_public_identity_me(
    mysqli $connection,
    array $identity,
    OperationalContext $context
): array {
    $checkinId = (int) $identity['checkin_id'];
    $operationalDate = $context->date();
    $checkin = $connection->prepare(
        'SELECT nome, ordem, COALESCE(is_closed,0)
         FROM checkins
         WHERE id=?
           AND operational_date=?
         LIMIT 1'
    );

    if (!$checkin) {
        throw new RuntimeException('Estado público indisponível.');
    }

    $checkin->bind_param('is', $checkinId, $operationalDate);

    if (!$checkin->execute()) {
        $checkin->close();
        throw new RuntimeException('Estado público indisponível.');
    }

    $name = null;
    $order = null;
    $isClosed = null;
    $checkin->bind_result($name, $order, $isClosed);

    if (!$checkin->fetch()) {
        $checkin->close();
        throw new RuntimeException('Estado público indisponível.');
    }
    $checkin->close();

    $me = [
        'display_name' => (string) $name,
        'checkin' => [
            'active' => (int) $isClosed === 0,
            'position' => $order === null ? null : (int) $order,
        ],
        'davez' => null,
    ];

    $queue = $connection->prepare(
        "SELECT id, status, ordem
         FROM fila_da_vez
         WHERE dia=?
           AND checkin_id=?
         ORDER BY entered_at DESC, id DESC
         LIMIT 1"
    );

    if (!$queue) {
        throw new RuntimeException('Estado público indisponível.');
    }

    $queue->bind_param('si', $operationalDate, $checkinId);

    if (!$queue->execute()) {
        $queue->close();
        throw new RuntimeException('Estado público indisponível.');
    }

    $queueId = null;
    $status = null;
    $queueOrder = null;
    $queue->bind_result($queueId, $status, $queueOrder);

    if ($queue->fetch()) {
        $queue->close();
        $position = null;

        if ((string) $status === 'na_fila') {
            $positionQuery = $connection->prepare(
                "SELECT COUNT(*)
                 FROM fila_da_vez
                 WHERE dia=?
                   AND status='na_fila'
                   AND (
                     ordem < ?
                     OR (ordem = ? AND id <= ?)
                   )"
            );

            if (!$positionQuery) {
                throw new RuntimeException('Estado público indisponível.');
            }

            $queueOrderInt = (int) $queueOrder;
            $queueIdInt = (int) $queueId;
            $positionQuery->bind_param(
                'siii',
                $operationalDate,
                $queueOrderInt,
                $queueOrderInt,
                $queueIdInt
            );

            if (!$positionQuery->execute()) {
                $positionQuery->close();
                throw new RuntimeException('Estado público indisponível.');
            }

            $positionQuery->bind_result($position);
            $positionQuery->fetch();
            $positionQuery->close();
        }

        $normalizedPosition = $position === null
            ? null
            : (int) $position;
        $me['davez'] = [
            'status' => (string) $status,
            'position' => $normalizedPosition,
            'is_next' => $normalizedPosition === 1,
        ];
    } else {
        $queue->close();
    }

    return $me;
}

/**
 * @return array{
 *   next: array{display_name: string}|null,
 *   counts: array{waiting: int, delivering: int}
 * }
 */
function davez_public_queue_summary(
    mysqli $connection,
    string $operationalDate
): array {
    $countsQuery = $connection->prepare(
        "SELECT
           SUM(CASE WHEN status='na_fila' THEN 1 ELSE 0 END),
           SUM(CASE WHEN status='em_entrega' THEN 1 ELSE 0 END)
         FROM fila_da_vez
         WHERE dia=?"
    );

    if (!$countsQuery) {
        throw new RuntimeException('Fila pública indisponível.');
    }

    $countsQuery->bind_param('s', $operationalDate);

    if (!$countsQuery->execute()) {
        $countsQuery->close();
        throw new RuntimeException('Fila pública indisponível.');
    }

    $waiting = null;
    $delivering = null;
    $countsQuery->bind_result($waiting, $delivering);
    $countsQuery->fetch();
    $countsQuery->close();

    $nextQuery = $connection->prepare(
        "SELECT nome
         FROM fila_da_vez
         WHERE dia=?
           AND status='na_fila'
         ORDER BY ordem ASC, entered_at ASC, id ASC
         LIMIT 1"
    );

    if (!$nextQuery) {
        throw new RuntimeException('Fila pública indisponível.');
    }

    $nextQuery->bind_param('s', $operationalDate);

    if (!$nextQuery->execute()) {
        $nextQuery->close();
        throw new RuntimeException('Fila pública indisponível.');
    }

    $nextName = null;
    $nextQuery->bind_result($nextName);
    $hasNext = $nextQuery->fetch();
    $nextQuery->close();

    return [
        'next' => $hasNext
            ? ['display_name' => davez_public_display_name((string) $nextName)]
            : null,
        'counts' => [
            'waiting' => (int) ($waiting ?? 0),
            'delivering' => (int) ($delivering ?? 0),
        ],
    ];
}
