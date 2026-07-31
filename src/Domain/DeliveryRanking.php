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

    private const PONTOS_POR_ENTREGA = 10;
    private const PONTOS_POR_DIA_ATIVO = 5;

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

        return $entregas * self::PONTOS_POR_ENTREGA
            + $diasAtivos * self::PONTOS_POR_DIA_ATIVO;
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
}
