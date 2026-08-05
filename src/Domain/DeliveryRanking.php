<?php

declare(strict_types=1);

namespace DaVez\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Regras puras do ranking de motoboys, derivadas do log de despachos.
 *
 * Não toca no banco: recebe datas e contagens e devolve limites de período,
 * pontuação e evolução. Isso mantém a agregação SQL fina no endpoint e permite
 * testar a política sem MySQL.
 */
final class DeliveryRanking
{
    public const PERIODS = ['dia', 'semana', 'mes'];

    public const POINTS_PER_DELIVERY = 10;
    public const POINTS_PER_ACTIVE_DAY = 5;

    /**
     * Janela [inicio, fim] em Y-m-d, inclusiva, encerrando na data de
     * referência (a data operacional atual).
     *
     * @return array{start: string, end: string, days: int}
     */
    public static function periodBounds(
        string $periodo,
        string $referenceDate
    ): array {
        if (!in_array($periodo, self::PERIODS, true)) {
            throw new InvalidArgumentException('Período inválido.');
        }

        $reference = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $referenceDate
        );

        if (
            $reference === false
            || $reference->format('Y-m-d') !== $referenceDate
        ) {
            throw new InvalidArgumentException('Data de referência inválida.');
        }

        $days = self::periodLength($periodo);
        $start = $reference->modify('-' . ($days - 1) . ' days');

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $reference->format('Y-m-d'),
            'days' => $days,
        ];
    }

    /**
     * Janela imediatamente anterior, de mesmo tamanho, para medir evolução.
     *
     * @return array{start: string, end: string, days: int}
     */
    public static function previousBounds(
        string $periodo,
        string $referenceDate
    ): array {
        $current = self::periodBounds($periodo, $referenceDate);
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $current['start']);
        $previousEnd = $start->modify('-1 day');

        return self::periodBounds(
            $periodo,
            $previousEnd->format('Y-m-d')
        );
    }


    /**
     * Janela personalizada inclusiva, limitada para evitar consultas abusivas.
     *
     * @return array{start: string, end: string, days: int}
     */
    public static function customBounds(
        string $dateFrom,
        string $dateTo,
        int $maximumDays = 366
    ): array {
        if ($maximumDays < 1 || $maximumDays > 3660) {
            throw new InvalidArgumentException('Limite de intervalo inválido.');
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
                'O intervalo solicitado excede o limite permitido.'
            );
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'days' => $days,
        ];
    }

    /**
     * Janela anterior de mesmo tamanho para comparação de evolução.
     *
     * @return array{start: string, end: string, days: int}
     */
    public static function previousCustomBounds(
        string $dateFrom,
        string $dateTo,
        int $maximumDays = 366
    ): array {
        $current = self::customBounds($dateFrom, $dateTo, $maximumDays);
        $start = self::parseDate($current['start']);
        $previousEnd = $start->modify('-1 day');
        $previousStart = $previousEnd->modify(
            '-' . ($current['days'] - 1) . ' days'
        );

        return [
            'start' => $previousStart->format('Y-m-d'),
            'end' => $previousEnd->format('Y-m-d'),
            'days' => $current['days'],
        ];
    }

    public static function periodLength(string $periodo): int
    {
        switch ($periodo) {
            case 'dia':
                return 1;
            case 'semana':
                return 7;
            case 'mes':
                return 30;
            default:
                throw new InvalidArgumentException('Período inválido.');
        }
    }

    /**
     * Pontuação transparente: privilegia volume de entregas e recompensa a
     * consistência (dias com pelo menos uma entrega).
     */
    public static function score(int $entregas, int $diasAtivos): int
    {
        if ($entregas < 0 || $diasAtivos < 0) {
            throw new InvalidArgumentException('Contagens não podem ser negativas.');
        }

        return $entregas * self::POINTS_PER_DELIVERY
            + $diasAtivos * self::POINTS_PER_ACTIVE_DAY;
    }

    /**
     * Evolução percentual entre o período atual e o anterior.
     *
     * Retorna null quando não há base de comparação (nenhuma entrega antes),
     * para a interface não exibir crescimento enganoso.
     */
    public static function evolutionPercent(int $atual, int $anterior): ?int
    {
        if ($anterior <= 0) {
            return null;
        }

        return (int) round((($atual - $anterior) / $anterior) * 100);
    }

    private static function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Data inválida.');
        }

        return $date;
    }
}
