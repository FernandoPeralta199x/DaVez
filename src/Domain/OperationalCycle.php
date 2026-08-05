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
    public const DEFAULT_START_HOUR = 1;
    public const DEFAULT_START_MINUTE = 30;

    /** @var DateTimeZone */
    private $timezone;

    /** @var int */
    private $startHour;

    /** @var int */
    private $startMinute;

    public function __construct(
        string $timezone = self::DEFAULT_TIMEZONE,
        int $startHour = self::DEFAULT_START_HOUR,
        int $startMinute = self::DEFAULT_START_MINUTE
    ) {
        // Compatibilidade: chamadas antigas com apenas timezone + hora
        // representavam uma virada em minuto zero.
        if (func_num_args() === 2) {
            $startMinute = 0;
        }

        if ($startHour < 0 || $startHour > 23) {
            throw new InvalidArgumentException(
                'A hora inicial deve estar entre 0 e 23.'
            );
        }

        if ($startMinute < 0 || $startMinute > 59) {
            throw new InvalidArgumentException(
                'O minuto inicial deve estar entre 0 e 59.'
            );
        }

        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException(
                'O fuso horário operacional é inválido.',
                0,
                $exception
            );
        }

        $this->startHour = $startHour;
        $this->startMinute = $startMinute;
    }

    /**
     * Cria o ciclo a partir de variáveis de ambiente centralizadas.
     *
     * APP_TIMEZONE usa um identificador IANA, como America/Sao_Paulo.
     * APP_OPERATIONAL_CYCLE_TIME usa HH:MM em formato de 24 horas.
     */
    public static function fromEnvironment(): self
    {
        $configuredTimezone = getenv('APP_TIMEZONE');
        $timezone = is_string($configuredTimezone)
            && trim($configuredTimezone) !== ''
            ? trim($configuredTimezone)
            : self::DEFAULT_TIMEZONE;

        $configuredTime = getenv('APP_OPERATIONAL_CYCLE_TIME');
        $time = is_string($configuredTime) ? trim($configuredTime) : '';

        if ($time === '') {
            return new self($timezone);
        }

        if (preg_match('/\A([01][0-9]|2[0-3]):([0-5][0-9])\z/', $time, $matches) !== 1) {
            throw new InvalidArgumentException(
                'APP_OPERATIONAL_CYCLE_TIME deve usar o formato HH:MM.'
            );
        }

        return new self(
            $timezone,
            (int) $matches[1],
            (int) $matches[2]
        );
    }

    /**
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    public function bounds(?DateTimeInterface $reference = null): array
    {
        $localReference = $reference === null
            ? new DateTimeImmutable('now', $this->timezone)
            : DateTimeImmutable::createFromInterface($reference)
                ->setTimezone($this->timezone);

        $start = $localReference->setTime(
            $this->startHour,
            $this->startMinute,
            0
        );

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

    public function startMinute(): int
    {
        return $this->startMinute;
    }

    public function startTimeLabel(): string
    {
        return sprintf('%02d:%02d', $this->startHour, $this->startMinute);
    }

    /**
     * Offset UTC vigente no fuso operacional para configurar a sessão MySQL.
     * O cálculo usa a data real, portanto respeita mudanças históricas de DST.
     */
    public function utcOffset(?DateTimeInterface $reference = null): string
    {
        $localReference = $reference === null
            ? new DateTimeImmutable('now', $this->timezone)
            : DateTimeImmutable::createFromInterface($reference)
                ->setTimezone($this->timezone);

        return $localReference->format('P');
    }
}
