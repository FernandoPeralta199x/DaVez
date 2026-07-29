<?php

declare(strict_types=1);

namespace DaVez\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class OperationalCycle
{
    public const DEFAULT_TIMEZONE = 'America/Sao_Paulo';
    public const DEFAULT_START_HOUR = 6;

    /** @var DateTimeZone */
    private $timezone;

    /** @var int */
    private $startHour;

    public function __construct(
        string $timezone = self::DEFAULT_TIMEZONE,
        int $startHour = self::DEFAULT_START_HOUR
    ) {
        if ($startHour < 0 || $startHour > 23) {
            throw new InvalidArgumentException('A hora inicial deve estar entre 0 e 23.');
        }

        $this->timezone = new DateTimeZone($timezone);
        $this->startHour = $startHour;
    }

    /**
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    public function bounds(?DateTimeInterface $reference = null): array
    {
        $localReference = $reference === null
            ? new DateTimeImmutable('now', $this->timezone)
            : DateTimeImmutable::createFromInterface($reference)->setTimezone($this->timezone);

        $start = $localReference->setTime($this->startHour, 0, 0);

        if ($localReference < $start) {
            $start = $start->modify('-1 day');
        }

        return [
            'start' => $start,
            'end' => $start->modify('+1 day'),
        ];
    }

    public function operationalDate(?DateTimeInterface $reference = null): string
    {
        return $this->bounds($reference)['start']->format('Y-m-d');
    }

    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    public function startHour(): int
    {
        return $this->startHour;
    }
}
