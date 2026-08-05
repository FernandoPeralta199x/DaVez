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
    '2026-07-29 01:29:59',
    new DateTimeZone('America/Sao_Paulo')
);
$bounds = $cycle->bounds($beforeStart);
assert_operational_cycle(
    $bounds['start']->format('Y-m-d H:i:s') === '2026-07-28 01:30:00',
    '01:29:59 deve pertencer ao ciclo iniciado no dia anterior.'
);
assert_operational_cycle(
    $bounds['end']->format('Y-m-d H:i:s') === '2026-07-29 01:30:00',
    'O término deve ser exclusivo e ocorrer às 01:30 do dia seguinte.'
);
assert_operational_cycle(
    $cycle->operationalDate($beforeStart) === '2026-07-28',
    'A data operacional antes das 01:30 está incorreta.'
);

$exactStart = new DateTimeImmutable(
    '2026-07-29 01:30:00',
    new DateTimeZone('America/Sao_Paulo')
);
$bounds = $cycle->bounds($exactStart);
assert_operational_cycle(
    $bounds['start']->format('Y-m-d H:i:s') === '2026-07-29 01:30:00',
    '01:30:00 deve iniciar um novo ciclo.'
);
assert_operational_cycle(
    $cycle->operationalDate($exactStart) === '2026-07-29',
    'A data operacional no início exato está incorreta.'
);

$afterStart = new DateTimeImmutable(
    '2026-07-29 01:30:01',
    new DateTimeZone('America/Sao_Paulo')
);
assert_operational_cycle(
    $cycle->operationalDate($afterStart) === '2026-07-29',
    '01:30:01 deve permanecer no novo ciclo.'
);

$utcReference = new DateTimeImmutable(
    '2026-07-29 04:29:59',
    new DateTimeZone('UTC')
);
assert_operational_cycle(
    $cycle->operationalDate($utcReference) === '2026-07-28',
    'A referência deve ser convertida para America/Sao_Paulo antes do cálculo.'
);

$customCycle = new OperationalCycle('America/Sao_Paulo', 4, 45);
$customReference = new DateTimeImmutable(
    '2026-07-29 04:44:59',
    new DateTimeZone('America/Sao_Paulo')
);
assert_operational_cycle(
    $customCycle->operationalDate($customReference) === '2026-07-28',
    'A hora e o minuto iniciais configuráveis não foram respeitados.'
);
assert_operational_cycle(
    $customCycle->startTimeLabel() === '04:45',
    'O rótulo de horário operacional está incorreto.'
);

$invalidHourRejected = false;
try {
    new OperationalCycle('America/Sao_Paulo', 24, 0);
} catch (InvalidArgumentException $exception) {
    $invalidHourRejected = true;
}
assert_operational_cycle(
    $invalidHourRejected,
    'Uma hora inicial fora do intervalo deveria ser rejeitada.'
);

$invalidMinuteRejected = false;
try {
    new OperationalCycle('America/Sao_Paulo', 1, 60);
} catch (InvalidArgumentException $exception) {
    $invalidMinuteRejected = true;
}
assert_operational_cycle(
    $invalidMinuteRejected,
    'Um minuto inicial fora do intervalo deveria ser rejeitado.'
);

putenv('APP_TIMEZONE=America/Sao_Paulo');
putenv('APP_OPERATIONAL_CYCLE_TIME=02:15');
$environmentCycle = OperationalCycle::fromEnvironment();
assert_operational_cycle(
    $environmentCycle->startHour() === 2
        && $environmentCycle->startMinute() === 15,
    'A configuração de ciclo por ambiente não foi aplicada.'
);

putenv('APP_OPERATIONAL_CYCLE_TIME=2:15');
$invalidEnvironmentRejected = false;
try {
    OperationalCycle::fromEnvironment();
} catch (InvalidArgumentException $exception) {
    $invalidEnvironmentRejected = true;
}
assert_operational_cycle(
    $invalidEnvironmentRejected,
    'O formato operacional sem zero à esquerda deveria ser rejeitado.'
);

putenv('APP_TIMEZONE');
putenv('APP_OPERATIONAL_CYCLE_TIME');

$offsetCycle = new OperationalCycle('America/Sao_Paulo', 1, 30);
assert_operational_cycle(
    $offsetCycle->utcOffset(new DateTimeImmutable(
        '2026-08-05 12:00:00',
        new DateTimeZone('UTC')
    )) === '-03:00',
    'O offset do banco deve ser derivado do fuso na data de referência.'
);

echo 'operational_cycle_test: OK' . PHP_EOL;

