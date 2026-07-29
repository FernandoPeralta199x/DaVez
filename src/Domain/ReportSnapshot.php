<?php

declare(strict_types=1);

namespace DaVez\Domain;

final class ReportSnapshot
{
    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public static function build(
        string $periodStart,
        string $periodEnd,
        array $items
    ): array {
        $uniqueNames = [];
        $closed = 0;

        foreach ($items as $item) {
            $name = trim((string) ($item['nome'] ?? ''));
            $normalizedName = function_exists('mb_strtolower')
                ? mb_strtolower($name, 'UTF-8')
                : strtolower($name);

            if ($normalizedName !== '') {
                $uniqueNames[$normalizedName] = true;
            }

            if ((int) ($item['is_closed'] ?? 0) === 1) {
                $closed++;
            }
        }

        return [
            'periodo_inicio' => $periodStart,
            'periodo_fim' => $periodEnd,
            'total_checkins' => count($items),
            'motoboys_unicos' => count($uniqueNames),
            'total_fechados' => $closed,
            'items' => $items,
        ];
    }
}
