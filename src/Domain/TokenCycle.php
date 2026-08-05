<?php

declare(strict_types=1);

namespace DaVez\Domain;

use DateTimeImmutable;

final class TokenCycle
{
    private const DURATION_DAYS = 3;

    /**
     * @param array<string, mixed> $settings
     * @return array{
     *   needs_rotate: bool,
     *   cycle_start: DateTimeImmutable,
     *   cycle_end: DateTimeImmutable,
     *   base_date: string
     * }
     */
    public static function evaluate(
        array $settings,
        OperationalContext $context
    ): array {
        $operationalDate = $context->date();
        $tokenDate = trim((string) ($settings['token_data'] ?? ''));
        $token = trim((string) ($settings['token'] ?? ''));

        if ($tokenDate === '') {
            $tokenDate = $operationalDate;
        }

        $cycleStart = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $tokenDate . ' ' . $context->start()->format('H:i:s'),
            $context->reference()->getTimezone()
        );

        if ($cycleStart === false) {
            $cycleStart = new DateTimeImmutable(
                $operationalDate . ' ' . $context->start()->format('H:i:s'),
                $context->reference()->getTimezone()
            );
        }

        $cycleEnd = $cycleStart->modify(
            '+' . self::DURATION_DAYS . ' days'
        );

        return [
            'needs_rotate' => $token === ''
                || $context->reference() >= $cycleEnd,
            'cycle_start' => $cycleStart,
            'cycle_end' => $cycleEnd,
            'base_date' => $cycleStart->format('Y-m-d'),
        ];
    }
}
