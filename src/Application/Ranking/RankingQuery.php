<?php

declare(strict_types=1);

namespace DaVez\Application\Ranking;

use DaVez\Domain\DeliveryRanking;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use mysqli;
use mysqli_stmt;

/**
 * Consulta paginada do ranking operacional.
 *
 * A ordenacao e a paginacao acontecem no MySQL. As consultas de serie diaria
 * e periodo anterior sao limitadas somente aos entregadores da pagina atual,
 * evitando carregar todo o historico em memoria quando a base crescer.
 */
final class RankingQuery
{
    /** @var mysqli */
    private $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array{start:string,end:string,days:int} $bounds
     * @param array{start:string,end:string,days:int} $previous
     * @return array{
     *   page:int,
     *   per_page:int,
     *   total:int,
     *   total_pages:int,
     *   ranking:list<array{
     *     position:int,
     *     nome:string,
     *     entregas:int,
     *     dias_ativos:int,
     *     media_dia:float,
     *     espera_media_seg:int|null,
     *     pontuacao:int,
     *     evolucao_pct:int|null,
     *     serie:list<int>
     *   }>
     * }
     */
    public function fetchPage(
        array $bounds,
        array $previous,
        int $page = 1,
        int $perPage = 25
    ): array {
        self::assertBounds($bounds);
        self::assertBounds($previous);

        if ($page < 1 || $page > 1000000) {
            throw new InvalidArgumentException('Pagina de ranking invalida.');
        }
        if ($perPage < 1 || $perPage > 500) {
            throw new InvalidArgumentException('Tamanho de pagina invalido.');
        }

        $total = $this->countDrivers($bounds['start'], $bounds['end']);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $scoreDelivery = DeliveryRanking::POINTS_PER_DELIVERY;
        $scoreActiveDay = DeliveryRanking::POINTS_PER_ACTIVE_DAY;
        $sql = sprintf(
            'SELECT nome, COUNT(*) AS entregas, '
            . '       COUNT(DISTINCT operational_date) AS dias_ativos, '
            . '       ROUND(AVG(queue_wait_seconds)) AS espera_media_seg, '
            . '       (COUNT(*) * %d '
            . '        + COUNT(DISTINCT operational_date) * %d) AS pontuacao '
            . 'FROM delivery_events '
            . 'WHERE operational_date >= ? '
            . '  AND operational_date <= ? '
            . 'GROUP BY nome '
            . 'ORDER BY pontuacao DESC, entregas DESC, nome ASC '
            . 'LIMIT ? OFFSET ?',
            $scoreDelivery,
            $scoreActiveDay
        );

        $statement = $this->prepare($sql, 'Ranking indisponivel.');
        $start = $bounds['start'];
        $end = $bounds['end'];
        $statement->bind_param('ssii', $start, $end, $perPage, $offset);
        $this->execute($statement, 'Ranking indisponivel.');

        $ranking = [];
        $names = [];
        $result = $statement->get_result();
        while ($row = $result->fetch_assoc()) {
            $name = (string) $row['nome'];
            $deliveries = (int) $row['entregas'];
            $activeDays = (int) $row['dias_ativos'];
            $ranking[$name] = [
                'position' => $offset + count($ranking) + 1,
                'nome' => $name,
                'entregas' => $deliveries,
                'dias_ativos' => $activeDays,
                'media_dia' => $activeDays > 0
                    ? round($deliveries / $activeDays, 1)
                    : 0.0,
                'espera_media_seg' => $row['espera_media_seg'] === null
                    ? null
                    : (int) $row['espera_media_seg'],
                'pontuacao' => (int) $row['pontuacao'],
                'evolucao_pct' => null,
                'serie' => [],
            ];
            $names[] = $name;
        }
        $statement->close();

        if ($names !== []) {
            $daily = $this->loadDailySeries($bounds, $names);
            $previousTotals = $this->loadPreviousTotals($previous, $names);
            $days = self::days($bounds['start'], $bounds['end']);

            foreach ($ranking as $name => &$entry) {
                foreach ($days as $day) {
                    $entry['serie'][] = $daily[$name][$day] ?? 0;
                }
                $entry['evolucao_pct'] = DeliveryRanking::evolutionPercent(
                    $entry['entregas'],
                    $previousTotals[$name] ?? 0
                );
            }
            unset($entry);
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'ranking' => array_values($ranking),
        ];
    }

