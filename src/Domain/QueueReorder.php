<?php

declare(strict_types=1);

namespace DaVez\Domain;

use InvalidArgumentException;

final class QueueReorder
{
    /**
     * @param mixed $submitted
     * @return array<int, int>
     */
    public static function normalize($submitted, int $maximumItems = 500): array
    {
        if (
            !is_array($submitted)
            || $submitted === []
            || count($submitted) > $maximumItems
        ) {
            throw new InvalidArgumentException('Lista de ordem inválida.');
        }

        $normalized = [];

        foreach ($submitted as $informedId) {
            $validatedId = filter_var($informedId, FILTER_VALIDATE_INT);

            if ($validatedId === false || $validatedId < 1) {
                throw new InvalidArgumentException('Lista de ordem inválida.');
            }

            $normalized[] = (int) $validatedId;
        }

        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new InvalidArgumentException('A lista contém IDs repetidos.');
        }

        return $normalized;
    }

    /**
     * @param array<int, int> $submittedIds
     * @param array<int, int> $currentIds
     */
    public static function assertExactSet(
        array $submittedIds,
        array $currentIds
    ): void {
        $submitted = $submittedIds;
        $current = $currentIds;

        sort($submitted, SORT_NUMERIC);
        sort($current, SORT_NUMERIC);

        if ($submitted !== $current) {
            throw new QueueStateChanged(
                'A fila mudou desde a última leitura.'
            );
        }
    }

    /**
     * @param array<int, int> $orderedIds
     * @return array<int, int>
     */
    public static function positions(array $orderedIds): array
    {
        $positions = [];

        foreach ($orderedIds as $index => $id) {
            $positions[$id] = $index + 1;
        }

        return $positions;
    }
}
