<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Domain/OperationalCycle.php';
require_once __DIR__ . '/../../src/Domain/OperationalContext.php';
require_once __DIR__ . '/../../src/Domain/TokenCycle.php';
require_once __DIR__ . '/../../src/Domain/LegacyIdentity.php';

use DaVez\Domain\LegacyIdentity;
use DaVez\Domain\OperationalContext;
use DaVez\Domain\OperationalCycle;
use DaVez\Domain\TokenCycle;

function assert_token_cycle(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "token_cycle_test: FAIL - {$message}" . PHP_EOL);
        exit(1);
    }
}

$timezone = new DateTimeZone('America/Sao_Paulo');
$context = new OperationalContext(
    new OperationalCycle(),
    new DateTimeImmutable('2026-07-29 01:29:59', $timezone)
);
$information = TokenCycle::evaluate([
    'token' => 'TESTE2',
    'token_data' => '2026-07-26',
], $context);

assert_token_cycle(
    !$information['needs_rotate'],
    'O token não deve girar antes do término exclusivo do ciclo.'
);

$atBoundary = new OperationalContext(
    new OperationalCycle(),
    new DateTimeImmutable('2026-07-29 01:30:00', $timezone)
);
$information = TokenCycle::evaluate([
    'token' => 'TESTE2',
    'token_data' => '2026-07-26',
], $atBoundary);
assert_token_cycle(
    $information['needs_rotate'],
    'O token deve girar no término exato do ciclo de três dias.'
);

$code = LegacyIdentity::tokenCode();
$clientId = LegacyIdentity::clientId();
assert_token_cycle(
    preg_match('/^[A-Z2-9]{6}$/', $code) === 1,
    'O código legado deve manter seis caracteres aceitos.'
);
assert_token_cycle(
    preg_match('/^[a-f0-9]{32}$/', $clientId) === 1,
    'O client_id legado deve manter o contrato hexadecimal de 32 caracteres.'
);

echo 'token_cycle_test: OK' . PHP_EOL;
