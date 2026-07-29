<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Domain/Geofence.php';

use DaVez\Domain\Geofence;

function assert_geofence(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "geofence_test: FAIL - {$message}" . PHP_EOL);
        exit(1);
    }
}

$samePoint = Geofence::evaluate(-23.5505, -46.6333, 10.0, -23.5505, -46.6333);
assert_geofence(
    abs($samePoint['distance_m']) < 0.001 && $samePoint['within'],
    'O mesmo ponto deveria ter distância zero e estar dentro do raio.'
);

$oneDegree = Geofence::haversineMeters(0.0, 0.0, 1.0, 0.0);
assert_geofence(
    $oneDegree > 111000.0 && $oneDegree < 111300.0,
    'O cálculo Haversine de referência está fora da tolerância.'
);

$outside = Geofence::evaluate(-23.5505, -46.6333, 5.0, -23.5500, -46.6333);
assert_geofence(!$outside['within'], 'Um ponto distante deveria ser recusado.');

foreach (
    [
        [0.0, 0.0, 100.0],
        [-23.0, -46.0, 0.0],
        [-23.0, -46.0, -1.0],
    ] as $invalid
) {
    $rejected = false;
    try {
        Geofence::evaluate(
            $invalid[0],
            $invalid[1],
            $invalid[2],
            -23.0,
            -46.0
        );
    } catch (InvalidArgumentException $exception) {
        $rejected = true;
    }
    assert_geofence($rejected, 'Configuração insegura deveria falhar fechada.');
}

echo 'geofence_test: OK' . PHP_EOL;
