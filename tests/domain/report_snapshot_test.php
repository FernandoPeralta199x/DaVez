<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Domain/ReportSnapshot.php';

use DaVez\Domain\ReportSnapshot;

$snapshot = ReportSnapshot::build(
    '2026-07-29 06:00:00',
    '2026-07-30 06:00:00',
    [
        ['nome' => 'Ana', 'is_closed' => 1],
        ['nome' => ' ana ', 'is_closed' => 0],
        ['nome' => 'Beto', 'is_closed' => 1],
    ]
);

if (
    $snapshot['total_checkins'] !== 3
    || $snapshot['motoboys_unicos'] !== 2
    || $snapshot['total_fechados'] !== 2
) {
    fwrite(STDERR, 'report_snapshot_test: FAIL - Totais incorretos.' . PHP_EOL);
    exit(1);
}

echo 'report_snapshot_test: OK' . PHP_EOL;
