<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Domain/OperationalCycle.php';

use DaVez\Domain\OperationalCycle;

function fail_operational_cycle_test(string $message): void
{
    fwrite(STDERR, "operational_cycle_test: FAIL - {$message}" . PHP_EOL);
    exit(1);
}

function assert_operational_cycle(bool $condition, string $message): void
{
    if (!$condition) {
        fail_operational_cycle_test($message);
    }
}

$cycle = new OperationalCycle();

$beforeStart = new DateTimeImmutable(
    '2026-07-29 05:59:59',
    new DateTimeZone('America/Sao_Paulo')
);
$bounds = $cycle->bounds($beforeStart);
assert_operational_cycle(
    $bounds['start']->format('Y-m-d H:i:s') === '2026-07-28 06:00:00',
    '05:59:59 deve pertencer ao ciclo iniciado no dia anterior.'
);
assert_operational_cycle(
    $bounds['end']->format('Y-m-d H:i:s') === '2026-07-29 06:00:00',
    'O término deve ser exclusivo e ocorrer às 06:00 do dia seguinte.'
);
assert_operational_cycle(
    $cycle->operationalDate($beforeStart) === '2026-07-28',
    'A data operacional antes das 06:00 está incorreta.'
);

$exactStart = new DateTimeImmutable(
    '2026-07-29 06:00:00',
    new DateTimeZone('America/Sao_Paulo')
);
$bounds = $cycle->bounds($exactStart);
assert_operational_cycle(
    $bounds['start']->format('Y-m-d H:i:s') === '2026-07-29 06:00:00',
    '06:00:00 deve iniciar um novo ciclo.'
);
assert_operational_cycle(
    $cycle->operationalDate($exactStart) === '2026-07-29',
    'A data operacional no início exato está incorreta.'
);

$utcReference = new DateTimeImmutable(
    '2026-07-29 08:59:59',
    new DateTimeZone('UTC')
);
assert_operational_cycle(
    $cycle->operationalDate($utcReference) === '2026-07-28',
    'A referência deve ser convertida para America/Sao_Paulo antes do cálculo.'
);

$customCycle = new OperationalCycle('America/Sao_Paulo', 4);
$customReference = new DateTimeImmutable(
    '2026-07-29 03:30:00',
    new DateTimeZone('America/Sao_Paulo')
);
assert_operational_cycle(
    $customCycle->operationalDate($customReference) === '2026-07-28',
    'A hora inicial configurável não foi respeitada.'
);

$invalidHourRejected = false;
try {
    new OperationalCycle('America/Sao_Paulo', 24);
} catch (InvalidArgumentException $exception) {
    $invalidHourRejected = true;
}
assert_operational_cycle(
    $invalidHourRejected,
    'Uma hora inicial fora do intervalo deveria ser rejeitada.'
);

echo 'operational_cycle_test: OK' . PHP_EOL;