    private function countDrivers(string $start, string $end): int
    {
        $statement = $this->prepare(
            'SELECT COUNT(DISTINCT nome) '
            . 'FROM delivery_events '
            . 'WHERE operational_date >= ? '
            . '  AND operational_date <= ?',
            'Ranking indisponivel.'
        );
        $statement->bind_param('ss', $start, $end);
        $this->execute($statement, 'Ranking indisponivel.');
        $total = 0;
        $statement->bind_result($total);
        $statement->fetch();
        $statement->close();

        return (int) $total;
    }

    /**
     * @param array{start:string,end:string,days:int} $bounds
     * @param list<string> $names
     * @return array<string,array<string,int>>
     */
    private function loadDailySeries(array $bounds, array $names): array
    {
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $statement = $this->prepare(
            'SELECT nome, operational_date, COUNT(*) AS entregas '
            . 'FROM delivery_events '
            . 'WHERE operational_date >= ? '
            . '  AND operational_date <= ? '
            . '  AND nome IN (' . $placeholders . ') '
            . 'GROUP BY nome, operational_date',
            'Serie do ranking indisponivel.'
        );

        $values = array_merge([$bounds['start'], $bounds['end']], $names);
        self::bindValues($statement, str_repeat('s', count($values)), $values);
        $this->execute($statement, 'Serie do ranking indisponivel.');

        $daily = [];
        $result = $statement->get_result();
        while ($row = $result->fetch_assoc()) {
            $daily[(string) $row['nome']][(string) $row['operational_date']] =
                (int) $row['entregas'];
        }
        $statement->close();

        return $daily;
    }

    /**
     * @param array{start:string,end:string,days:int} $bounds
     * @param list<string> $names
     * @return array<string,int>
     */
    private function loadPreviousTotals(array $bounds, array $names): array
    {
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $statement = $this->prepare(
            'SELECT nome, COUNT(*) AS entregas '
            . 'FROM delivery_events '
            . 'WHERE operational_date >= ? '
            . '  AND operational_date <= ? '
            . '  AND nome IN (' . $placeholders . ') '
            . 'GROUP BY nome',
            'Comparacao do ranking indisponivel.'
        );

        $values = array_merge([$bounds['start'], $bounds['end']], $names);
        self::bindValues($statement, str_repeat('s', count($values)), $values);
        $this->execute($statement, 'Comparacao do ranking indisponivel.');

        $totals = [];
        $result = $statement->get_result();
        while ($row = $result->fetch_assoc()) {
            $totals[(string) $row['nome']] = (int) $row['entregas'];
        }
        $statement->close();

        return $totals;
    }

    /**
     * @param array{start:string,end:string,days:int} $bounds
     */
    private static function assertBounds(array $bounds): void
    {
        if (
            !isset($bounds['start'], $bounds['end'], $bounds['days'])
            || !is_string($bounds['start'])
            || !is_string($bounds['end'])
            || !is_int($bounds['days'])
            || $bounds['days'] < 1
        ) {
            throw new InvalidArgumentException('Intervalo de ranking invalido.');
        }

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $bounds['start']);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $bounds['end']);
        if (
            $start === false
            || $end === false
            || $start->format('Y-m-d') !== $bounds['start']
            || $end->format('Y-m-d') !== $bounds['end']
            || $end < $start
        ) {
            throw new InvalidArgumentException('Intervalo de ranking invalido.');
        }
    }

    /** @return list<string> */
    private static function days(string $start, string $end): array
    {
        $days = [];
        $cursor = new DateTimeImmutable($start);
        $limit = new DateTimeImmutable($end);
        while ($cursor <= $limit) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
        return $days;
    }

    /**
     * @param list<string> $values
     */
    private static function bindValues(
        mysqli_stmt $statement,
        string $types,
        array &$values
    ): void {
        $references = [];
        foreach ($values as &$value) {
            $references[] = &$value;
        }
        unset($value);

        if (!$statement->bind_param($types, ...$references)) {
            throw new RuntimeException('Nao foi possivel vincular a consulta.');
        }
    }

    private function prepare(string $sql, string $message): mysqli_stmt
    {
        $statement = $this->connection->prepare($sql);
        if (!$statement) {
            throw new RuntimeException($message);
        }
        return $statement;
    }

    private function execute(mysqli_stmt $statement, string $message): void
    {
        if (!$statement->execute()) {
            $statement->close();
            throw new RuntimeException($message);
        }
    }
}
