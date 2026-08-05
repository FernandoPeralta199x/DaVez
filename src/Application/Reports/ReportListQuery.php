<?php

declare(strict_types=1);

namespace DaVez\Application\Reports;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use mysqli;
use mysqli_stmt;

/**
 * Consulta somente leitura dos relatórios persistidos.
 *
 * Centraliza contagem, filtros e ordenação para que painel e exportação PDF
 * usem exatamente a mesma semântica. O intervalo é aplicado diretamente à
 * coluna, preservando a possibilidade de uso de índice.
 */
final class ReportListQuery
{
    /** @var mysqli */
    private $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array{
     *   items:list<array<string,mixed>>,
     *   page:int,
     *   per_page:int,
     *   total:int,
     *   total_pages:int,
     *   date_from:string|null,
     *   date_to:string|null
     * }
     */
    public function fetchPage(
        string $dateFrom,
        string $dateTo,
        int $page = 1,
        int $perPage = 15
    ): array {
        self::assertDateFilter($dateFrom, $dateTo, 3660);
        if ($page < 1 || $page > 1000000) {
            throw new InvalidArgumentException('Página de relatório inválida.');
        }
        if ($perPage < 1 || $perPage > 50) {
            throw new InvalidArgumentException('Tamanho de página inválido.');
        }

        $total = $this->count($dateFrom, $dateTo);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $items = $this->load($dateFrom, $dateTo, $perPage, $offset);

        return [
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'date_from' => $dateFrom === '' ? null : $dateFrom,
            'date_to' => $dateTo === '' ? null : $dateTo,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function fetchForExport(
        string $dateFrom,
        string $dateTo,
        int $limit = 1000
    ): array {
        self::assertDateFilter($dateFrom, $dateTo, 3660);
        if ($limit < 1 || $limit > 5000) {
            throw new InvalidArgumentException('Limite de exportação inválido.');
        }

        return $this->load($dateFrom, $dateTo, $limit, 0);
    }

    public function count(string $dateFrom, string $dateTo): int
    {
        self::assertDateFilter($dateFrom, $dateTo, 3660);
        $statement = $this->prepare(
            "SELECT COUNT(*)
             FROM reports
             WHERE (? = '' OR periodo_inicio >= CONCAT(?, ' 00:00:00'))
               AND (? = '' OR periodo_inicio < DATE_ADD(CONCAT(?, ' 00:00:00'), INTERVAL 1 DAY))"
        );
        $statement->bind_param(
            'ssss',
            $dateFrom,
            $dateFrom,
            $dateTo,
            $dateTo
        );
        $this->execute($statement);
        $total = 0;
        $statement->bind_result($total);
        $statement->fetch();
        $statement->close();

        return (int) $total;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function load(
        string $dateFrom,
        string $dateTo,
        int $limit,
        int $offset
    ): array {
        $statement = $this->prepare(
            "SELECT id, periodo_inicio, periodo_fim, total_checkins,
                    motoboys_unicos, total_fechados, created_at
             FROM reports
             WHERE (? = '' OR periodo_inicio >= CONCAT(?, ' 00:00:00'))
               AND (? = '' OR periodo_inicio < DATE_ADD(CONCAT(?, ' 00:00:00'), INTERVAL 1 DAY))
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?"
        );
        $statement->bind_param(
            'ssssii',
            $dateFrom,
            $dateFrom,
            $dateTo,
            $dateTo,
            $limit,
            $offset
        );
        $this->execute($statement);

        $items = [];
        $result = $statement->get_result();
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $statement->close();

        return $items;
    }

    public static function assertDateFilter(
        string $dateFrom,
        string $dateTo,
        int $maximumDays
    ): void {
        if ($maximumDays < 1 || $maximumDays > 3660) {
            throw new InvalidArgumentException('Limite de data inválido.');
        }
        if (($dateFrom === '') !== ($dateTo === '')) {
            throw new InvalidArgumentException(
                'Informe data inicial e final para filtrar os relatórios.'
            );
        }
        if ($dateFrom === '') {
            return;
        }

        $start = self::parseDate($dateFrom);
        $end = self::parseDate($dateTo);
        if ($end < $start) {
            throw new InvalidArgumentException(
                'A data final não pode ser anterior à data inicial.'
            );
        }
        $days = (int) $start->diff($end)->format('%a') + 1;
        if ($days > $maximumDays) {
            throw new InvalidArgumentException(
                'O intervalo de relatórios excede o limite permitido.'
            );
        }
    }

    private static function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Data inválida.');
        }
        return $date;
    }

    private function prepare(string $sql): mysqli_stmt
    {
        $statement = $this->connection->prepare($sql);
        if (!$statement) {
            throw new RuntimeException('Relatórios indisponíveis.');
        }
        return $statement;
    }

    private function execute(mysqli_stmt $statement): void
    {
        if (!$statement->execute()) {
            $statement->close();
            throw new RuntimeException('Relatórios indisponíveis.');
        }
    }
}
