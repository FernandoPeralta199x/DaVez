<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Domain/DeliveryRanking.php';

use DaVez\Domain\DeliveryRanking;

function assert_delivery_ranking(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "delivery_ranking_test: FAIL - {$message}" . PHP_EOL);
        exit(1);
    }
}

$day = DeliveryRanking::periodBounds('dia', '2026-08-05');
assert_delivery_ranking(
    $day === ['start' => '2026-08-05', 'end' => '2026-08-05', 'days' => 1],
    'Período diário incorreto.'
);

$week = DeliveryRanking::periodBounds('semana', '2026-08-05');
assert_delivery_ranking(
    $week === ['start' => '2026-07-30', 'end' => '2026-08-05', 'days' => 7],
    'Período semanal incorreto.'
);

$month = DeliveryRanking::periodBounds('mes', '2026-08-05');
assert_delivery_ranking(
    $month === ['start' => '2026-07-07', 'end' => '2026-08-05', 'days' => 30],
    'Período mensal incorreto.'
);

$previous = DeliveryRanking::previousBounds('semana', '2026-08-05');
assert_delivery_ranking(
    $previous === ['start' => '2026-07-23', 'end' => '2026-07-29', 'days' => 7],
    'Período anterior incorreto.'
);

$custom = DeliveryRanking::customBounds('2026-07-01', '2026-07-31');
assert_delivery_ranking(
    $custom === ['start' => '2026-07-01', 'end' => '2026-07-31', 'days' => 31],
    'Intervalo personalizado incorreto.'
);

$previousCustom = DeliveryRanking::previousCustomBounds(
    '2026-07-01',
    '2026-07-31'
);
assert_delivery_ranking(
    $previousCustom === [
        'start' => '2026-05-31',
        'end' => '2026-06-30',
        'days' => 31,
    ],
    'Intervalo personalizado anterior incorreto.'
);

$reversedRejected = false;
try {
    DeliveryRanking::customBounds('2026-08-05', '2026-08-01');
} catch (InvalidArgumentException $exception) {
    $reversedRejected = true;
}
assert_delivery_ranking(
    $reversedRejected,
    'Intervalo invertido deveria ser rejeitado.'
);

$tooLongRejected = false;
try {
    DeliveryRanking::customBounds('2024-01-01', '2026-08-05', 366);
} catch (InvalidArgumentException $exception) {
    $tooLongRejected = true;
}
assert_delivery_ranking(
    $tooLongRejected,
    'Intervalo excessivo deveria ser rejeitado.'
);

assert_delivery_ranking(
    DeliveryRanking::score(10, 3) === 115,
    'Pontuação incorreta.'
);
assert_delivery_ranking(
    DeliveryRanking::evolutionPercent(15, 10) === 50,
    'Evolução percentual incorreta.'
);
assert_delivery_ranking(
    DeliveryRanking::evolutionPercent(10, 0) === null,
    'Evolução sem base deveria ser nula.'
);

echo 'delivery_ranking_test: OK' . PHP_EOL;
