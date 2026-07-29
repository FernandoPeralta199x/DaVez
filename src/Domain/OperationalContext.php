<?php

declare(strict_types=1);

namespace DaVez\Domain;

use DateTimeImmutable;
use DateTimeInterface;

final class OperationalContext
{
    /** @var DateTimeImmutable */
    private $reference;

    /** @var DateTimeImmutable */
    private $start;

    /** @var DateTimeImmutable */
    private $end;

    public function __construct(
        OperationalCycle $cycle,
        ?DateTimeInterface $reference = null
    ) {
        $this->reference = $reference === null
            ? new DateTimeImmutable('now', $cycle->timezone())
            : DateTimeImmutable::createFromInterface($reference)
                ->setTimezone($cycle->timezone());

        $bounds = $cycle->bounds($this->reference);
        $this->start = $bounds['start'];
        $this->end = $bounds['end'];
    }

    public function reference(): DateTimeImmutable
    {
        return $this->reference;
    }

    public function start(): DateTimeImmutable
    {
        return $this->start;
    }

    public function end(): DateTimeImmutable
    {
        return $this->end;
    }

    public function date(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function startSql(): string
    {
        return $this->start->format('Y-m-d H:i:s');
    }

    public function endSql(): string
    {
        return $this->end->format('Y-m-d H:i:s');
    }
}
