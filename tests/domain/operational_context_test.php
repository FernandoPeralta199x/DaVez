<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Domain/OperationalCycle.php';
require_once __DIR__ . '/../../src/Domain/OperationalContext.php';

use DaVez\Domain\OperationalContext;
use DaVez\Domain\OperationalCycle;

function assert_operational_context(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "operational_context_test: FAIL - {$message}" . PHP_EOL);
        exit(1);
    }
}

$reference = new DateTimeImmutable(
    '2026-07-29 01:29:59',
    new DateTimeZone('America/Sao_Paulo')
);
$context = new OperationalContext(new OperationalCycle(), $reference);

assert_operational_context(
    $context->reference()->format('Y-m-d H:i:s.uP')
        === $reference->format('Y-m-d H:i:s.uP'),
    'O contexto deve preservar a referência temporal capturada.'
);
assert_operational_context(
    $context->startSql() === '2026-07-28 01:30:00'
        && $context->endSql() === '2026-07-29 01:30:00'
        && $context->date() === '2026-07-28',
    'Os limites [início, fim) do contexto estão incorretos.'
);

echo 'operational_context_test: OK' . PHP_EOL;
